<?php

declare(strict_types=1);

namespace QueryBuilder\Service;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Thelia\Domain\Catalog\Bundle\BundleCatalogState;
use Thelia\Domain\Customer\Service\CustomerGroupResolver;
use Thelia\Domain\Pricing\CatalogActivityProbe;
use Thelia\Domain\Pricing\CustomerPrice\CustomerDiscountRate;
use Thelia\Domain\Pricing\PricingActivityChecker;
use Thelia\Domain\SaleUnit\Pricing\SaleUnitPricingActivityChecker;
use Thelia\Model\Customer;

/**
 * Tells the core that something prices the catalog beyond `product_price` while a
 * discount rule of this module is turned on.
 *
 * The core asks its activity checker before every effective price resolution (the
 * loops, the front API, the product page, the cart settlement): without this
 * answer the decorated price resolver would never be called. A rule discount is
 * open to anonymous visitors, so it counts as a public rule, and it depends on
 * who is asking and on what the cart holds, so the shared API cache of the
 * catalog steps aside for it exactly as it does for a rule reserved to named
 * customers.
 *
 * The named-rule check stays the core's own: it drives the resolution of the
 * catalog price rules reserved to customers, which this module knows nothing about.
 *
 * Since Thelia 3.4 the core checker reads its catalog activity directly instead of
 * going through hasActivePublicRule(): every public question that a public rule
 * answers is therefore asked to the decorated checker and widened here, and every
 * other one is passed through. The parent is built with the core dependencies so
 * that a question added by a later core still gets the core's answer.
 */
#[AsDecorator(PricingActivityChecker::class)]
final class DiscountPricingActivityChecker extends PricingActivityChecker
{
    public function __construct(
        #[AutowireDecorated]
        private readonly PricingActivityChecker $inner,
        private readonly DiscountActivityChecker $discountActivityChecker,
        CatalogActivityProbe $catalogActivityProbe,
        CustomerDiscountRate $customerDiscountRate,
        CustomerGroupResolver $customerGroupResolver,
        ?SaleUnitPricingActivityChecker $saleUnitPricingActivityChecker = null,
        ?BundleCatalogState $bundleCatalogState = null,
    ) {
        parent::__construct($catalogActivityProbe, $customerDiscountRate, $customerGroupResolver, $saleUnitPricingActivityChecker, $bundleCatalogState);
    }

    public function mayPriceBeyondCatalog(?Customer $customer): bool
    {
        return $this->inner->mayPriceBeyondCatalog($customer) || $this->discountActivityChecker->hasActiveDiscountRule();
    }

    public function mayPriceBeyondCatalogAsideFromBundles(?Customer $customer): bool
    {
        return $this->inner->mayPriceBeyondCatalogAsideFromBundles($customer) || $this->discountActivityChecker->hasActiveDiscountRule();
    }

    public function mayOrderDifferFromThePublicPrice(?Customer $customer): bool
    {
        return $this->inner->mayOrderDifferFromThePublicPrice($customer);
    }

    public function mayResolveEffectivePriceFor(?Customer $customer): bool
    {
        return $this->inner->mayResolveEffectivePriceFor($customer) || $this->discountActivityChecker->hasActiveDiscountRule();
    }

    public function pricesDependOnWhoIsAsking(?Customer $customer): bool
    {
        return $this->inner->pricesDependOnWhoIsAsking($customer) || $this->discountActivityChecker->hasActiveDiscountRule();
    }

    public function hasActivePublicRule(): bool
    {
        return $this->inner->hasActivePublicRule() || $this->discountActivityChecker->hasActiveDiscountRule();
    }

    public function hasActiveNamedRule(): bool
    {
        return $this->inner->hasActiveNamedRule();
    }

    public function hasComputedBundles(): bool
    {
        return $this->inner->hasComputedBundles();
    }

    public function hasCustomerGroupPrices(): bool
    {
        return $this->inner->hasCustomerGroupPrices();
    }

    public function hasDiscountedCustomerGroup(): bool
    {
        return $this->inner->hasDiscountedCustomerGroup();
    }

    public function hasDiscountedCustomer(?Customer $customer): bool
    {
        return $this->inner->hasDiscountedCustomer($customer);
    }

    public function hasVisitorDependentPricing(): bool
    {
        return $this->inner->hasVisitorDependentPricing() || $this->discountActivityChecker->hasActiveDiscountRule();
    }

    public function reset(): void
    {
        //The decoration moved the kernel.reset tag of the core checker onto this service
        $this->inner->reset();
        $this->discountActivityChecker->reset();
    }
}
