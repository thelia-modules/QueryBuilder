<?php

declare(strict_types=1);

namespace QueryBuilder\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QueryBuilder\Dictionary\FieldDefinition;
use QueryBuilder\Enum\Context;
use QueryBuilder\Tests\Support\DictionaryFactory;

final class DataDictionaryTest extends TestCase
{
    protected function tearDown(): void
    {
        DictionaryFactory::cleanUp();
    }

    #[Test]
    public function theBaseDictionaryDeclaresTheFlexyHooksPerContext(): void
    {
        $dictionary = DictionaryFactory::base();

        self::assertSame(['product.top', 'product.details.bottom', 'product.bottom'], array_keys($dictionary->getHooks(Context::PRODUCT)));
        self::assertSame(['cart.top', 'cart.bottom'], array_keys($dictionary->getHooks(Context::CART)));
        self::assertSame(Context::CUSTOMER, $dictionary->getContextForHook('account.bottom'));
        self::assertNull($dictionary->getContextForHook('unknown.hook'));
    }

    #[Test]
    public function aGlobalRuleSeesEveryDeclaredHook(): void
    {
        $globalHooks = DictionaryFactory::base()->getHooks(Context::GLOBAL_SCOPE);

        foreach (['home.top', 'category.top', 'brand.bottom', 'product.top', 'cart.bottom', 'account.top'] as $hookCode) {
            self::assertArrayHasKey($hookCode, $globalHooks);
        }
    }

    #[Test]
    public function anOverrideMergesHooksByCodeAndTheLastLabelWins(): void
    {
        $dictionary = DictionaryFactory::withOverrides(
            <<<YAML
            contexts:
                PRODUCT:
                    - code: product.top
                      label: "Project label"
                CART:
                    - cart.recommendations
            YAML,
        );

        $productHooks = $dictionary->getHooks(Context::PRODUCT);
        self::assertSame('Project label', $productHooks['product.top']);
        self::assertSame(['product.top', 'product.details.bottom', 'product.bottom'], array_keys($productHooks), 'the base hooks stay, in order');
        self::assertSame('cart.recommendations', $dictionary->getHooks(Context::CART)['cart.recommendations'], 'a bare code is its own label');
        self::assertSame(Context::CART, $dictionary->getContextForHook('cart.recommendations'));
    }

    #[Test]
    public function anOverrideCanDisableABaseFieldAndAddItsOwn(): void
    {
        $dictionary = DictionaryFactory::withOverrides(
            <<<YAML
            fields:
                erp_family:
                    label: "ERP family"
                    field: product.ref
                    contexts: [PRODUCT]
            disabled: [product_visible]
            YAML,
        );

        self::assertNull($dictionary->getField('product_visible'));
        self::assertNotNull($dictionary->getField('erp_family'));
        self::assertArrayHasKey('erp_family', $dictionary->getFields(Context::PRODUCT));
        self::assertArrayNotHasKey('erp_family', $dictionary->getFields(Context::CART));
    }

    #[Test]
    public function aFieldWithoutContextsIsGlobalAndOfferedInEveryContextAndEditor(): void
    {
        $field = DictionaryFactory::base()->getField('product_ref');

        self::assertNotNull($field);
        self::assertSame([Context::GLOBAL_SCOPE], $field->contexts);
        self::assertSame(FieldDefinition::USAGES, $field->usages);
        self::assertSame('product', $field->getGroup());

        foreach (Context::cases() as $context) {
            self::assertTrue($field->isAvailableInContext($context), $context->value);
        }
    }

    #[Test]
    public function aFieldRestrictedToAContextIsHiddenFromTheOthersAndFromGlobalRules(): void
    {
        $dictionary = DictionaryFactory::base();

        self::assertArrayHasKey('context_product_same_brand', $dictionary->getFields(Context::PRODUCT));
        self::assertArrayNotHasKey('context_product_same_brand', $dictionary->getFields(Context::CART));
        self::assertArrayNotHasKey('context_product_same_brand', $dictionary->getFields(Context::GLOBAL_SCOPE));
        self::assertArrayHasKey('product_ref', $dictionary->getFields(Context::GLOBAL_SCOPE), 'a GLOBAL field stays in GLOBAL rules');
    }

