<?php
use Duir\Services\FinancialStatementChecker;
function test_financial_statement_late(): void {
    $c = new FinancialStatementChecker();
    $r = $c->evaluate('2024-12-31','2025-08-01');
    assert_eq($r['status'], 'late');
}
function test_financial_statement_on_time(): void {
    $c = new FinancialStatementChecker();
    $r = $c->evaluate('2024-12-31','2025-07-10');
    assert_eq($r['status'], 'on_time');
}
function test_financial_statement_due_date_boundary(): void {
    $c = new FinancialStatementChecker();
    $r = $c->evaluate('2024-12-31', null);
    assert_eq($r['due_date'], '2025-07-15');
}
function test_financial_statement_on_time_exact_deadline(): void {
    $c = new FinancialStatementChecker();
    $r = $c->evaluate('2024-12-31','2025-07-15');
    assert_eq($r['status'], 'on_time');
}
function test_financial_statement_late_day_after_deadline(): void {
    $c = new FinancialStatementChecker();
    $r = $c->evaluate('2024-12-31','2025-07-16');
    assert_eq($r['status'], 'late');
}
function test_financial_statement_leap_end_of_february(): void {
    $c = new FinancialStatementChecker();
    // 2024-02-29 to ostatni dzień lutego (rok przestępny), więc +6 mies. => ostatni
    // dzień sierpnia = 2024-08-31, a +15 dni => 2024-09-15 (bez overflow).
    $r = $c->evaluate('2024-02-29', null);
    assert_eq($r['due_date'], '2024-09-15');
}
function test_financial_statement_mid_month_unchanged(): void {
    $c = new FinancialStatementChecker();
    // 2024-03-15 nie jest końcem miesiąca => zwykłe +6 mies. = 2024-09-15, +15 dni = 2024-09-30.
    $r = $c->evaluate('2024-03-15', null);
    assert_eq($r['due_date'], '2024-09-30');
}
