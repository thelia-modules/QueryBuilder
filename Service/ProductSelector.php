<?php

declare(strict_types=1);

namespace QueryBuilder\Service;

use QueryBuilder\Query\ProductFamilyProviderInterface;
use QueryBuilder\Query\RuntimeContext;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Picks the products of a selection (a display block, the discounted products
 * of an action) in one place for every consumer.
 *
 * Ranking, resolved in SQL: the project order providers first, then the
 * promoted products (ids the caller wants ahead of their peers, ex: the
 * products currently discounted), then the rotation of the sticky cycles
 * (never selected first, then oldest cycle end — an expired product goes to
 * the back of the queue), then the product id as deterministic tiebreaker.
 *
 * Family mixing, resolved in PHP on the ranked candidates: a slot takes the
 * best ranked candidate whose families are not already present (families of
 * the products already engaged in the selection included); when every
 * candidate repeats a present family, the best ranked one is taken anyway
 * rather than leaving the slot empty. The candidates are therefore fetched
 * without SQL LIMIT: the mixing needs the whole ranked pool (ids only, a few
 * hundred rows on a catalog of this size), the count applies after it.
 */
final readonly class ProductSelector
{
    /** @param iterable<ProductFamilyProviderInterface> $familyProviders */
    public function __construct(
        private SqlBuilder $sqlBuilder,
        private ProductOrderResolver $productOrderResolver,
        private SuggestionService $suggestionService,
        #[TaggedIterator(ProductFamilyProviderInterface::TAG)]
        private iterable $familyProviders = [],
    ) {
    }

    /**
     * @param int[]    $engagedProductIds  products already part of the selection: excluded from
     *                                     the candidates, their families count as present
     * @param int[]    $promotedProductIds ranked ahead of their peers
     * @param string[] $extraWhere         trusted SQL clauses (never user input)
     * @param int|null $rotationActionId   sticky action whose cycles order the ex aequo
     *                                     candidates (needs an identified customer)
     *
     * @return int[] at most $count product ids, in selection order
     *
     * @throws \InvalidArgumentException when the condition tree cannot be compiled for this context
     */
    public function select(
        ?array $conditionTree,
        RuntimeContext $runtimeContext,
        int $count,
        array $engagedProductIds = [],
        array $promotedProductIds = [],
        array $extraWhere = [],
        ?int $rotationActionId = null,
    ): array {
        if ($count <= 0) {
            return [];
        }

        $candidateIds = array_values(array_diff(
            $this->sqlBuilder->getProductIds(
                $conditionTree,
                $runtimeContext,
                null,
                $extraWhere,
                $this->getOrderBy($runtimeContext, $promotedProductIds, $rotationActionId)
            ),
            $engagedProductIds
        ));

        return $this->mixFamilies($candidateIds, $engagedProductIds, $count, $runtimeContext);
    }

    /**
     * Re-orders an assembled selection by the ranking alone (display purpose:
     * no rotation, no family mixing). An id the query would not return (its
     * displayability was checked by the caller) is kept at the end rather than
     * silently dropped.
     *
     * @param int[] $productIds
     * @param int[] $promotedProductIds
     *
     * @return int[]
     */
    public function rank(array $productIds, RuntimeContext $runtimeContext, array $promotedProductIds = []): array
    {
        if (\count($productIds) < 2) {
            return $productIds;
        }

        $rankedIds = $this->sqlBuilder->getProductIds(
            null,
            $runtimeContext,
            null,
            [$this->inClause($productIds)],
            $this->getOrderBy($runtimeContext, $promotedProductIds)
        );

        return array_merge(
            array_values(array_intersect($rankedIds, $productIds)),
            array_values(array_diff($productIds, $rankedIds))
        );
    }

    /**
     * @param int[] $promotedProductIds
     *
     * @return string[] trusted "expression ASC|DESC" clauses
     */
    public function getOrderBy(RuntimeContext $runtimeContext, array $promotedProductIds = [], ?int $rotationActionId = null): array
    {
        $orderBy = $this->productOrderResolver->getOrderByExpressions($runtimeContext);

        if ($promotedProductIds !== []) {
            $orderBy[] = sprintf('IF(%s, 0, 1) ASC', $this->inClause($promotedProductIds));
        }

        if ($rotationActionId !== null && $runtimeContext->customerId !== null) {
            $orderBy[] = $this->suggestionService->getRotationOrderByExpression($runtimeContext->customerId, $rotationActionId);
        }

        $orderBy[] = '`product`.`id` ASC';

        return $orderBy;
    }

    /**
     * @param int[] $rankedCandidateIds
     * @param int[] $engagedProductIds
     *
     * @return int[]
     */
    private function mixFamilies(array $rankedCandidateIds, array $engagedProductIds, int $count, RuntimeContext $runtimeContext): array
    {
        if ($rankedCandidateIds === []) {
            return [];
        }

        $familyKeysByProductId = $this->resolveFamilyKeys([...$rankedCandidateIds, ...$engagedProductIds], $runtimeContext);

        if ($familyKeysByProductId === []) {
            return \array_slice($rankedCandidateIds, 0, $count);
        }

        $presentFamilyKeys = [];

        foreach ($engagedProductIds as $productId) {
            $presentFamilyKeys += array_fill_keys($familyKeysByProductId[$productId] ?? [], true);
        }

        $selectedIds = [];
        $remainingIds = $rankedCandidateIds;

        while (\count($selectedIds) < $count && $remainingIds !== []) {
            $index = $this->findFirstOutsidePresentFamilies($remainingIds, $familyKeysByProductId, $presentFamilyKeys) ?? 0;
            $productId = $remainingIds[$index];

            unset($remainingIds[$index]);
            $remainingIds = array_values($remainingIds);

            $selectedIds[] = $productId;
            $presentFamilyKeys += array_fill_keys($familyKeysByProductId[$productId] ?? [], true);
        }

        return $selectedIds;
    }

    /**
     * @param int[]                     $rankedIds
     * @param array<int, list<string>>  $familyKeysByProductId
     * @param array<string, true>       $presentFamilyKeys
     */
    private function findFirstOutsidePresentFamilies(array $rankedIds, array $familyKeysByProductId, array $presentFamilyKeys): ?int
    {
        foreach ($rankedIds as $index => $productId) {
            foreach ($familyKeysByProductId[$productId] ?? [] as $familyKey) {
                if (isset($presentFamilyKeys[$familyKey])) {
                    continue 2;
                }
            }

            return $index;
        }

        return null;
    }

    /**
     * @param int[] $productIds
     *
     * @return array<int, list<string>> keys prefixed by provider class so two providers never collide
     */
    private function resolveFamilyKeys(array $productIds, RuntimeContext $runtimeContext): array
    {
        $productIds = array_values(array_unique(array_map(intval(...), $productIds)));

        if ($productIds === []) {
            return [];
        }

        $familyKeysByProductId = [];

        foreach ($this->familyProviders as $familyProvider) {
            foreach ($familyProvider->getFamilyKeysByProductId($productIds, $runtimeContext) as $productId => $familyKeys) {
                foreach ($familyKeys as $familyKey) {
                    $familyKeysByProductId[(int) $productId][] = $familyProvider::class . ':' . $familyKey;
                }
            }
        }

        return $familyKeysByProductId;
    }

    /** @param int[] $productIds */
    private function inClause(array $productIds): string
    {
        return sprintf('`product`.`id` IN (%s)', implode(', ', array_map(intval(...), $productIds)));
    }
}
