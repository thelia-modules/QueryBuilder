<?php

declare(strict_types=1);

namespace QueryBuilder\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QueryBuilder\Dictionary\FieldDefinition;
use QueryBuilder\Enum\Context;
use QueryBuilder\Query\RuntimeContext;
use QueryBuilder\Service\DataDictionary;
use QueryBuilder\Service\SqlBuilder;
use QueryBuilder\Tests\Support\DictionaryFactory;

final class SqlBuilderTest extends TestCase
{
    private const PROJECT_OVERRIDE = <<<YAML
        fields:
            cart_only_field:
                label: "Cart only"
                field: product.ref
                contexts: [CART]
            bought_category:
                label: "Category already bought"
                expression: "product.id IN (SELECT p.id FROM product p WHERE p.ref IN (:value))"
                operators: [in, notIn]
            bought_brand:
                label: "Brand already bought"
                expression: "product.brand_id IN (:value)"
                type: number
                operators: [notIn]
            bought_ref:
                label: "Reference already bought"
                expression: "product.ref IN (:value)"
                operators: ['=', '!=']
            selection_only:
                label: "Selection only"
                field: product.ref
                usage: [action]
        YAML;

    protected function tearDown(): void
    {
        DictionaryFactory::cleanUp();
    }

    #[Test]
    public function aColumnComparisonBindsItsValue(): void
    {
        $compiled = $this->builder()->compile(self::rule('product_ref', '=', 'ABC'), new RuntimeContext());

        self::assertStringStartsWith('SELECT DISTINCT `product`.`id` FROM `product`', $compiled->sql);
        self::assertStringContainsString('WHERE (`product`.`ref` = :qb_0)', $compiled->sql);
        self::assertSame(['qb_0' => 'ABC'], $compiled->parameters);
    }

    #[Test]
    public function aFieldOfAJoinedTableAddsTheJoinChainAndBindsTheLocale(): void
    {
        $compiled = $this->builder()->compile(self::rule('product_title', 'contains', 'tee'), new RuntimeContext(locale: 'en_US'));

        self::assertStringContainsString(
            'INNER JOIN `product_i18n` ON `product`.`id` = `product_i18n`.`id` AND product_i18n.locale = :locale',
            $compiled->sql
        );
        self::assertStringContainsString('`product_i18n`.`title` LIKE :qb_0', $compiled->sql);
        self::assertSame(['qb_0' => '%tee%', 'locale' => 'en_US'], $compiled->parameters);
    }

    #[Test]
    public function aNegativeOperatorOnAMultivaluedJoinExcludesTheWholeProduct(): void
    {
        $compiled = $this->builder()->compile(self::rule('product_category_title', 'notIn', ['Shoes']), new RuntimeContext());

        self::assertStringContainsString('NOT EXISTS (SELECT 1 FROM `product_category`', $compiled->sql);
        self::assertStringContainsString('WHERE `product_category`.`product_id` = `product`.`id` AND `category_i18n`.`title` IN (:qb_0))', $compiled->sql);
        self::assertStringNotContainsString("\nINNER JOIN `product_category`", $compiled->sql, 'a multivalued join must not be added to the main FROM');
        self::assertSame('Shoes', $compiled->parameters['qb_0']);
    }

    #[Test]
    public function aListPlaceholderIsExpandedToOneParameterPerItem(): void
    {
        $compiled = $this->builder()->compile(self::rule('product_in_cart', '=', true), new RuntimeContext(cartProductIds: [4, 9]));

        self::assertStringContainsString('(product.id IN (:cart_product_ids_0, :cart_product_ids_1)) = :qb_0', $compiled->sql);
        self::assertSame(['qb_0' => 1, 'cart_product_ids_0' => 4, 'cart_product_ids_1' => 9], $compiled->parameters);
    }

    #[Test]
    public function anEmptyListPlaceholderBindsASentinelThatNeverMatches(): void
    {
        $compiled = $this->builder()->compile(self::rule('product_in_cart', '=', 'true'), new RuntimeContext());

        self::assertStringContainsString('IN (:cart_product_ids_0)', $compiled->sql);
        self::assertSame(-1, $compiled->parameters['cart_product_ids_0']);
    }

    #[Test]
    public function aMissingRuntimePlaceholderIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(':customer_id');

