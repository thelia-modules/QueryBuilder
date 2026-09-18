<?php

declare(strict_types=1);

namespace QueryBuilder\Service;

use QueryBuilder\Model\QueryBuilderRule;

/**
 * Snapshot of what the sticky cycles of every action of a rule depend on at
 * rule level: its eligibility (condition tree, context) and its activation.
 * Taken BEFORE the rule is saved, then compared to the saved state — same
 * mechanism as ActionCycleState one level up. Hooks are left out: a cycle is
 * keyed by action, not by hook, and serves every hook of the rule.
 */
final readonly class RuleCycleState
{
    private function __construct(
        private ?array $conditionTree,
        private string $context,
        public bool $active,
    ) {
    }

    public static function fromRule(QueryBuilderRule $rule): self
    {
        return new self(
            ConditionTreeNormalizer::normalize($rule->getConditionTreeArray()),
            (string) $rule->getContext(),
            (bool) $rule->getActivate()
        );
    }

    /**
     * A cycle never survives a change of the customers the rule targets (tree
     * or context) nor a deactivation: re-enabling starts from a clean selection.
     */
    public function invalidatesCyclesOf(self $next): bool
    {
        return $this->conditionTree !== $next->conditionTree
            || $this->context !== $next->context
            || ($this->active && !$next->active);
    }
}
