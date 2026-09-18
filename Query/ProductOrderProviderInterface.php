<?php

declare(strict_types=1);

namespace QueryBuilder\Query;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Extension point: the project ranking of the products picked by the
 * selections (display blocks, discounted products). The expressions come
 * first in the ORDER BY built by ProductSelector, ahead of the module's own
 * criteria (promoted products, cycle rotation, product id). Never applied by
 * SqlBuilder::compile() on its own nor by a QueryScope: rule eligibility and
 * plain product queries stay unordered. Implementations are autoconfigured
 * through this tag.
 */
#[AutoconfigureTag(ProductOrderProviderInterface::TAG)]
interface ProductOrderProviderInterface
{
    public const TAG = 'querybuilder.product_order_provider';

    /**
     * @return string[] trusted "expression ASC|DESC" clauses (never user input), in
     *                  priority order, may reference runtime placeholders. An expression
     *                  is either a correlated scalar subquery on `product`.`id` or a
     *                  `product`.<column> reference: the compiled query groups by
     *                  product.id, any other joined column is not functionally dependent
     *                  on that key (rejected by MySQL 8 ONLY_FULL_GROUP_BY, silently
     *                  non-deterministic on the MariaDB development database)
     */
    public function getOrderByExpressions(RuntimeContext $runtimeContext): array;
}
