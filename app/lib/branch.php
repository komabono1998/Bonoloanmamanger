<?php
/**
 * Branch context and branch-level access control (SRS v3 section 7).
 * A user sees only the branches assigned to them (COO: all, via `branch.all`). Every list query is scoped with
 * branch_scope(); every single-record fetch calls assert_branch(); every posting calls assert_active_branch().
 */

function branch_row(int $id): ?array
{
    static $cache = [];
    if (!isset($cache[$id]) || isset($GLOBALS['__branch_dirty'])) { unset($GLOBALS['__branch_dirty']); $cache = []; $cache[$id] = row('SELECT * FROM branches WHERE id = ?', [$id]); }
    return $cache[$id];
}

function branch_name(?int $id): string
{
    return $id ? (branch_row($id)['name'] ?? ('#' . $id)) : '—';
}

function branches_changed(): void { $GLOBALS['__branch_dirty'] = true; unset($GLOBALS['__branch_ids'], $GLOBALS['__branch']); }

/** Branch ids the user may access. */
function accessible_branch_ids(?int $userId = null): array
{
    $userId = $userId ?? uid();
    if (!$userId) return [];
    if ($userId === uid() && isset($GLOBALS['__branch_ids'])) return $GLOBALS['__branch_ids'];
    if (in_array('branch.all', user_permissions($userId), true)) {
        $ids = array_map('intval', array_column(rows('SELECT id FROM branches ORDER BY id'), 'id'));
    } else {
        $ids = array_map('intval', array_column(rows('SELECT ub.branch_id FROM user_branches ub WHERE ub.user_id = ? ORDER BY ub.is_primary DESC, ub.branch_id', [$userId]), 'branch_id'));
    }
    if ($userId === uid()) $GLOBALS['__branch_ids'] = $ids;
    return $ids;
}

/** The branch the user is currently working in (0 = none). */
function branch_id(): int
{
    if (isset($GLOBALS['__branch'])) return $GLOBALS['__branch'];
    $ids = accessible_branch_ids();
    $want = (int)($_SESSION['branch'] ?? 0);
    if ($want && in_array($want, $ids, true)) return $GLOBALS['__branch'] = $want;
    $primary = (int)val('SELECT branch_id FROM user_branches WHERE user_id = ? AND is_primary = 1', [uid() ?? 0]);
    if ($primary && in_array($primary, $ids, true)) return $GLOBALS['__branch'] = $primary;
    // prefer an active branch
    foreach ($ids as $i) { $b = branch_row($i); if ($b && $b['status'] === 'active') return $GLOBALS['__branch'] = $i; }
    return $GLOBALS['__branch'] = (int)($ids[0] ?? 0);
}

function set_branch_context(int $id): void
{
    must(in_array($id, accessible_branch_ids(), true), 'You do not have access to that branch.');
    $GLOBALS['__branch'] = $id;
    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) $_SESSION['branch'] = $id;
}

/** SQL fragment restricting $col to the branches the user may see (optionally narrowed to $only). */
function branch_scope(string $col, ?array $only = null): string
{
    $ids = accessible_branch_ids();
    if ($only !== null) $ids = array_values(array_intersect($ids, array_map('intval', $only)));
    return $ids ? "$col IN (" . implode(',', array_map('intval', $ids)) . ')' : '1=0';
}

/** Throw if the user may not see records of this branch. Prevents cross-branch reads by guessing ids. */
function assert_branch(int $branchId): void
{
    if (!in_array($branchId, accessible_branch_ids(), true)) throw new AppError('You do not have access to records of that branch.');
}

/** Postings must happen in the user's current branch (and that branch must be active). */
function assert_active_branch(int $branchId): void
{
    assert_branch($branchId);
    must($branchId === branch_id(), 'This record belongs to ' . branch_name($branchId) . '. Switch to that branch before posting transactions to it.');
    $b = branch_row($branchId);
    must($b && $b['status'] === 'active', 'This branch is inactive.');
}

/** id => name of branches the user can access (for selectors). */
function branch_options(): array
{
    $o = [];
    foreach (accessible_branch_ids() as $i) $o[$i] = branch_name($i);
    return $o;
}

/** Active branches (for assignment forms). */
function all_branch_options(bool $activeOnly = true): array
{
    $o = [];
    foreach (rows('SELECT id, name FROM branches ' . ($activeOnly ? "WHERE status = 'active' " : '') . 'ORDER BY name') as $b) $o[$b['id']] = $b['name'];
    return $o;
}

/** Branch filter chosen on a page/report (0 = all accessible) -> list of ids to scope to. */
function branch_filter_ids(int $chosen): ?array
{
    return $chosen && in_array($chosen, accessible_branch_ids(), true) ? [$chosen] : null;
}

/** Create the standard cash accounts for a new branch. */
function seed_branch_accounts(int $branchId): void
{
    foreach ([['Main Safe', 'safe'], ['Bank Account', 'bank'], ['Mobile Money', 'mobile']] as [$n, $t]) {
        q('INSERT INTO cash_accounts(branch_id, name, type) VALUES (?,?,?) ON CONFLICT (branch_id, name) DO NOTHING', [$branchId, $n, $t]);
    }
}
