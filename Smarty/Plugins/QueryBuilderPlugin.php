<?php

declare(strict_types=1);

namespace QueryBuilder\Smarty\Plugins;

use QueryBuilder\Service\HookResultPresenter;
use QueryBuilder\Service\RuntimeContextFactory;
use Thelia\Log\Tlog;
use TheliaSmarty\Template\AbstractSmartyPlugin;
use TheliaSmarty\Template\SmartyPluginDescriptor;

/**
 * Smarty counterpart of the /query_builder/products/{hookCode} JSON endpoint.
 *
 *   {queryBuilderProducts hook="cart.recommendations"}
 *
 * assigns to the template (same structure as the JSON payload):
 *   $qb_hook          the hook code
 *   $qb_product_ids   unique product ids selected by the display actions
 *   $qb_offers        discount offers keyed by product id (rate, label, cumulative)
 *   $qb_actions       one entry per executed display action (rule, action, description, product_ids, offers)
 *
 * Optional parameters forwarded to the runtime context: product_id, order_id,
 * category_id, brand_id.
 */
class QueryBuilderPlugin extends AbstractSmartyPlugin
{
    public function __construct(
        private readonly HookResultPresenter $hookResultPresenter,
        private readonly RuntimeContextFactory $runtimeContextFactory,
    ) {
    }

    public function getPluginDescriptors()
    {
        return [
            new SmartyPluginDescriptor('function', 'queryBuilderProducts', $this, 'queryBuilderProducts'),
        ];
    }

    public function queryBuilderProducts($params, &$smarty): void
    {
        $hookCode = (string) ($params['hook'] ?? '');
        $result = ['hook' => $hookCode, 'product_ids' => [], 'offers' => [], 'actions' => []];

        try {
            if ($hookCode === '') {
                throw new \InvalidArgumentException('queryBuilderProducts: the "hook" parameter is required.');
            }

            $runtimeContext = $this->runtimeContextFactory->fromSession(
                productId: isset($params['product_id']) ? (int) $params['product_id'] : null,
                orderId: isset($params['order_id']) ? (int) $params['order_id'] : null,
                categoryId: isset($params['category_id']) ? (int) $params['category_id'] : null,
                brandId: isset($params['brand_id']) ? (int) $params['brand_id'] : null,
            );

            $result = $this->hookResultPresenter->present($hookCode, $runtimeContext);
        } catch (\Throwable $throwable) {
            //Un bloc de suggestions ne doit jamais casser le rendu de la page
            Tlog::getInstance()->error('QueryBuilder: queryBuilderProducts failed', [
                'hook' => $hookCode,
                'error' => $throwable->getMessage(),
            ]);
        }

        $smarty->assign('qb_hook', $result['hook']);
        $smarty->assign('qb_product_ids', $result['product_ids']);
        $smarty->assign('qb_offers', $result['offers']);
        $smarty->assign('qb_actions', $result['actions']);
    }
}
