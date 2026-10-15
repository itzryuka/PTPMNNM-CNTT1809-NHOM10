<?php
declare(strict_types=1);
function saveDraft(array &$session, array $data, int $now): void {
    $session['draft'] = ['data'=>$data, 'created_at'=>$now];
    unset($session['completed']);
}
function draftExpired(array $session, int $now, int $ttl): bool {
    if (!isset($session['draft'])) { return true; }
    return $now - $session['draft']['created_at'] >= $ttl;
}
function cancelDraft(array &$session): void { unset($session['draft']); }
