<?php
use Duir\Support\SearchPlan;
function test_krz_query_prefers_pesel_for_business_person(): void {
    [$key,$value] = SearchPlan::krzQuery(['type'=>'business_person','nip'=>'1112223344','pesel'=>'80010112345']);
    assert_eq($key, 'pesel');
    assert_eq($value, '80010112345');
}
function test_krz_query_prefers_krs_for_company(): void {
    [$key,$value] = SearchPlan::krzQuery(['type'=>'company','krs'=>'0000123456','nip'=>'1112223344']);
    assert_eq($key, 'krs');
    assert_eq($value, '0000123456');
}
function test_weak_name_is_not_safe_search_key(): void {
    assert_true(SearchPlan::isWeakName('Kancelaria'), 'single generic word should be weak');
    assert_true(!SearchPlan::isWeakName('Kancelaria Prawna Kantorowski Głąb Wspólnicy'), 'full distinctive name should be stronger');
}

function test_name_match_uses_distinctive_tokens(): void {
    assert_true(SearchPlan::nameMatches('Kancelaria Prawna Kantorowski Głąb Wspólnicy', 'Dłużnik: Kantorowski Głąb Wspólnicy spółka komandytowa'));
}

function test_msig_task_query_skips_pesel_uses_name_for_natural_person(): void {
    // Osoba fizyczna z samym PESEL: MSiG nie szuka po PESEL, więc bierzemy nazwę.
    [$key,$value] = SearchPlan::msigTaskQuery(['type'=>'natural_person','pesel'=>'80010112345','name'=>'Jan Aleksander Kowalczyk']);
    assert_eq($key, 'name');
    assert_eq($value, 'Jan Aleksander Kowalczyk');
}
function test_msig_task_query_prefers_krs_over_nip(): void {
    // Ogłoszenia MSiG są indeksowane po nazwie i numerze KRS — wyszukiwanie po NIP
    // dawało fałszywe "brak wyników" dla spółek rejestrowych (case: Budpol S.A.).
    [$key,$value] = SearchPlan::msigTaskQuery(['type'=>'company','krs'=>'0000123456','nip'=>'1112223344','regon'=>'123456785']);
    assert_eq($key, 'krs');
    assert_eq($value, '0000123456');
}
function test_msig_task_query_uses_nip_when_no_krs(): void {
    // Podmiot bez KRS (np. JDG): NIP pozostaje pierwszym twardym identyfikatorem.
    [$key,$value] = SearchPlan::msigTaskQuery(['type'=>'business_person','nip'=>'1112223344','regon'=>'123456785']);
    assert_eq($key, 'nip');
    assert_eq($value, '1112223344');
}
function test_msig_task_query_empty_when_only_pesel_and_weak_name(): void {
    // Sam PESEL + słaba nazwa = brak sensownego zapytania MSiG (zadanie nie powstaje).
    [$key,$value] = SearchPlan::msigTaskQuery(['type'=>'natural_person','pesel'=>'80010112345','name'=>'Firma']);
    assert_eq($key, '');
    assert_eq($value, '');
}
function test_short_krs_is_padded_to_ten_digits_for_every_source(): void {
    // 19.10.2026: KRS wpisane bez zer wiodących („123456") dawały w KRZ fałszywy
    // brak wyników (portal wymaga 10 cyfr), w MSiG not_announcement, w API KRS losowe błędy.
    $subject = ['type'=>'company','krs'=>'123456','nip'=>'1112223344','name'=>'PRZYKŁADOWA SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ'];
    assert_eq(['krs','0000123456'], SearchPlan::krzQuery($subject));
    assert_eq(['krs','0000123456'], SearchPlan::msigTaskQuery($subject));
    assert_true(in_array('0000123456', SearchPlan::identifiers($subject), true), 'identyfikatory muszą zawierać pełny KRS');
    assert_eq('0001234567', \Duir\Support\Normalize::krs('1234567'));
    assert_eq('0000654321', \Duir\Support\Normalize::krs('0000654321'));
    assert_eq('', \Duir\Support\Normalize::krs(''));
}
function test_cron_default_batch_covers_whole_daily_portfolio(): void {
    // Limit 25 przy 33 podmiotach wyrzucał co dzień 8 podmiotów z przebiegu.
    $cron = file_get_contents(dirname(__DIR__).'/app/Controllers/CronController.php');
    assert_true(str_contains($cron, "Config::get('CRON_BATCH_SIZE', 200)"), 'domyślny limit CRON musi obejmować cały portfel');
}
