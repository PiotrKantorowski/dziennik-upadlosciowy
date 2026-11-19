<?php
// Pomiar trafności dopasowania podmiotu na zbiorze wzorcowym (tests/name_match_cases.php).
// Samodzielnie: php tests/test_name_match_cases.php  — wypisuje fałszywe alarmy i pominięcia.
if (!function_exists('assert_true')) require_once __DIR__.'/TestCase.php';

function name_match_case_errors(): array {
    $r = new \Duir\Services\RiskAnalyzer();
    $fp = []; $fn = [];
    foreach (require __DIR__.'/name_match_cases.php' as [$label, $subject, $text, $expected]) {
        $got = $r->textMatchesSubject($text, $subject);
        if ($got && !$expected) $fp[] = $label;
        if (!$got && $expected) $fn[] = $label;
    }
    return [$fp, $fn];
}

function test_name_match_cases_have_no_false_alarms_or_misses(): void {
    [$fp, $fn] = name_match_case_errors();
    assert_true(!$fp && !$fn, 'fałszywe alarmy: '.implode('; ', $fp).' | pominięcia: '.implode('; ', $fn));
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    [$fp, $fn] = name_match_case_errors();
    $n = count(require __DIR__.'/name_match_cases.php');
    echo "Przypadków: $n, fałszywe alarmy: ".count($fp).", pominięcia: ".count($fn)."\n";
    foreach ($fp as $l) echo "  FA: $l\n";
    foreach ($fn as $l) echo "  PO: $l\n";
}
