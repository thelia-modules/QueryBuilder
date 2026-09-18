<?php

declare(strict_types=1);

namespace QueryBuilder\Service;

use QueryBuilder\Model\QueryBuilderAction;

/**
 * Snapshot of what the sticky cycles of an action depend on: its selection
 * (condition tree, action code), its persistence (persist_days) and its
 * activation. Taken BEFORE the action is saved — Propel keeps no previous
 * values — then compared to the saved state to decide whether the running
 * cycles still belong to the action they were created for.
 */
final readonly class ActionCycleState
{
    private function __construct(
        private ?array $conditionTree,
        private string $code,
        private int $persistDays,
        public bool $active,
    ) {
    }

    public static function fromAction(QueryBuilderAction $action): self
    {
        return new self(
            ConditionTreeNormalizer::normalize($action->getConditionTreeArray()),
            (string) $action->getCode(),
            (int) ($action->getParametersArray()['persist_days'] ?? 0),
            (bool) $action->getActivate()
        );
    }

    /**
     * A cycle never survives a change of the selection it came from (tree or
     * action code), of the persistence itself, nor a deactivation: re-enabling
     * starts from a clean selection. Other parameters (limit, labels, rates)
     * leave the cycles untouched.
     */
    public function invalidatesCyclesOf(self $next): bool
    {
        return $this->conditionTree !== $next->conditionTree
            || $this->code !== $next->code
            || $this->persistDays !== $next->persistDays
            || ($this->active && !$next->active);
    }
}
