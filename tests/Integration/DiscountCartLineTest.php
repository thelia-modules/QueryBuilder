<?php

declare(strict_types=1);

namespace QueryBuilder\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use QueryBuilder\Action\ActionInterface;
use QueryBuilder\Action\ApplyDiscountAction;
use QueryBuilder\Enum\Context;
use QueryBuilder\Model\QueryBuilderAction;
use QueryBuilder\Model\QueryBuilderRule;
use QueryBuilder\QueryBuilder;
use QueryBuilder\Service\CartLineDiscountResolver;
use Thelia\Core\Event\Cart\CartEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Domain\Pricing\EffectivePriceCatalog;
use Thelia\Domain\Pricing\PricingActivityChecker;
use Thelia\Model\Cart;
use Thelia\Model\CartItem;
use Thelia\Model\Currency;
use Thelia\Model\Customer;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Module\BaseModule;
use Thelia\Test\ActionIntegrationTestCase;

/**
 * The core writes the rule discount on the cart lines it settles, customer
 * discount included, and the cart fragment of the module recognises the line as
 * carrying the discount, so the label shown names the price actually charged.
 */
final class DiscountCartLineTest extends ActionIntegrationTestCase
{
    private const CATALOG_PRICE = 100.0;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $module = ModuleQuery::create()->findOneByCode(QueryBuilder::getModuleCode());

        if ($module === null || (int) $module->getActivate() !== BaseModule::IS_ACTIVATED) {
            self::markTestSkipped('The QueryBuilder module is not activated in the test database.');
        }

        $this->currency = $this->factory->currency();
    }

    #[Test]
    public function theCoreWritesTheRuleDiscountOnTheLineAndTheFragmentNamesIt(): void
    {
        $product = $this->catalogProduct();
        $this->discountRule(20.0, 'Loyalty');
        $this->newRequest();

        $line = $this->addToCart($this->factory->cart(), $product);

        self::assertSame(1, (int) $line->getPromo());
        self::assertEqualsWithDelta(80.0, (float) $line->getPromoPrice(), 0.000001, 'the core priced the line from the decorated resolver');
        self::assertEqualsWithDelta(100.0, (float) $line->getPrice(), 0.000001);

        $discount = $this->getService(CartLineDiscountResolver::class)->resolve($line);

        self::assertNotNull($discount);
        self::assertSame('Loyalty', $discount->label);
        self::assertSame(20.0, $discount->rate);
    }

    #[Test]
    public function aCustomerDiscountAppliesOnTopAndTheLineIsStillRecognised(): void
    {
        $product = $this->catalogProduct();
        $this->discountRule(20.0, 'Loyalty');
        $customer = $this->factory->customer($this->factory->customerTitle());
        $customer->setDiscount('10')->save();
        $this->newRequest();

        $line = $this->addToCart($this->factory->cart($customer), $product);

        self::assertEqualsWithDelta(72.0, (float) $line->getPromoPrice(), 0.000001, '80 less the 10% customer discount');
        self::assertEqualsWithDelta(90.0, (float) $line->getPrice(), 0.000001);
        self::assertSame('Loyalty', $this->getService(CartLineDiscountResolver::class)->resolve($line)?->label);
    }

    #[Test]
    public function aLineAtTheCatalogPriceCarriesNoRuleDiscount(): void
    {
        $product = $this->catalogProduct();
        $this->newRequest();

        $line = $this->addToCart($this->factory->cart(), $product);

        self::assertSame(0, (int) $line->getPromo());
        self::assertNull($this->getService(CartLineDiscountResolver::class)->resolve($line));
    }

    /**
     * The per-request memoised pricing checks are what a new request starts from.
     */
    private function newRequest(): void
    {
        $this->getService(PricingActivityChecker::class)->reset();
        $this->getService(EffectivePriceCatalog::class)->reset();
    }

    private function discountRule(float $rate, string $label): void
    {
        $rule = (new QueryBuilderRule())
            ->setName('Discount rule under test')
            ->setContext(Context::GLOBAL_SCOPE->value)
            ->setActivate(1);
        $rule->save();

        (new QueryBuilderAction())
            ->setRuleId($rule->getId())
            ->setName('Discount action under test')
            ->setCode(ApplyDiscountAction::CODE)
            ->setType(ActionInterface::TYPE_ACTION)
            ->setParameters(json_encode(['discount_rate' => $rate, 'discount_label' => $label], \JSON_THROW_ON_ERROR))
            ->setActivate(1)
            ->save();
    }

    private function addToCart(Cart $cart, Product $product): CartItem
    {
        $event = new CartEvent($cart);
        $event
            ->setProductId($product->getId())
            ->setProductSaleElementsId($this->defaultPseFor($product)->getId())
            ->setQuantity(1)
            ->setNewness(true)
            ->setAppend(true);

        $this->dispatch($event, TheliaEvents::CART_ADDITEM);

        return $event->getCartItem();
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
