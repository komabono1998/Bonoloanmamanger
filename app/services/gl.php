<?php
/**
 * General ledger: chart of accounts, append-only double-entry journals and the posting engine.
 *
 * Accounting basis
 *  - Amounts owed by borrowers (principal, interest, fees, penalties) are receivables that always equal the loan sub-ledger.
 *  - Interest, fees and penalties are recognised as income when they are RECEIVED (cash basis, as the income reports do).
 *    Until then they sit in an "unearned" liability account, so the receivable can still show what is owed.
 *  - Every posting is generated from an immutable source row (loan_ledger, cash_movements, disbursements, credit_events,
 *    cashier_sessions) and is keyed by it, so posting can be repeated safely and a source row is never posted twice.
 *  - Journals are validated to balance, never edited and never deleted; mistakes are corrected by a reversing journal.
 */
class Gl
{
    public const VIEWS = ['tb' => 'Trial balance', 'is' => 'Income statement', 'bs' => 'Balance sheet', 'gl' => 'General ledger (all accounts)', 'jr' => 'Journal register'];
    public const TYPES = ['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity', 'income' => 'Income', 'expense' => 'Expenses'];

    /** code => [name, type, description] */
    public static function defaultChart(): array
    {
        return [
            '1000' => ['Cash in Till', 'asset', 'Cash held by cashiers'],
            '1010' => ['Cash at Safe', 'asset', 'Cash held in branch safes'],
            '1020' => ['Bank Accounts', 'asset', 'Balances at banks'],
            '1030' => ['Mobile Money Wallets', 'asset', 'Mobile money balances'],
            '1200' => ['Loans Receivable - Principal', 'asset', 'Principal owed by borrowers (control account of the loan sub-ledger)'],
            '1210' => ['Interest Receivable', 'asset', 'Contractual interest owed by borrowers'],
            '1220' => ['Fees Receivable', 'asset', 'Fees owed by borrowers'],
            '1230' => ['Penalties Receivable', 'asset', 'Penalties owed by borrowers'],
            '1900' => ['Disbursement Clearing', 'asset', 'Links loan principal to cash released and fees deducted; should be nil'],
            '1950' => ['Collections Clearing', 'asset', 'Links cash received to loan repayments and customer credit; should be nil'],
            '1960' => ['Cash Transfers Clearing', 'asset', 'Links the two sides of a transfer between cash accounts; should be nil'],
            '2100' => ['Unearned Interest', 'liability', 'Interest charged but not yet received'],
            '2110' => ['Unearned Fees', 'liability', 'Fees charged but not yet received'],
            '2120' => ['Unearned Penalties', 'liability', 'Penalties charged but not yet received'],
            '2200' => ['Customer Credits', 'liability', 'Overpayments held for clients'],
            '3100' => ['Capital and Float Funding', 'equity', 'Cash introduced into the business (opening floats, capital)'],
            '3900' => ['Retained Earnings', 'equity', 'Accumulated profit brought forward'],
            '4100' => ['Interest Income', 'income', 'Interest received'],
            '4200' => ['Fee Income', 'income', 'Processing, application, inspection and other fees received'],
            '4300' => ['Penalty Income', 'income', 'Penalties received'],
            '5300' => ['Waivers and Write-offs', 'expense', 'Principal waived or written off'],
            '5800' => ['General Expenses', 'expense', 'Operating expenses without a category account'],
            '5900' => ['Cash Over / Short', 'expense', 'Counted cash differing from expected cash'],
            '5910' => ['Ledger Adjustments', 'expense', 'Authorised corrections of loan balances and cash adjustments'],
        ];
    }

    public static function seedChart(): void
    {
        foreach (self::defaultChart() as $code => [$name, $type, $desc]) {
            q('INSERT INTO gl_accounts(code, name, type, description, is_system, active, created_at) VALUES (?,?,?,?,1,1,?) ON CONFLICT (code) DO NOTHING', [$code, $name, $type, $desc, now()]);
        }
    }

