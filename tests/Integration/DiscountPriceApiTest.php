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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Thelia\Domain\Pricing\EffectivePriceCatalog;
use Thelia\Domain\Pricing\PricingActivityChecker;
use Thelia\Model\Currency;
use Thelia\Model\ModuleQuery;
use Thelia\Model\Product;
use Thelia\Module\BaseModule;
use Thelia\Test\ActionIntegrationTestCase;

/**
 * The front API serves the rule discount as the promo price of a sale element,
 * through the catalog price contract of the core: nothing of the module listens
 * to the API any more, the core asks the decorated resolver. The read is the one
 * of a decoupled front: no session, no cart.
 */
final class DiscountPriceApiTest extends ActionIntegrationTestCase
{
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
    public function aVisitorReadsTheDiscountedPriceOnTheFrontProductResource(): void
    {
        $product = $this->catalogProduct();
        $this->discountRule(20.0);
        $this->newRequest();

        $saleElement = $this->readJson('/api/front/products/' . $product->getId())['productSaleElements'][0];

        self::assertTrue($saleElement['promo']);
        self::assertTrue($saleElement['displayInitialPrice']);
        self::assertSame(80.0, (float) $saleElement['productPrices'][0]['promoPrice']);
        self::assertSame(100.0, (float) $saleElement['productPrices'][0]['price'], 'the catalog price is untouched');
    }

    #[Test]
    public function aProductNoRuleDiscountsKeepsItsCatalogPrices(): void
    {
        $product = $this->catalogProduct();
        $this->newRequest();

        $saleElement = $this->readJson('/api/front/products/' . $product->getId())['productSaleElements'][0];

        self::assertFalse($saleElement['promo']);
        self::assertSame(100.0, (float) $saleElement['productPrices'][0]['price']);
    }

    /**
     * The per-request memoised pricing checks are what a new request starts from.
     */
    private function newRequest(): void
    {
        $this->getService(PricingActivityChecker::class)->reset();
        $this->getService(EffectivePriceCatalog::class)->reset();
    }

    /**
     * A stateless read, handled by the kernel itself: no browser, no session cookie.
     */
    private function readJson(string $uri): array
    {
        $request = Request::create($uri, 'GET', server: ['HTTP_ACCEPT' => 'application/ld+json']);
        $response = self::$kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, false);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function discountRule(float $rate): void
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
            ->setParameters(json_encode(['discount_rate' => $rate, 'discount_label' => 'Test'], \JSON_THROW_ON_ERROR))
            ->setActivate(1)
            ->save();
    }

    private function catalogProduct(): Product
    {
        return $this->factory->product(
            $this->factory->category(),
            $this->factory->taxRule(),
            $this->currency,
            ['baseQuantity' => 100, 'basePrice' => 100.0, 'title' => 'Discounted product'],
        );
    }
}
