<?php

declare(strict_types=1);

namespace QueryBuilder\Service;

use Propel\Runtime\ActiveQuery\Criteria;
use QueryBuilder\Model\QueryBuilderAction;
use QueryBuilder\Model\QueryBuilderRule;
use QueryBuilder\Model\QueryBuilderSuggestion;
use QueryBuilder\Model\QueryBuilderSuggestionQuery;

/**
 * Persistent state of the sticky selections (displayed suggestions and
 * persisted product discounts): a cycle lasts persist_days × 24 hours or ends
 * with the purchase of the product. One row per (customer, product, action).
 */
final class SuggestionService
{
    /** @return int[] product ids of the still-active suggestions of this action, oldest display first */
    public function getActiveProductIds(int $customerId, int $actionId): array
    {
        $productIds = [];

        $suggestions = $this->createActiveQuery($customerId)
            ->filterByActionId($actionId)
            ->orderByDisplayedAt()
            ->find();

        foreach ($suggestions as $suggestion) {
            $productIds[] = (int) $suggestion->getProductId();
        }

        return $productIds;
    }

    /**
     * Registers a new display cycle for the given products. Existing rows are
     * refreshed (a product re-selected after expiry or purchase starts a new
     * cycle), new ones are created.
     *
     * @param int[] $productIds
     */
    public function recordSuggestions(
        int $customerId,
        QueryBuilderAction $action,
        array $productIds,
        int $persistDays,
        ?string $hook = null,
    ): void {
        if ($productIds === []) {
            return;
        }

        $now = new \DateTime();
        $expiresAt = (new \DateTime())->modify(sprintf('+%d day', max(1, $persistDays)));

        //Les cycles existants sont chargés en une seule requête (pas de findOne par produit)
        $existingSuggestions = [];
        $suggestions = QueryBuilderSuggestionQuery::create()
            ->filterByCustomerId($customerId)
            ->filterByActionId($action->getId())
            ->filterByProductId($productIds, Criteria::IN)
            ->find();

        foreach ($suggestions as $suggestion) {
            $existingSuggestions[(int) $suggestion->getProductId()] = $suggestion;
        }

        foreach ($productIds as $productId) {
            $suggestion = $existingSuggestions[(int) $productId]
                ?? (new QueryBuilderSuggestion())
                    ->setCustomerId($customerId)
                    ->setProductId($productId)
                    ->setActionId($action->getId());

            $suggestion
                ->setRuleId($action->getRuleId())
                ->setHook($hook)
                ->setDisplayedAt($now)
                ->setExpiresAt($expiresAt)
                ->setPurchasedAt(null)
                ->save();
        }
    }

    /** Pushes back the expiry of still-active cycles (a discounted product in the cart keeps its price). */
    public function extendCycles(int $customerId, int $actionId, array $productIds, int $persistDays): void
    {
        if ($productIds === []) {
            return;
        }

        $this->createActiveQuery($customerId)
            ->filterByActionId($actionId)
            ->filterByProductId($productIds, Criteria::IN)
            ->update([
                'ExpiresAt' => (new \DateTime())->modify(sprintf('+%d day', max(1, $persistDays))),
                'UpdatedAt' => new \DateTime(),
            ]);
    }

    /**
     * Ends the active cycles of the action for every customer when the saved
     * action no longer matches the state its cycles were created in (see
     * ActionCycleState): a still-running cycle belongs to the selection it
     * was created from, not to the new one.
     */
    public function expireCyclesInvalidatedBy(ActionCycleState $previousState, QueryBuilderAction $action): void
    {
        if ($previousState->invalidatesCyclesOf(ActionCycleState::fromAction($action))) {
            $this->expireActiveCycles((int) $action->getId());
        }
    }

    /**
     * Same at rule level (see RuleCycleState): the cycles of every action of
     * the rule end when the customers it targets or its activation change.
     */
    public function expireRuleCyclesInvalidatedBy(RuleCycleState $previousState, QueryBuilderRule $rule): void
    {
        if ($previousState->invalidatesCyclesOf(RuleCycleState::fromRule($rule))) {
            $this->expireActiveCyclesOfRule((int) $rule->getId());
        }
    }

    /** Ends the active cycles of the action for every customer. */
    public function expireActiveCycles(int $actionId): void
    {
        $this->expireNow(QueryBuilderSuggestionQuery::create()->filterByActionId($actionId));
    }

    /** Ends the active cycles of every action of the rule, for every customer. */
    public function expireActiveCyclesOfRule(int $ruleId): void
    {
        $this->expireNow(QueryBuilderSuggestionQuery::create()->filterByRuleId($ruleId));
    }

    /**
     * Ends the cycles of the given products for one customer and action: the
     * products no longer match the action conditions (bought category, first
     * order, typology…) and must free their slot instead of waiting for expiry.
     *
     * @param int[] $productIds
     */
    public function expireCycles(int $customerId, int $actionId, array $productIds): void
    {
        if ($productIds === []) {
            return;
        }

        $this->expireNow(
            QueryBuilderSuggestionQuery::create()
                ->filterByCustomerId($customerId)
                ->filterByActionId($actionId)
                ->filterByProductId($productIds, Criteria::IN)
        );
    }

    private function expireNow(QueryBuilderSuggestionQuery $query): void
    {
        $now = new \DateTime();

        $query
            ->filterByPurchasedAt(null, Criteria::ISNULL)
            ->filterByExpiresAt($now, Criteria::GREATER_THAN)
            ->update(['ExpiresAt' => $now, 'UpdatedAt' => $now]);
    }

    /**
     * ORDER BY clause of the refill rotation of this action: products never
     * selected first (no cycle row, NULL sorts first), then from the oldest
     * cycle end — an expired product goes to the back of the queue instead of
     * being immediately re-selected. One row per (customer, product, action):
     * the correlated subquery is scalar. Module integers, inlined.
     */
    public function getRotationOrderByExpression(int $customerId, int $actionId): string
    {
        return sprintf(
            '(SELECT COALESCE(qb_rot.purchased_at, qb_rot.expires_at) FROM query_builder_suggestion qb_rot WHERE qb_rot.customer_id = %d AND qb_rot.action_id = %d AND qb_rot.product_id = `product`.`id`) ASC',
            $customerId,
            $actionId
        );
    }

    /** Ends the display cycle of the purchased products (the suggestion dies with the purchase). */
    public function markPurchased(int $customerId, array $productIds): void
    {
        if ($productIds === []) {
            return;
        }

        $now = new \DateTime();

        //Mass update: single SQL query, no per-row hydration/save
        $this->createActiveQuery($customerId)
            ->filterByProductId($productIds, Criteria::IN)
            ->update(['PurchasedAt' => $now, 'UpdatedAt' => $now]);
    }

    private function createActiveQuery(int $customerId): QueryBuilderSuggestionQuery
    {
        return QueryBuilderSuggestionQuery::create()
            ->filterByCustomerId($customerId)
            ->filterByPurchasedAt(null, Criteria::ISNULL)
            ->filterByExpiresAt(new \DateTime(), Criteria::GREATER_THAN);
    }
}