    private static array $ids = [];
    public static function acct(string $code): int
    {
        if (!isset(self::$ids[$code])) {
            $id = val('SELECT id FROM gl_accounts WHERE code = ?', [$code]);
            if (!$id) { self::seedChart(); $id = val('SELECT id FROM gl_accounts WHERE code = ?', [$code]); }
            must((bool)$id, 'Ledger account ' . $code . ' does not exist.');
            self::$ids[$code] = (int)$id;
        }
        return self::$ids[$code];
    }

    /** One expense account per expense category, created on first use (codes 6001 upward). */
    public static function expenseAccount(int $categoryId): int
    {
        $code = '6' . str_pad((string)$categoryId, 3, '0', STR_PAD_LEFT);
        if (!val('SELECT COUNT(*) FROM gl_accounts WHERE code = ?', [$code])) {
            $name = (string)val('SELECT name FROM expense_categories WHERE id = ?', [$categoryId]);
            if ($name === '') return self::acct('5800');
            q('INSERT INTO gl_accounts(code, name, type, description, is_system, active, created_at) VALUES (?,?,?,?,1,1,?) ON CONFLICT (code) DO NOTHING',
                [$code, 'Expense - ' . $name, 'expense', 'Expenses recorded in the category "' . $name . '"', now()]);
        }
        return self::acct($code);
    }

    // ------------------------------------------------------------------ journals
    /**
     * Insert one balanced journal. $lines = [[account id, debit, credit, description?], ...]. Zero lines are dropped.
     * Returns the journal id (or 0 when there is nothing to post).
     */
    public static function journal(string $date, ?int $branch, string $memo, array $lines, string $source = 'auto', ?string $srcKey = null, ?int $reverses = null): int
    {
        $keep = [];
        $dr = 0; $cr = 0;
        foreach ($lines as $l) {
            $d = (int)$l[1]; $c = (int)$l[2];
            if ($d < 0 || $c < 0) { [$d, $c] = [max(0, -$c), max(0, -$d)]; }
            if ($d === 0 && $c === 0) continue;
            $dr += $d; $cr += $c;
            $keep[] = [(int)$l[0], $d, $c, $l[3] ?? null];
        }
        if (!$keep) {   // an event with no money effect (e.g. a float equal to the last closing cash) is still marked as processed
            if ($srcKey === null) return 0;
            return insert('gl_journals', ['journal_no' => 'GL-' . str_pad((string)next_seq('gl_journal'), 7, '0', STR_PAD_LEFT), 'journal_date' => $date, 'branch_id' => $branch,
                'source' => $source, 'src_key' => $srcKey, 'memo' => 'No ledger effect: ' . $memo, 'reverses_id' => null, 'posted_by' => uid(), 'created_at' => now()]);
        }
        must($dr === $cr, 'The journal does not balance: debits ' . ugx($dr) . ' and credits ' . ugx($cr) . '.');
        return tx(function () use ($date, $branch, $memo, $keep, $source, $srcKey, $reverses) {
            $jid = insert('gl_journals', ['journal_no' => 'GL-' . str_pad((string)next_seq('gl_journal'), 7, '0', STR_PAD_LEFT), 'journal_date' => $date, 'branch_id' => $branch,
                'source' => $source, 'src_key' => $srcKey, 'memo' => $memo !== '' ? $memo : null, 'reverses_id' => $reverses, 'posted_by' => uid(), 'created_at' => now()]);
            foreach ($keep as [$a, $d, $c, $desc]) {
                insert('gl_journal_lines', ['journal_id' => $jid, 'account_id' => $a, 'branch_id' => $branch, 'debit' => $d, 'credit' => $c, 'description' => $desc]);
            }
            return $jid;
        });
    }

