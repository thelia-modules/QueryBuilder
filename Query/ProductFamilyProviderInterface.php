<?php

declare(strict_types=1);

namespace QueryBuilder\Query;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Extension point: the project notion of "product family" used by
 * ProductSelector to mix the products of a selection (two products of the
 * same family are not proposed together while another family is available).
 * Without provider, selections follow the ranking only. Implementations are
 * autoconfigured through this tag.
 */
#[AutoconfigureTag(ProductFamilyProviderInterface::TAG)]
interface ProductFamilyProviderInterface
{
    public const TAG = 'querybuilder.product_family_provider';

    /**
     * @param int[] $productIds
     *
     * @return array<int, list<int|string>> family keys by product id; a product
     *                                       may belong to several families, a
     *                                       product without family may be omitted
     */
    public function getFamilyKeysByProductId(array $productIds, RuntimeContext $runtimeContext): array;
}
