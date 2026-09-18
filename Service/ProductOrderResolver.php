<?php

declare(strict_types=1);

namespace QueryBuilder\Service;

use QueryBuilder\Query\ProductOrderProviderInterface;
use QueryBuilder\Query\RuntimeContext;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Collects the ORDER BY expressions of the tagged providers, in service order.
 */
final readonly class ProductOrderResolver
{
    /** @param iterable<ProductOrderProviderInterface> $orderProviders */
    public function __construct(
        #[TaggedIterator(ProductOrderProviderInterface::TAG)]
        private iterable $orderProviders = [],
    ) {
    }

    /** @return string[] trusted "expression ASC|DESC" clauses */
    public function getOrderByExpressions(RuntimeContext $runtimeContext): array
    {
        $expressions = [];

        foreach ($this->orderProviders as $orderProvider) {
            $expressions = [...$expressions, ...$orderProvider->getOrderByExpressions($runtimeContext)];
        }

        return $expressions;
    }
}
