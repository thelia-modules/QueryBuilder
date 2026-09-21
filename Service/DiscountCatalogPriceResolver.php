<?php

declare(strict_types=1);

namespace QueryBuilder\Service;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Thelia\Domain\Pricing\CatalogPriceResolverInterface;
use Thelia\Log\Tlog;
use Thelia\Model\Currency;
use Thelia\Model\Customer;

/**
 * Plugs the ApplyDiscount rules into the catalog price contract of the core.
 *
 * Every reader of a price (the product loops, the front API, the product page
 * service, the cart settlement) asks the core once for the sale elements it is
 * about to show and substitutes the answer for the promo price. The core answers
 * with its catalog price rules first; this decorator then prices the sale
 * elements a rule of this module discounts for the visitor, and hands the rest
 * back untouched. The customer discount, the tax and the writing of the cart
 * lines are the core's, so the price announced on the product page is the price
 * charged on the line, to the cent.
 *
 * The visit the rules are evaluated for is the customer the core asks for and the
 * cart of the session, read without restoring it: the core settles a cart while
 * it restores it, and asking the session for the cart there would restore it
 * again.
 */
#[AsDecorator(CatalogPriceResolverInterface::class)]
final readonly class DiscountCatalogPriceResolver implements CatalogPriceResolverInterface
{
    public function __construct(
        #[AutowireDecorated]
        private CatalogPriceResolverInterface $inner,
        private DiscountActivityChecker $discountActivityChecker,
        private RuntimeContextFactory $runtimeContextFactory,
        private RuleDiscountedPriceResolver $ruleDiscountedPriceResolver,
    ) {
    }

    public function resolve(
        array $productSaleElementsIds,
        Currency $currency,
        ?Customer $customer,
        ?\DateTimeInterface $now = null,
    ): array {
        $prices = $this->inner->resolve($productSaleElementsIds, $currency, $customer, $now);

        if ($productSaleElementsIds === [] || !$this->discountActivityChecker->hasActiveDiscountRule()) {
            return $prices;
        }

        try {
            $discounted = $this->ruleDiscountedPriceResolver->resolve(
                $productSaleElementsIds,
                $currency,
                $this->runtimeContextFactory->forVisitor($customer),
                $prices
            );
        } catch (\Throwable $throwable) {
            //Never break a price read for a discount: the core answer stands
            Tlog::getInstance()->addError('QueryBuilder: product discounts not resolved on the catalog prices: ' . $throwable->getMessage());

            return $prices;
        }

        foreach ($discounted as $productSaleElementsId => $discountedCatalogPrice) {
            $prices[$productSaleElementsId] = $discountedCatalogPrice->price;
        }

        return $prices;
    }
}
