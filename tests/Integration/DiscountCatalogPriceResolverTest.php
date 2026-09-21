<?php

declare(strict_types=1);

namespace QueryBuilder\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use QueryBuilder\Action\ApplyDiscountAction;
use QueryBuilder\Action\ActionInterface;
use QueryBuilder\Enum\Context;
use QueryBuilder\Model\QueryBuilderAction;
use QueryBuilder\Model\QueryBuilderRule;
use QueryBuilder\QueryBuilder;
use QueryBuilder\Service\DiscountCatalogPriceResolver;
use QueryBuilder\Service\DiscountPricingActivityChecker;
use Thelia\Core\Event\CatalogPriceRule\CatalogPriceRuleToggleActivityEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Domain\Pricing\CatalogPriceResolverInterface;
use Thelia\Domain\Pricing\EffectivePriceCatalog;
use Thelia\Domain\Pricing\PricingActivityChecker;
use Thelia\Model\CatalogPriceRule;
use Thelia\Model\Currency;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductPriceQuery;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Module\BaseModule;
use Thelia\Test\ActionIntegrationTestCase;

/**
 * The ApplyDiscount rules answer through the catalog price contract of the core:
 * the decorated resolver prices what a rule discounts, the decorated activity
 * checker tells the core to ask, and both step aside when no discount rule runs.
 *
 * Prerequisites: a shop test database (bin/test-prepare) where the module is
 * activated (module:activate QueryBuilder).
 */
final class DiscountCatalogPriceResolverTest extends ActionIntegrationTestCase
{
    private const CATALOG_PRICE = 100.0;

    private CatalogPriceResolverInterface $resolver;

    private PricingActivityChecker $activityChecker;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $module = ModuleQuery::create()->findOneByCode(QueryBuilder::getModuleCode());

        if ($module === null || (int) $module->getActivate() !== BaseModule::IS_ACTIVATED) {
            self::markTestSkipped('The QueryBuilder module is not activated in the test database.');
        }

