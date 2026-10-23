<?php
/**
 * Penalty accrual. Penalties are stored separately from principal and interest, one row per loan per accrual date
 * (UNIQUE(loan_id, business_date) prevents duplicate posting), using the terms snapshotted when the loan was approved.
 *
 * The rule is OFF by default (setting penalty_enabled = 0) until management confirms the 3.5% daily rate (SRS 23.4).
 */
final class Penalty
{
    public static function termsFor(array $loan): array
    {
        $t = $loan['penalty_terms'] ? json_decode($loan['penalty_terms'], true) : null;
        return $t ?: ['enabled' => false, 'rate_bp' => 0, 'basis' => 'outstanding_principal', 'compound' => false, 'cap_bp' => 0, 'grace_days' => 0];
    }

    /** Balance to which the penalty rate applies on date $d. */
    public static function basisBalance(array $loan, array $terms, string $d): int
    {
        $b = Loans::balances((int)$loan['id'], $d);
        switch ($terms['basis']) {
            case 'original_principal': $base = (int)$loan['approved_principal']; break;
            case 'total_outstanding': $base = $b['principal'] + $b['interest'] + $b['fee']; break;
            default: $base = $b['principal'];
        }
        if (!empty($terms['compound'])) $base += $b['penalty'];
        return max(0, $base);
    }

    /** Post any missing daily penalties up to and including $date. Idempotent. Returns number of rows posted. */
    public static function accrue(string $date): int
    {
        $posted = 0;
        foreach (rows("SELECT * FROM loans WHERE status IN ('Active','Overdue')") as $loan) {
            $terms = self::termsFor($loan);
            if (empty($terms['enabled']) || (int)$terms['rate_bp'] <= 0) continue;
            $info = Loans::overdueInfo($loan, $date);
            if (!$info['since']) continue;
            $first = Calc::addDays($info['since'], 1 + (int)$terms['grace_days']);
            $last = val('SELECT MAX(business_date) FROM penalties WHERE loan_id = ?', [$loan['id']]);
            $d = $last && $last >= $first ? Calc::addDays($last, 1) : $first;
            for (; $d <= $date; $d = Calc::addDays($d, 1)) {
                if (val('SELECT COUNT(*) FROM penalties WHERE loan_id = ? AND business_date = ?', [$loan['id'], $d])) continue;
                $basis = self::basisBalance($loan, $terms, $d);
                $already = (int)val('SELECT COALESCE(SUM(amount),0) FROM penalties WHERE loan_id = ?', [$loan['id']]);
                $amt = Calc::dailyPenalty($basis, (int)$terms['rate_bp'], $already, (int)$terms['cap_bp'], (int)$loan['approved_principal']);
                if ($amt <= 0) continue;
                insert('penalties', [
                    'loan_id' => $loan['id'], 'business_date' => $d, 'accrual_start' => $first, 'days_overdue' => days_between($info['since'], $d),
                    'rate_bp' => $terms['rate_bp'], 'basis' => $terms['basis'] . (!empty($terms['compound']) ? '+compound' : ''),
                    'applicable_balance' => $basis, 'amount' => $amt, 'method' => 'daily simple', 'posted_by' => uid(), 'created_at' => now(),
                ]);
                insert('loan_ledger', [
                    'loan_id' => $loan['id'], 'business_date' => $date, 'entry_date' => $d, 'type' => 'penalty', 'component' => 'penalty', 'amount' => $amt,
                    'ref_type' => 'penalty', 'ref_id' => null, 'description' => 'Penalty ' . bp_to_pct((int)$terms['rate_bp']) . '% on ' . ugx($basis) . ' (day ' . days_between($info['since'], $d) . ')',
                    'user_id' => uid(), 'created_at' => now(),
                ]);
                $posted++;
            }
        }
        if ($posted) audit('Penalties accrued', 'Penalties', 'business_day', $date, null, $posted . ' daily penalty entries');
        return $posted;
    }

    /** Read-only explanation of the penalty position of one loan. */
    public static function explain(array $loan): array
    {
        $terms = self::termsFor($loan);
        $info = Loans::overdueInfo($loan);
        $b = Loans::balances((int)$loan['id']);
        return [
            'enabled' => !empty($terms['enabled']), 'terms' => $terms, 'overdue' => $info,
            'accrued' => (int)val('SELECT COALESCE(SUM(amount),0) FROM penalties WHERE loan_id = ?', [$loan['id']]),
            'outstanding' => $b['penalty'],
            'collected' => (int)val("SELECT COALESCE(SUM(a.amount),0) FROM payment_allocations a JOIN payments p ON p.id=a.payment_id WHERE a.loan_id = ? AND a.component='penalty' AND p.status='Posted'", [$loan['id']]),
        ];
    }
}
