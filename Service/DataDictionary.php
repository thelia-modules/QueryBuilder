<?php

declare(strict_types=1);

namespace QueryBuilder\Service;

use QueryBuilder\Dictionary\DictionaryOverrideLocatorInterface;
use QueryBuilder\Dictionary\FieldDefinition;
use QueryBuilder\Dictionary\JoinDefinition;
use QueryBuilder\Enum\Context;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads the base data dictionary shipped with this module then merges the
 * Config/query_builder.yml exposed by every other active module (project overrides).
 */
final class DataDictionary
{
    public const DICTIONARY_FILENAME = 'query_builder.yml';
    //The operators a :value expression may declare: the polarities and the equalities Operators folds into them
    private const VALUE_EXPRESSION_OPERATORS = ['in', 'notIn', '=', '!='];

    private ?array $dictionary = null;

    public function __construct(
        private readonly DictionaryOverrideLocatorInterface $overrideLocator,
        private readonly string $baseFile = __DIR__ . '/../Config/' . self::DICTIONARY_FILENAME,
    ) {
    }

    /** @return array<string, JoinDefinition> keyed by joined table name */
    public function getJoins(): array
    {
        return $this->load()['joins'];
    }

    public function getJoin(string $table): ?JoinDefinition
    {
        return $this->load()['joins'][$table] ?? null;
    }

    /**
     * Fields available in the given context (every context when null) and for
     * the given editor usage (FieldDefinition::USAGE_*, every usage when null).
     *
     * @return array<string, FieldDefinition> keyed by field code
     */
    public function getFields(?Context $context = null, ?string $usage = null): array
    {
        return array_filter(
            $this->load()['fields'],
            static fn (FieldDefinition $field): bool => ($context === null || $field->isAvailableInContext($context))
                && ($usage === null || $field->isAvailableForUsage($usage))
        );
    }

    public function getField(string $code): ?FieldDefinition
    {
        return $this->load()['fields'][$code] ?? null;
    }

    /** @return array<string, string> hook code => functional label, for the given context */
    public function getHooks(Context $context): array
    {
        //A GLOBAL rule can be bound to any hook: the same rule serves
        //identical recommendations on every hook it is checked on
        if ($context === Context::GLOBAL_SCOPE) {
            $allHooks = [];
            foreach ($this->load()['contexts'] as $hooks) {
                $allHooks = array_replace($allHooks, $hooks);
            }

            return $allHooks;
        }

        return $this->load()['contexts'][$context->value] ?? [];
    }

    public function getContextForHook(string $hookCode): ?Context
    {
        foreach ($this->load()['contexts'] as $contextValue => $hooks) {
            if (\array_key_exists($hookCode, $hooks)) {
                return Context::from($contextValue);
            }
        }

        return null;
    }

    private function load(): array
    {
        if ($this->dictionary !== null) {
            return $this->dictionary;
        }

        $raw = $this->loadFile($this->baseFile);

        foreach ($this->overrideLocator->locate() as $file) {
            $override = $this->loadFile($file);

            $raw['joins'] = array_replace($raw['joins'], $override['joins']);
            $raw['fields'] = array_replace($raw['fields'], $override['fields']);
            $raw['disabled'] = array_merge($raw['disabled'], $override['disabled']);

            //Union by hook code, the last module wins on the label (a project
            //override can refine the label of a base hook)
            foreach ($override['contexts'] as $contextValue => $hooks) {
                $raw['contexts'][$contextValue] = array_replace($raw['contexts'][$contextValue] ?? [], $hooks);
            }
        }

        foreach ($raw['disabled'] as $disabledFieldCode) {
            unset($raw['fields'][$disabledFieldCode]);
        }

        return $this->dictionary = [
            'joins' => $this->buildJoins($raw['joins']),
            'fields' => $this->buildFields($raw['fields']),
            'contexts' => $raw['contexts'],
        ];
    }

    private function loadFile(string $path): array
    {
        $content = Yaml::parseFile($path) ?? [];

        $contexts = [];
        foreach ($content['contexts'] ?? [] as $contextValue => $hooks) {
            $contexts[$contextValue] = $this->normalizeHooks($hooks ?? [], (string) $contextValue);
        }

        return [
            'joins' => $content['joins'] ?? [],
            'fields' => $content['fields'] ?? [],
            'contexts' => $contexts,
            'disabled' => $content['disabled'] ?? [],
        ];
    }

    /**
     * A hook entry is either a plain code string or a {code, label} mapping —
     * the label defaults to the code, like the label of a field definition.
     *
     * @return array<string, string> hook code => label
     */
    private function normalizeHooks(array $entries, string $contextValue): array
    {
        $hooks = [];

        foreach ($entries as $entry) {
            if (\is_string($entry)) {
                $hooks[$entry] = $entry;
                continue;
            }

            if (\is_array($entry) && isset($entry['code'])) {
                $hooks[(string) $entry['code']] = (string) ($entry['label'] ?? $entry['code']);
                continue;
            }

            throw new \InvalidArgumentException(sprintf(
                'QueryBuilder dictionary: invalid hook entry in context "%s" — expected a code string or a {code, label} mapping.',
                $contextValue
            ));
        }

        return $hooks;
    }

