<?php
use Duir\Services\KrsClient;
function test_krs_parse_extracts_financial_report_dates(): void {
    $client = new KrsClient();
    $profile = $client->parseProfile(['sprawozdania'=>[['okresDo'=>'2024-12-31','dataZlozenia'=>'2025-07-10']]], [], '0000123456');
    assert_eq($profile['financial_report']['period_to'], '2024-12-31');
    assert_eq($profile['financial_report']['submitted_at'], '2025-07-10');
}

// Daty sprawozdań wolno brać tylko z jawnie nazwanych pól odpisu. Fabrykowanie ich
// z dowolnych dat znalezionych w JSON-ie (np. daty ostatniego wpisu rejestrowego)
// dawało fałszywe alarmy "sprawozdanie po terminie" dla niemal każdej spółki.
function test_krs_parse_does_not_fabricate_financial_dates_from_unrelated_ones(): void {
    $client = new KrsClient();
    $profile = $client->parseProfile(
        ['naglowek'=>['dataRejestracji'=>'2015-03-10','dataOstatniegoWpisu'=>'2026-07-01','innaData'=>'2024-12-31']],
        [],
        '0000123456'
    );
    assert_eq($profile['financial_report']['submitted_at'], null, 'data złożenia nie może pochodzić z przypadkowych dat odpisu');
    assert_eq($profile['financial_report']['period_to'], null, 'koniec okresu nie może pochodzić z przypadkowych dat odpisu');
}
