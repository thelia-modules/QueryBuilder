<?php

declare(strict_types=1);

namespace QueryBuilder\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\AddressQuery;
use Thelia\Model\Cart;
use Thelia\Model\Country;

/**
 * The country a cart ships to, for the taxes of the cart totals and the
 * "delivery country" dictionary field: the cart's own delivery address first,
 * then the address picked in the legacy order session, then the shop default.
 */
final readonly class DeliveryCountryResolver
{
    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function resolve(?Cart $cart): Country
    {
        $country = $cart?->getCartAddressRelatedByAddressDeliveryId()?->getCountry();

        if ($country !== null) {
            return $country;
        }

        //A request of the stateless API carries no session, and asking it for one throws
        $request = $this->requestStack->getCurrentRequest();
        $session = $request?->hasSession() ? $request->getSession() : null;
        $deliveryAddressId = $session instanceof Session && $session->isStarted()
            ? $session->getOrder()?->getChoosenDeliveryAddress()
            : null;

        if ($deliveryAddressId) {
            $country = AddressQuery::create()->findPk($deliveryAddressId)?->getCountry();

            if ($country !== null) {
                return $country;
            }
        }

        return Country::getDefaultCountry();
    }
}
