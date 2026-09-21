<?php

declare(strict_types=1);

namespace QueryBuilder\Service;

use QueryBuilder\Action\ApplyDiscountAction;
use QueryBuilder\Model\QueryBuilderActionQuery;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Whether a rule of this module may discount a product at all: one turned-on
 * ApplyDiscount action on a turned-on rule exists.
 *
 * One indexed existence check, asked of the database once per request. It is what
 * the pricing decorators of the module consult before spending anything on a
 * resolution, so a shop whose rules only recommend products keeps the query plans
 * and the caches the core gives it.
 */
final class DiscountActivityChecker implements ResetInterface
{
    private ?bool $hasActiveDiscountRule = null;

    public function hasActiveDiscountRule(): bool
    {
        return $this->hasActiveDiscountRule ??= QueryBuilderActionQuery::create()
            ->filterByCode(ApplyDiscountAction::CODE)
            ->filterByActivate(1)
            ->useQueryBuilderRuleQuery()
                ->filterByActivate(1)
            ->endUse()
            ->exists();
    }

    public function reset(): void
    {
        $this->hasActiveDiscountRule = null;
    }
}
