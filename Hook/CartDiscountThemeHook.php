<?php

declare(strict_types=1);

namespace QueryBuilder\Hook;

use QueryBuilder\QueryBuilder;
use QueryBuilder\Service\CartDiscountCalculator;
use QueryBuilder\Service\CartLineDiscountResolver;
use QueryBuilder\Service\FrontTemplateRenderer;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Hook\Theme\ThemeHookInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Core\Translation\Translator;
use Thelia\Log\Tlog;
use Thelia\Model\Cart;

/**
 * Shows the rule discounts of the cart on the checkout pages of the theme: the
 * cart discount granted by a rule (label, amount, free shipping), whose amount
 * the core summary carries in its discount total, and the product discounts
 * charged on the cart lines (label, products), whose prices the lines show.
 *
 * Flexy calls checkout.top on every checkout page, the cart page included, and
 * cart.bottom on the cart page: the fragment renders once per request.
 */
final class CartDiscountThemeHook implements ThemeHookInterface
{
    private const HOOKS = ['checkout.top', 'cart.bottom'];

    private bool $rendered = false;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly CartDiscountCalculator $cartDiscountCalculator,
        private readonly CartLineDiscountResolver $cartLineDiscountResolver,
        private readonly FrontTemplateRenderer $frontTemplateRenderer,
    ) {
    }

    public function supports(string $hookName): bool
    {
        return \in_array($hookName, self::HOOKS, true);
    }

    public function render(string $hookName, array $parameters): string
    {
        if ($this->rendered) {
            return '';
        }

        $request = $this->requestStack->getCurrentRequest();
        $session = $request?->hasSession() ? $request->getSession() : null;

        if (!$session instanceof Session || !$session->isStarted()) {
            return '';
        }

        $cart = $session->getSessionCart($this->eventDispatcher);
        $locale = $session->getLang()?->getLocale() ?? 'en_US';
        $applied = $this->cartDiscountCalculator->resolve($cart);
        $hasCartDiscount = $applied !== null && (($applied->amount !== null && $applied->amount > 0) || $applied->freeShipping);
        $lineDiscounts = $this->lineDiscounts($cart, $locale);

        if (!$hasCartDiscount && $lineDiscounts === []) {
            return '';
        }

        $this->rendered = true;

        return $this->frontTemplateRenderer->render('cart-discount.html.twig', [
            'hook' => $hookName,
            'label' => $hasCartDiscount ? $applied->label : null,
            'rate' => $hasCartDiscount ? $applied->rate : null,
            'amount' => $hasCartDiscount ? $applied->amount : null,
            'free_shipping' => $hasCartDiscount && $applied->freeShipping,
            'currency_code' => $cart->getCurrency()?->getCode(),
            'locale' => $locale,
            'line_discounts' => $lineDiscounts,
            'texts' => [
                'discount' => $this->trans('Discount'),
                'rate_off' => $this->trans('%rate%% off the products total', ['%rate%' => (string) ($applied?->rate ?? 0)]),
                'free_shipping' => $this->trans('Free shipping'),
            ],
        ]);
    }

    /**
     * The labelled product discounts charged on the cart lines, one entry per
     * label with the titles of the products it applies to.
     *
     * @return array<int, array{label: string, rate: float, products: string[]}>
     */
    private function lineDiscounts(Cart $cart, string $locale): array
    {
        $byLabel = [];

        foreach ($cart->getCartItems() as $cartItem) {
            try {
                $discount = $this->cartLineDiscountResolver->resolve($cartItem);

                if ($discount === null || $discount->label === null) {
                    continue;
                }

                $byLabel[$discount->label] ??= ['label' => $discount->label, 'rate' => $discount->rate, 'products' => []];
                $title = (string) $cartItem->getProduct()->setLocale($locale)->getTitle();

                if ($title !== '' && !\in_array($title, $byLabel[$discount->label]['products'], true)) {
                    $byLabel[$discount->label]['products'][] = $title;
                }
            } catch (\Throwable $throwable) {
                //Never break the cart for a label
                Tlog::getInstance()->addError('QueryBuilder: discount label not shown for a cart line: ' . $throwable->getMessage());
            }
        }

        return array_values($byLabel);
    }

    private function trans(string $id, array $parameters = []): string
    {
        return Translator::getInstance()->trans($id, $parameters, QueryBuilder::DOMAIN_NAME);
    }
}
