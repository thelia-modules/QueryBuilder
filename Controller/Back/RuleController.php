<?php

declare(strict_types=1);

namespace QueryBuilder\Controller\Back;

use QueryBuilder\Action\ActionRegistry;
use QueryBuilder\Action\ApplyCartDiscountAction;
use QueryBuilder\Dictionary\FieldDefinition;
use QueryBuilder\Enum\Context;
use QueryBuilder\Event\QueryBuilderRulesChangedEvent;
use QueryBuilder\Form\ActionForm;
use QueryBuilder\Form\RuleForm;
use QueryBuilder\Model\QueryBuilderActionQuery;
use QueryBuilder\Model\QueryBuilderRule;
use QueryBuilder\Model\QueryBuilderRuleQuery;
use QueryBuilder\QueryBuilder;
use QueryBuilder\Service\DataDictionary;
use QueryBuilder\Service\EditorLabels;
use QueryBuilder\Service\FieldsBuilder;
use QueryBuilder\Service\SqlBuilder;
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

#[Route('/admin/query_builder', name: 'admin_query_builder_')]
class RuleController extends BaseAdminController
{
    public const LIST_PATH = '/admin/query_builder';

    #[Route('', name: 'list', methods: 'GET')]
    public function listRules(DataDictionary $dataDictionary): Response
    {
        if (null !== $response = $this->denyUnlessGranted(AccessManager::VIEW)) {
            return $response;
        }

        $actionCounts = [];
        foreach (QueryBuilderActionQuery::create()->find() as $action) {
            $actionCounts[$action->getRuleId()] = ($actionCounts[$action->getRuleId()] ?? 0) + 1;
        }

        //A hook code stored by a rule but no longer declared (removed from the
        //dictionary) keeps its raw code as label: visible, never hidden
        $hookLabels = array_map($this->trans(...), $dataDictionary->getHooks(Context::GLOBAL_SCOPE));

        $rules = [];
        foreach (QueryBuilderRuleQuery::create()->orderByPosition()->orderById()->find() as $rule) {
            $rules[] = [
                'id' => $rule->getId(),
                'name' => $rule->getName(),
                'description' => $rule->getDescription(),
                'context' => $rule->getContext(),
                'context_label' => $this->trans(Context::tryFrom($rule->getContext() ?? '')?->label() ?? (string) $rule->getContext()),
                'hooks' => array_map(
                    static fn (string $hookCode): array => [
                        'code' => $hookCode,
                        'label' => $hookLabels[$hookCode] ?? $hookCode,
                    ],
                    $rule->getHookCodes()
                ),
                'activate' => (bool) $rule->getActivate(),
                'action_count' => $actionCounts[$rule->getId()] ?? 0,
            ];
        }

        return $this->render('rule-list', [
            'rules' => $rules,
            'contexts' => $this->contextChoices(),
            'create_form' => $this->createForm(RuleForm::getName())->createView()->getView(),
        ]);
    }

    #[Route('/rule/create', name: 'rule_create', methods: 'POST')]
    public function createRule(ParserContext $parserContext, EventDispatcherInterface $eventDispatcher): RedirectResponse|Response
    {
        if (null !== $response = $this->denyUnlessGranted(AccessManager::CREATE)) {
            return $response;
        }

        $form = $this->createForm(RuleForm::getName());

        try {
            $data = $this->validateForm($form, 'POST')->getData();
            $context = $this->requireContext($data['context']);

            $rule = (new QueryBuilderRule())
                ->setName($data['name'])
                ->setDescription($data['description'] ?? null)
                ->setContext($context->value)
                ->setHookCodes([])
                ->setActivate(0);
            $rule->save();
            $eventDispatcher->dispatch(new QueryBuilderRulesChangedEvent());

            //URL absolue : les routes #[Route] du module ne sont pas dans "router.admin",
            //seul router consulté par generateRedirectFromRoute (RouteNotFoundException sinon)
            return $this->generateRedirect(URL::getInstance()->absoluteUrl(self::LIST_PATH . '/rule/' . $rule->getId()));
        } catch (FormValidationException $exception) {
            $errorMessage = $this->createStandardFormValidationErrorMessage($exception);
        } catch (\InvalidArgumentException $exception) {
            $errorMessage = $exception->getMessage();
        } catch (\Exception $exception) {
            Tlog::getInstance()->addError(sprintf('QueryBuilder rule create error: %s', $exception->getMessage()));
            $errorMessage = $this->unexpectedErrorMessage();
        }

        return $this->redirectWithError($form, $parserContext, $errorMessage);
    }

