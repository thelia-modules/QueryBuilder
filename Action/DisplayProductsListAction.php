<?php

declare(strict_types=1);

namespace QueryBuilder\Action;

use QueryBuilder\Model\QueryBuilderAction;
use QueryBuilder\Query\RuntimeContext;
use QueryBuilder\Service\SqlBuilder;
use QueryBuilder\Service\SuggestionService;

/**
 * Selects the products matching the action condition tree and exposes their
 * ids; the hook template (front) or the API controller does the rendering.
 *
 * Parameters:
 *  - limit (int, default 3)
 *  - persist_days (int, optional): the selection becomes sticky — already
 *    displayed suggestions are served again for N calendar days or until
 *    purchase, and only the missing slots are filled from the condition tree
 *
 * Discounts are NOT handled here: use a dedicated ApplyDiscount action.
 */
final readonly class DisplayProductsListAction implements ActionInterface
{
    public const DEFAULT_LIMIT = 3;

    //A product never appears in its own recommendations — neutral outside
    //a product page, where :product_id is bound to the 0 sentinel. Only for the
    //stateless selection: the sticky path filters AFTER its persistence logic
    private const CURRENT_PRODUCT_EXCLUSION = '`product`.`id` != :product_id';

    public function __construct(
        private SqlBuilder $sqlBuilder,
        private SuggestionService $suggestionService,
    ) {
    }

    public static function getCode(): string
    {
        return 'DisplayProductsList';
    }

    public static function getType(): string
    {
        return self::TYPE_DISPLAY;
    }

    public static function getLabel(): string
    {
        return 'Display a list of products';
    }

    public static function getSupportedContexts(): array
    {
        return [];
    }

    public function execute(QueryBuilderAction $action, RuntimeContext $runtimeContext): ActionResult
    {
        $parameters = $action->getParametersArray();
        $limit = (int) ($parameters['limit'] ?? self::DEFAULT_LIMIT);
        $persistDays = isset($parameters['persist_days']) ? (int) $parameters['persist_days'] : null;

        if ($persistDays === null || $persistDays <= 0 || $runtimeContext->customerId === null) {
            return new ActionResult(
                productIds: $this->sqlBuilder->getProductIds(
                    $action->getConditionTreeArray(),
                    $runtimeContext,
                    $limit,
                    [self::CURRENT_PRODUCT_EXCLUSION]
                )
            );
        }

        return $this->executeSticky($action, $runtimeContext, $limit, $persistDays);
    }

    private function executeSticky(
        QueryBuilderAction $action,
        RuntimeContext $runtimeContext,
        int $limit,
        int $persistDays,
    ): ActionResult {
        $customerId = (int) $runtimeContext->customerId;

        //Suggestions of the current cycle, minus the products now in the cart
        $productIds = array_values(array_diff(
            $this->suggestionService->getActiveProductIds($customerId, (int) $action->getId()),
            $runtimeContext->cartProductIds
        ));

        //Active suggestions stay subject to the query scopes (visibility, price...):
        //a product no longer displayable frees its slot for the backfill below
        //instead of leaving a missing card until the cycle expires
        if ($productIds !== []) {
            $displayableIds = $this->sqlBuilder->getProductIds(
                null,
                $runtimeContext,
                null,
                [sprintf('`product`.`id` IN (%s)', implode(', ', array_map('intval', $productIds)))]
            );
            //array_intersect keeps the oldest-display-first order of the suggestions
            $productIds = array_values(array_intersect($productIds, $displayableIds));
        }

        $productIds = \array_slice($productIds, 0, $limit);

        $missing = $limit - \count($productIds);

        if ($missing > 0) {
            $eligibleIds = array_values(array_diff(
                $this->sqlBuilder->getProductIds(
                    $action->getConditionTreeArray(),
                    $runtimeContext
                ),
                $productIds
            ));
            $newProductIds = \array_slice(
                $this->suggestionService->orderRefillCandidates(
                    $eligibleIds,
                    $this->suggestionService->getLastCycleEndsByProductId($customerId, (int) $action->getId())
                ),
                0,
                $missing
            );

            if ($newProductIds !== []) {
                $this->suggestionService->recordSuggestions(
                    $customerId,
                    $action,
                    $newProductIds,
                    $persistDays
                );

                $productIds = array_merge($productIds, $newProductIds);
            }
        }

        //Display-only filter: the sticky rotation (displayability, backfill,
        //recording) must never react to which product page is being viewed —
        //a slot silently hidden here is not a freed slot
        if ($runtimeContext->productId !== null && $runtimeContext->productId > 0) {
            $productIds = array_values(array_diff($productIds, [$runtimeContext->productId]));
        }

        return new ActionResult(productIds: $productIds);
    }
}
