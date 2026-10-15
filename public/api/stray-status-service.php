<?php
declare(strict_types=1);

/** Submitted is the original receipt state; rescue work may otherwise be reopened. */
function strayStatusTransitionAllowed(string $oldStatus, string $newStatus): bool
{
    $statuses = ['submitted', 'under_review', 'assigned', 'resolved', 'closed'];
    return in_array($oldStatus, $statuses, true)
        && in_array($newStatus, $statuses, true)
        && ($newStatus !== 'submitted' || $oldStatus === 'submitted');
}

/** Keeps the first resolution time on note edits, resets it on a later resolution. */
function strayResolvedTimestampMode(string $oldStatus, string $newStatus): array
{
    return [
        'set_resolved' => $newStatus === 'resolved' && $oldStatus !== 'resolved' ? 1 : 0,
        'keep_resolved' => $newStatus === 'resolved' && $oldStatus === 'resolved' ? 1 : 0,
    ];
}
