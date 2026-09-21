<?php

declare(strict_types=1);

namespace QueryBuilder\Api\Resource\Addon;

use ApiPlatform\Metadata\Operation;
use Propel\Runtime\ActiveQuery\ModelCriteria;
use Propel\Runtime\ActiveRecord\ActiveRecordInterface;
use Propel\Runtime\Map\TableMap;
use QueryBuilder\Service\AddonRuntime;
use Symfony\Component\Serializer\Attribute\Groups;
use Thelia\Api\Resource\Product;
use Thelia\Api\Resource\PropelResourceInterface;
use Thelia\Api\Resource\ResourceAddonInterface;
use Thelia\Api\Resource\ResourceAddonTrait;
use Thelia\Model\Product as ProductModel;

/**
 * Exposes on the front product resource the discount an ApplyDiscount rule
 * grants the current visitor on this product (rate, label, stackable flag), for
 * the price badges of a decoupled front. The discounted price itself is served
 * by the core, on the sale elements of the resource, through the catalog price
 * resolver this module decorates (DiscountCatalogPriceResolver).
 */
final class QueryBuilderProductOffer implements ResourceAddonInterface
{
    use ResourceAddonTrait;

    #[Groups([Product::GROUP_FRONT_READ, Product::GROUP_FRONT_READ_SINGLE])]
    public ?float $rate = null;

    #[Groups([Product::GROUP_FRONT_READ, Product::GROUP_FRONT_READ_SINGLE])]
    public ?string $label = null;

    #[Groups([Product::GROUP_FRONT_READ, Product::GROUP_FRONT_READ_SINGLE])]
    public bool $cumulative = false;

    public static function getResourceParent(): string
    {
        return Product::class;
    }

    public static function getPropelRelatedTableMap(): ?TableMap
    {
        return null;
    }

    public static function extendQuery(ModelCriteria $query, ?Operation $operation = null, array $context = []): void
    {
        //No table behind this addon: the offer is resolved on demand in buildFromModel()
    }

    public function buildFromModel(ActiveRecordInterface $activeRecord, PropelResourceInterface $abstractPropelResource): ResourceAddonInterface
    {
        $runtime = AddonRuntime::current();

        if (!$activeRecord instanceof ProductModel || $activeRecord->getId() === null || $runtime === null) {
            return $this;
        }

        $discount = $runtime->discountResolutionService->getDiscount(
            $runtime->runtimeContextFactory->fromSession(),
            (int) $activeRecord->getId()
        );

        if ($discount === null) {
            return $this;
        }

        $this->rate = $discount->rate;
        $this->label = $discount->label;
        $this->cumulative = $discount->cumulative;

        return $this;
    }

    public function buildFromArray(array $data, PropelResourceInterface $abstractPropelResource): ResourceAddonInterface
    {
        return $this;
    }

    public function doSave(ActiveRecordInterface $activeRecord, PropelResourceInterface $abstractPropelResource): void
    {
    }

    public function doDelete(ActiveRecordInterface $activeRecord, PropelResourceInterface $abstractPropelResource): void
    {
    }
}