    /** Lines for a signed amount: positive = debit that account, negative = credit it. */
    private static function dr(int $acct, int $signed, ?string $d = null): array
    {
        return [$acct, $signed > 0 ? $signed : 0, $signed < 0 ? -$signed : 0, $d];
    }

    // ------------------------------------------------------------------ automatic posting
    private const RECEIVABLE = ['principal' => '1200', 'interest' => '1210', 'fee' => '1220', 'penalty' => '1230'];
    private const UNEARNED = ['interest' => '2100', 'fee' => '2110', 'penalty' => '2120'];
    private const INCOME = ['interest' => '4100', 'fee' => '4200', 'penalty' => '4300'];

    /** Post every business event not yet in the ledger. Optionally for one branch and up to a business date. Returns the number of journals created. */
    public static function postPending(?int $branch = null, ?string $upTo = null): int
    {
        self::seedChart();
        return (int)tx(function () use ($branch, $upTo) {
            $n = 0;
            $w = fn(string $brCol, string $dtCol) => ($branch ? " AND $brCol = " . (int)$branch : '') . ($upTo && is_date($upTo) ? " AND $dtCol <= '" . $upTo . "'" : '');
            $has = fn(string $prefix, string $idCol) => "NOT EXISTS (SELECT 1 FROM gl_journals j WHERE j.src_key = '$prefix' || $idCol)";

            // 1. loan sub-ledger
            foreach (rows("SELECT g.*, l.branch_id AS br, l.loan_no FROM loan_ledger g JOIN loans l ON l.id = g.loan_id WHERE " . $has('led:', 'g.id') . $w('l.branch_id', 'g.business_date') . ' ORDER BY g.id') as $g) {
                $comp = $g['component']; $a = (int)$g['amount']; $lines = [];
                $rec = self::acct(self::RECEIVABLE[$comp]);
                $note = $g['loan_no'] . ' ' . $g['type'] . ' (' . $comp . ')';
                $lines[] = self::dr($rec, $a, $note);
                switch ($g['type']) {
                    case 'disbursement': $lines[] = self::dr(self::acct('1900'), -$a, $note); break;
                    case 'interest': case 'fee': case 'penalty': $lines[] = self::dr(self::acct(self::UNEARNED[$comp] ?? '2100'), -$a, $note); break;
                    case 'payment': case 'payment_reversal':
                        $lines[] = self::dr(self::acct('1950'), -$a, $note);
                        if (isset(self::INCOME[$comp])) { $lines[] = self::dr(self::acct(self::UNEARNED[$comp]), -$a, $note); $lines[] = self::dr(self::acct(self::INCOME[$comp]), $a, $note); }
                        break;
                    case 'waiver': $lines[] = self::dr(self::acct(isset(self::UNEARNED[$comp]) ? self::UNEARNED[$comp] : '5300'), -$a, $note); break;
                    default: $lines[] = self::dr(self::acct('5910'), -$a, $note);
                }
                $n += self::journal($g['business_date'], (int)$g['br'], $note, $lines, 'auto', 'led:' . $g['id']) ? 1 : 0;
            }

            // 2. fees deducted from the loan at disbursement (income when the loan is paid out)
            foreach (rows("SELECT d.* FROM disbursements d WHERE d.approved_principal > d.net_amount AND " . $has('dis:', 'd.id') . $w('d.branch_id', 'd.business_date') . ' ORDER BY d.id') as $d) {
                $fee = (int)$d['approved_principal'] - (int)$d['net_amount'];
                $n += self::journal($d['business_date'], (int)$d['branch_id'], 'Fees deducted at disbursement ' . $d['disb_no'],
                    [self::dr(self::acct('1900'), $fee), self::dr(self::acct('4200'), -$fee)], 'auto', 'dis:' . $d['id']) ? 1 : 0;
            }

            // 3. cash, bank and mobile-money movements
            foreach (rows("SELECT m.*, a.type AS acct_type FROM cash_movements m LEFT JOIN cash_accounts a ON a.id = m.account_id WHERE " . $has('cm:', 'm.id') . $w('m.branch_id', 'm.business_date') . ' ORDER BY m.id') as $m) {
                $amt = (int)$m['amount'] * (int)$m['direction'];
                if ($m['category'] === 'float') {   // a float that merely continues the last counted closing is not new money
                    $prev = $m['ref_type'] === 'session' ? val("SELECT counted_cash FROM cashier_sessions WHERE status = 'Reconciled' AND counted_cash IS NOT NULL AND branch_id = ? AND cashier_id = (SELECT cashier_id FROM cashier_sessions WHERE id = ?) AND id < ? ORDER BY id DESC LIMIT 1", [$m['branch_id'], $m['ref_id'], $m['ref_id']]) : null;
                    if ($prev !== null) $amt -= (int)$prev;
                }
                $cash = match ($m['acct_type']) {
                    'safe' => '1010', 'bank' => '1020', 'mobile' => '1030',
                    default => (int)$m['is_cash'] === 1 ? '1000' : ($m['method'] === 'Mobile Money' ? '1030' : '1020'),
                };
                $counter = match (true) {
                    $m['ref_type'] === 'expense' => (function () use ($m) { $cat = val('SELECT category_id FROM expenses WHERE id = ?', [$m['ref_id']]); return $cat ? self::expenseAccount((int)$cat) : self::acct('5800'); })(),
                    $m['ref_type'] === 'payment' => self::acct('1950'),
                    $m['ref_type'] === 'disbursement' => self::acct('1900'),
                    $m['ref_type'] === 'customer_credit' => self::acct('2200'),
                    $m['category'] === 'float' => self::acct('3100'),
                    $m['category'] === 'transfer' => self::acct('1960'),
                    $m['category'] === 'collection' || $m['category'] === 'reversal' => self::acct('1950'),
                    $m['category'] === 'disbursement' => self::acct('1900'),
                    $m['category'] === 'expense' => self::acct('5800'),
                    default => self::acct('5910'),
                };
                $desc = (string)($m['description'] ?? $m['category']);
                $n += self::journal($m['business_date'], (int)$m['branch_id'], $desc, [self::dr(self::acct($cash), $amt, $desc), self::dr($counter, -$amt, $desc)], 'auto', 'cm:' . $m['id']) ? 1 : 0;
            }

            // 4. customer credit (overpayments)
            foreach (rows("SELECT e.*, c.branch_id AS br FROM credit_events e JOIN customer_credits c ON c.id = e.credit_id
                           WHERE (e.type IN ('Created from overpayment','Voided (payment reversed)','Restored (payment reversed)') OR e.type LIKE 'Applied to%') AND " . $has('ce:', 'e.id')
                           . ($branch ? ' AND c.branch_id = ' . (int)$branch : '') . ($upTo && is_date($upTo) ? " AND COALESCE(e.business_date, SUBSTR(e.at,1,10)) <= '$upTo'" : '') . ' ORDER BY e.id') as $e) {
                $toCredit = in_array($e['type'], ['Created from overpayment', 'Restored (payment reversed)'], true);
                $amt = (int)$e['amount'] * ($toCredit ? 1 : -1);
                $n += self::journal($e['business_date'] ?: substr($e['at'], 0, 10), (int)$e['br'], 'Customer credit: ' . $e['type'],
                    [self::dr(self::acct('1950'), $amt), self::dr(self::acct('2200'), -$amt)], 'auto', 'ce:' . $e['id']) ? 1 : 0;
            }

            // 5. counted cash that differs from expected cash
            foreach (rows("SELECT s.* FROM cashier_sessions s WHERE s.status = 'Reconciled' AND s.variance <> 0 AND " . $has('var:', 's.id') . $w('s.branch_id', 's.business_date') . ' ORDER BY s.id') as $s) {
                $v = (int)$s['variance'];
                $n += self::journal($s['business_date'], (int)$s['branch_id'], 'Cash variance, till ' . $s['session_no'],
                    [self::dr(self::acct('1000'), $v), self::dr(self::acct('5900'), -$v)], 'auto', 'var:' . $s['id']) ? 1 : 0;
            }
            return $n;
        });
    }