    #[Route('/rule/{ruleId}', name: 'rule_edit', requirements: ['ruleId' => '\d+'], methods: 'GET')]
    public function editRule(
        int $ruleId,
        DataDictionary $dataDictionary,
        FieldsBuilder $fieldsBuilder,
        ActionRegistry $actionRegistry,
        EditorLabels $editorLabels,
    ): Response {
        if (null !== $response = $this->denyUnlessGranted(AccessManager::VIEW)) {
            return $response;
        }

        $rule = QueryBuilderRuleQuery::create()->findOneById($ruleId);

        if ($rule === null) {
            return $this->generateRedirect(URL::getInstance()->absoluteUrl(self::LIST_PATH));
        }

        $context = Context::tryFrom($rule->getContext() ?? '') ?? Context::GLOBAL_SCOPE;

        $actions = [];
        foreach (QueryBuilderActionQuery::create()->filterByRuleId($ruleId)->orderByPosition()->orderById()->find() as $action) {
            $actions[] = [
                'id' => $action->getId(),
                'name' => $action->getName(),
                'code' => $action->getCode(),
                'type' => $action->getType(),
                'activate' => (bool) $action->getActivate(),
                'condition_tree' => $action->getConditionTreeArray(),
                'parameters' => $action->getParametersArray(),
            ];
        }

        $availableActions = [];
        foreach ($actionRegistry->forContext($context) as $code => $actionHandler) {
            $availableActions[] = [
                'code' => $code,
                'label' => $this->trans($actionHandler::getLabel()),
                'type' => $actionHandler::getType(),
            ];
        }

        $hookChoices = [];
        foreach (Context::cases() as $contextCase) {
            foreach ($dataDictionary->getHooks($contextCase) as $hookCode => $hookLabel) {
                $hookChoices[] = [
                    'context' => $contextCase->value,
                    'code' => $hookCode,
                    'label' => $this->trans($hookLabel),
                    'checked' => $contextCase === $context && \in_array($hookCode, $rule->getHookCodes(), true),
                ];
            }
        }

        $form = $this->createForm(RuleForm::getName(), FormType::class, [
            'name' => $rule->getName(),
            'description' => $rule->getDescription(),
            'context' => $context->value,
            'condition_tree' => $rule->getConditionTreeArray(),
            'activate' => (bool) $rule->getActivate(),
        ]);

        $locale = $this->getRequest()->getLocale();
        $fieldsByContext = $fieldsBuilder->buildForAllContexts($locale, FieldDefinition::USAGE_RULE);

        return $this->render('rule-edit', [
            'rule' => [
                'id' => $rule->getId(),
                'name' => $rule->getName(),
                'context' => $context->value,
                'context_label' => $this->trans($context->label()),
                'activate' => (bool) $rule->getActivate(),
            ],
            'form' => $form->createView()->getView(),
            'action_form' => $this->createForm(ActionForm::getName())->createView()->getView(),
            'actions' => $actions,
            'available_actions' => $availableActions,
            'cart_discount_code' => ApplyCartDiscountAction::CODE,
            'contexts' => $this->contextChoices(),
            'hook_choices' => $hookChoices,
            'fields_by_context' => $fieldsByContext,
            'context_fields' => $fieldsByContext[$context->value] ?? [],
            //The reading of each action selection needs the fields of the action editor
            'action_fields' => $fieldsBuilder->buildForContext($context, $locale, FieldDefinition::USAGE_ACTION),
            'editor_labels' => $editorLabels->build(),
        ]);
    }

