<?php

declare(strict_types=1);

namespace QueryBuilder\Service;

use QueryBuilder\Discount\CartLineDiscountPolicy;
use QueryBuilder\Discount\Discount;
use Thelia\Domain\Pricing\Rule\CatalogPriceRuleResolver;
use Thelia\Model\CartItem;
use Thelia\Model\Currency;

/**
 * The rule discount a cart line actually charges: the rule that wins for the
 * product of the line, provided the promotion price of the line is the one the
 * core wrote from it (the catalog price resolver of this module, then the
 * customer discount). A line still at the catalog prices, or priced by a better
 * catalog promotion or core rule, carries none.
 */
final readonly class CartLineDiscountResolver
{
    public function __construct(
        private RuntimeContextFactory $runtimeContextFactory,
        //The core's own answer, before this module decorates it: what the discount is measured against
        private CatalogPriceRuleResolver $catalogPriceRuleResolver,
        private RuleDiscountedPriceResolver $ruleDiscountedPriceResolver,
    ) {
    }

    public function resolve(CartItem $cartItem): ?Discount
    {
        $cart = $cartItem->getCart();
        $productSaleElementsId = $cartItem->getProductSaleElementsId();

        if ($cart === null || $productSaleElementsId === null || (int) $cartItem->getPromo() !== 1) {
            return null;
        }

        $productSaleElementsId = (int) $productSaleElementsId;
        $currency = $cart->getCurrency() ?? Currency::getDefaultCurrency();
        $customer = $cart->getCustomer();

        $discounted = $this->ruleDiscountedPriceResolver->resolve(
            [$productSaleElementsId],
            $currency,
            $this->runtimeContextFactory->forCart($cart),
            $this->catalogPriceRuleResolver->resolve([$productSaleElementsId], $currency, $customer)
        )[$productSaleElementsId] ?? null;

        if ($discounted === null) {
            return null;
        }

        //Same reading as the core: the DECIMAL column comes back as a string
        $customerDiscount = $customer !== null ? (float) $customer->getDiscount() : 0.0;
        $expectedPromoPrice = $discounted->price->untaxedPrice * (1 - max(0.0, $customerDiscount) / 100);

        return abs((float) $cartItem->getPromoPrice() - $expectedPromoPrice) < CartLineDiscountPolicy::PRICE_TOLERANCE
            ? $discounted->discount
            : null;
    }
}