    /** @return array<string, JoinDefinition> */
    private function buildJoins(array $rawJoins): array
    {
        $joins = [];

        foreach ($rawJoins as $table => $definition) {
            //"~" marks a table resolved elsewhere (ex: added by a QueryScope), no dynamic join to build
            if ($definition === null) {
                continue;
            }

            $from = (string) ($definition['from'] ?? '');

            if (!str_contains($from, '.')) {
                throw new \InvalidArgumentException(sprintf(
                    'QueryBuilder dictionary: join "%s" requires a "from" in "table.column" form.',
                    $table
                ));
            }

            [$fromTable, $fromColumn] = explode('.', $from, 2);

            $joins[$table] = new JoinDefinition(
                table: (string) $table,
                fromTable: $fromTable,
                fromColumn: $fromColumn,
                toColumn: (string) ($definition['to'] ?? 'id'),
                type: strtoupper((string) ($definition['type'] ?? JoinDefinition::TYPE_INNER)),
                extraOn: isset($definition['on']) ? (string) $definition['on'] : null,
                multivalued: (bool) ($definition['multivalued'] ?? false),
            );
        }

        return $joins;
    }

    /** @return array<string, FieldDefinition> */
    private function buildFields(array $rawFields): array
    {
        $fields = [];

        foreach ($rawFields as $code => $definition) {
            $column = isset($definition['field']) ? (string) $definition['field'] : null;
            $expression = isset($definition['expression']) ? (string) $definition['expression'] : null;

            if (($column === null) === ($expression === null)) {
                throw new \InvalidArgumentException(sprintf(
                    'QueryBuilder dictionary: field "%s" requires exactly one of "field" or "expression".',
                    $code
                ));
            }

            $type = (string) ($definition['type'] ?? FieldDefinition::TYPE_TEXT);

            if (!\in_array($type, FieldDefinition::TYPES, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'QueryBuilder dictionary: field "%s" has unknown type "%s".',
                    $code,
                    $type
                ));
            }

            $valuesQuery = isset($definition['values_query']) ? (string) $definition['values_query'] : null;

            if ($valuesQuery !== null && $type === FieldDefinition::TYPE_BOOLEAN) {
                throw new \InvalidArgumentException(sprintf(
                    'QueryBuilder dictionary: field "%s" is boolean, "values_query" does not apply.',
                    $code
                ));
            }

            $field = new FieldDefinition(
                code: (string) $code,
                label: (string) ($definition['label'] ?? $code),
                type: $type,
                column: $column,
                expression: $expression,
                contexts: $this->buildContexts((string) $code, $definition['contexts'] ?? null),
                operators: $definition['operators'] ?? null,
                valuesQuery: $valuesQuery,
                usages: $this->buildUsages((string) $code, $definition['usage'] ?? null),
            );

            //A :value expression consumes the entered value itself: the field must
            //restrict its operators to the polarities (in/notIn) and the equalities
            //folded into them (=/!=) so the comparison path never applies
            if ($field->usesValuePlaceholder()
                && ($field->operators === null || $field->operators === [] || array_diff($field->operators, self::VALUE_EXPRESSION_OPERATORS) !== [])
            ) {
                throw new \InvalidArgumentException(sprintf(
                    'QueryBuilder dictionary: field "%s" uses the :value placeholder, its "operators" must be declared among [%s].',
                    $code,
                    implode(', ', self::VALUE_EXPRESSION_OPERATORS)
                ));
            }

            $fields[$code] = $field;
        }

        return $fields;
    }

    /**
     * Contexts of a field: a list of Context values, GLOBAL (every context) when
     * absent or empty.
     *
     * @return Context[]
     */
    private function buildContexts(string $code, mixed $rawContexts): array
    {
        if ($rawContexts === null || $rawContexts === []) {
            return [Context::GLOBAL_SCOPE];
        }

        if (!\is_array($rawContexts)) {
            throw new \InvalidArgumentException(sprintf(
                'QueryBuilder dictionary: field "%s" expects a list of contexts in "contexts".',
                $code
            ));
        }

        $contexts = [];

        foreach ($rawContexts as $contextValue) {
            $context = \is_string($contextValue) ? Context::tryFrom($contextValue) : null;

            if ($context === null) {
                throw new \InvalidArgumentException(sprintf(
                    'QueryBuilder dictionary: field "%s" has unknown context "%s" (expected one of %s).',
                    $code,
                    \is_scalar($contextValue) ? (string) $contextValue : get_debug_type($contextValue),
                    implode(', ', array_map(static fn (Context $case): string => $case->value, Context::cases()))
                ));
            }

            $contexts[] = $context;
        }

        return array_values(array_unique($contexts, \SORT_REGULAR));
    }

    /**
     * Editors a field is offered in: a list of FieldDefinition::USAGE_* values,
     * both when absent or empty.
     *
     * @return string[]
     */
    private function buildUsages(string $code, mixed $rawUsages): array
    {
        if ($rawUsages === null || $rawUsages === []) {
            return FieldDefinition::USAGES;
        }

        if (!\is_array($rawUsages)) {
            throw new \InvalidArgumentException(sprintf(
                'QueryBuilder dictionary: field "%s" expects a list in "usage" (values: %s).',
                $code,
                implode(', ', FieldDefinition::USAGES)
            ));
        }

        foreach ($rawUsages as $usage) {
            if (!\is_string($usage) || !\in_array($usage, FieldDefinition::USAGES, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'QueryBuilder dictionary: field "%s" has unknown usage "%s" (expected one of %s).',
                    $code,
                    \is_scalar($usage) ? (string) $usage : get_debug_type($usage),
                    implode(', ', FieldDefinition::USAGES)
                ));
            }
        }

        return array_values(array_unique($rawUsages));
    }
}
