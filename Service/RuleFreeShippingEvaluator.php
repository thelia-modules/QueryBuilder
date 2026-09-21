<?php

declare(strict_types=1);

namespace QueryBuilder\Service;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Promotion\Coupon\Service\CouponFreeShippingEvaluator;
use Thelia\Domain\Promotion\Coupon\Service\CouponManager;
use Thelia\Log\Tlog;

/**
 * The postage estimator of the core (delivery estimate on the cart, virtual
 * deliveries) asks the coupon evaluator whether the shipping is free: a rule
 * offering the shipping answers yes there too, so the estimate shown to the
 * customer matches the postage the CART_SET_POSTAGE listener will clear.
 */
#[AsDecorator(CouponFreeShippingEvaluator::class)]
final class RuleFreeShippingEvaluator extends CouponFreeShippingEvaluator
{
    public function __construct(
        #[AutowireDecorated]
        private readonly CouponFreeShippingEvaluator $inner,
        CouponManager $couponManager,
        private readonly RequestStack $requestStack,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly CartDiscountCalculator $cartDiscountCalculator,
    ) {
        parent::__construct($couponManager);
    }

    public function isCouponRemovingPostage(int $countryId, int $deliveryModuleId): bool
    {
        if ($this->inner->isCouponRemovingPostage($countryId, $deliveryModuleId)) {
            return true;
        }

        try {
            $request = $this->requestStack->getCurrentRequest();
            $session = $request?->hasSession() ? $request->getSession() : null;

            if (!$session instanceof Session || !$session->isStarted()) {
                return false;
            }

            return (bool) $this->cartDiscountCalculator->resolve($session->getSessionCart($this->eventDispatcher))?->freeShipping;
        } catch (\Throwable $throwable) {
            Tlog::getInstance()->addError('QueryBuilder: free shipping evaluation failed: ' . $throwable->getMessage());

            return false;
        }
    }
}