    /** Posting that must never stop the End-of-Day: a failure is logged and the run can be repeated with "Post now". */
    public static function postPendingSafe(?int $branch, ?string $upTo): int
    {
        try {
            $n = self::postPending($branch, $upTo);
            if ($n) audit('General ledger posted', 'General Ledger', 'gl', $upTo ?? 'all', null, $n . ' journal(s)', '', '', null, null, $branch);
            return $n;
        } catch (Throwable $e) {
            error_log('General ledger posting failed: ' . $e->getMessage());
            try { audit('General ledger posting failed', 'General Ledger', 'gl', $upTo ?? 'all', null, substr($e->getMessage(), 0, 200), '', '', null, null, $branch); } catch (Throwable $e2) {}
            return 0;
        }
    }

    /** Source events still waiting to be posted (for the status panel). */
    public static function pendingCount(?int $branch = null): int
    {
        $br = fn(string $c) => $branch ? " AND $c = " . (int)$branch : '';
        $x = fn(string $prefix, string $id) => "NOT EXISTS (SELECT 1 FROM gl_journals j WHERE j.src_key = '$prefix' || $id)";
        return (int)val("SELECT COUNT(*) FROM loan_ledger g JOIN loans l ON l.id = g.loan_id WHERE " . $x('led:', 'g.id') . $br('l.branch_id'))
            + (int)val("SELECT COUNT(*) FROM cash_movements m WHERE " . $x('cm:', 'm.id') . $br('m.branch_id'));
    }

