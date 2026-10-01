<?php

declare(strict_types=1);

namespace QueryBuilder\Hook;

use QueryBuilder\Service\HookResultPresenter;
use QueryBuilder\Service\RuntimeContextFactory;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Template\Assets\AssetResolverInterface;
use TheliaSmarty\Template\SmartyParser;

class FrontHook extends BaseHook
{
    public function __construct(
        SmartyParser $smartyParser,
        AssetResolverInterface $assetResolver,
        EventDispatcherInterface $eventDispatcher,
        private readonly HookResultPresenter $hookResultPresenter,
        private readonly RuntimeContextFactory $runtimeContextFactory,
    ) {
        parent::__construct($smartyParser, $assetResolver, $eventDispatcher);
    }

    public static function getSubscribedHooks(): array
    {
        return [
            'product.top' => [
                'type' => 'front',
                'method' => 'onProductHook',
            ],
            'product.bottom' => [
                'type' => 'front',
                'method' => 'onProductHook',
            ],
        ];
    }

    public function onProductHook(HookRenderEvent $event): void
    {
        $runtimeContext = $this->runtimeContextFactory->fromSession(
            productId: (int) $event->getArgument('product') ?: null
        );

        //Same aggregation as the JSON endpoint and the Smarty plugin: the
        //presenter dedupes and caps the shared hook slot across rules.
        //Offers are skipped: this path only renders product ids
        $result = $this->hookResultPresenter->present($event->getCode(), $runtimeContext, withOffers: false);

        foreach ($result['actions'] as $action) {
            if ($action['product_ids'] === []) {
                continue;
            }

            $event->add($this->render('query-builder/product-list.html', [
                'qb_rule_name' => $action['rule'],
                'qb_action_name' => $action['action'],
                'qb_product_ids' => implode(',', $action['product_ids']),
            ]));
        }
    }
}
