<?php

declare(strict_types=1);

namespace QueryBuilder\Controller\Back;

use Propel\Runtime\Propel;
use QueryBuilder\Action\ActionRegistry;
use QueryBuilder\Action\ApplyCartDiscountAction;
use QueryBuilder\Action\ApplyDiscountAction;
use QueryBuilder\Enum\Context;
use QueryBuilder\Event\QueryBuilderRulesChangedEvent;
use QueryBuilder\Form\ActionForm;
use QueryBuilder\Model\Map\QueryBuilderActionTableMap;
use QueryBuilder\Model\QueryBuilderAction;
use QueryBuilder\Model\QueryBuilderActionQuery;
use QueryBuilder\Model\QueryBuilderRule;
use QueryBuilder\Model\QueryBuilderRuleQuery;
use QueryBuilder\QueryBuilder;
use QueryBuilder\Service\ActionCycleState;
use QueryBuilder\Service\FieldsBuilder;
use QueryBuilder\Service\SqlBuilder;
use QueryBuilder\Service\SuggestionService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Annotation\Route;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Template\ParserContext;
use Thelia\Core\Translation\Translator;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Log\Tlog;
use Thelia\Tools\TokenProvider;
use Thelia\Tools\URL;

#[Route('/admin/query_builder/rule/{ruleId}/action', name: 'admin_query_builder_action_', requirements: ['ruleId' => '\d+'])]
class ActionController extends BaseAdminController
{
    #[Route('/create', name: 'create', methods: 'POST')]
    public function createAction(
        ParserContext $parserContext,
        int $ruleId,
        ActionRegistry $actionRegistry,
        EventDispatcherInterface $eventDispatcher,
    ): RedirectResponse|Response {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, QueryBuilder::getModuleCode(), AccessManager::CREATE)) {
            return $response;
        }

        $form = $this->createForm(ActionForm::getName());

        try {
            $rule = $this->requireRule($ruleId);
            $data = $this->validateForm($form)->getData();
            $handler = $this->requireHandler($data['code'], $rule, $actionRegistry);

            $action = (new QueryBuilderAction())
                ->setRuleId($rule->getId())
                ->setName($data['name'])
                ->setCode($data['code'])
                ->setType($handler::getType())
                ->setActivate(0);
            $action->save();
            $eventDispatcher->dispatch(new QueryBuilderRulesChangedEvent());

            //URL absolue : les routes #[Route] du module ne sont pas dans "router.admin",
            //seul router consulté par generateRedirectFromRoute (RouteNotFoundException sinon)
            return $this->generateRedirect(URL::getInstance()->absoluteUrl(
                sprintf('/admin/query_builder/rule/%d/action/%d', $rule->getId(), $action->getId())
            ));
        } catch (FormValidationException $exception) {
            $errorMessage = $this->createStandardFormValidationErrorMessage($exception);
        } catch (\InvalidArgumentException $exception) {
            $errorMessage = $exception->getMessage();
        } catch (\Exception $exception) {
            Tlog::getInstance()->addError(sprintf('QueryBuilder action create error: %s', $exception->getMessage()));
            $errorMessage = $this->unexpectedErrorMessage();
        }

        $form->setErrorMessage($errorMessage);
        $parserContext->addForm($form)->setGeneralError($errorMessage);

        return $this->generateErrorRedirect($form);
    }

    #[Route('/{actionId}', name: 'edit', requirements: ['actionId' => '\d+'], methods: 'GET')]
    public function editAction(
        int $ruleId,
        int $actionId,
        ActionRegistry $actionRegistry,
        FieldsBuilder $fieldsBuilder,
    ): Response {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, QueryBuilder::getModuleCode(), AccessManager::VIEW)) {
            return $response;
        }

        $rule = QueryBuilderRuleQuery::create()->findOneById($ruleId);
        $action = QueryBuilderActionQuery::create()->filterByRuleId($ruleId)->findOneById($actionId);

        if ($rule === null || $action === null) {
            return $this->generateRedirect(URL::getInstance()->absoluteUrl('/admin/query_builder'));
        }

        $context = Context::tryFrom($rule->getContext() ?? '') ?? Context::GLOBAL_SCOPE;
        $parameters = $action->getParametersArray();

        $availableActions = [];
        foreach ($actionRegistry->forContext($context) as $code => $actionHandler) {
            $availableActions[] = [
                'code' => $code,
                'label' => $actionHandler::getLabel(),
                'type' => $actionHandler::getType(),
            ];
        }

        return $this->render('query-builder/action-edit', [
            'admin_current_location' => RuleController::ADMIN_LOCATION,
            'rule' => [
                'id' => $rule->getId(),
                'name' => $rule->getName(),
                'context' => $context->value,
                'context_label' => $context->label(),
            ],
            'action' => [
                'id' => $action->getId(),
                'name' => $action->getName(),
                'description' => $action->getDescription(),
                'code' => $action->getCode(),
                'type' => $action->getType(),
                'condition_tree' => $action->getConditionTree(),
                'activate' => (bool) $action->getActivate(),
                'param_limit' => $parameters['limit'] ?? '',
                'param_persist_days' => $parameters['persist_days'] ?? '',
                'param_discount_rate' => $parameters['discount_rate'] ?? '',
                'param_discount_label' => $parameters['discount_label'] ?? '',
                'param_discount_cumulative' => (bool) ($parameters['discount_cumulative'] ?? false),
                'param_cart_discount_rate' => $parameters['cart_discount_rate'] ?? '',
                'param_cart_discount_free_shipping' => (bool) ($parameters['cart_discount_free_shipping'] ?? false),
            ],
            'available_actions' => $availableActions,
            'fields' => json_encode($fieldsBuilder->buildForContext($context), \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
        ]);
    }

    #[Route('/{actionId}/save', name: 'save', requirements: ['actionId' => '\d+'], methods: 'POST')]
    public function saveAction(
        ParserContext $parserContext,
        int $ruleId,
        int $actionId,
        ActionRegistry $actionRegistry,
        SqlBuilder $sqlBuilder,
        SuggestionService $suggestionService,
        EventDispatcherInterface $eventDispatcher,
    ): RedirectResponse|Response {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, QueryBuilder::getModuleCode(), AccessManager::UPDATE)) {
            return $response;
        }

        $form = $this->createForm(ActionForm::getName());

        try {
            $rule = $this->requireRule($ruleId);
            $action = QueryBuilderActionQuery::create()->filterByRuleId($ruleId)->findOneById($actionId);

            if ($action === null) {
                throw new \RuntimeException(sprintf('Action #%d not found.', $actionId));
            }

            $data = $this->validateForm($form)->getData();
            $handler = $this->requireHandler($data['code'], $rule, $actionRegistry);
            $conditionTree = $this->parseConditionTree(
                $data['condition_tree'] ?? null,
                $sqlBuilder,
                Context::tryFrom($rule->getContext() ?? '') ?? Context::GLOBAL_SCOPE
            );
            $parameters = $this->buildParameters($action->getParametersArray(), $data);
            $previousState = ActionCycleState::fromAction($action);

            $action
                ->setName($data['name'])
                ->setDescription($data['description'] ?? null)
                ->setCode($data['code'])
                ->setType($handler::getType())
                ->setConditionTree($conditionTree !== null ? json_encode($conditionTree, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE) : null)
                ->setParameters($parameters !== [] ? json_encode($parameters, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE) : null)
                ->setActivate($data['activate'] ? 1 : 0);

            $this->saveWithCycles($action, $previousState, $suggestionService);

            $eventDispatcher->dispatch(new QueryBuilderRulesChangedEvent());

            return $this->generateSuccessRedirect($form);
        } catch (FormValidationException $exception) {
            $errorMessage = $this->createStandardFormValidationErrorMessage($exception);
        } catch (\InvalidArgumentException $exception) {
            $errorMessage = $exception->getMessage();
        } catch (\Exception $exception) {
            Tlog::getInstance()->addError(sprintf('QueryBuilder action save error: %s', $exception->getMessage()));
            $errorMessage = $this->unexpectedErrorMessage();
        }

        $form->setErrorMessage($errorMessage);
        $parserContext->addForm($form)->setGeneralError($errorMessage);

        return $this->generateErrorRedirect($form);
    }

    #[Route('/{actionId}/toggle', name: 'toggle', requirements: ['actionId' => '\d+'], methods: 'POST')]
    public function toggleAction(
        Request $request,
        TokenProvider $tokenProvider,
        int $ruleId,
        int $actionId,
        EventDispatcherInterface $eventDispatcher,
        SuggestionService $suggestionService,
    ): RedirectResponse {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, QueryBuilder::getModuleCode(), AccessManager::UPDATE)) {
            return $response;
        }

        $tokenProvider->checkToken($request->query->get('_token'));

        $action = QueryBuilderActionQuery::create()->filterByRuleId($ruleId)->findOneById($actionId);

        if ($action !== null) {
            $previousState = ActionCycleState::fromAction($action);
            $action->setActivate($previousState->active ? 0 : 1);

            $this->saveWithCycles($action, $previousState, $suggestionService);
            $eventDispatcher->dispatch(new QueryBuilderRulesChangedEvent());
        }

        return $this->generateRedirect(URL::getInstance()->absoluteUrl('/admin/query_builder/rule/' . $ruleId));
    }

    #[Route('/{actionId}/delete', name: 'delete', requirements: ['actionId' => '\d+'], methods: 'POST')]
    public function deleteAction(
        Request $request,
        TokenProvider $tokenProvider,
        int $ruleId,
        int $actionId,
        EventDispatcherInterface $eventDispatcher,
    ): RedirectResponse {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, QueryBuilder::getModuleCode(), AccessManager::DELETE)) {
            return $response;
        }

        $tokenProvider->checkToken($request->query->get('_token'));

        $action = QueryBuilderActionQuery::create()->filterByRuleId($ruleId)->findOneById($actionId);

        if ($action !== null) {
            $action->delete();
            $eventDispatcher->dispatch(new QueryBuilderRulesChangedEvent());
        }

        return $this->generateRedirect(URL::getInstance()->absoluteUrl('/admin/query_builder/rule/' . $ruleId));
    }

    private function unexpectedErrorMessage(): string
    {
        return Translator::getInstance()->trans(
            'An unexpected error occurred, please check the logs.',
            [],
            QueryBuilder::DOMAIN_NAME
        );
    }

    private function requireRule(int $ruleId): QueryBuilderRule
    {
        $rule = QueryBuilderRuleQuery::create()->findOneById($ruleId);

        if ($rule === null) {
            throw new \RuntimeException(sprintf('Rule #%d not found.', $ruleId));
        }

        return $rule;
    }

    private function requireHandler(string $code, QueryBuilderRule $rule, ActionRegistry $actionRegistry): \QueryBuilder\Action\ActionInterface
    {
        $context = Context::tryFrom($rule->getContext() ?? '') ?? Context::GLOBAL_SCOPE;
        $handler = $actionRegistry->forContext($context)[$code] ?? null;

        if ($handler === null) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown action code "%s" for context %s.',
                $code,
                $context->value
            ));
        }

        return $handler;
    }

    private function parseConditionTree(?string $rawTree, SqlBuilder $sqlBuilder, Context $context): ?array
    {
        if ($rawTree === null || trim($rawTree) === '' || $rawTree === 'null') {
            return null;
        }

        try {
            $conditionTree = json_decode($rawTree, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException(sprintf('Invalid condition tree JSON: %s.', $exception->getMessage()), 0, $exception);
        }

        if (!\is_array($conditionTree)) {
            throw new \InvalidArgumentException('Invalid condition tree JSON.');
        }

        $sqlBuilder->validateTree($conditionTree, $context);

        return $conditionTree;
    }

    /**
     * The action and the expiry of its invalidated cycles succeed or fail
     * together: an action saved with cycles still running is the state this
     * mechanism exists to prevent.
     */
    private function saveWithCycles(QueryBuilderAction $action, ActionCycleState $previousState, SuggestionService $suggestionService): void
    {
        Propel::getWriteConnection(QueryBuilderActionTableMap::DATABASE_NAME)->transaction(
            static function () use ($action, $previousState, $suggestionService): void {
                $action->save();
                $suggestionService->expireCyclesInvalidatedBy($previousState, $action);
            }
        );
    }

    /**
     * Merges the typed form fields into the stored parameters, keeping unknown
     * keys untouched. Each action code owns its parameter set: display actions
     * take limit/persist_days, ApplyDiscount takes the discount fields plus
     * the optional limit/persist_days, ApplyCartDiscount takes the cart
     * discount fields.
     */
    private function buildParameters(array $existingParameters, array $data): array
    {
        $parameters = $existingParameters;
        unset(
            $parameters['limit'],
            $parameters['persist_days'],
            $parameters['discount_rate'],
            $parameters['discount_label'],
            $parameters['discount_cumulative'],
            $parameters['cart_discount_rate'],
            $parameters['cart_discount_free_shipping']
        );

        if ($data['code'] === ApplyCartDiscountAction::CODE) {
            $rate = $data['cart_discount_rate'] !== null ? (float) $data['cart_discount_rate'] : null;
            $freeShipping = (bool) ($data['cart_discount_free_shipping'] ?? false);

            if (($rate === null || $rate <= 0) && !$freeShipping) {
                throw new \InvalidArgumentException('Une remise globale panier nécessite un taux supérieur à 0 ou les frais de port offerts.');
            }

            if ($rate !== null && $rate > 0) {
                $parameters['cart_discount_rate'] = $rate;
            }

            $parameters['cart_discount_free_shipping'] = $freeShipping;

            return $parameters;
        }

        if ($data['code'] === ApplyDiscountAction::CODE) {
            if ($data['discount_rate'] === null || (float) $data['discount_rate'] <= 0) {
                throw new \InvalidArgumentException('Une action remise nécessite un taux de remise supérieur à 0.');
            }

            $parameters['discount_rate'] = (float) $data['discount_rate'];

            if (($data['discount_label'] ?? '') !== '' && $data['discount_label'] !== null) {
                $parameters['discount_label'] = (string) $data['discount_label'];
            }

            $parameters['discount_cumulative'] = (bool) ($data['discount_cumulative'] ?? false);

            if ($data['limit'] !== null) {
                $parameters['limit'] = (int) $data['limit'];
            }

            if ($data['persist_days'] !== null) {
                $parameters['persist_days'] = (int) $data['persist_days'];
            }

            return $parameters;
        }

        if ($data['limit'] !== null) {
            $parameters['limit'] = (int) $data['limit'];
        }

        if ($data['persist_days'] !== null) {
            $parameters['persist_days'] = (int) $data['persist_days'];
        }

        return $parameters;
    }
}
