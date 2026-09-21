<?php

declare(strict_types=1);

namespace QueryBuilder\Tests\Unit\Discount;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QueryBuilder\Discount\CartLineDiscountPolicy;
use QueryBuilder\Discount\CatalogPricePolicy;
use QueryBuilder\Discount\Discount;
use QueryBuilder\Discount\LinePrices;
use Thelia\Domain\Pricing\ResolvedCatalogPrice;

final class CatalogPricePolicyTest extends TestCase
{
    private const PSE_ID = 42;

    private CatalogPricePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new CatalogPricePolicy(new CartLineDiscountPolicy());
    }

    #[Test]
    public function aRulePricesASaleElementTheCoreLeftAtItsCatalogPrice(): void
    {
        $price = $this->policy->resolve(self::PSE_ID, new Discount(20.0, 'Loyalty', false), new LinePrices(100.0, 0.0, 0), null);

        self::assertNotNull($price);
        self::assertSame(self::PSE_ID, $price->productSaleElementsId);
        self::assertSame(80.0, $price->untaxedPrice);
        self::assertSame(0, $price->ruleId, 'no catalog price rule of the core is behind this price');
        self::assertTrue($price->displayInitialPrice);
        self::assertNull($price->validUntil);
    }

    #[Test]
    public function aNonStackableRuleLeavesABetterCatalogPromotionAlone(): void
    {
        self::assertNull($this->policy->resolve(self::PSE_ID, new Discount(10.0, null, false), new LinePrices(100.0, 85.0, 1), null));
    }

    #[Test]
    public function aCoreRulePriceIsThePromotionANonStackableRuleIsMeasuredAgainst(): void
    {
        $corePrice = new ResolvedCatalogPrice(self::PSE_ID, 70.0, 7, false, new \DateTimeImmutable('2030-01-01'));

        self::assertNull(
            $this->policy->resolve(self::PSE_ID, new Discount(20.0, null, false), new LinePrices(100.0, 0.0, 0), $corePrice),
            'the core rule (70) beats the module discount (80): the core answer stands'
        );

        $price = $this->policy->resolve(self::PSE_ID, new Discount(40.0, null, false), new LinePrices(100.0, 0.0, 0), $corePrice);

        self::assertNotNull($price);
        self::assertSame(60.0, $price->untaxedPrice, 'a better module discount replaces the core rule price');
        self::assertSame(0, $price->ruleId);
        self::assertNull($price->validUntil, 'computed from the catalog price, it does not move when the core rule ends');
    }

    #[Test]
    public function aStackableRuleAppliesOnTopOfTheCoreRulePriceAndFollowsItsEnd(): void
    {
        $end = new \DateTimeImmutable('2030-01-01');
        $corePrice = new ResolvedCatalogPrice(self::PSE_ID, 70.0, 7, false, $end);

        $price = $this->policy->resolve(self::PSE_ID, new Discount(10.0, null, true), new LinePrices(100.0, 0.0, 0), $corePrice);

        self::assertNotNull($price);
        self::assertSame(63.0, $price->untaxedPrice);
        self::assertSame($end, $price->validUntil);
    }

    #[Test]
    public function aStackableRuleAppliesOnTopOfACatalogPromotion(): void
    {
        $price = $this->policy->resolve(self::PSE_ID, new Discount(10.0, null, true), new LinePrices(100.0, 80.0, 1), null);

        self::assertNotNull($price);
        self::assertSame(72.0, $price->untaxedPrice);
    }
}
