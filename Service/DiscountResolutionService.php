<?php

declare(strict_types=1);

namespace QueryBuilder\Service;

use Propel\Runtime\ActiveQuery\Criteria;
use QueryBuilder\Action\ApplyDiscountAction;
use QueryBuilder\Discount\Discount;
use QueryBuilder\Model\QueryBuilderAction;
use QueryBuilder\Model\QueryBuilderActionQuery;
use QueryBuilder\Model\QueryBuilderRule;
use QueryBuilder\Model\QueryBuilderRuleQuery;
use QueryBuilder\Query\RuntimeContext;
use Thelia\Log\Tlog;

/**
 * Resolves the discounts carried by the ApplyDiscount actions: for every
 * active rule matching the runtime context (customer eligibility), the
 * products selected by the action condition tree get the discount. When
 * several rules discount the same product, the highest rate wins.
 *
 * An action may carry a `limit` (at most N products discounted at a time)
 * and a `persist_days` (a discounted product keeps its discount N × 24 hours
 * or until purchased, then its slot rotates to another eligible product).
 * Persistence reuses the query_builder_suggestion cycles and requires an
 * identified customer: an anonymous visitor gets NO discount from a
 * persisted action (never an unlimited fallback). A discounted product
 * sitting in the cart has its cycle extended at every resolution so its
 * price holds until the order is placed. Without these parameters the
 * resolution stays stateless, as before.
 *
 * A limited or persisted action picks its products through ProductSelector
 * (project ranking, family mixing), without promoted ids: the discounted
 * products are what is being resolved here, ranking them by "discounted"
 * would recurse.
 *
 * Not readonly: results are memoized per (context, products) for the request.
 */
final class DiscountResolutionService
{
    private const DEFAULT_STICKY_LIMIT = 3;

    /** @var array<string, array<int, Discount>> */
    private array $memoizedDiscounts = [];

    /** @var array<string, int[]> */
    private array $memoizedDiscountedProductIds = [];

    /** @var array<string, int[]|null> */
    private array $memoizedActionProductIds = [];

    public function __construct(
        private readonly RuleEngine $ruleEngine,
        private readonly SqlBuilder $sqlBuilder,
        private readonly SuggestionService $suggestionService,
        private readonly ProductSelector $productSelector,
        private readonly StickySelectionService $stickySelectionService,
    ) {
    }

