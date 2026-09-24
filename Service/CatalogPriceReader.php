<?php

declare(strict_types=1);

namespace QueryBuilder\Service;

use Propel\Runtime\ActiveQuery\Criteria;
use QueryBuilder\Discount\LinePrices;
use Thelia\Model\Currency;
use Thelia\Model\ProductPriceQuery;
use Thelia\Model\ProductSaleElements;

/**
 * Reads the catalog prices of sale elements exactly as the core does before it
 * prices a cart line or a product page: the row of the requested currency, the
 * default currency row converted at the rates when there is none or when it is
 * flagged as derived from the default currency. Before the customer discount:
 * the core applies it on top of whatever price this module answers, the way it
 * applies it to a promo price.
 */
final readonly class CatalogPriceReader
{
    public function inCurrency(ProductSaleElements $productSaleElements, Currency $currency): LinePrices
    {
        $prices = $productSaleElements->getPricesByCurrency($currency);

        return new LinePrices((float) $prices->getPrice(), (float) $prices->getPromoPrice(), (int) $productSaleElements->getPromo());
    }

    /**
     * The prices of a batch in one statement.
     *
     * @param iterable<ProductSaleElements> $productSaleElements
     *
     * @return array<int, LinePrices> keyed by sale element id; a sale element with no
     *                                price in the default currency is absent
     */
    public function forSaleElements(iterable $productSaleElements, Currency $currency): array
    {
        $promoById = [];

        foreach ($productSaleElements as $productSaleElement) {
            $promoById[(int) $productSaleElement->getId()] = (int) $productSaleElement->getPromo();
        }

        if ($promoById === []) {
            return [];
        }

        $defaultCurrency = Currency::getDefaultCurrency();
        $currencyId = (int) $currency->getId();
        $defaultCurrencyId = (int) $defaultCurrency->getId();
        $rate = (float) $defaultCurrency->getRate() > 0.0 ? (float) $currency->getRate() / (float) $defaultCurrency->getRate() : 1.0;

        /** @var array<int, array{explicit: ?array{0: float, 1: float}, default: ?array{0: float, 1: float}}> $rows */
        $rows = [];

        $productPrices = ProductPriceQuery::create()
            ->filterByProductSaleElementsId(array_keys($promoById), Criteria::IN)
            ->filterByCurrencyId(array_values(array_unique([$currencyId, $defaultCurrencyId])), Criteria::IN)
            ->find();

        foreach ($productPrices as $productPrice) {
            $productSaleElementsId = (int) $productPrice->getProductSaleElementsId();
            $pair = [(float) $productPrice->getPrice(), (float) $productPrice->getPromoPrice()];

            if ((int) $productPrice->getCurrencyId() === $defaultCurrencyId) {
                $rows[$productSaleElementsId]['default'] = $pair;
            }

            if ((int) $productPrice->getCurrencyId() === $currencyId && !$productPrice->getFromDefaultCurrency()) {
                $rows[$productSaleElementsId]['explicit'] = $pair;
            }
        }

        $prices = [];

        foreach ($promoById as $productSaleElementsId => $promo) {
            $row = $rows[$productSaleElementsId] ?? [];

            if (isset($row['explicit'])) {
                [$price, $promoPrice] = $row['explicit'];
            } elseif (isset($row['default'])) {
                [$price, $promoPrice] = [$row['default'][0] * $rate, $row['default'][1] * $rate];
            } else {
                continue;
            }

            $prices[$productSaleElementsId] = new LinePrices($price, $promoPrice, $promo);
        }

        return $prices;
    }
}
