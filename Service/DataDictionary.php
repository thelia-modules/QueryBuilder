<?php

declare(strict_types=1);

namespace QueryBuilder\Service;

use Propel\Runtime\ActiveQuery\Criteria;
use QueryBuilder\Dictionary\FieldDefinition;
use QueryBuilder\Dictionary\JoinDefinition;
use QueryBuilder\Enum\Context;
use QueryBuilder\QueryBuilder;
use Symfony\Component\Yaml\Yaml;
use Thelia\Model\ModuleQuery;
use Thelia\Module\BaseModule;

/**
 * Loads the base data dictionary shipped with this module then merges the
 * Config/query_builder.yml exposed by every other active module (project overrides).
 */
final class DataDictionary
{
    public const DICTIONARY_FILENAME = 'query_builder.yml';

    private ?array $dictionary = null;

    /** @return array<string, JoinDefinition> keyed by joined table name */
    public function getJoins(): array
    {
        return $this->load()['joins'];
    }

    public function getJoin(string $table): ?JoinDefinition
    {
        return $this->load()['joins'][$table] ?? null;
    }

    /** @return array<string, FieldDefinition> keyed by field code */
    public function getFields(?Context $context = null): array
    {
        $fields = $this->load()['fields'];

        if ($context === null) {
            return $fields;
        }

        return array_filter(
            $fields,
            static fn (FieldDefinition $field): bool => $field->isAvailableInContext($context)
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

        $raw = $this->loadFile(__DIR__ . '/../Config/' . self::DICTIONARY_FILENAME);

        foreach ($this->getOverrideFiles() as $file) {
            $override = $this->loadFile($file);

            $raw['joins'] = array_replace($raw['joins'], $override['joins']);
            $raw['fields'] = array_replace($raw['fields'], $override['fields']);
            $raw['disabled'] = array_merge($raw['disabled'], $override['disabled']);
            $raw['disabled_hooks'] = array_merge($raw['disabled_hooks'], $override['disabled_hooks']);

            //Union by hook code, the last module wins on the label (a project
            //override can refine the label of a base hook)
            foreach ($override['contexts'] as $contextValue => $hooks) {
                $raw['contexts'][$contextValue] = array_replace($raw['contexts'][$contextValue] ?? [], $hooks);
            }
        }

        foreach ($raw['disabled'] as $disabledFieldCode) {
            unset($raw['fields'][$disabledFieldCode]);
        }

        //A disabled hook leaves the dictionary entirely: no longer offered by the
        //editor, dropped by the save intersect on the next edit of a bound rule.
        //Rules still bound in database keep resolving front-side (the RuleEngine
        //matches raw stored codes) and show the raw code in the back-office list.
        foreach ($raw['contexts'] as $contextValue => $hooks) {
            foreach ($raw['disabled_hooks'] as $disabledHookCode) {
                unset($raw['contexts'][$contextValue][$disabledHookCode]);
            }
        }

        return $this->dictionary = [
            'joins' => $this->buildJoins($raw['joins']),
            'fields' => $this->buildFields($raw['fields']),
            'contexts' => $raw['contexts'],
        ];
    }

    /** @return string[] */
    private function getOverrideFiles(): array
    {
        $files = [];

        $modules = ModuleQuery::create()
            ->filterByActivate(BaseModule::IS_ACTIVATED)
            ->filterByCode(QueryBuilder::getModuleCode(), Criteria::NOT_EQUAL)
            ->orderByPosition()
            ->find();

        foreach ($modules as $module) {
            $file = THELIA_MODULE_DIR . $module->getCode() . DS . 'Config' . DS . self::DICTIONARY_FILENAME;

            if (is_file($file)) {
                $files[] = $file;
            }
        }

        return $files;
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
            'disabled_hooks' => $content['disabled_hooks'] ?? [],
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
                contexts: array_map(
                    static fn (string $contextValue): Context => Context::from($contextValue),
                    $definition['contexts'] ?? []
                ),
                operators: $definition['operators'] ?? null,
                valuesQuery: $valuesQuery,
            );

            //A :value expression consumes the entered value itself: the field must
            //restrict its operators to in/notIn so the comparison path never applies
            if ($field->usesValuePlaceholder()
                && ($field->operators === null || $field->operators === [] || array_diff($field->operators, ['in', 'notIn']) !== [])
            ) {
                throw new \InvalidArgumentException(sprintf(
                    'QueryBuilder dictionary: field "%s" uses the :value placeholder, its "operators" must be declared among [in, notIn].',
                    $code
                ));
            }

            $fields[$code] = $field;
        }

        return $fields;
    }
}
