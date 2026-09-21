<?php

declare(strict_types=1);

namespace QueryBuilder\Service;

use Propel\Runtime\ActiveQuery\Criteria;
use QueryBuilder\Discount\CatalogPricePolicy;
use QueryBuilder\Discount\DiscountedCatalogPrice;
use QueryBuilder\Query\RuntimeContext;
use Thelia\Domain\Pricing\ResolvedCatalogPrice;
use Thelia\Model\Currency;
use Thelia\Model\ProductSaleElementsQuery;

/**
 * What the ApplyDiscount rules make of a batch of sale elements, for one visit.
 *
 * The rules are resolved once for the products of the batch, the catalog prices
 * of the discounted sale elements are read in one statement, and the policy
 * decides, sale element by sale element, whether the discount improves on the
 * promotion already shown. Two readers start from here: the catalog price
 * resolver the core calls, and the cart fragment that names the discount charged
 * on a line.
 */
final readonly class RuleDiscountedPriceResolver
{
    public function __construct(
        private DiscountResolutionService $discountResolutionService,
        private CatalogPriceReader $catalogPriceReader,
        private CatalogPricePolicy $catalogPricePolicy,
    ) {
    }

    /**
     * @param list<int>                        $productSaleElementsIds
     * @param array<int, ResolvedCatalogPrice> $corePrices             what the catalog price rules of the core answered for the batch
     *
     * @return array<int, DiscountedCatalogPrice> keyed by sale element id; absent when no rule of this module has the last word
     */
    public function resolve(array $productSaleElementsIds, Currency $currency, RuntimeContext $runtimeContext, array $corePrices): array
    {
        $productSaleElementsIds = array_values(array_unique(array_map('intval', $productSaleElementsIds)));

        if ($productSaleElementsIds === []) {
            return [];
        }

        $productSaleElements = ProductSaleElementsQuery::create()
            ->filterById($productSaleElementsIds, Criteria::IN)
            ->find();

        $productIds = [];

        foreach ($productSaleElements as $productSaleElement) {
            $productIds[] = (int) $productSaleElement->getProductId();
        }

        $discounts = $this->discountResolutionService->getDiscounts($runtimeContext, array_values(array_unique($productIds)));

        if ($discounts === []) {
            return [];
        }

        $discounted = [];

        foreach ($productSaleElements as $productSaleElement) {
            if (isset($discounts[(int) $productSaleElement->getProductId()])) {
                $discounted[] = $productSaleElement;
            }
        }

        $catalogPrices = $this->catalogPriceReader->forSaleElements($discounted, $currency);
        $resolved = [];

        foreach ($discounted as $productSaleElement) {
            $productSaleElementsId = (int) $productSaleElement->getId();
            $catalog = $catalogPrices[$productSaleElementsId] ?? null;

            if ($catalog === null) {
                continue;
            }

            $discount = $discounts[(int) $productSaleElement->getProductId()];
            $price = $this->catalogPricePolicy->resolve($productSaleElementsId, $discount, $catalog, $corePrices[$productSaleElementsId] ?? null);

            if ($price !== null) {
                $resolved[$productSaleElementsId] = new DiscountedCatalogPrice($discount, $price);
            }
        }

        return $resolved;
    }
}
