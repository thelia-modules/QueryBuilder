<?php

declare(strict_types=1);

namespace QueryBuilder\Service;

/**
 * Strips the react-querybuilder bookkeeping (node ids, default valueSource
 * and "not" flags) so two trees compare on their semantics only: the editor
 * may re-serialize an unchanged tree with different ids or extra defaults.
 */
final readonly class ConditionTreeNormalizer
{
    public static function normalize(?array $tree): ?array
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
                $tree[$key] = self::normalize($value);
            }
        }

        return $tree;
    }
}
