<?php
use Duir\Services\RiskAnalyzer;
function test_krz_negative_finished_restructuring_is_high(): void {
    $r = new RiskAnalyzer();
    $text = 'Rafał Mucharski KR15/GRz-nu/175/2025 postępowanie restrukturyzacyjne zakończone umorzeniem, data zakończenia 2025-11-20';
    $events = $r->krzEventsFromText($text, ['name'=>'Rafał Mucharski','pesel'=>'80010112345']);
    assert_true(count($events) >= 1, 'KRZ event expected');
    assert_eq($events[0]['risk'], 'wysoki', 'negative finished proceeding should be high risk');
}
function test_krz_positively_finished_restructuring_is_medium(): void {
    $r = new RiskAnalyzer();
    $text = 'Sąd zawiadamia, że postępowanie restrukturyzacyjne wobec Przykład sp. z o.o. zostało prawomocnie zakończono, wykonano układ. Sygnatura KR15/GRz-Zw/88/2024, data zakończenia 2025-10-01';
    $events = $r->krzEventsFromText($text, ['name'=>'Przykład sp. z o.o.','pesel'=>'80010112345']);
    assert_true(count($events) >= 1, 'KRZ event expected');
    assert_eq($events[0]['risk'], 'średni', 'positively finished proceeding should be medium risk');
    assert_eq($events[0]['proceeding_status'], 'found_closed_positive_or_neutral', 'status should be found_closed_positive_or_neutral, not found_active');
}
function test_no_results_is_not_event(): void {
    $r = new RiskAnalyzer();
    $events = $r->krzEventsFromText('Brak wyników spełniających kryteria wyszukiwania', ['name'=>'X']);
    assert_eq(count($events), 0, 'no results should not produce event');
    assert_true($r->isConfirmedNoResults('Nie znaleziono danych'), 'no result detection');
    assert_true(!$r->isConfirmedNoResults('Nie znaleziono właściwego pola wyszukiwania KRZ.'), 'generic automation error is not confirmed no-results');
}

// Przechwycony szczegół MSiG zawiera elementy interfejsu portalu (Zamknij/Pobierz/
// Poprzedni/Następny/"Treść ogłoszenia"/samotne "x") — nie mogą trafiać do opisów
// zdarzeń ani raportów. Tytułem zdarzenia jest RODZAJ ogłoszenia z pola rozdziału.
function test_msig_event_is_tidy_and_titled_by_section(): void {
    $r = new RiskAnalyzer();
    $text = "SPOLKA TESTOWA S.A. W UPADLOSCI\nRozdzial/nazwa rozdzialu: III. OGLOSZENIA WYMAGANE PRZEZ PRAWO UPADLOSCIOWE/4. Ogloszenie o mozliwosci przegladania planu podzialu\nData publikacji: 2025-05-06\nTresc ogloszenia\nZamknij\nPobierz\nPoprzedni\nNastepny\nx";
    $e = $r->msigEventFromDetail(['text'=>$text,'publication_date'=>'2025-05-06'], ['name'=>'Spolka Testowa S.A.']);
    assert_eq('MSiG: Ogloszenie o mozliwosci przegladania planu podzialu', $e['title']);
    foreach (['Zamknij','Pobierz','Poprzedni','Nastepny',"\nx"] as $junk) {
        assert_true(!str_contains($e['description'], $junk), 'opis nie może zawierać elementu UI: '.$junk);
    }
}

// Reorganizacje korporacyjne (połączenie/podział/przekształcenie/wydzielenie majątku)
// przenoszą majątek i zobowiązania — NIGDY nie są niskim ryzykiem dla wierzyciela.
function test_corporate_reorganization_is_medium_risk(): void {
    $r = new RiskAnalyzer();
    $risk = $r->riskFromText('Połączenie / podział / przekształcenie — okoliczności: WYDZIELENIE CZĘŚCI MAJĄTKU SPÓŁKI W WYNIKU PODZIAŁU; opis: uchwała o podziale w trybie art. 529 par. 1 pkt 4 KSH');
    assert_eq('średni', $risk['risk'], 'reorganizacja musi być co najmniej średnim ryzykiem');
    assert_eq('reorganizacja', $risk['status']);
}

// Dokładne brzmienie komunikatu braku wyników w KRZ / Wyszukiwanie podmiotów
// (zweryfikowane na żywym portalu 2026-07-12) — MUSI być rozpoznawane jako
// potwierdzony brak wyników, inaczej przebieg kończy się błędem not_announcement.
function test_krz_no_results_message_is_recognized(): void {
    $r = new RiskAnalyzer();
    assert_true($r->isConfirmedNoResults('Nie zostały znalezione żadne pozycje dla podanych kryteriów wyszukiwania.'));
    assert_true($r->isConfirmedNoResults('Brak wyników wyszukiwania'));
}

// Osoby fizyczne: status z CEIDG zamiast fałszywego błędu KRS.
// Wykreślenie = wysokie ryzyko, zawieszenie = średnie, aktywna = brak zdarzenia.
function test_ceidg_status_maps_to_risk_events(): void {
    $r = new RiskAnalyzer();
    $wyk = $r->ceidgEventsFromProfile(['ceidg_status'=>'WYKRESLONY','label'=>'CEIDG: działalność wykreślona — JAN TEST']);
    // Osoba fizyczna: wykreślenie z CEIDG = koniec DZIAŁALNOŚCI, ale osoba nadal
    // istnieje (nie „przestała istnieć") → WYSOKIE, nie krytyczne.
    assert_eq(1, count($wyk)); assert_eq('wysoki', $wyk[0]['risk']);
    $zaw = $r->ceidgEventsFromProfile(['ceidg_status'=>'ZAWIESZONY','label'=>'x']);
    assert_eq(1, count($zaw)); assert_eq('średni', $zaw[0]['risk']);
    assert_eq(0, count($r->ceidgEventsFromProfile(['ceidg_status'=>'AKTYWNY'])));
}
function test_ceidg_skipped_without_key(): void {
    \Duir\Config::set('CEIDG_API_KEY', '');
    $res = (new \Duir\Services\CeidgClient())->confirm(['nip'=>'1234563218']);
    assert_eq('skipped', $res['status']);
    assert_true(str_contains($res['label'], 'Ustawieniach'), 'komunikat prowadzi do Ustawień');
}

// RODZAJ postępowania KRZ MUSI być nazwany wprost w tytule zdarzenia (trafia do
// oceny LLM) — „postępowanie o zatwierdzenie układu", nie ogólne „postępowanie".
function test_krz_event_title_names_proceeding_kind(): void {
    $r = new RiskAnalyzer();
    $events = $r->krzEventsFromText('postępowanie o zatwierdzenie układu KR1S/GRz-nu/175/2025 05.06.2025 06.10.2025 zakończone', ['name'=>'Jan Testowy','pesel'=>'80010112345']);
    assert_true(count($events) >= 1, 'oczekiwano zdarzenia KRZ');
    assert_true(str_contains($events[0]['title'], 'zatwierdzenie układu'), 'tytuł nazywa rodzaj: '.$events[0]['title']);
    assert_true(str_contains($events[0]['title'], 'KR1S/GRz-nu/175/2025'), 'tytuł zawiera sygnaturę');

    $up = $r->krzEventsFromText('postępowanie upadłościowe XX1U/GUp/5/2024 w toku', ['name'=>'ABC','pesel'=>'80010112345']);
    assert_true(str_contains($up[0]['title'], 'upadłościowe'), 'rodzaj upadłościowy w tytule');
}
