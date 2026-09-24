<?php

declare(strict_types=1);

namespace QueryBuilder\Discount;

use Thelia\Domain\Pricing\ResolvedCatalogPrice;

/** A rule discount as the core prices a sale element with it: the discount and the price it yields. */
final readonly class DiscountedCatalogPrice
{
    public function __construct(
        public Discount $discount,
        public ResolvedCatalogPrice $price,
    ) {
    }
}