        $this->builder()->compile(self::rule('customer_purchased_last_3_months', '=', true), new RuntimeContext(customerId: null));
    }

    #[Test]
    public function theCurrentProductPlaceholderFallsBackToZeroOutsideAProductPage(): void
    {
        $compiled = $this->builder()->compile(self::rule('product_is_current', '=', false), new RuntimeContext());

        self::assertSame(0, $compiled->parameters['product_id']);
        self::assertSame(0, $compiled->parameters['qb_0']);
    }

    #[Test]
    public function aDatetimeColumnIsComparedOnItsDatePart(): void
    {
        $compiled = $this->builder()->compile(self::rule('product_created_at', '>=', '2026-01-01'), new RuntimeContext());

        self::assertStringContainsString('DATE(`product`.`created_at`) >= :qb_0', $compiled->sql);
        self::assertSame('2026-01-01', $compiled->parameters['qb_0']);
    }

    #[Test]
    public function theCartTotalIsComparedNumerically(): void
    {
        $compiled = $this->builder()->compile(self::rule('cart_total_amount', '>=', '100'), new RuntimeContext(cartTotal: 120.5));

        self::assertStringContainsString('(CAST(:cart_total AS DECIMAL(16, 6))) >= :qb_0', $compiled->sql);
        self::assertSame(100, $compiled->parameters['qb_0']);
        self::assertSame(120.5, $compiled->parameters['cart_total']);
    }

    #[Test]
    public function aCommaSeparatedListFromTheEditorIsSplit(): void
    {
        $compiled = $this->builder()->compile(self::rule('product_ref', 'in', 'A, B'), new RuntimeContext());

        self::assertStringContainsString('`product`.`ref` IN (:qb_0, :qb_1)', $compiled->sql);
        self::assertSame(['qb_0' => 'A', 'qb_1' => 'B'], $compiled->parameters);
    }

    #[Test]
    public function aNegatedGroupWrapsItsClausesInNot(): void
    {
        $tree = ['combinator' => 'or', 'not' => true, 'rules' => [
            ['field' => 'product_ref', 'operator' => '=', 'value' => 'A'],
            ['field' => 'product_ref', 'operator' => 'beginsWith', 'value' => 'B'],
        ]];

        $compiled = $this->builder()->compile($tree, new RuntimeContext());

        self::assertStringContainsString('WHERE NOT (`product`.`ref` = :qb_0 OR `product`.`ref` LIKE :qb_1)', $compiled->sql);
        self::assertSame('B%', $compiled->parameters['qb_1']);
    }

    #[Test]
    public function anEmptyGroupIsAlwaysTrueAndTheLimitIsAppended(): void
    {
        $compiled = $this->builder()->compile(['combinator' => 'and', 'rules' => []], new RuntimeContext(), 3, ['`product`.`visible` = 1']);

        self::assertStringContainsString("WHERE 1=1\n  AND `product`.`visible` = 1\nLIMIT 3", $compiled->sql);
    }

    #[Test]
    public function anUnknownFieldIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown field "product.ref; DROP TABLE product"');

        $this->builder()->validateTree(self::rule('product.ref; DROP TABLE product', '=', 'x'));
    }

    #[Test]
    public function anOperatorOutsideTheFieldWhitelistIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('operator "contains" is not allowed for field "product_visible"');

        $this->builder()->validateTree(self::rule('product_visible', 'contains', 'x'));
    }

    #[Test]
    public function aBetweenNeedsExactlyTwoValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly two values');

        $this->builder()->compile(self::rule('product_price', 'between', '10'), new RuntimeContext());
    }

    #[Test]
    public function aFieldRestrictedToAnotherContextIsRefusedOnValidation(): void
    {
        $builder = $this->builder(DictionaryFactory::withOverrides(self::PROJECT_OVERRIDE));
        $tree = self::rule('cart_only_field', '=', 'x');

        $builder->validateTree($tree, Context::CART);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not available in context PRODUCT');
        $builder->validateTree($tree, Context::PRODUCT);
    }

    #[Test]
    public function aFieldReservedToTheActionEditorIsRefusedInTheRuleEditor(): void
    {
        $builder = $this->builder(DictionaryFactory::withOverrides(self::PROJECT_OVERRIDE));
        $tree = self::rule('selection_only', '=', 'x');

        $builder->validateTree($tree, Context::PRODUCT, FieldDefinition::USAGE_ACTION);
        $builder->validateTree($tree, Context::PRODUCT);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not available in the rule editor');
        $builder->validateTree($tree, Context::PRODUCT, FieldDefinition::USAGE_RULE);
    }

    #[Test]
    public function aContextFieldBindsTheContextPlaceholderInsideItsExistsSubquery(): void
    {
        $compiled = $this->builder()->compile(
            self::rule('context_product_same_brand', '=', true),
            new RuntimeContext(productId: 42)
        );

        self::assertStringContainsString('(EXISTS (SELECT 1 FROM product qb_ctx WHERE qb_ctx.id = :product_id AND qb_ctx.brand_id IS NOT NULL AND qb_ctx.brand_id = product.brand_id)) = :qb_0', $compiled->sql);
        self::assertSame(42, $compiled->parameters['product_id']);
        self::assertSame(1, $compiled->parameters['qb_0']);
    }

    #[Test]
    public function aValueExpressionConsumesTheEnteredListAndTheOperatorOnlySetsThePolarity(): void
    {
        $builder = $this->builder(DictionaryFactory::withOverrides(self::PROJECT_OVERRIDE));

        $kept = $builder->compile(self::rule('bought_category', 'in', ['A', 'B']), new RuntimeContext());
        self::assertStringContainsString('WHERE ((product.id IN (SELECT p.id FROM product p WHERE p.ref IN (:qb_0, :qb_1))))', $kept->sql);
        self::assertSame(['qb_0' => 'A', 'qb_1' => 'B'], $kept->parameters);

        $excluded = $builder->compile(self::rule('bought_category', 'notIn', ['A']), new RuntimeContext());
        self::assertStringContainsString('WHERE (NOT (product.id IN (SELECT p.id FROM product p WHERE p.ref IN (:qb_0))))', $excluded->sql);

        $emptyKept = $builder->compile(self::rule('bought_category', 'in', []), new RuntimeContext());
        self::assertStringContainsString('WHERE (1=0)', $emptyKept->sql, 'an empty list keeps nothing');

        $emptyExcluded = $builder->compile(self::rule('bought_category', 'notIn', []), new RuntimeContext());
        self::assertStringContainsString('WHERE (1=1)', $emptyExcluded->sql, 'an empty list excludes nothing');
    }

    #[Test]
    public function anEqualityOnAValueExpressionIsAOneItemInclusion(): void
    {
        $builder = $this->builder(DictionaryFactory::withOverrides(self::PROJECT_OVERRIDE));

        //The "list of values" pick of the editor is stored as the equality it stands for
        $kept = $builder->compile(self::rule('bought_category', '=', 'A'), new RuntimeContext());
        self::assertStringContainsString('WHERE ((product.id IN (SELECT p.id FROM product p WHERE p.ref IN (:qb_0))))', $kept->sql);
        self::assertSame(['qb_0' => 'A'], $kept->parameters);

        $excluded = $builder->compile(self::rule('bought_category', '!=', 'A'), new RuntimeContext());
        self::assertStringContainsString('WHERE (NOT (product.id IN (SELECT p.id FROM product p WHERE p.ref IN (:qb_0))))', $excluded->sql);
        self::assertSame(['qb_0' => 'A'], $excluded->parameters);
    }

    #[Test]
    public function anEqualityOnAValueExpressionFollowsTheDeclaredPolarities(): void
    {
        $builder = $this->builder(DictionaryFactory::withOverrides(self::PROJECT_OVERRIDE));

        $excluded = $builder->compile(self::rule('bought_brand', '!=', '3'), new RuntimeContext());
        self::assertStringContainsString('WHERE (NOT (product.brand_id IN (:qb_0)))', $excluded->sql);
        self::assertSame(['qb_0' => 3], $excluded->parameters);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('operator "=" is not allowed for field "bought_brand"');

        $builder->compile(self::rule('bought_brand', '=', '3'), new RuntimeContext());
    }

    #[Test]
    public function aValueExpressionMayDeclareTheEqualitiesOutright(): void
    {
        $builder = $this->builder(DictionaryFactory::withOverrides(self::PROJECT_OVERRIDE));

        $kept = $builder->compile(self::rule('bought_ref', '=', 'A'), new RuntimeContext());
        self::assertStringContainsString('WHERE ((product.ref IN (:qb_0)))', $kept->sql);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('operator "in" is not allowed for field "bought_ref"');

        $builder->compile(self::rule('bought_ref', 'in', ['A']), new RuntimeContext());
    }

    private function builder(?DataDictionary $dictionary = null): SqlBuilder
    {
        return new SqlBuilder($dictionary ?? DictionaryFactory::base());
    }

    /** @return array{combinator: string, rules: list<array{field: string, operator: string, value: mixed}>} */
    private static function rule(string $field, string $operator, mixed $value): array
    {
        return ['combinator' => 'and', 'rules' => [['field' => $field, 'operator' => $operator, 'value' => $value]]];
    }
}
