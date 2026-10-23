<?php
/**
 * Loan calculation engine. Pure functions only (no database, no session) so it can be tested independently.
 *
 * Money:    whole UGX integers.
 * Rates:    integer basis points (1% = 100 bp) so 3.5% = 350 and no floating point error can creep in.
 * Rounding: round-half-up to the nearest whole UGX (documented rule, applied to every rate-based amount).
 */
final class Calc
{
    /** amount * bp / 10000, rounded half-up to the nearest UGX. */
    public static function pct(int $amount, int $bp): int
    {
        if ($amount < 0) return -self::pct(-$amount, $bp);
        return intdiv($amount * $bp + 5000, 10000);
    }

    /**
     * Compute all financial terms of a loan.
     * Interest is flat: Principal x monthly rate x number of months (SRS 5.1).
     *
     * @return array{principal:int,rate_bp:int,months:int,interest:int,total_pi:int,processing_fee:int,application_fee:int,
     *               inspection_fee:int,other_fees:int,total_fees:int,fees_deducted:bool,net_disbursement:int,total_contractual:int}
     */
    public static function terms(int $principal, int $rateBp, int $months, int $processingBp, int $applicationFee,
                                 int $inspectionFee, int $otherFees = 0, bool $feesDeducted = true): array
    {
        if ($principal <= 0) throw new AppError('Loan amount must be greater than zero.');
        if ($months < 1) throw new AppError('Loan period must be at least one month.');
        if ($rateBp < 0 || $processingBp < 0 || $applicationFee < 0 || $inspectionFee < 0 || $otherFees < 0) {
            throw new AppError('Rates and fees cannot be negative.');
        }
        $interest = self::pct($principal, $rateBp) * $months;
        $processing = self::pct($principal, $processingBp);
        $totalFees = $processing + $applicationFee + $inspectionFee + $otherFees;
        $net = $feesDeducted ? $principal - $totalFees : $principal;
        if ($net <= 0) throw new AppError('Fees exceed the approved principal; nothing would be disbursed.');
        return [
            'principal' => $principal, 'rate_bp' => $rateBp, 'months' => $months, 'interest' => $interest,
            'total_pi' => $principal + $interest, 'processing_fee' => $processing, 'application_fee' => $applicationFee,
            'inspection_fee' => $inspectionFee, 'other_fees' => $otherFees, 'total_fees' => $totalFees,
            'fees_deducted' => $feesDeducted, 'net_disbursement' => $net,
            // fees only add to the contractual debt when they are NOT deducted at disbursement
            'total_contractual' => $principal + $interest + ($feesDeducted ? 0 : $totalFees),
        ];
    }

    // ---------------------------------------------------------------- dates
    /** Add calendar months, clamping to month end (31 Jan + 1 month = 28/29 Feb). */
    public static function addMonths(string $date, int $months): string
    {
        $d = new DateTimeImmutable($date);
        $y = (int)$d->format('Y');
        $m = (int)$d->format('n') + $months;
        $day = (int)$d->format('j');
        $y += intdiv($m - 1, 12);
        $m = (($m - 1) % 12) + 1;
        $last = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m)))->format('t');
        return sprintf('%04d-%02d-%02d', $y, $m, min($day, $last));
    }

    public static function addDays(string $date, int $days): string
    {
        return (new DateTimeImmutable($date))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    /**
     * @param callable|null $isWorking fn(string $date): bool  (defaults to Monday-Friday)
     */
    public static function isWorkingDay(string $date, ?callable $isWorking = null): bool
    {
        if ($isWorking) return (bool)$isWorking($date);
        return (int)date('N', strtotime($date)) <= 5;
    }

    public static function nextWorkingDay(string $date, ?callable $isWorking = null): string
    {
        for ($i = 0; $i < 60 && !self::isWorkingDay($date, $isWorking); $i++) $date = self::addDays($date, 1);
        return $date;
    }

    /** Add N working days (used for the 20-working-day offer validity). */
    public static function addWorkingDays(string $date, int $n, ?callable $isWorking = null): string
    {
        while ($n > 0) {
            $date = self::addDays($date, 1);
            if (self::isWorkingDay($date, $isWorking)) $n--;
        }
        return $date;
    }

    // ---------------------------------------------------------------- schedule
    /**
     * Build repayment instalments. Monthly (or collection-method mode) => a single instalment on the due date.
     * Weekly / daily in 'installments' mode => equal instalments, remainder on the last one.
     *
     * @return list<array{no:int,due_date:string,principal:int,interest:int,total:int}>
     */
    public static function schedule(int $principal, int $interest, string $disbDate, string $dueDate,
                                    string $frequency = 'monthly', string $mode = 'collection_method'): array
    {
        $n = 1;
        $dates = [$dueDate];
        if ($mode === 'installments' && $frequency !== 'monthly') {
            $days = max(1, days_between($disbDate, $dueDate));
            $step = $frequency === 'weekly' ? 7 : 1;
            $n = max(1, intdiv($days, $step));
            $dates = [];
            for ($i = 1; $i < $n; $i++) $dates[] = self::addDays($disbDate, $i * $step);
            $dates[] = $dueDate;
        }
        $out = [];
        $pBase = intdiv($principal, $n);
        $iBase = intdiv($interest, $n);
        $pLeft = $principal;
        $iLeft = $interest;
        for ($i = 1; $i <= $n; $i++) {
            $p = $i === $n ? $pLeft : $pBase;
            $in = $i === $n ? $iLeft : $iBase;
            $pLeft -= $p;
            $iLeft -= $in;
            $out[] = ['no' => $i, 'due_date' => $dates[$i - 1], 'principal' => $p, 'interest' => $in, 'total' => $p + $in];
        }
        return $out;
    }

    // ---------------------------------------------------------------- payment allocation
    /**
     * Allocate a payment across outstanding components in the configured priority order.
     *
     * @param array<string,int> $outstanding component => amount owed
     * @param list<string>      $order       e.g. ['penalty','interest','principal']
     * @return array{allocations:array<string,int>,excess:int}
     */
    public static function allocate(int $amount, array $outstanding, array $order): array
    {
        $alloc = [];
        $left = $amount;
        foreach ($order as $c) {
            if ($left <= 0) break;
            $due = max(0, (int)($outstanding[$c] ?? 0));
            if ($due <= 0) continue;
            $take = min($left, $due);
            $alloc[$c] = $take;
            $left -= $take;
        }
        return ['allocations' => $alloc, 'excess' => $left];
    }

    // ---------------------------------------------------------------- penalty
    /**
     * Penalty for ONE day. $basisBalance is the balance the rate applies to for that day.
     * Cap (if any) is expressed as a % of approved principal over the life of the loan.
     */
    public static function dailyPenalty(int $basisBalance, int $rateBp, int $alreadyAccrued = 0, int $capBp = 0, int $principal = 0): int
    {
        if ($basisBalance <= 0 || $rateBp <= 0) return 0;
        $p = self::pct($basisBalance, $rateBp);
        if ($capBp > 0 && $principal > 0) {
            $cap = self::pct($principal, $capBp);
            $p = max(0, min($p, $cap - $alreadyAccrued));
        }
        return $p;
    }
}
