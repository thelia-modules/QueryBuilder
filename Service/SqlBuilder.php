<?php

declare(strict_types=1);

namespace QueryBuilder\Service;

use Propel\Runtime\Propel;
use QueryBuilder\Dictionary\FieldDefinition;
use QueryBuilder\Dictionary\JoinDefinition;
use QueryBuilder\Dictionary\Operators;
use QueryBuilder\Enum\Context;
use QueryBuilder\Query\CompiledQuery;
use QueryBuilder\Query\QueryParts;
use QueryBuilder\Query\QueryScopeInterface;
use QueryBuilder\Query\RuntimeContext;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

/**
 * Compiles a react-querybuilder condition tree into a fully parameterized SQL
 * query returning product ids. Only fields declared in the data dictionary and
 * whitelisted operators are accepted: no SQL ever comes from the stored JSON.
 */
final readonly class SqlBuilder
{
    private const BASE_TABLE = 'product';
    private const IDENTIFIER_PATTERN = '/^[A-Za-z0-9_]+$/';
    //Bound in place of an empty id list so "IN ()" stays valid and never matches
    private const EMPTY_LIST_SENTINEL = -1;

    /** @param iterable<QueryScopeInterface> $queryScopes */
    public function __construct(
        private DataDictionary $dataDictionary,
        #[TaggedIterator(QueryScopeInterface::TAG)]
        private iterable $queryScopes = [],
    ) {
    }

    /**
     * @param string[] $extraWhere trusted SQL clauses (never user input), may reference runtime placeholders
     * @param string[] $orderBy    trusted "expression ASC|DESC" clauses (never user input), may reference
     *                             runtime placeholders. GROUP BY product.id (not DISTINCT) deduplicates the
     *                             rows: strict MySQL rejects ORDER BY expressions outside a DISTINCT select
     *                             list, while correlated subqueries are deterministic per grouped product id
     */
    public function compile(?array $conditionTree, RuntimeContext $runtimeContext, ?int $limit = null, array $extraWhere = [], array $orderBy = []): CompiledQuery
    {
        $queryParts = new QueryParts();

        foreach ($this->queryScopes as $queryScope) {
            $queryScope->apply($queryParts, $runtimeContext);
        }

        if ($conditionTree !== null) {
            $queryParts->addWhere($this->compileGroup($conditionTree, $queryParts));
        }

        foreach ($extraWhere as $clause) {
            $queryParts->addWhere($clause);
        }

        $sql = 'SELECT `product`.`id` FROM `product`';

        foreach ($queryParts->getJoins() as $joinClause) {
            $sql .= "\n" . $joinClause;
        }

        if ($queryParts->getWhere() !== []) {
            $sql .= "\nWHERE " . implode("\n  AND ", $queryParts->getWhere());
        }

        $sql .= "\nGROUP BY `product`.`id`";

        if ($orderBy !== []) {
            $sql .= "\nORDER BY " . implode(', ', $orderBy);
        }

        if ($limit !== null) {
            $sql .= "\nLIMIT " . max(1, $limit);
        }

        return $this->bindRuntimeParameters($sql, $queryParts, $runtimeContext);
    }

    /**
     * @param string[] $extraWhere trusted SQL clauses (never user input)
     * @param string[] $orderBy    trusted "expression ASC|DESC" clauses (never user input)
     * @return int[]
     */
    public function getProductIds(?array $conditionTree, RuntimeContext $runtimeContext, ?int $limit = null, array $extraWhere = [], array $orderBy = []): array
    {
        $compiledQuery = $this->compile($conditionTree, $runtimeContext, $limit, $extraWhere, $orderBy);

        $connection = Propel::getConnection();
        $statement = $connection->prepare($compiledQuery->sql);

        foreach ($compiledQuery->parameters as $name => $value) {
            $statement->bindValue(
                ':' . $name,
                $value,
                \is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR
            );
        }

        $statement->execute();

        return array_map('intval', $statement->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @param string[] $extraWhere trusted SQL clauses (never user input) */
    public function exists(?array $conditionTree, RuntimeContext $runtimeContext, array $extraWhere = []): bool
    {
        return $this->getProductIds($conditionTree, $runtimeContext, 1, $extraWhere) !== [];
    }

    /**
     * Structural validation of a condition tree (known fields, allowed
     * operators, resolvable joins) without binding runtime values. When a
     * context is given, fields restricted to other contexts are rejected —
     * the editor field list is filtered per context, but the posted JSON
     * must not be trusted to honor that filter.
     *
     * @throws \InvalidArgumentException when the tree is invalid
     */
    public function validateTree(?array $conditionTree, ?Context $context = null): void
    {
        if ($conditionTree === null) {
            return;
        }

        $this->compileGroup($conditionTree, new QueryParts(), $context);
    }

    private function compileGroup(array $group, QueryParts $queryParts, ?Context $context = null): string
    {
        $combinator = strtoupper((string) ($group['combinator'] ?? 'AND'));

        if (!\in_array($combinator, ['AND', 'OR'], true)) {
            throw new \InvalidArgumentException(sprintf('QueryBuilder: unknown combinator "%s".', $combinator));
        }

        $clauses = [];

        foreach ($group['rules'] ?? [] as $rule) {
            if (!\is_array($rule)) {
                continue;
            }

            $clauses[] = isset($rule['rules'])
                ? $this->compileGroup($rule, $queryParts, $context)
                : $this->compileRule($rule, $queryParts, $context);
        }

        if ($clauses === []) {
            return '1=1';
        }

        $sql = '(' . implode(' ' . $combinator . ' ', $clauses) . ')';

        if (!empty($group['not'])) {
            $sql = 'NOT ' . $sql;
        }

        return $sql;
    }

    private function compileRule(array $rule, QueryParts $queryParts, ?Context $context = null): string
    {
        $fieldCode = (string) ($rule['field'] ?? '');
        $field = $this->dataDictionary->getField($fieldCode);

        if ($field === null) {
            throw new \InvalidArgumentException(sprintf('QueryBuilder: unknown field "%s".', $fieldCode));
        }

        if ($context !== null && !$field->isAvailableInContext($context)) {
            throw new \InvalidArgumentException(sprintf(
                'QueryBuilder: field "%s" is not available in context %s.',
                $fieldCode,
                $context->value
            ));
        }

        $operator = (string) ($rule['operator'] ?? '');

        if (!\in_array($operator, Operators::forField($field), true)) {
            throw new \InvalidArgumentException(sprintf(
                'QueryBuilder: operator "%s" is not allowed for field "%s".',
                $operator,
                $fieldCode
            ));
        }

        if ($field->column !== null && $this->fieldJoinChain($field) !== []) {
            return $this->compileMultivaluedComparison($field, $operator, $rule['value'] ?? null, $queryParts);
        }

        if ($field->usesValuePlaceholder()) {
            return $this->compileValueInExpression($field, $operator, $rule['value'] ?? null, $queryParts);
        }

        return $this->compileComparison(
            $this->compileLeftHandSide($field, $queryParts),
            $operator,
            $rule['value'] ?? null,
            $field,
            $queryParts
        );
    }

    /**
     * Ordered join chain of a column field when it crosses a multivalued (1-N)
     * join, empty otherwise. Multivaluedness propagates transitively: any table
     * reached through a multivalued hop yields several rows per product.
     *
     * @return JoinDefinition[]
     */
    private function fieldJoinChain(FieldDefinition $field): array
    {
        [$table] = explode('.', (string) $field->column, 2);

        if ($table === self::BASE_TABLE) {
            return [];
        }

        $chain = $this->resolveJoinChain($table, []);

        foreach ($chain as $join) {
            if ($join->multivalued) {
                return $chain;
            }
        }

        return [];
    }

    /**
     * A comparison on a field reached through a 1-N join must be evaluated per
     * product, not per joined row: "category notIn [X]" would otherwise match
     * any OTHER category attached to the product. Negative operators compile
     * to NOT EXISTS around the positive comparison; null/notNull keep their
     * positive form ("has a row with a NULL/non-NULL value" — ambiguous on
     * multivalued fields, out of scope here).
     */
    private function compileMultivaluedComparison(
        FieldDefinition $field,
        string $operator,
        mixed $value,
        QueryParts $queryParts,
    ): string {
        $negatedOperators = ['!=' => '=', 'notIn' => 'in', 'notBetween' => 'between', 'doesNotContain' => 'contains'];

        [$table, $column] = explode('.', (string) $field->column, 2);

        if (!preg_match(self::IDENTIFIER_PATTERN, $table) || !preg_match(self::IDENTIFIER_PATTERN, $column)) {
            throw new \InvalidArgumentException(sprintf(
                'QueryBuilder dictionary: invalid identifier in field "%s".',
                $field->code
            ));
        }

        $chain = $this->fieldJoinChain($field);
        $comparison = $this->compileComparison(
            sprintf('`%s`.`%s`', $table, $column),
            $negatedOperators[$operator] ?? $operator,
            $value,
            $field,
            $queryParts
        );

        $anchor = $chain[0];
        $subquery = sprintf('SELECT 1 FROM `%s`', $anchor->table);

        foreach (\array_slice($chain, 1) as $join) {
            $subquery .= ' ' . $this->buildJoinClause($join);
        }

        $subquery .= sprintf(
            ' WHERE `%s`.`%s` = `%s`.`%s`%s AND %s',
            $anchor->table,
            $anchor->toColumn,
            self::BASE_TABLE,
            $anchor->fromColumn,
            $anchor->extraOn !== null ? ' AND ' . $anchor->extraOn : '',
            $comparison
        );

        return sprintf(
            '%s (%s)',
            isset($negatedOperators[$operator]) ? 'NOT EXISTS' : 'EXISTS',
            $subquery
        );
    }

    /**
     * A :value expression consumes the entered list itself (expanded to one
     * bound placeholder per item), the operator only selecting the polarity:
     * "in" keeps the products matching the expression, "notIn" the others.
     */
    private function compileValueInExpression(
        FieldDefinition $field,
        string $operator,
        mixed $value,
        QueryParts $queryParts,
    ): string {
        $placeholders = array_map(
            fn (mixed $item): string => $queryParts->bindValue($this->normalizeValue($item, $field)),
            $this->normalizeList($value)
        );

        if ($placeholders === []) {
            return $operator === 'in' ? '1=0' : '1=1';
        }

        $expression = (string) preg_replace(
            '/:value\b/',
            implode(', ', $placeholders),
            (string) $field->expression
        );

        return ($operator === 'in' ? '' : 'NOT ') . '(' . $expression . ')';
    }

    private function compileLeftHandSide(FieldDefinition $field, QueryParts $queryParts): string
    {
        if ($field->expression !== null) {
            return '(' . $field->expression . ')';
        }

        [$table, $column] = explode('.', (string) $field->column, 2);

        if (!preg_match(self::IDENTIFIER_PATTERN, $table) || !preg_match(self::IDENTIFIER_PATTERN, $column)) {
            throw new \InvalidArgumentException(sprintf(
                'QueryBuilder dictionary: invalid identifier in field "%s".',
                $field->code
            ));
        }

        $this->collectJoinChain($table, $queryParts, []);

        return sprintf('`%s`.`%s`', $table, $column);
    }

    /** @param string[] $visiting */
    private function collectJoinChain(string $table, QueryParts $queryParts, array $visiting): void
    {
        foreach ($this->resolveJoinChain($table, $visiting) as $join) {
            $queryParts->addJoin($join->table, $this->buildJoinClause($join));
        }
    }

    /**
     * Ordered chain of joins linking "product" to the given table.
     *
     * @param string[] $visiting
     * @return JoinDefinition[]
     */
    private function resolveJoinChain(string $table, array $visiting): array
    {
        if ($table === self::BASE_TABLE) {
            return [];
        }

        if (\in_array($table, $visiting, true)) {
            throw new \LogicException(sprintf('QueryBuilder dictionary: circular join path around "%s".', $table));
        }

        $join = $this->dataDictionary->getJoin($table);

        if ($join === null) {
            throw new \InvalidArgumentException(sprintf(
                'QueryBuilder dictionary: no join path from "product" to table "%s".',
                $table
            ));
        }

        //The source table of the join must be resolved first
        return [...$this->resolveJoinChain($join->fromTable, [...$visiting, $table]), $join];
    }

    private function buildJoinClause(JoinDefinition $join): string
    {
        foreach ([$join->table, $join->fromTable, $join->fromColumn, $join->toColumn] as $identifier) {
            if (!preg_match(self::IDENTIFIER_PATTERN, $identifier)) {
                throw new \InvalidArgumentException(sprintf(
                    'QueryBuilder dictionary: invalid identifier "%s" in join "%s".',
                    $identifier,
                    $join->table
                ));
            }
        }

        if (!\in_array($join->type, [JoinDefinition::TYPE_INNER, JoinDefinition::TYPE_LEFT, JoinDefinition::TYPE_RIGHT], true)) {
            throw new \InvalidArgumentException(sprintf(
                'QueryBuilder dictionary: invalid join type "%s" for table "%s".',
                $join->type,
                $join->table
            ));
        }

        return sprintf(
            '%s JOIN `%s` ON `%s`.`%s` = `%s`.`%s`%s',
            $join->type,
            $join->table,
            $join->fromTable,
            $join->fromColumn,
            $join->table,
            $join->toColumn,
            $join->extraOn !== null ? ' AND ' . $join->extraOn : ''
        );
    }

    private function compileComparison(
        string $leftHandSide,
        string $operator,
        mixed $value,
        FieldDefinition $field,
        QueryParts $queryParts,
    ): string {
        switch ($operator) {
            case 'null':
                return $leftHandSide . ' IS NULL';
            case 'notNull':
                return $leftHandSide . ' IS NOT NULL';
            case '=':
            case '!=':
            case '<':
            case '<=':
            case '>':
            case '>=':
                return sprintf(
                    '%s %s %s',
                    $leftHandSide,
                    $operator === '!=' ? '<>' : $operator,
                    $queryParts->bindValue($this->normalizeValue($value, $field))
                );
            case 'contains':
            case 'doesNotContain':
                return sprintf(
                    '%s %s %s',
                    $leftHandSide,
                    $operator === 'contains' ? 'LIKE' : 'NOT LIKE',
                    $queryParts->bindValue('%' . $this->escapeLikeValue($value) . '%')
                );
            case 'beginsWith':
                return $leftHandSide . ' LIKE ' . $queryParts->bindValue($this->escapeLikeValue($value) . '%');
            case 'endsWith':
                return $leftHandSide . ' LIKE ' . $queryParts->bindValue('%' . $this->escapeLikeValue($value));
            case 'between':
            case 'notBetween':
                [$lower, $upper] = $this->normalizeRange($value, $field);

                return sprintf(
                    '%s %s %s AND %s',
                    $leftHandSide,
                    $operator === 'between' ? 'BETWEEN' : 'NOT BETWEEN',
                    $queryParts->bindValue($lower),
                    $queryParts->bindValue($upper)
                );
            case 'in':
            case 'notIn':
                $placeholders = array_map(
                    fn (mixed $item): string => $queryParts->bindValue($this->normalizeValue($item, $field)),
                    $this->normalizeList($value)
                );

                if ($placeholders === []) {
                    return $operator === 'in' ? '1=0' : '1=1';
                }

                return sprintf(
                    '%s %s (%s)',
                    $leftHandSide,
                    $operator === 'in' ? 'IN' : 'NOT IN',
                    implode(', ', $placeholders)
                );
            default:
                throw new \InvalidArgumentException(sprintf('QueryBuilder: unsupported operator "%s".', $operator));
        }
    }

    private function normalizeValue(mixed $value, FieldDefinition $field): int|float|string
    {
        return match ($field->type) {
            FieldDefinition::TYPE_BOOLEAN => (int) filter_var($value, \FILTER_VALIDATE_BOOLEAN),
            FieldDefinition::TYPE_NUMBER => \is_string($value) && str_contains($value, '.')
                ? (float) $value
                : (int) $value,
            default => (string) $value,
        };
    }

    /** @return array{0: int|float|string, 1: int|float|string} */
    private function normalizeRange(mixed $value, FieldDefinition $field): array
    {
        $items = $this->normalizeList($value);

        if (\count($items) !== 2) {
            throw new \InvalidArgumentException('QueryBuilder: a between operator requires exactly two values.');
        }

        return [
            $this->normalizeValue($items[0], $field),
            $this->normalizeValue($items[1], $field),
        ];
    }

    /** @return list<mixed> */
    private function normalizeList(mixed $value): array
    {
        if (\is_array($value)) {
            return array_values($value);
        }

        if (\is_string($value)) {
            return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== ''));
        }

        return $value === null ? [] : [$value];
    }

    private function escapeLikeValue(mixed $value): string
    {
        return addcslashes((string) $value, '%_\\');
    }

    /**
     * Replaces the runtime placeholders (:customer_id, :locale, :cart_product_ids...)
     * used by dictionary expressions, join clauses and scopes with bound values.
     * List values are expanded into one placeholder per item.
     */
    private function bindRuntimeParameters(string $sql, QueryParts $queryParts, RuntimeContext $runtimeContext): CompiledQuery
    {
        $parameters = $queryParts->getParameters();
        $bindable = $runtimeContext->getBindableParameters();

        preg_match_all('/:([a-z][a-z0-9_]*)/i', $sql, $matches);

        foreach (array_unique($matches[1]) as $placeholder) {
            if (isset($parameters[$placeholder])) {
                continue;
            }

            if (!\array_key_exists($placeholder, $bindable)) {
                throw new \InvalidArgumentException(sprintf(
                    'QueryBuilder: unknown runtime placeholder ":%s" — not provided by the runtime context.',
                    $placeholder
                ));
            }

            $value = $bindable[$placeholder];

            if (\is_array($value)) {
                $values = $value === [] ? [self::EMPTY_LIST_SENTINEL] : array_values($value);
                $expandedPlaceholders = [];

                foreach ($values as $index => $item) {
                    $expandedName = $placeholder . '_' . $index;
                    $parameters[$expandedName] = \is_int($item) || \is_float($item) ? $item : (string) $item;
                    $expandedPlaceholders[] = ':' . $expandedName;
                }

                $sql = preg_replace(
                    '/:' . preg_quote($placeholder, '/') . '\b/',
                    implode(', ', $expandedPlaceholders),
                    $sql
                );

                continue;
            }

            if ($value === null) {
                throw new \InvalidArgumentException(sprintf(
                    'QueryBuilder: runtime placeholder ":%s" is required but has no value in the current context.',
                    $placeholder
                ));
            }

            $parameters[$placeholder] = $value;
        }

        return new CompiledQuery($sql, $parameters);
    }
}
