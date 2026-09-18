<?php

declare(strict_types=1);

namespace QueryBuilder\Action;

use QueryBuilder\Model\QueryBuilderAction;
use QueryBuilder\Query\RuntimeContext;
use QueryBuilder\Service\DiscountResolutionService;
use QueryBuilder\Service\ProductSelector;
use QueryBuilder\Service\StickySelectionService;

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
 * The selection follows the ProductSelector policy (project ranking, products
 * currently discounted first among peers, family mixing): the stateless path
 * picks the best candidates, the sticky path (StickySelectionService) fills
 * its free slots with them and re-ranks the assembled block for display only —
 * persistence and rotation are never affected by the ranking.
 *
 * Discounts are NOT handled here: use a dedicated ApplyDiscount action.
 */
final readonly class DisplayProductsListAction implements ActionInterface
{
    public const DEFAULT_LIMIT = 3;

    //A product never appears in its own recommendations (#561) — neutral outside
    //a product page, where :product_id is bound to the 0 sentinel. Only for the
    //stateless selection: the sticky path filters AFTER its persistence logic
    private const CURRENT_PRODUCT_EXCLUSION = '`product`.`id` != :product_id';

    public function __construct(
        private ProductSelector $productSelector,
        private StickySelectionService $stickySelectionService,
        private DiscountResolutionService $discountResolutionService,
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
        return 'Afficher une liste de produits';
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
        $discountedProductIds = $this->discountResolutionService->getDiscountedProductIds($runtimeContext);

        if ($persistDays === null || $persistDays <= 0 || $runtimeContext->customerId === null) {
            return new ActionResult(
                productIds: $this->productSelector->select(
                    $action->getConditionTreeArray(),
                    $runtimeContext,
                    $limit,
                    promotedProductIds: $discountedProductIds,
                    extraWhere: [self::CURRENT_PRODUCT_EXCLUSION]
                )
            );
        }

        //Suggestions of the current cycle, minus the products now in the cart
        $productIds = $this->stickySelectionService->getActiveProductIds(
            $action,
            $runtimeContext,
            $limit,
            $runtimeContext->cartProductIds
        );
        $productIds = $this->stickySelectionService->refill(
            $action,
            $runtimeContext,
            $limit,
            $persistDays,
            $productIds,
            $discountedProductIds
        );
        $productIds = $this->productSelector->rank($productIds, $runtimeContext, $discountedProductIds);

        //Display-only filter: the sticky rotation (displayability, backfill,
        //recording) must never react to which product page is being viewed —
        //a slot silently hidden here is not a freed slot (#561)
        if ($runtimeContext->productId !== null && $runtimeContext->productId > 0) {
            $productIds = array_values(array_diff($productIds, [$runtimeContext->productId]));
        }

        return new ActionResult(productIds: $productIds);
    }
}
