<?php

declare(strict_types=1);

namespace QueryBuilder\Controller\Back;

use Propel\Runtime\Propel;
use QueryBuilder\Action\ActionRegistry;
use QueryBuilder\Enum\Context;
use QueryBuilder\Event\QueryBuilderRulesChangedEvent;
use QueryBuilder\Form\RuleForm;
use QueryBuilder\Model\Map\QueryBuilderRuleTableMap;
use QueryBuilder\Model\QueryBuilderActionQuery;
use QueryBuilder\Model\QueryBuilderRule;
use QueryBuilder\Model\QueryBuilderRuleQuery;
use QueryBuilder\QueryBuilder;
use QueryBuilder\Service\DataDictionary;
use QueryBuilder\Service\FieldsBuilder;
use QueryBuilder\Service\RuleCycleState;
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

#[Route('/admin/query_builder', name: 'admin_query_builder_')]
class RuleController extends BaseAdminController
{
    public const ADMIN_LOCATION = 'QueryBuilder';

    #[Route('', name: 'list')]
    public function listRules(DataDictionary $dataDictionary): Response
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, QueryBuilder::getModuleCode(), AccessManager::VIEW)) {
            return $response;
        }

        $actionCounts = [];
        foreach (QueryBuilderActionQuery::create()->find() as $action) {
            $actionCounts[$action->getRuleId()] = ($actionCounts[$action->getRuleId()] ?? 0) + 1;
        }

        //A hook code stored by a rule but no longer declared (removed from the
        //dictionary) keeps its raw code as label: visible, never hidden
        $hookLabels = $dataDictionary->getHooks(Context::GLOBAL_SCOPE);

        $rules = [];
        foreach (QueryBuilderRuleQuery::create()->orderByPosition()->orderById()->find() as $rule) {
            $rules[] = [
                'id' => $rule->getId(),
                'name' => $rule->getName(),
                'description' => $rule->getDescription(),
                'context' => $rule->getContext(),
                'context_label' => Context::tryFrom($rule->getContext() ?? '')?->label() ?? $rule->getContext(),
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

        return $this->render('query-builder/rule-list', [
            'admin_current_location' => self::ADMIN_LOCATION,
            'rules' => $rules,
            'contexts' => self::contextChoices(),
        ]);
    }

    #[Route('/rule/create', name: 'rule_create', methods: 'POST')]
    public function createRule(ParserContext $parserContext, EventDispatcherInterface $eventDispatcher): RedirectResponse|Response
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, QueryBuilder::getModuleCode(), AccessManager::CREATE)) {
            return $response;
        }

        $form = $this->createForm(RuleForm::getName());

        try {
            $data = $this->validateForm($form)->getData();
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
            return $this->generateRedirect(URL::getInstance()->absoluteUrl('/admin/query_builder/rule/' . $rule->getId()));
        } catch (FormValidationException $exception) {
            $errorMessage = $this->createStandardFormValidationErrorMessage($exception);
        } catch (\InvalidArgumentException $exception) {
            $errorMessage = $exception->getMessage();
        } catch (\Exception $exception) {
            Tlog::getInstance()->addError(sprintf('QueryBuilder rule create error: %s', $exception->getMessage()));
            $errorMessage = $this->unexpectedErrorMessage();
        }

        $form->setErrorMessage($errorMessage);
        $parserContext->addForm($form)->setGeneralError($errorMessage);

        return $this->generateErrorRedirect($form);
    }

    #[Route('/rule/{ruleId}', name: 'rule_edit', requirements: ['ruleId' => '\d+'], methods: 'GET')]
    public function editRule(
        int $ruleId,
        DataDictionary $dataDictionary,
        FieldsBuilder $fieldsBuilder,
        ActionRegistry $actionRegistry,
    ): Response {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, QueryBuilder::getModuleCode(), AccessManager::VIEW)) {
            return $response;
        }

        $rule = QueryBuilderRuleQuery::create()->findOneById($ruleId);

        if ($rule === null) {
            return $this->generateRedirect(URL::getInstance()->absoluteUrl('/admin/query_builder'));
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
            ];
        }

        $availableActions = [];
        foreach ($actionRegistry->forContext($context) as $code => $actionHandler) {
            $availableActions[] = [
                'code' => $code,
                'label' => $actionHandler::getLabel(),
                'type' => $actionHandler::getType(),
            ];
        }

        $hookChoices = [];
        $fieldsByContext = [];
        foreach (Context::cases() as $contextCase) {
            foreach ($dataDictionary->getHooks($contextCase) as $hookCode => $hookLabel) {
                $hookChoices[] = [
                    'context' => $contextCase->value,
                    'code' => $hookCode,
                    'label' => $hookLabel,
                    'checked' => $contextCase === $context && \in_array($hookCode, $rule->getHookCodes(), true),
                ];
            }
            $fieldsByContext[$contextCase->value] = $fieldsBuilder->buildForContext($contextCase);
        }

        return $this->render('query-builder/rule-edit', [
            'admin_current_location' => self::ADMIN_LOCATION,
            'rule' => [
                'id' => $rule->getId(),
                'name' => $rule->getName(),
                'description' => $rule->getDescription(),
                'context' => $context->value,
                'context_label' => $context->label(),
                'hooks' => $rule->getHookCodes(),
                'condition_tree' => $rule->getConditionTree(),
                'activate' => (bool) $rule->getActivate(),
            ],
            'actions' => $actions,
            'available_actions' => $availableActions,
            'contexts' => self::contextChoices(),
            'hook_choices' => $hookChoices,
            'fields_by_context' => json_encode($fieldsByContext, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
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
        SuggestionService $suggestionService,
    ): RedirectResponse|Response {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, QueryBuilder::getModuleCode(), AccessManager::UPDATE)) {
            return $response;
        }

        $form = $this->createForm(RuleForm::getName());

        try {
            $rule = QueryBuilderRuleQuery::create()->findOneById($ruleId);

            if ($rule === null) {
                throw new \RuntimeException(sprintf('Rule #%d not found.', $ruleId));
            }

            $data = $this->validateForm($form)->getData();
            $context = $this->requireContext($data['context']);
            $conditionTree = $this->parseConditionTree($data['condition_tree'] ?? null, $sqlBuilder, $context);

            //Only hooks declared for the selected context are kept
            $postedHooks = $request->request->all('hooks');
            $hooks = array_values(array_intersect(array_keys($dataDictionary->getHooks($context)), $postedHooks));
            $previousState = RuleCycleState::fromRule($rule);

            $rule
                ->setName($data['name'])
                ->setDescription($data['description'] ?? null)
                ->setContext($context->value)
                ->setHookCodes($hooks)
                ->setConditionTree($conditionTree !== null ? json_encode($conditionTree, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE) : null)
                ->setActivate($data['activate'] ? 1 : 0);

            $this->saveWithCycles($rule, $previousState, $suggestionService);
            $eventDispatcher->dispatch(new QueryBuilderRulesChangedEvent());

            return $this->generateSuccessRedirect($form);
        } catch (FormValidationException $exception) {
            $errorMessage = $this->createStandardFormValidationErrorMessage($exception);
        } catch (\InvalidArgumentException $exception) {
            $errorMessage = $exception->getMessage();
        } catch (\Exception $exception) {
            Tlog::getInstance()->addError(sprintf('QueryBuilder rule save error: %s', $exception->getMessage()));
            $errorMessage = $this->unexpectedErrorMessage();
        }

        $form->setErrorMessage($errorMessage);
        $parserContext->addForm($form)->setGeneralError($errorMessage);

        return $this->generateErrorRedirect($form);
    }

    #[Route('/rule/{ruleId}/toggle', name: 'rule_toggle', requirements: ['ruleId' => '\d+'], methods: 'POST')]
    public function toggleRule(
        Request $request,
        TokenProvider $tokenProvider,
        int $ruleId,
        EventDispatcherInterface $eventDispatcher,
        SuggestionService $suggestionService,
    ): RedirectResponse {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, QueryBuilder::getModuleCode(), AccessManager::UPDATE)) {
            return $response;
        }

        $tokenProvider->checkToken($request->query->get('_token'));

        $rule = QueryBuilderRuleQuery::create()->findOneById($ruleId);

        if ($rule !== null) {
            $previousState = RuleCycleState::fromRule($rule);
            $rule->setActivate($previousState->active ? 0 : 1);

            $this->saveWithCycles($rule, $previousState, $suggestionService);
            $eventDispatcher->dispatch(new QueryBuilderRulesChangedEvent());
        }

        return $this->generateRedirect(URL::getInstance()->absoluteUrl('/admin/query_builder'));
    }

    /**
     * The rule and the expiry of the cycles it invalidates succeed or fail
     * together, like ActionController::saveWithCycles() one level down.
     */
    private function saveWithCycles(QueryBuilderRule $rule, RuleCycleState $previousState, SuggestionService $suggestionService): void
    {
        Propel::getWriteConnection(QueryBuilderRuleTableMap::DATABASE_NAME)->transaction(
            static function () use ($rule, $previousState, $suggestionService): void {
                $rule->save();
                $suggestionService->expireRuleCyclesInvalidatedBy($previousState, $rule);
            }
        );
    }

    #[Route('/rule/{ruleId}/delete', name: 'rule_delete', requirements: ['ruleId' => '\d+'], methods: 'POST')]
    public function deleteRule(
        Request $request,
        TokenProvider $tokenProvider,
        int $ruleId,
        EventDispatcherInterface $eventDispatcher,
    ): RedirectResponse {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, QueryBuilder::getModuleCode(), AccessManager::DELETE)) {
            return $response;
        }

        $tokenProvider->checkToken($request->query->get('_token'));

        $rule = QueryBuilderRuleQuery::create()->findOneById($ruleId);

        if ($rule !== null) {
            $rule->delete();
            $eventDispatcher->dispatch(new QueryBuilderRulesChangedEvent());
        }

        return $this->generateRedirect(URL::getInstance()->absoluteUrl('/admin/query_builder'));
    }

    /**
     * @return array<int, array{value: string, label: string, description: string}>
     */
    private static function contextChoices(): array
    {
        return array_map(
            static fn (Context $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
                'description' => $case->description(),
            ],
            Context::cases()
        );
    }

    private function unexpectedErrorMessage(): string
    {
        return Translator::getInstance()->trans(
            'An unexpected error occurred, please check the logs.',
            [],
            QueryBuilder::DOMAIN_NAME
        );
    }

    private function requireContext(?string $contextValue): Context
    {
        $context = Context::tryFrom((string) $contextValue);

        if ($context === null) {
            throw new \InvalidArgumentException(sprintf('Unknown context "%s".', $contextValue));
        }

        return $context;
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
}