    // ------------------------------------------------------------------ manual journals
    public static function manual(string $date, ?int $branch, string $memo, array $lines): int
    {
        require_perm('gl.post');
        must(is_date($date), 'Enter a valid journal date.');
        must($date <= today(), 'A journal cannot be dated in the future.');
        must(trim($memo) !== '', 'A description is required for a manual journal.');
        if ($branch) assert_branch($branch); else must(can('branch.all'), 'Choose the branch this journal belongs to.');
        $clean = [];
        foreach ($lines as $l) {
            $acct = (int)($l['account'] ?? 0);
            $d = (int)($l['debit'] ?? 0); $c = (int)($l['credit'] ?? 0);
            if (!$acct && !$d && !$c) continue;
            must($acct > 0 && (bool)val('SELECT COUNT(*) FROM gl_accounts WHERE id = ? AND active = 1', [$acct]), 'Choose an active account on every journal line.');
            must(($d > 0) !== ($c > 0), 'Each line needs either a debit or a credit amount (not both, not neither).');
            $clean[] = [$acct, $d, $c, $l['memo'] ?? null];
        }
        must(count($clean) >= 2, 'A journal needs at least two lines.');
        $id = self::journal($date, $branch, trim($memo), $clean, 'manual');
        audit('Manual journal posted', 'General Ledger', 'gl_journal', $id, null, (string)val('SELECT journal_no FROM gl_journals WHERE id = ?', [$id]), $memo, '', null, null, $branch);
        return $id;
    }

