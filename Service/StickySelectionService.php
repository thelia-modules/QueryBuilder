<?php

declare(strict_types=1);

namespace QueryBuilder\Service;

use QueryBuilder\Model\QueryBuilderAction;
use QueryBuilder\Query\RuntimeContext;

/**
 * Common trunk of the sticky selections (displayed suggestions, persisted
 * discounts): the still-active cycles of the action, kept while displayable,
 * then the free slots filled by ProductSelector and recorded as new cycles.
 * Two steps on purpose: a consumer decides what a compilation failure means
 * at each step (the display action lets it propagate, the discount resolution
 * keeps the active cycles rather than dropping a running discount).
 */
final readonly class StickySelectionService
{
    public function __construct(
        private SuggestionService $suggestionService,
        private SqlBuilder $sqlBuilder,
        private ProductSelector $productSelector,
    ) {
    }

    /**
     * Still-active cycles of the action for the customer of the context, oldest
     * display first, minus the excluded ids, capped at the limit. Active cycles
     * stay subject to the query scopes (visibility, price...) and to the action
     * conditions, re-checked on the customer and the product alone (no current
     * product, no cart): a product no longer displayable or no longer eligible
     * (its category was bought since, the customer placed a first order…) has
     * its cycle ended and frees its slot instead of being served until expiry.
     * The excluded ids (cart) are hidden from the result, never ended for
     * sitting in the cart (the re-check ignores it), but they stay subject to
     * the eligibility re-check like every other cycle: a product that no longer
     * matches must not come back once it leaves the cart.
     *
     * @param int[] $excludedProductIds
     *
     * @return int[]
     *
     * @throws \InvalidArgumentException when the condition tree cannot be compiled for this context
     */
    public function getActiveProductIds(
        QueryBuilderAction $action,
        RuntimeContext $runtimeContext,
        int $limit,
        array $excludedProductIds = [],
    ): array {
        $customerId = (int) $runtimeContext->customerId;
        $actionId = (int) $action->getId();
        $productIds = $this->suggestionService->getActiveProductIds($customerId, $actionId);

        if ($productIds !== []) {
            $eligibleIds = $this->sqlBuilder->getProductIds(
                $action->getConditionTreeArray(),
                $runtimeContext->withoutCurrentProductAndCart(),
                null,
                [sprintf('`product`.`id` IN (%s)', implode(', ', array_map(intval(...), $productIds)))]
            );

            $this->suggestionService->expireCycles($customerId, $actionId, array_values(array_diff($productIds, $eligibleIds)));

            //array_intersect keeps the oldest-display-first order of the cycles
            $productIds = array_values(array_intersect($productIds, $eligibleIds));
        }

        return \array_slice(array_values(array_diff($productIds, $excludedProductIds)), 0, $limit);
    }

    /**
     * Fills the free slots up to the limit (families of the active products
     * present, promoted ids first among peers, rotation of the action cycles),
     * records the new cycles and returns the completed selection, active first.
     *
     * @param int[] $activeProductIds
     * @param int[] $promotedProductIds
     *
     * @return int[]
     *
     * @throws \InvalidArgumentException when the condition tree cannot be compiled for this context
     */
    public function refill(
        QueryBuilderAction $action,
        RuntimeContext $runtimeContext,
        int $limit,
        int $persistDays,
        array $activeProductIds,
        array $promotedProductIds = [],
    ): array {
        $missing = $limit - \count($activeProductIds);

        if ($missing <= 0) {
            return $activeProductIds;
        }

        $newProductIds = $this->productSelector->select(
            $action->getConditionTreeArray(),
            $runtimeContext,
            $missing,
            engagedProductIds: $activeProductIds,
            promotedProductIds: $promotedProductIds,
            rotationActionId: (int) $action->getId()
        );

        if ($newProductIds === []) {
            return $activeProductIds;
        }

        $this->suggestionService->recordSuggestions(
            (int) $runtimeContext->customerId,
            $action,
            $newProductIds,
            $persistDays
        );

        return array_merge($activeProductIds, $newProductIds);
    }
}
