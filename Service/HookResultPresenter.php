<?php

declare(strict_types=1);

namespace QueryBuilder\Service;

use QueryBuilder\Action\ActionInterface;
use QueryBuilder\Action\DisplayProductsListAction;
use QueryBuilder\Query\RuntimeContext;

/**
 * Executes the display rules of a hook and shapes the result in the structure
 * shared by the API resource (GET /api/front/query_builder/products/{hookCode}), the
 * query_builder_products() Twig function and the theme hooks. The offers come from the stateless
 * ApplyDiscount rules (DiscountResolutionService), resolved on the returned
 * products so the front can render its badges.
 */
final readonly class HookResultPresenter
{
    public function __construct(
        private RuleEngine $ruleEngine,
        private DiscountResolutionService $discountResolutionService,
    ) {
    }

    /**
     * @return array{
     *     hook: string,
     *     product_ids: int[],
     *     offers: array<int, array{rate: float, label: ?string, cumulative: bool}>,
     *     actions: array<int, array{rule: ?string, action: ?string, product_ids: int[], offers: array}>
     * }
     */
    public function present(string $hookCode, RuntimeContext $runtimeContext, bool $withOffers = true): array
    {
        $actions = [];
        $displayLimits = [];

        foreach ($this->ruleEngine->executeHook($hookCode, $runtimeContext) as $executedAction) {
            if ($executedAction->type !== ActionInterface::TYPE_DISPLAY) {
                continue;
            }

            $displayLimits[] = (int) ($executedAction->action->getParametersArray()['limit']
                ?? DisplayProductsListAction::DEFAULT_LIMIT);

            $actions[] = [
                'rule' => $executedAction->rule->getName(),
                'action' => $executedAction->action->getName(),
                'product_ids' => $executedAction->result->productIds,
            ];
        }

        //The hook slot is shared: several rules on the same hook fill a single
        //budget (the highest action limit) in rule order, instead of stacking
        //their own limits. Presentation only — sticky cycles already
        //recorded by the actions are never affected.
        //Floor at 1 like SqlBuilder::compile() does on the per-action LIMIT,
        //so a hand-written "limit: 0" never blanks the whole hook slot
        $hookLimit = $displayLimits === [] ? 0 : max(1, max($displayLimits));
        $productIds = [];

        foreach ($actions as $index => $action) {
            $keptIds = \array_slice(
                array_values(array_diff($action['product_ids'], $productIds)),
                0,
                max(0, $hookLimit - \count($productIds))
            );

            $actions[$index]['product_ids'] = $keptIds;
            $productIds = array_merge($productIds, $keptIds);
        }

        //The native hook path (FrontHook) only renders product ids: skip the
        //discount resolution instead of throwing its result away
        if (!$withOffers) {
            foreach ($actions as $index => $action) {
                $actions[$index]['offers'] = [];
            }

            return [
                'hook' => $hookCode,
                'product_ids' => $productIds,
                'offers' => [],
                'actions' => $actions,
            ];
        }

        //The current product carries its own offer (badge « Pour vous aujourd'hui »)
        //even though it never appears in its own recommendations
        $offerProductIds = $runtimeContext->productId !== null && $runtimeContext->productId > 0
            ? array_values(array_unique([...$productIds, $runtimeContext->productId]))
            : $productIds;

        $offers = [];
        foreach ($this->discountResolutionService->getDiscounts($runtimeContext, $offerProductIds) as $productId => $discount) {
            $offers[$productId] = [
                'rate' => $discount->rate,
                'label' => $discount->label,
                'cumulative' => $discount->cumulative,
            ];
        }

        foreach ($actions as $index => $action) {
            $actions[$index]['offers'] = array_intersect_key($offers, array_flip($action['product_ids']));
        }

        return [
            'hook' => $hookCode,
            'product_ids' => $productIds,
            'offers' => $offers,
            'actions' => $actions,
        ];
    }
}
