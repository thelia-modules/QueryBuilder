<?php

declare(strict_types=1);

namespace QueryBuilder\Query;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Extension point: the project notion of "group" of a candidate, used by
 * ProductSelector to bound the family mixing. When a slot looks for a family
 * not yet present, only the candidates of the same group as the best ranked
 * remaining candidate are considered: a group is never skipped for the sake of
 * family diversity while it still holds candidates. Without provider every
 * candidate shares the same group and the mixing is unbounded.
 *
 * The meaning of a group belongs to the project (ex: products of the customer
 * trade selection versus the rest of the catalog); the selector only compares
 * keys for equality. A group must coarsen the dominant ranking criterion of
 * the ProductOrderProviderInterface implementations (a better ranked candidate
 * never belongs to a "later" group), otherwise the boundary cannot guarantee
 * that a group is exhausted before the next one is served.
 *
 * With several providers the keys are combined into one composite key and
 * compared for equality: two candidates share a group only when every provider
 * agrees — unlike the families, whose keys are unioned. Implementations are
 * autoconfigured through this tag.
 */
#[AutoconfigureTag(ProductGroupProviderInterface::TAG)]
interface ProductGroupProviderInterface
{
    public const TAG = 'querybuilder.product_group_provider';

    /**
     * @param int[] $productIds
     *
     * @return array<int, string> group key by product id; a product without
     *                            key falls into the default group
     */
    public function getGroupKeyByProductId(array $productIds, RuntimeContext $runtimeContext): array;
}
