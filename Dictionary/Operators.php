<?php

declare(strict_types=1);

namespace QueryBuilder\Dictionary;

/**
 * Single source of truth for the operators allowed per field type, shared by
 * the back-office editor (FieldsBuilder) and the SQL generation (SqlBuilder whitelist).
 * Operator names follow the react-querybuilder convention; their labels come from
 * the query-builder-bundle editor.
 */
final class Operators
{
    public const BY_TYPE = [
        FieldDefinition::TYPE_TEXT => [
            '=', '!=', 'contains', 'doesNotContain', 'beginsWith', 'endsWith',
            'in', 'notIn', 'null', 'notNull',
        ],
        FieldDefinition::TYPE_NUMBER => [
            '=', '!=', '<', '<=', '>', '>=', 'between', 'notBetween', 'in', 'notIn', 'null', 'notNull',
        ],
        FieldDefinition::TYPE_DATE => [
            '=', '!=', '<', '<=', '>', '>=', 'between', 'notBetween', 'null', 'notNull',
        ],
        FieldDefinition::TYPE_DATETIME => [
            '=', '!=', '<', '<=', '>', '>=', 'between', 'notBetween', 'null', 'notNull',
        ],
        FieldDefinition::TYPE_BOOLEAN => [
            '=',
        ],
    ];

    /**
     * On a :value expression an equality is the inclusion of a single item: the
     * editor stores its "list of values" pick as the "=" it stands for, and a
     * project may declare "=" and "!=" outright. Keys are the equalities a rule
     * may carry, values the polarity that compiles them.
     */
    public const VALUE_EXPRESSION_INCLUSIONS = ['=' => 'in', '!=' => 'notIn'];

    private function __construct()
    {
    }

    /** @return string[] */
    public static function forField(FieldDefinition $field): array
    {
        $allowed = self::BY_TYPE[$field->type] ?? self::BY_TYPE[FieldDefinition::TYPE_TEXT];

        if ($field->operators === null) {
            return $allowed;
        }

        return array_values(array_intersect($field->operators, $allowed));
    }

    /**
     * Whether a rule on the field may carry the operator. On a :value expression
     * an equality also passes when the inclusion it compiles to is declared.
     */
    public static function isAllowed(FieldDefinition $field, string $operator): bool
    {
        $allowed = self::forField($field);

        if (\in_array($operator, $allowed, true)) {
            return true;
        }

        return $field->usesValuePlaceholder()
            && \in_array(self::forValueExpression($operator), $allowed, true);
    }

    /** The polarity compiling the operator on a :value expression: an equality becomes its inclusion. */
    public static function forValueExpression(string $operator): string
    {
        return self::VALUE_EXPRESSION_INCLUSIONS[$operator] ?? $operator;
    }
}
