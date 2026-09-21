<?php

declare(strict_types=1);

namespace QueryBuilder\Action;

use QueryBuilder\Model\QueryBuilderAction;
use QueryBuilder\Query\RuntimeContext;

/**
 * Marks the products selected by the action condition tree as discounted for
 * the customers matching the rule eligibility.
 *
 * Parameters:
 *  - discount_rate (float, required): percentage off the reference price
 *  - discount_label (string, optional): label shown to the customer
 *  - discount_cumulative (bool, default false): stacks on top of an already
 *    discounted price instead of competing with it (project arbitration)
 *  - limit (int, optional): at most N products discounted at a time
 *  - persist_days (int, optional): a discounted product keeps its discount
 *    N × 24 hours or until purchased, then its slot rotates to another
 *    eligible product (query_builder_suggestion cycles; requires an
 *    identified customer — an anonymous visitor gets no discount at all
 *    from a persisted action; defaults limit to 3 when set alone)
 *
 * Unlike display actions, this action is NOT driven by hooks: the discounts
 * are resolved on demand by DiscountResolutionService. The module answers them
 * through the catalog price contract of the core (DiscountCatalogPriceResolver),
 * which prices the listings, the product page, the cart lines and the front API
 * with them, and exposes them on the front product resource
 * (QueryBuilderProductOffer addon). execute() is therefore a no-op on hook
 * execution.
 */
final readonly class ApplyDiscountAction implements ActionInterface
{
    public const CODE = 'ApplyDiscount';

    public static function getCode(): string
    {
        return self::CODE;
    }

    public static function getType(): string
    {
        return self::TYPE_ACTION;
    }

    public static function getLabel(): string
    {
        return 'Apply a product discount';
    }

    public static function getSupportedContexts(): array
    {
        return [];
    }

    public function execute(QueryBuilderAction $action, RuntimeContext $runtimeContext): ActionResult
    {
        return new ActionResult();
    }
}
