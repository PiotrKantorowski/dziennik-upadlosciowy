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
