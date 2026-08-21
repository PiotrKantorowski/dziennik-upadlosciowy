<?php
namespace Duir\Services;

use Duir\Support\Normalize;

final class FinancialStatementChecker
{
    public function evaluate(?string $periodTo, ?string $submittedAt, ?string $firstFinancialYearEnd = null): array
    {
        $periodTo = Normalize::dateOrNull($periodTo) ?: $firstFinancialYearEnd;
        $submittedAt = Normalize::dateOrNull($submittedAt);
        if (!$periodTo) return ['status'=>'unknown','reason'=>'Brak daty końca roku obrotowego / okresu sprawozdania.'];
        $base = new \DateTimeImmutable($periodTo);
        $isEndOfMonth = $base->format('d') === $base->format('t');
        $sixMonths = $isEndOfMonth ? $base->modify('last day of +6 months') : $base->modify('+6 months');
        $due = $sixMonths->modify('+15 days')->format('Y-m-d');
        if (!$submittedAt) return ['status'=>'missing','period_to'=>$periodTo,'submitted_at'=>null,'due_date'=>$due,'reason'=>'Brak informacji o dacie złożenia ostatniego sprawozdania finansowego.'];
        $late = $submittedAt > $due;
        return [
            'status'=>$late?'late':'on_time','period_to'=>$periodTo,'submitted_at'=>$submittedAt,'due_date'=>$due,
            'reason'=>$late ? "Sprawozdanie złożono po szacowanym terminie {$due}." : "Sprawozdanie wygląda na złożone w terminie do {$due}.",
        ];
    }

    public function fromKrsProfile(array $profile): array
    {
        $fr = $profile['financial_report'] ?? [];
        if (!is_array($fr)) $fr = [];
        return $this->evaluate($fr['period_to'] ?? null, $fr['submitted_at'] ?? null, $fr['first_financial_year_end'] ?? null);
    }
}