    public static function reverse(int $journalId, string $reason): int
    {
        require_perm('gl.post');
        must(trim($reason) !== '', 'A reason is required to reverse a journal.');
        $j = row('SELECT * FROM gl_journals WHERE id = ?', [$journalId]);
        must((bool)$j, 'Journal does not exist.');
        must($j['source'] === 'manual' && !$j['reverses_id'], 'Only manual journals can be reversed here. Automatic journals are corrected by reversing the business transaction itself.');
        if ($j['branch_id']) assert_branch((int)$j['branch_id']);
        must(!val('SELECT COUNT(*) FROM gl_journals WHERE reverses_id = ?', [$journalId]), 'This journal has already been reversed.');
        $lines = [];
        foreach (rows('SELECT * FROM gl_journal_lines WHERE journal_id = ? ORDER BY id', [$journalId]) as $l) $lines[] = [(int)$l['account_id'], (int)$l['credit'], (int)$l['debit'], $l['description']];
        $id = self::journal(max(today(), $j['journal_date']), $j['branch_id'] ? (int)$j['branch_id'] : null, 'Reversal of ' . $j['journal_no'] . ': ' . $reason, $lines, 'manual', null, $journalId);
        audit('Manual journal reversed', 'General Ledger', 'gl_journal', $journalId, $j['journal_no'], (string)val('SELECT journal_no FROM gl_journals WHERE id = ?', [$id]), $reason, '', null, null, $j['branch_id'] ? (int)$j['branch_id'] : null);
        return $id;
    }

    // ------------------------------------------------------------------ chart of accounts maintenance
    public static function saveAccount(?int $id, string $code, string $name, string $type, string $desc, bool $active): int
    {
        require_perm('gl.manage');
        $name = trim($name); $code = trim($code);
        must($name !== '', 'Account name is required.');
        if ($id === null) {
            must(preg_match('/^[0-9]{4,8}$/', $code) === 1, 'The account code must be 4 to 8 digits.');
            must(isset(self::TYPES[$type]), 'Choose an account type.');
            must(!val('SELECT COUNT(*) FROM gl_accounts WHERE code = ?', [$code]), 'That account code is already used.');
            $id = insert('gl_accounts', ['code' => $code, 'name' => $name, 'type' => $type, 'description' => trim($desc) ?: null, 'is_system' => 0, 'active' => 1, 'created_at' => now()]);
            audit('Ledger account created', 'General Ledger', 'gl_account', $id, null, $code . ' ' . $name);
            return $id;
        }
        $a = row('SELECT * FROM gl_accounts WHERE id = ?', [$id]);
        must((bool)$a, 'Account does not exist.');
        must($active || !self::inUseByRules($a['code']), 'This account is used by automatic postings and cannot be deactivated.');
        q('UPDATE gl_accounts SET name = ?, description = ?, active = ? WHERE id = ?', [$name, trim($desc) ?: null, $active ? 1 : 0, $id]);
        audit('Ledger account changed', 'General Ledger', 'gl_account', $id, $a['name'] . ($a['active'] ? '' : ' (inactive)'), $name . ($active ? '' : ' (inactive)'));
        return $id;
    }

    private static function inUseByRules(string $code): bool
    {
        return isset(self::defaultChart()[$code]) || (strlen($code) === 4 && $code[0] === '6');
    }

    // ------------------------------------------------------------------ reporting
    /** SQL fragment limiting journal lines to the branches the user may see (and optionally one). Head-office lines (no branch) need branch.all. */
    public static function lineScope(?int $only = null): string
    {
        $ids = accessible_branch_ids();
        if ($only) $ids = array_values(array_intersect($ids, [$only]));
        $in = $ids ? 'l.branch_id IN (' . implode(',', array_map('intval', $ids)) . ')' : '1=0';
        return !$only && can('branch.all') ? "($in OR l.branch_id IS NULL)" : $in;
    }