        $this->resolver = $this->getService(CatalogPriceResolverInterface::class);
        $this->activityChecker = $this->getService(PricingActivityChecker::class);
        $this->currency = $this->factory->currency();
    }

    #[Test]
    public function theModuleDecoratesTheCatalogPriceContractOfTheCore(): void
    {
        self::assertInstanceOf(DiscountCatalogPriceResolver::class, $this->resolver);
        self::assertInstanceOf(DiscountPricingActivityChecker::class, $this->activityChecker);
    }

    #[Test]
    public function aDiscountRulePricesTheSaleElementsOfTheProductsItSelectsForEveryVisitor(): void
    {
        $product = $this->catalogProduct();
        $pse = $this->defaultPseFor($product);
        $this->discountRule(20.0);
        $this->newRequest();

        self::assertTrue($this->activityChecker->hasActivePublicRule(), 'the core is told a rule open to everyone runs');
        self::assertTrue($this->activityChecker->hasVisitorDependentPricing(), 'and that the price depends on who is asking');

        $prices = $this->resolver->resolve([$pse->getId()], $this->currency, null);

        self::assertArrayHasKey($pse->getId(), $prices);
        self::assertEqualsWithDelta(80.0, $prices[$pse->getId()]->untaxedPrice, 0.000001);
        self::assertSame(0, $prices[$pse->getId()]->ruleId, 'no catalog price rule of the core is behind this price');
        self::assertTrue($prices[$pse->getId()]->displayInitialPrice);
    }

    #[Test]
    public function aNonStackableRuleLeavesABetterCatalogPromotionToTheCatalog(): void
    {
        $product = $this->catalogProduct();
        $pse = $this->defaultPseFor($product);
        $this->catalogPromotion($pse, 75.0);
        $this->discountRule(20.0);
        $this->newRequest();

        self::assertSame([], $this->resolver->resolve([$pse->getId()], $this->currency, null), 'the catalog promotion (75) beats the rule (80)');
    }

    #[Test]
    public function aStackableRuleAppliesOnTopOfACatalogPriceRuleOfTheCore(): void
    {
        $product = $this->catalogProduct();
        $pse = $this->defaultPseFor($product);
        $this->coreRule($product, 50.0);
        $this->discountRule(10.0, cumulative: true);
        $this->newRequest();

        $prices = $this->resolver->resolve([$pse->getId()], $this->currency, null);

        self::assertEqualsWithDelta(45.0, $prices[$pse->getId()]->untaxedPrice, 0.000001, '10% off the 50 the core rule gives');
    }

    #[Test]
    public function aNonStackableRuleBeatenByACatalogPriceRuleOfTheCoreLeavesTheCoreAnswerStanding(): void
    {
        $product = $this->catalogProduct();
        $pse = $this->defaultPseFor($product);
        $coreRule = $this->coreRule($product, 50.0);
        $this->discountRule(20.0);
        $this->newRequest();

        $prices = $this->resolver->resolve([$pse->getId()], $this->currency, null);

        self::assertEqualsWithDelta(50.0, $prices[$pse->getId()]->untaxedPrice, 0.000001);
        self::assertSame((int) $coreRule->getId(), $prices[$pse->getId()]->ruleId, 'the core rule keeps the last word');
    }

    #[Test]
    public function aTurnedOffRuleLeavesTheCoreAloneAndThePriceToTheCatalog(): void
    {
        $product = $this->catalogProduct();
        $pse = $this->defaultPseFor($product);
        $rule = $this->discountRule(20.0);
        $this->newRequest();
        self::assertNotSame([], $this->resolver->resolve([$pse->getId()], $this->currency, null), 'the rule prices while it runs');

        $rule->setActivate(0)->save();
        $this->newRequest();

        self::assertSame([], $this->resolver->resolve([$pse->getId()], $this->currency, null));
        self::assertFalse($this->activityChecker->hasActivePublicRule(), 'nothing is left for the core to ask about');
        self::assertFalse($this->activityChecker->hasVisitorDependentPricing());
    }

    /**
     * The per-request memoised pricing checks are what a new request starts from.
     */
    private function newRequest(): void
    {
        $this->activityChecker->reset();
        $this->getService(EffectivePriceCatalog::class)->reset();
    }

    /**
     * A turned-on GLOBAL rule without conditions (always eligible), carrying one
     * ApplyDiscount action without a tree (the whole catalog).
     */
    private function discountRule(float $rate, bool $cumulative = false): QueryBuilderRule
    {
        $rule = (new QueryBuilderRule())
            ->setName('Discount rule under test')
            ->setContext(Context::GLOBAL_SCOPE->value)
            ->setActivate(1);
        $rule->save();

        $action = (new QueryBuilderAction())
            ->setRuleId($rule->getId())
            ->setName('Discount action under test')
            ->setCode(ApplyDiscountAction::CODE)
            ->setType(ActionInterface::TYPE_ACTION)
            ->setParameters(json_encode(['discount_rate' => $rate, 'discount_label' => 'Test', 'discount_cumulative' => $cumulative], \JSON_THROW_ON_ERROR))
            ->setActivate(1);
        $action->save();

        return $rule;
    }

    private function coreRule(Product $product, float $percentage): CatalogPriceRule
    {
        $rule = $this->factory->catalogPriceRule(['active' => true, 'percentageValue' => $percentage]);
        $this->factory->catalogPriceRuleCriterion($rule, CatalogPriceRule::CRITERION_PRODUCT, $product->getId());
        $this->dispatch(
            new CatalogPriceRuleToggleActivityEvent($rule->getId(), true),
            TheliaEvents::CATALOG_PRICE_RULE_TOGGLE_ACTIVITY,
        );

        return $rule;
    }

    private function catalogPromotion(ProductSaleElements $pse, float $promoPrice): void
    {
        $productPrice = ProductPriceQuery::create()
            ->filterByProductSaleElementsId($pse->getId())
            ->filterByCurrencyId($this->currency->getId())
            ->findOne();
        self::assertNotNull($productPrice);

        $productPrice->setPromoPrice((string) $promoPrice)->save();
        $pse->setPromo(1)->save();
    }

    private function catalogProduct(): Product
    {
        return $this->factory->product(
            $this->factory->category(),
            $this->factory->taxRule(),
            $this->currency,
            ['baseQuantity' => 100, 'basePrice' => self::CATALOG_PRICE],
        );
    }

    private function defaultPseFor(Product $product): ProductSaleElements
    {
        $pse = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->filterByIsDefault(true)->findOne();
        self::assertNotNull($pse);

        return $pse;
    }
}
