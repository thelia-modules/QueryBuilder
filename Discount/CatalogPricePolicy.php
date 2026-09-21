<?php

declare(strict_types=1);

namespace QueryBuilder\Discount;

use Thelia\Domain\Pricing\ResolvedCatalogPrice;

/**
 * How an ApplyDiscount rule combines with what a sale element is already priced
 * at: the catalog prices, or the price a catalog price rule of the core gave it.
 *
 * A core rule replaces the promo price the visitor would otherwise see, so it is
 * the promotion the module discount is measured against: a non-stackable rule
 * wins only when it is better, a stackable rule applies on top of it. The core
 * carries the id of the catalog price rule responsible for a price; a price this
 * module has the last word on carries none (0), so nothing reads a rule of the
 * core behind a discount of this module.
 */
final readonly class CatalogPricePolicy
{
    public function __construct(
        private CartLineDiscountPolicy $cartLineDiscountPolicy,
    ) {
    }

    /**
     * The price the discount gives the sale element, null when the promotion already
     * shown (catalog or core rule) is at least as good.
     */
    public function resolve(int $productSaleElementsId, Discount $discount, LinePrices $catalog, ?ResolvedCatalogPrice $corePrice): ?ResolvedCatalogPrice
    {
        $reference = $corePrice === null
            ? $catalog
            : new LinePrices($catalog->price, $corePrice->untaxedPrice, 1);

        $line = $this->cartLineDiscountPolicy->discountedLine($discount, $reference);

        if ($line === null) {
            return null;
        }

        return new ResolvedCatalogPrice(
            $productSaleElementsId,
            $line->promoPrice,
            0,
            true,
            //A discount stacked on a core rule price moves when that price ends
            $discount->cumulative ? $corePrice?->validUntil : null,
        );
    }
}