    /**
     * Account balances. Each row: id, code, name, type, opening (debit-credit before $from), debit, credit (within the period), closing (debit-credit at $to).
     * $from = null gives every posting up to $to as the period.
     */
    public static function trialBalance(?string $from, string $to, ?int $branch = null, bool $hideZero = true): array
    {
        $sc = self::lineScope($branch);
        $from = $from ?: '0000-01-01';
        $rs = rows("SELECT a.id, a.code, a.name, a.type,
                SUM(CASE WHEN j.journal_date < ? THEN l.debit - l.credit ELSE 0 END) AS opening,
                SUM(CASE WHEN j.journal_date >= ? AND j.journal_date <= ? THEN l.debit ELSE 0 END) AS debit,
                SUM(CASE WHEN j.journal_date >= ? AND j.journal_date <= ? THEN l.credit ELSE 0 END) AS credit
              FROM gl_accounts a
              LEFT JOIN gl_journal_lines l ON l.account_id = a.id AND $sc
              LEFT JOIN gl_journals j ON j.id = l.journal_id AND j.journal_date <= ?
              GROUP BY a.id, a.code, a.name, a.type ORDER BY a.code", [$from, $from, $to, $from, $to, $to]);
        $out = [];
        foreach ($rs as $r) {
            $r['opening'] = (int)$r['opening']; $r['debit'] = (int)$r['debit']; $r['credit'] = (int)$r['credit'];
            $r['closing'] = $r['opening'] + $r['debit'] - $r['credit'];
            if ($hideZero && !$r['opening'] && !$r['debit'] && !$r['credit'] && !$r['closing']) continue;
            $out[] = $r;
        }
        return $out;
    }

    /** Account activity (the general ledger proper) with a running balance. */
    public static function accountActivity(int $accountId, string $from, string $to, ?int $branch = null): array
    {
        $sc = self::lineScope($branch);
        $open = (int)val("SELECT COALESCE(SUM(l.debit - l.credit),0) FROM gl_journal_lines l JOIN gl_journals j ON j.id = l.journal_id WHERE l.account_id = ? AND j.journal_date < ? AND $sc", [$accountId, $from]);
        $rows = rows("SELECT j.id AS jid, j.journal_no, j.journal_date, j.source, j.memo, l.description, l.debit, l.credit, COALESCE(b.name, 'Head office') AS branch
                      FROM gl_journal_lines l JOIN gl_journals j ON j.id = l.journal_id LEFT JOIN branches b ON b.id = l.branch_id
                      WHERE l.account_id = ? AND j.journal_date BETWEEN ? AND ? AND $sc ORDER BY j.journal_date, j.id, l.id", [$accountId, $from, $to]);
        $run = $open;
        foreach ($rows as &$r) { $run += (int)$r['debit'] - (int)$r['credit']; $r['balance'] = $run; }
        unset($r);
        return ['opening' => $open, 'rows' => $rows, 'closing' => $run];
    }

    /** Checks that tie the ledger to the loan sub-ledger and to cash. Each: [label, ledger amount, source amount]. */
    public static function reconciliation(?int $branch = null): array
    {
        $sc = self::lineScope($branch);
        $bal = fn(string $code) => (int)val("SELECT COALESCE(SUM(l.debit - l.credit),0) FROM gl_journal_lines l JOIN gl_accounts a ON a.id = l.account_id WHERE a.code = ? AND $sc", [$code]);
        $lb = $branch ? ' AND l.branch_id = ' . (int)$branch : ' AND ' . branch_scope('l.branch_id');
        $sub = fn(string $comp) => (int)val("SELECT COALESCE(SUM(g.amount),0) FROM loan_ledger g JOIN loans l ON l.id = g.loan_id WHERE g.component = ?" . $lb, [$comp]);
        $o = [];
        foreach (['principal' => ['1200', 'Principal'], 'interest' => ['1210', 'Interest'], 'fee' => ['1220', 'Fees'], 'penalty' => ['1230', 'Penalties']] as $comp => [$code, $lab]) {
            $o[] = [$lab . ' receivable (' . $code . ') against the loan sub-ledger', $bal($code), $sub($comp)];
        }
        $o[] = ['Disbursement clearing (1900) should be nil', $bal('1900'), 0];
        $o[] = ['Collections clearing (1950) should be nil', $bal('1950'), 0];
        $o[] = ['Cash transfers clearing (1960) should be nil', $bal('1960'), 0];
        return $o;
    }
}
