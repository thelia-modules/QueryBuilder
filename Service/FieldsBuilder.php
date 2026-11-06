<?php

declare(strict_types=1);

namespace QueryBuilder\Service;

use Propel\Runtime\Propel;
use QueryBuilder\Dictionary\FieldDefinition;
use QueryBuilder\Dictionary\Operators;
use QueryBuilder\Enum\Context;
use QueryBuilder\QueryBuilder;
use Thelia\Core\Translation\Translator;
use Thelia\Log\Tlog;

/**
 * Builds the "fields" configuration consumed by the query-builder-bundle editor
 * (OpenStudio\QueryBuilderBundle\Dto\Field shape), for a given rule context.
 */
final readonly class FieldsBuilder
{
    //Beyond this size a select is unusable: fall back to the free input. A shop's category tree
    //easily passes 300 entries, still usable in a select searched by typing
    private const MAX_VALUES = 1000;

    //The editor stores the values picked in a select as a plain equality
    private const VALUES_LIST_OPERATOR = 'valuesList';

    //The editor has no datetime type: a datetime column is entered as a date (SqlBuilder compares on DATE())
    private const VALUE_TYPES = [
        FieldDefinition::TYPE_TEXT => 'text',
        FieldDefinition::TYPE_NUMBER => 'number',
        FieldDefinition::TYPE_DATE => 'date',
        FieldDefinition::TYPE_DATETIME => 'date',
        FieldDefinition::TYPE_BOOLEAN => 'boolean',
    ];

    public function __construct(
        private DataDictionary $dataDictionary,
    ) {
    }

    //Between the translated group of a field and its label
    private const GROUP_SEPARATOR = ' / ';

    /**
     * Fields of the given context and editor usage (FieldDefinition::USAGE_*),
     * every field when null (the form option must accept every field: the
     * context and usage restrictions are enforced by SqlBuilder::validateTree()).
     * Labels are prefixed with the translated group of the field (the part of
     * its code before the first underscore), so the alphabetical order of the
     * editor gathers the fields of a same group.
     *
     * @return list<array{name: string, type: string, label: string, labelInformation: ?string, values: ?list<array{name: string, label: string}>, operators: list<string>}>
     */
    public function buildForContext(?Context $context, string $locale = 'fr_FR', ?string $usage = null): array
    {
        $fields = [];

        foreach ($this->dataDictionary->getFields($context, $usage) as $field) {
            $fields[] = $this->buildField($field, $locale);
        }

        usort($fields, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $fields;
    }

    /** @return array<string, list<array>> keyed by context value */
    public function buildForAllContexts(string $locale = 'fr_FR', ?string $usage = null): array
    {
        $fieldsByContext = [];

        foreach (Context::cases() as $context) {
            $fieldsByContext[$context->value] = $this->buildForContext($context, $locale, $usage);
        }

        return $fieldsByContext;
    }

    private function buildField(FieldDefinition $field, string $locale): array
    {
        $operators = Operators::forField($field);
        $values = $field->valuesQuery !== null ? $this->fetchValues($field, $locale) : null;

        if ($values !== null) {
            //A field with a list of values gets the select editor first, the free operators after
            array_unshift($operators, self::VALUES_LIST_OPERATOR);
        }

        return [
            'name' => $field->code,
            'type' => self::VALUE_TYPES[$field->type] ?? 'text',
            'label' => $this->translateGroup($field) . self::GROUP_SEPARATOR . Translator::getInstance()->trans($field->label, [], QueryBuilder::DOMAIN_NAME),
            'labelInformation' => null,
            'values' => $values,
            'operators' => $operators,
        ];
    }

    /** The group is translated through its capitalized code ("cart" => "Cart"), shown as is without translation. */
    private function translateGroup(FieldDefinition $field): string
    {
        return Translator::getInstance()->trans(ucfirst($field->getGroup()), [], QueryBuilder::DOMAIN_NAME);
    }

    /**
     * Runs the values_query of the field ("value" + optional "label" columns; a
     * "group" column is accepted but flattened, the editor has no option groups).
     * Returns null (free input fallback) on SQL error, oversized or empty result:
     * the editor must stay usable even when a referential misbehaves.
     *
     * @return list<array{name: string, label: string}>|null
     */
    private function fetchValues(FieldDefinition $field, string $locale): ?array
    {
        try {
            $statement = Propel::getConnection()->prepare((string) $field->valuesQuery);

            if (str_contains((string) $field->valuesQuery, ':locale')) {
                $statement->bindValue(':locale', $locale);
            }

            $statement->execute();
            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $exception) {
            Tlog::getInstance()->error('QueryBuilder: values_query failed, falling back to free input', [
                'field' => $field->code,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($rows === []) {
            return null;
        }

        if (\count($rows) > self::MAX_VALUES) {
            Tlog::getInstance()->addWarning(sprintf(
                'QueryBuilder: values_query of field "%s" returned %d rows (max %d), falling back to free input.',
                $field->code,
                \count($rows),
                self::MAX_VALUES
            ));

            return null;
        }

        $values = [];

        foreach ($rows as $row) {
            if (!\array_key_exists('value', $row)) {
                Tlog::getInstance()->error('QueryBuilder: values_query must expose a "value" column', [
                    'field' => $field->code,
                ]);

                return null;
            }

            $value = (string) $row['value'];
            $values[] = ['name' => $value, 'label' => (string) ($row['label'] ?? $value)];
        }

        return $values;
    }
}
