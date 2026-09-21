<?php

declare(strict_types=1);

namespace QueryBuilder\Service;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Thelia\Domain\Pricing\PricingActivityChecker;
use Thelia\Domain\Sale\SaleAudienceChecker;

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
 */
#[AsDecorator(PricingActivityChecker::class)]
final class DiscountPricingActivityChecker extends PricingActivityChecker
{
    public function __construct(
        #[AutowireDecorated]
        private readonly PricingActivityChecker $inner,
        SaleAudienceChecker $saleAudienceChecker,
        private readonly DiscountActivityChecker $discountActivityChecker,
    ) {
        parent::__construct($saleAudienceChecker);
    }

    public function hasActivePublicRule(): bool
    {
        return $this->inner->hasActivePublicRule() || $this->discountActivityChecker->hasActiveDiscountRule();
    }

    public function hasActiveNamedRule(): bool
    {
        return $this->inner->hasActiveNamedRule();
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
