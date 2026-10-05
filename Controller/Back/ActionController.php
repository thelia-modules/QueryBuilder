<?php

declare(strict_types=1);

namespace QueryBuilder\Controller\Back;

use QueryBuilder\Action\ActionInterface;
use QueryBuilder\Action\ActionRegistry;
use QueryBuilder\Action\ApplyCartDiscountAction;
use QueryBuilder\Action\ApplyDiscountAction;
use QueryBuilder\Dictionary\FieldDefinition;
use QueryBuilder\Enum\Context;
use QueryBuilder\Event\QueryBuilderRulesChangedEvent;
use QueryBuilder\Form\ActionForm;
use QueryBuilder\Model\QueryBuilderAction;
use QueryBuilder\Model\QueryBuilderActionQuery;
use QueryBuilder\Model\QueryBuilderRule;
use QueryBuilder\Model\QueryBuilderRuleQuery;
use QueryBuilder\QueryBuilder;
use QueryBuilder\Service\EditorLabels;
use QueryBuilder\Service\FieldsBuilder;
use QueryBuilder\Service\SqlBuilder;
use QueryBuilder\Service\SuggestionService;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Template\ParserContext;
use Thelia\Core\Translation\Translator;
use Thelia\Form\BaseForm;
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
        if (null !== $response = $this->denyUnlessGranted(AccessManager::CREATE)) {
            return $response;
        }

        $form = $this->createForm(ActionForm::getName());

        try {
            $rule = $this->requireRule($ruleId);
            $data = $this->validateForm($form, 'POST')->getData();
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
                sprintf('%s/rule/%d/action/%d', RuleController::LIST_PATH, $rule->getId(), $action->getId())
            ));
        } catch (FormValidationException $exception) {
            $errorMessage = $this->createStandardFormValidationErrorMessage($exception);
        } catch (\InvalidArgumentException $exception) {
            $errorMessage = $exception->getMessage();
        } catch (\Exception $exception) {
            Tlog::getInstance()->addError(sprintf('QueryBuilder action create error: %s', $exception->getMessage()));
            $errorMessage = $this->unexpectedErrorMessage();
        }

        return $this->redirectWithError($form, $parserContext, $errorMessage);
    }

    #[Route('/{actionId}', name: 'edit', requirements: ['actionId' => '\d+'], methods: 'GET')]
    public function editAction(
        int $ruleId,
        int $actionId,
        ActionRegistry $actionRegistry,
        FieldsBuilder $fieldsBuilder,
        EditorLabels $editorLabels,
    ): Response {
        if (null !== $response = $this->denyUnlessGranted(AccessManager::VIEW)) {
            return $response;
        }

        $rule = QueryBuilderRuleQuery::create()->findOneById($ruleId);
        $action = QueryBuilderActionQuery::create()->filterByRuleId($ruleId)->findOneById($actionId);

        if ($rule === null || $action === null) {
            return $this->generateRedirect(URL::getInstance()->absoluteUrl(RuleController::LIST_PATH));
        }

        $context = Context::tryFrom($rule->getContext() ?? '') ?? Context::GLOBAL_SCOPE;
        $parameters = $action->getParametersArray();

        $availableActions = [];
        foreach ($actionRegistry->forContext($context) as $code => $actionHandler) {
            $availableActions[] = [
                'code' => $code,
                'label' => $this->trans($actionHandler::getLabel()),
                'type' => $actionHandler::getType(),
            ];
        }

        $form = $this->createForm(ActionForm::getName(), FormType::class, [
            'name' => $action->getName(),
            'description' => $action->getDescription(),
            'code' => $action->getCode(),
            'limit' => isset($parameters['limit']) ? (int) $parameters['limit'] : null,
            'persist_days' => isset($parameters['persist_days']) ? (int) $parameters['persist_days'] : null,
            'discount_rate' => isset($parameters['discount_rate']) ? (float) $parameters['discount_rate'] : null,
            'discount_label' => $parameters['discount_label'] ?? null,
            'discount_cumulative' => (bool) ($parameters['discount_cumulative'] ?? false),
            'cart_discount_rate' => isset($parameters['cart_discount_rate']) ? (float) $parameters['cart_discount_rate'] : null,
            'cart_discount_free_shipping' => (bool) ($parameters['cart_discount_free_shipping'] ?? false),
            'condition_tree' => $action->getConditionTreeArray(),
            'activate' => (bool) $action->getActivate(),
        ]);

        $contextFields = $fieldsBuilder->buildForContext($context, $this->getRequest()->getLocale(), FieldDefinition::USAGE_ACTION);

        return $this->render('action-edit', [
            'rule' => [
                'id' => $rule->getId(),
                'name' => $rule->getName(),
                'context' => $context->value,
                'context_label' => $this->trans($context->label()),
            ],
            'action' => [
                'id' => $action->getId(),
                'name' => $action->getName(),
                'code' => $action->getCode(),
                'type' => $action->getType(),
                'activate' => (bool) $action->getActivate(),
            ],
            'form' => $form->createView()->getView(),
            'available_actions' => $availableActions,
            'discount_code' => ApplyDiscountAction::CODE,
            'cart_discount_code' => ApplyCartDiscountAction::CODE,
            'fields_by_context' => [$context->value => $contextFields],
            'context_fields' => $contextFields,
            'editor_labels' => $editorLabels->build(),
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
        if (null !== $response = $this->denyUnlessGranted(AccessManager::UPDATE)) {
            return $response;
        }

        $form = $this->createForm(ActionForm::getName());

        try {
            $rule = $this->requireRule($ruleId);
            $action = QueryBuilderActionQuery::create()->filterByRuleId($ruleId)->findOneById($actionId);

            if ($action === null) {
                throw new \RuntimeException(sprintf('Action #%d not found.', $actionId));
            }

            $data = $this->validateForm($form, 'POST')->getData();
            $handler = $this->requireHandler($data['code'], $rule, $actionRegistry);
            $conditionTree = $this->validateConditionTree(
                $data['condition_tree'] ?? null,
                $sqlBuilder,
                Context::tryFrom($rule->getContext() ?? '') ?? Context::GLOBAL_SCOPE
            );
            $parameters = $this->buildParameters($action->getParametersArray(), $data);
            $conditionTreeChanged = $this->normalizeConditionTree($action->getConditionTreeArray())
                !== $this->normalizeConditionTree($conditionTree);

            $action
                ->setName($data['name'])
                ->setDescription($data['description'] ?? null)
                ->setCode($data['code'])
                ->setType($handler::getType())
                ->setConditionTree($conditionTree !== null ? json_encode($conditionTree, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE) : null)
                ->setParameters($parameters !== [] ? json_encode($parameters, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE) : null)
                ->setActivate($data['activate'] ? 1 : 0);
            $action->save();

            if ($conditionTreeChanged) {
                $suggestionService->expireActiveCycles((int) $action->getId());
            }

            $eventDispatcher->dispatch(new QueryBuilderRulesChangedEvent());

            $this->addFlash('success', $this->trans('The action has been saved.'));

            return $this->generateSuccessRedirect($form);
        } catch (FormValidationException $exception) {
            $errorMessage = $this->createStandardFormValidationErrorMessage($exception);
        } catch (\InvalidArgumentException $exception) {
            $errorMessage = $exception->getMessage();
        } catch (\Exception $exception) {
            Tlog::getInstance()->addError(sprintf('QueryBuilder action save error: %s', $exception->getMessage()));
            $errorMessage = $this->unexpectedErrorMessage();
        }

        return $this->redirectWithError($form, $parserContext, $errorMessage);
    }

    #[Route('/{actionId}/toggle', name: 'toggle', requirements: ['actionId' => '\d+'], methods: 'POST')]
    public function toggleAction(
        Request $request,
        TokenProvider $tokenProvider,
        int $ruleId,
        int $actionId,
        EventDispatcherInterface $eventDispatcher,
    ): Response {
        if (null !== $response = $this->denyUnlessGranted(AccessManager::UPDATE)) {
            return $response;
        }

        $tokenProvider->checkRequestToken($request);

        $action = QueryBuilderActionQuery::create()->filterByRuleId($ruleId)->findOneById($actionId);

        if ($action !== null) {
            $action->setActivate($action->getActivate() ? 0 : 1)->save();
            $eventDispatcher->dispatch(new QueryBuilderRulesChangedEvent());
        }

        return $this->generateRedirect(URL::getInstance()->absoluteUrl(RuleController::LIST_PATH . '/rule/' . $ruleId));
    }

    #[Route('/{actionId}/delete', name: 'delete', requirements: ['actionId' => '\d+'], methods: 'POST')]
    public function deleteAction(
        Request $request,
        TokenProvider $tokenProvider,
        int $ruleId,
        int $actionId,
        EventDispatcherInterface $eventDispatcher,
    ): Response {
        if (null !== $response = $this->denyUnlessGranted(AccessManager::DELETE)) {
            return $response;
        }

        $tokenProvider->checkRequestToken($request);

        $action = QueryBuilderActionQuery::create()->filterByRuleId($ruleId)->findOneById($actionId);

        if ($action !== null) {
            $action->delete();
            $eventDispatcher->dispatch(new QueryBuilderRulesChangedEvent());
        }

        return $this->generateRedirect(URL::getInstance()->absoluteUrl(RuleController::LIST_PATH . '/rule/' . $ruleId));
    }

    private function redirectWithError(BaseForm $form, ParserContext $parserContext, string $errorMessage): RedirectResponse|Response
    {
        $form->setErrorMessage($errorMessage);
        $parserContext->addForm($form)->setGeneralError($errorMessage);
        $this->addFlash('danger', $errorMessage);

        return $this->generateErrorRedirect($form);
    }

    /**
     * Every screen of the module, in reading as in writing, is reserved to the
     * administrators granted on the module itself: the core only checks that
     * an administrator is logged in. The "admin.module" resource is not required,
     * it guards the module management screens, not the screens of this module.
     */
    private function denyUnlessGranted(string $access): ?Response
    {
        return $this->checkAuth([], QueryBuilder::getModuleCode(), $access);
    }

    private function trans(string $id): string
    {
        return Translator::getInstance()->trans($id, [], QueryBuilder::DOMAIN_NAME);
    }

    private function unexpectedErrorMessage(): string
    {
        return $this->trans('An unexpected error occurred, please check the logs.');
    }

    private function requireRule(int $ruleId): QueryBuilderRule
    {
        $rule = QueryBuilderRuleQuery::create()->findOneById($ruleId);

        if ($rule === null) {
            throw new \RuntimeException(sprintf('Rule #%d not found.', $ruleId));
        }

        return $rule;
    }

    private function requireHandler(string $code, QueryBuilderRule $rule, ActionRegistry $actionRegistry): ActionInterface
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

    /**
     * The form type already refused any field or operator outside the dictionary;
     * the context restriction of the fields is checked here, on the stored shape.
     * A group emptied of its rules is "no condition".
     */
    private function validateConditionTree(?array $conditionTree, SqlBuilder $sqlBuilder, Context $context): ?array
    {
        if ($conditionTree === null || ($conditionTree['rules'] ?? []) === []) {
            return null;
        }

        $sqlBuilder->validateTree($conditionTree, $context, FieldDefinition::USAGE_ACTION);

        return $conditionTree;
    }

    /**
     * Strips the editor bookkeeping (node ids, default valueSource and "not"
     * flags) so two trees compare on their semantics only: the editor may
     * re-serialize an unchanged tree with different ids or extra defaults.
     */
    private function normalizeConditionTree(?array $tree): ?array
    {
        if ($tree === null) {
            return null;
        }

        unset($tree['id']);

        if (($tree['valueSource'] ?? null) === 'value') {
            unset($tree['valueSource']);
        }

        if (empty($tree['not'])) {
            unset($tree['not']);
        }

        foreach ($tree as $key => $value) {
            if (\is_array($value)) {
                $tree[$key] = $this->normalizeConditionTree($value);
            }
        }

        return $tree;
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
                throw new \InvalidArgumentException($this->trans('A cart discount needs a rate above 0 or free shipping.'));
            }

            if ($rate !== null && $rate > 0) {
                $parameters['cart_discount_rate'] = $rate;
            }

            $parameters['cart_discount_free_shipping'] = $freeShipping;

            return $parameters;
        }

        if ($data['code'] === ApplyDiscountAction::CODE) {
            if ($data['discount_rate'] === null || (float) $data['discount_rate'] <= 0) {
                throw new \InvalidArgumentException($this->trans('A discount action needs a discount rate above 0.'));
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