    #[Test]
    public function theUsageReservesAFieldToOneEditor(): void
    {
        $dictionary = DictionaryFactory::withOverrides(
            <<<YAML
            fields:
                selection_only:
                    label: "Selection only"
                    field: product.ref
                    usage: [action]
            YAML,
        );

        $ruleFields = $dictionary->getFields(Context::PRODUCT, FieldDefinition::USAGE_RULE);
        $actionFields = $dictionary->getFields(Context::PRODUCT, FieldDefinition::USAGE_ACTION);

        self::assertArrayNotHasKey('selection_only', $ruleFields);
        self::assertArrayHasKey('selection_only', $actionFields);
        self::assertArrayHasKey('product_ref', $ruleFields, 'a field without usage is offered to both editors');
        self::assertArrayHasKey('product_ref', $actionFields);
        self::assertArrayNotHasKey('context_category_id', $actionFields, 'the context object fields stay in the rule editor');
        self::assertArrayHasKey('context_category_id', $dictionary->getFields(Context::CATEGORY, FieldDefinition::USAGE_RULE));
        self::assertArrayNotHasKey('context_category_product', $dictionary->getFields(Context::CATEGORY, FieldDefinition::USAGE_RULE));
    }

    #[Test]
    public function everyShippedContextFieldIsRestrictedToItsPlaceholderContext(): void
    {
        $placeholderContexts = [
            ':product_id' => Context::PRODUCT,
            ':category_id' => Context::CATEGORY,
            ':brand_id' => Context::BRAND,
            ':order_id' => Context::ORDER,
        ];

        //Reads the cart of the visit, offered to the PRODUCT product lists by design
        $sessionBoundContextFields = ['context_product_in_cart' => Context::PRODUCT];

        foreach (DictionaryFactory::base()->getFields() as $field) {
            if (!str_starts_with($field->code, 'context_')) {
                continue;
            }

            if (isset($sessionBoundContextFields[$field->code])) {
                self::assertSame([$sessionBoundContextFields[$field->code]], $field->contexts, $field->code);
                continue;
            }

            $expected = null;
            foreach ($placeholderContexts as $placeholder => $context) {
                if (str_contains((string) $field->expression, $placeholder)) {
                    $expected = $context;
                }
            }

            self::assertNotNull($expected, sprintf('%s reads no context placeholder', $field->code));
            self::assertSame([$expected], $field->contexts, $field->code);
        }
    }

    #[Test]
    #[DataProvider('invalidDefinitions')]
    public function anInvalidDefinitionIsRefused(string $yaml, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        DictionaryFactory::withOverrides($yaml)->getFields();
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidDefinitions(): iterable
    {
        yield 'field and expression together' => [
            "fields:\n    broken:\n        field: product.ref\n        expression: \"1\"\n",
            'requires exactly one of "field" or "expression"',
        ];
        yield 'unknown type' => [
            "fields:\n    broken:\n        field: product.ref\n        type: json\n",
            'unknown type "json"',
        ];
        yield 'values on a boolean' => [
            "fields:\n    broken:\n        field: product.visible\n        type: boolean\n        values_query: \"SELECT 1 AS value\"\n",
            '"values_query" does not apply',
        ];
        yield ':value expression without polarity operators' => [
            "fields:\n    broken:\n        expression: \"product.id IN (:value)\"\n",
            'its "operators" must be declared among [in, notIn, =, !=]',
        ];
        yield ':value expression with a free operator' => [
            "fields:\n    broken:\n        expression: \"product.id IN (:value)\"\n        operators: [in, contains]\n",
            'its "operators" must be declared among [in, notIn, =, !=]',
        ];
        yield 'join without a source column' => [
            "joins:\n    broken_table:\n        to: id\n",
            'requires a "from" in "table.column" form',
        ];
        yield 'unknown context' => [
            "fields:\n    broken:\n        field: product.ref\n        contexts: [SHOP]\n",
            'unknown context "SHOP"',
        ];
        yield 'contexts not a list' => [
            "fields:\n    broken:\n        field: product.ref\n        contexts: PRODUCT\n",
            'expects a list of contexts',
        ];
        yield 'unknown usage' => [
            "fields:\n    broken:\n        field: product.ref\n        usage: [editor]\n",
            'unknown usage "editor"',
        ];
        yield 'malformed hook entry' => [
            "contexts:\n    PRODUCT:\n        - 42\n",
            'invalid hook entry in context "PRODUCT"',
        ];
    }
}
