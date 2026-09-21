<?php

declare(strict_types=1);

namespace QueryBuilder\EventListener;

use QueryBuilder\Service\CartDiscountCalculator;
use QueryBuilder\Service\CartDiscountLedger;
use QueryBuilder\Service\DeliveryModuleOptionPostageClearer;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\Event;
use Thelia\Api\Bridge\Propel\Event\DeliveryModuleOptionEvent;
use Thelia\Core\Event\Cart\CartCheckoutEvent;
use Thelia\Core\Event\Cart\CartEvent;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Log\Tlog;
use Thelia\Model\Cart;
use Thelia\Model\Event\AddressEvent;

/**
 * Applies the ApplyCartDiscount rule discounts on the core Thelia discount
 * channel (cart.discount / order.discount) — deliberately WITHOUT any coupon
 * machinery (no CouponManager, no coupon facade, no coupon session): coupons
 * remain a separate channel and both amounts simply add up in the column.
 *
 * Anti-accumulation: the listener adds its amount on top of the current
 * cart.discount (owned by whoever wrote it before us — the core resets it to
 * an absolute value at priority 10 on these same events, we run at 1; the
 * coupon consume/clear handlers do the same absolute write at priority 128
 * on COUPON_CONSUME / COUPON_CLEAR_ALL, hence our subscription there too). But
 * cart events may NEST (a listener re-dispatching CART_ADDITEM while handling
 * one), so the current value may still CONTAIN our own previous addition:
 * CartDiscountLedger tracks what this request wrote and never adds it twice.
 *
 * Free shipping follows the coupon path of the core: CART_SET_POSTAGE at
 * priority 133, right before the core coupon check (132) and the core postage
 * setter (128), clearing the cart postage and stopping the propagation exactly
 * like Coupon::forceFreePostage. The legacy ORDER_SET_POSTAGE point is kept for
 * a Smarty front still going through the order session.
 *
 * The delivery options listed on the delivery step (MODULE_DELIVERY_GET_OPTIONS,
 * one event per module, the module answers at 128/129) carry the price each
 * module computed: once every module has answered, a rule offering the shipping
 * zeroes them so the card and the summary announce the same free delivery.
 */
final readonly class CartDiscountListener implements EventSubscriberInterface
{
    public function __construct(
        private RequestStack $requestStack,
        private EventDispatcherInterface $eventDispatcher,
        private CartDiscountCalculator $cartDiscountCalculator,
        private CartDiscountLedger $cartDiscountLedger,
        private DeliveryModuleOptionPostageClearer $deliveryModuleOptionPostageClearer,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::CART_ADDITEM => ['updateCartDiscount', 1],
            TheliaEvents::CART_UPDATEITEM => ['updateCartDiscount', 1],
            TheliaEvents::CART_DELETEITEM => ['updateCartDiscount', 1],
            TheliaEvents::CUSTOMER_LOGIN => ['updateCartDiscount', 1],
            TheliaEvents::COUPON_CONSUME => ['updateCartDiscount', 1],
            TheliaEvents::COUPON_CLEAR_ALL => ['updateCartDiscount', 1],
            AddressEvent::POST_UPDATE => ['updateCartDiscount', 1],
            TheliaEvents::CART_SET_POSTAGE => ['removeCartPostageWhenFreeShipping', 133],
            TheliaEvents::ORDER_SET_POSTAGE => ['removeOrderPostageWhenFreeShipping', 133],
            TheliaEvents::MODULE_DELIVERY_GET_OPTIONS => ['removeDeliveryOptionsPostageWhenFreeShipping', -128],
        ];
    }

    public function updateCartDiscount(Event $event): void
    {
        try {
            $session = $this->getStartedSession();

            if ($session === null) {
                return;
            }

            //Sur les événements panier le cart est porté par l'événement ; sinon
            //(login, adresse) le core vient de résoudre le panier de session à
            //prio 10, le relire ici est sans risque de boucle de restauration
            //Thelia 3: the session needs the dispatcher to restore or create the cart
            $cart = $event instanceof CartEvent
                ? $event->getCart()
                : $session->getSessionCart($this->eventDispatcher);

            if (!$cart instanceof Cart) {
                return;
            }

            $ruleDiscountAmount = $this->cartDiscountCalculator->resolve($cart)?->amount;

            if ($ruleDiscountAmount === null || $ruleDiscountAmount <= 0) {
                return;
            }

            $totalDiscount = $this->cartDiscountLedger->nextTotal(
                (int) $cart->getId(),
                (float) $cart->getDiscount(),
                $ruleDiscountAmount
            );

            //Propel decimal columns are typed string under Thelia 3
            $cart->setDiscount((string) $totalDiscount)->save();
            $session->getOrder()?->setDiscount((string) $totalDiscount);
        } catch (\Throwable $throwable) {
            //Ne jamais bloquer le panier pour une remise
            Tlog::getInstance()->addError('QueryBuilder: cart discount not applied: ' . $throwable->getMessage());
        }
    }

    /** Thelia 3 checkout: the postage is computed on the cart (Flexy, API). */
    public function removeCartPostageWhenFreeShipping(CartCheckoutEvent $event): void
    {
        try {
            $cart = $event->getCart();

            if (!$this->cartDiscountCalculator->resolve($cart)?->freeShipping) {
                return;
            }

            //Same write as Coupon::forceFreePostage: a cleared postage is a free delivery
            $cart
                ->setPostage(null)
                ->setPostageTax(null)
                ->setPostageTaxRuleTitle(null)
                ->save();
            $event->stopPropagation();
        } catch (\Throwable $throwable) {
            //Ne jamais bloquer la commande pour des frais de port offerts
            Tlog::getInstance()->addError('QueryBuilder: free shipping rule not applied on the cart: ' . $throwable->getMessage());
        }
    }

    /** Delivery step: the options a module lists are priced by the module, whatever the cart postage. */
    public function removeDeliveryOptionsPostageWhenFreeShipping(DeliveryModuleOptionEvent $event): void
    {
        try {
            $cart = $event->getCart();

            if (!$cart instanceof Cart || !$this->cartDiscountCalculator->resolve($cart)?->freeShipping) {
                return;
            }

            $this->deliveryModuleOptionPostageClearer->clear($event->getDeliveryModuleOptions());
        } catch (\Throwable $throwable) {
            Tlog::getInstance()->addError('QueryBuilder: free shipping rule not applied on the delivery options: ' . $throwable->getMessage());
        }
    }

    /** Legacy checkout: the postage is set on the order carried by the session. */
    public function removeOrderPostageWhenFreeShipping(OrderEvent $event): void
    {
        try {
            $session = $this->getStartedSession();
            $cart = $session?->getSessionCart($this->eventDispatcher);

            if (!$cart instanceof Cart || !$this->cartDiscountCalculator->resolve($cart)?->freeShipping) {
                return;
            }

            $order = $event->getOrder();
            $order->setPostage('0');
            $event->setOrder($order);
            $event->stopPropagation();
        } catch (\Throwable $throwable) {
            Tlog::getInstance()->addError('QueryBuilder: free shipping rule not applied on the order: ' . $throwable->getMessage());
        }
    }

    private function getStartedSession(): ?Session
    {
        $request = $this->requestStack->getCurrentRequest();
        $session = $request?->hasSession() ? $request->getSession() : null;

        return $session instanceof Session && $session->isStarted() ? $session : null;
    }
}