    #[Route('/rule/{ruleId}/save', name: 'rule_save', requirements: ['ruleId' => '\d+'], methods: 'POST')]
    public function saveRule(
        Request $request,
        ParserContext $parserContext,
        int $ruleId,
        DataDictionary $dataDictionary,
        SqlBuilder $sqlBuilder,
        EventDispatcherInterface $eventDispatcher,
    ): RedirectResponse|Response {
        if (null !== $response = $this->denyUnlessGranted(AccessManager::UPDATE)) {
            return $response;
        }

        $form = $this->createForm(RuleForm::getName());

        try {
            $rule = QueryBuilderRuleQuery::create()->findOneById($ruleId);

            if ($rule === null) {
                throw new \RuntimeException(sprintf('Rule #%d not found.', $ruleId));
            }

            $data = $this->validateForm($form, 'POST')->getData();
            $context = $this->requireContext($data['context']);
            $conditionTree = $this->validateConditionTree($data['condition_tree'] ?? null, $sqlBuilder, $context);

            //Only hooks declared for the selected context are kept
            $postedHooks = $request->request->all('hooks');
            $hooks = array_values(array_intersect(array_keys($dataDictionary->getHooks($context)), $postedHooks));

            $rule
                ->setName($data['name'])
                ->setDescription($data['description'] ?? null)
                ->setContext($context->value)
                ->setHookCodes($hooks)
                ->setConditionTree($conditionTree !== null ? json_encode($conditionTree, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE) : null)
                ->setActivate($data['activate'] ? 1 : 0);
            $rule->save();
            $eventDispatcher->dispatch(new QueryBuilderRulesChangedEvent());

            $this->addFlash('success', $this->trans('The rule has been saved.'));

            return $this->generateSuccessRedirect($form);
        } catch (FormValidationException $exception) {
            $errorMessage = $this->createStandardFormValidationErrorMessage($exception);
        } catch (\InvalidArgumentException $exception) {
            $errorMessage = $exception->getMessage();
        } catch (\Exception $exception) {
            Tlog::getInstance()->addError(sprintf('QueryBuilder rule save error: %s', $exception->getMessage()));
            $errorMessage = $this->unexpectedErrorMessage();
        }

        return $this->redirectWithError($form, $parserContext, $errorMessage);
    }

    #[Route('/rule/{ruleId}/toggle', name: 'rule_toggle', requirements: ['ruleId' => '\d+'], methods: 'POST')]
    public function toggleRule(
        Request $request,
        TokenProvider $tokenProvider,
        int $ruleId,
        EventDispatcherInterface $eventDispatcher,
    ): Response {
        if (null !== $response = $this->denyUnlessGranted(AccessManager::UPDATE)) {
            return $response;
        }

        $tokenProvider->checkRequestToken($request);

        $rule = QueryBuilderRuleQuery::create()->findOneById($ruleId);

        if ($rule !== null) {
            $rule->setActivate($rule->getActivate() ? 0 : 1)->save();
            $eventDispatcher->dispatch(new QueryBuilderRulesChangedEvent());
        }

        return $this->generateRedirect(URL::getInstance()->absoluteUrl(self::LIST_PATH));
    }

    #[Route('/rule/{ruleId}/delete', name: 'rule_delete', requirements: ['ruleId' => '\d+'], methods: 'POST')]
    public function deleteRule(
        Request $request,
        TokenProvider $tokenProvider,
        int $ruleId,
        EventDispatcherInterface $eventDispatcher,
    ): Response {
        if (null !== $response = $this->denyUnlessGranted(AccessManager::DELETE)) {
            return $response;
        }

        $tokenProvider->checkRequestToken($request);

        $rule = QueryBuilderRuleQuery::create()->findOneById($ruleId);

        if ($rule !== null) {
            $rule->delete();
            $eventDispatcher->dispatch(new QueryBuilderRulesChangedEvent());
        }

        return $this->generateRedirect(URL::getInstance()->absoluteUrl(self::LIST_PATH));
    }

    /**
     * @return array<int, array{value: string, label: string, description: string}>
     */
    private function contextChoices(): array
    {
        return array_map(
            fn (Context $case): array => [
                'value' => $case->value,
                'label' => $this->trans($case->label()),
                'description' => $this->trans($case->description()),
            ],
            Context::cases()
        );
    }

    /**
     * The rejected form is kept in the parser context (Thelia convention) and the
     * message shown through the back-office flash block on the redirected page.
     */
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

    private function requireContext(?string $contextValue): Context
    {
        $context = Context::tryFrom((string) $contextValue);

        if ($context === null) {
            throw new \InvalidArgumentException(sprintf('Unknown context "%s".', $contextValue));
        }

        return $context;
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

        $sqlBuilder->validateTree($conditionTree, $context, FieldDefinition::USAGE_RULE);

        return $conditionTree;
    }
}