    /** @return array<int, Discount> keyed by product id */
    public function getDiscounts(RuntimeContext $runtimeContext, array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));

        if ($productIds === []) {
            return [];
        }

        $memoKey = md5(serialize([$runtimeContext, $productIds]));

        return $this->memoizedDiscounts[$memoKey] ??= $this->resolve($runtimeContext, $productIds);
    }

    public function getDiscount(RuntimeContext $runtimeContext, int $productId): ?Discount
    {
        return $this->getDiscounts($runtimeContext, [$productId])[$productId] ?? null;
    }

    /**
     * Every product matched by an active ApplyDiscount rule for this context
     * (scopes applied, no other restriction). Lets the project treat rule
     * discounted products as promotions in its own SQL (filters, sorting).
     *
     * @return int[]
     */
    public function getDiscountedProductIds(RuntimeContext $runtimeContext): array
    {
        $memoKey = md5(serialize($runtimeContext));

        return $this->memoizedDiscountedProductIds[$memoKey] ??= $this->resolveDiscountedProductIds($runtimeContext);
    }

    /** @return int[] */
    private function resolveDiscountedProductIds(RuntimeContext $runtimeContext): array
    {
        $productIdsByAction = [];

        foreach ($this->getDiscountActionsByEligibleRule($runtimeContext) as [$rule, $actions]) {
            foreach ($actions as $action) {
                if (Discount::fromActionParameters($action->getParametersArray()) === null) {
                    continue;
                }

                $actionProductIds = $this->getActionProductIds($rule, $action, $runtimeContext, null);

                if ($actionProductIds !== null) {
                    $productIdsByAction[] = $actionProductIds;
                }
            }
        }

        return $productIdsByAction !== []
            ? array_values(array_unique(array_merge(...$productIdsByAction)))
            : [];
    }

    /** @return array<int, Discount> */
    private function resolve(RuntimeContext $runtimeContext, array $productIds): array
    {
        $discounts = [];

        foreach ($this->getDiscountActionsByEligibleRule($runtimeContext) as [$rule, $actions]) {
            foreach ($actions as $action) {
                $discount = Discount::fromActionParameters($action->getParametersArray());

                if ($discount === null) {
                    Tlog::getInstance()->addWarning(sprintf(
                        'QueryBuilder: ApplyDiscount action #%d of rule "%s" has no valid discount_rate, skipped.',
                        $action->getId(),
                        $rule->getName()
                    ));

                    continue;
                }

                $matchedProductIds = $this->getActionProductIds($rule, $action, $runtimeContext, $productIds);

                if ($matchedProductIds === null) {
                    continue;
                }

                foreach ($matchedProductIds as $matchedProductId) {
                    $currentDiscount = $discounts[$matchedProductId] ?? null;

                    if ($currentDiscount === null || $discount->rate > $currentDiscount->rate) {
                        $discounts[$matchedProductId] = $discount;
                    }
                }
            }
        }

        return $discounts;
    }

    /**
     * Products currently discounted by this action, restricted to the
     * requested ids when given. A limited or persisted action resolves its
     * product set against the full eligible universe (memoized per request,
     * never restricted to the requested ids: a product must not steal a slot
     * just because its price is being displayed), then intersects. A plain
     * action keeps the historical single-query path.
     *
     * @param int[]|null $restrictToIds
     *
     * @return int[]|null null when the action must be skipped
     */
    private function getActionProductIds(
        QueryBuilderRule $rule,
        QueryBuilderAction $action,
        RuntimeContext $runtimeContext,
        ?array $restrictToIds,
    ): ?array {
        $parameters = $action->getParametersArray();
        $persistDays = (int) ($parameters['persist_days'] ?? 0);
        $limit = (int) ($parameters['limit'] ?? ($persistDays > 0 ? self::DEFAULT_STICKY_LIMIT : 0));

        if ($persistDays <= 0 && $limit <= 0) {
            return $this->queryActionProductIds($rule, $action, $runtimeContext, $restrictToIds);
        }

        if ($persistDays > 0 && $runtimeContext->customerId === null) {
            return null;
        }

        $memoKey = md5(serialize($runtimeContext)) . '#' . (int) $action->getId();

        if (!\array_key_exists($memoKey, $this->memoizedActionProductIds)) {
            $this->memoizedActionProductIds[$memoKey] = $persistDays > 0
                ? $this->resolveStickyProductIds($rule, $action, $runtimeContext, $limit, $persistDays)
                : $this->selectActionProductIds($rule, $action, $runtimeContext, $limit);
        }

        $actionProductIds = $this->memoizedActionProductIds[$memoKey];

        if ($actionProductIds === null || $restrictToIds === null) {
            return $actionProductIds;
        }

        return array_values(array_intersect($actionProductIds, $restrictToIds));
    }

    /**
     * The persisted selection of the action: still-active cycles first (kept
     * as long as the product stays sellable per the query scopes), completed
     * up to the limit by rotating refill, cart cycles extended.
     *
     * @return int[]|null null when the action must be skipped
     */
    private function resolveStickyProductIds(
        QueryBuilderRule $rule,
        QueryBuilderAction $action,
        RuntimeContext $runtimeContext,
        int $limit,
        int $persistDays,
    ): ?array {
        try {
            $stickyIds = $this->stickySelectionService->getActiveProductIds($action, $runtimeContext, $limit);
        } catch (\InvalidArgumentException $exception) {
            $this->logSkippedAction($rule, $action, $exception);

            return null;
        }

        //A refill that cannot compile leaves the running discounts untouched
        try {
            $stickyIds = $this->stickySelectionService->refill($action, $runtimeContext, $limit, $persistDays, $stickyIds);
        } catch (\InvalidArgumentException $exception) {
            $this->logSkippedAction($rule, $action, $exception);
        }

        $cartProductIds = array_values(array_intersect($stickyIds, $runtimeContext->cartProductIds));

        if ($cartProductIds !== []) {
            $this->suggestionService->extendCycles(
                (int) $runtimeContext->customerId,
                (int) $action->getId(),
                $cartProductIds,
                $persistDays
            );
        }

        return $stickyIds;
    }

    /**
     * @param int[]|null $restrictToIds
     *
     * @return int[]|null null when the condition cannot be compiled for this context
     */
    private function queryActionProductIds(
        QueryBuilderRule $rule,
        QueryBuilderAction $action,
        RuntimeContext $runtimeContext,
        ?array $restrictToIds,
    ): ?array {
        $extraWhere = $restrictToIds !== null
            ? [sprintf('`product`.`id` IN (%s)', implode(', ', $restrictToIds))]
            : [];

        try {
            return $this->sqlBuilder->getProductIds(
                $action->getConditionTreeArray(),
                $runtimeContext,
                null,
                $extraWhere
            );
        } catch (\InvalidArgumentException $exception) {
            $this->logSkippedAction($rule, $action, $exception);

            return null;
        }
    }

    /**
     * Stateless limited action: the best ranked products of the tree, families mixed.
     *
     * @return int[]|null null when the condition cannot be compiled for this context
     */
    private function selectActionProductIds(
        QueryBuilderRule $rule,
        QueryBuilderAction $action,
        RuntimeContext $runtimeContext,
        int $limit,
    ): ?array {
        try {
            return $this->productSelector->select($action->getConditionTreeArray(), $runtimeContext, $limit);
        } catch (\InvalidArgumentException $exception) {
            $this->logSkippedAction($rule, $action, $exception);

            return null;
        }
    }

    private function logSkippedAction(QueryBuilderRule $rule, QueryBuilderAction $action, \InvalidArgumentException $exception): void
    {
        Tlog::getInstance()->addWarning(sprintf(
            'QueryBuilder: ApplyDiscount action #%d of rule "%s" skipped: %s',
            $action->getId(),
            $rule->getName(),
            $exception->getMessage()
        ));
    }

    /**
     * Active rules carrying at least one active ApplyDiscount action, filtered
     * on their eligibility (rule condition tree). Rule hooks are irrelevant
     * here: a discount is resolved wherever a price is needed, not on a hook.
     *
     * @return array<int, array{0: QueryBuilderRule, 1: QueryBuilderAction[]}>
     */
    private function getDiscountActionsByEligibleRule(RuntimeContext $runtimeContext): array
    {
        $actionsByRuleId = [];

        $actions = QueryBuilderActionQuery::create()
            ->filterByCode(ApplyDiscountAction::CODE)
            ->filterByActivate(1)
            ->orderByPosition()
            ->find();

        foreach ($actions as $action) {
            $actionsByRuleId[(int) $action->getRuleId()][] = $action;
        }

        if ($actionsByRuleId === []) {
            return [];
        }

        $eligible = [];

        $rules = QueryBuilderRuleQuery::create()
            ->filterById(array_keys($actionsByRuleId), Criteria::IN)
            ->filterByActivate(1)
            ->orderByPosition()
            ->find();

        foreach ($rules as $rule) {
            if ($this->ruleEngine->ruleMatches($rule, $runtimeContext)) {
                $eligible[] = [$rule, $actionsByRuleId[(int) $rule->getId()]];
            }
        }

        return $eligible;
    }
}
