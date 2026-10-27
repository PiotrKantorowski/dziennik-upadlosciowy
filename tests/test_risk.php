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
function test_krz_discontinued_enforcement_is_named_and_high(): void {
    // Format pozycji, którą wtyczka 1.9.2 wysyła z tabeli „Umorzone postępowania egzekucyjne".
    $r = new RiskAnalyzer();
    $text = 'Umorzone postępowanie egzekucyjne. Organ wydający orzeczenie: Komornik Sądowy przy Sądzie Rejonowym w Przykładowie; Sygnatura: Km 396/25; Data ujawnienia: 02.09.2025; Data umorzenia: 28.08.2025 [Kryterium wyszukiwania: 0001234567]';
    $events = $r->krzEventsFromText($text, ['name'=>'Przykład sp. z o.o.','krs'=>'0001234567','type'=>'company']);
    assert_true(count($events) === 1, 'jedna pozycja egzekucji = jedno zdarzenie');
    assert_eq('wysoki', $events[0]['risk'], 'umorzona egzekucja to wysokie ryzyko');
    assert_eq('enforcement_discontinued', $events[0]['proceeding_status']);
    assert_eq('Km 396/25', $events[0]['signature'], 'sygnatura egzekucyjna musi zostać zachowana');
    assert_true(str_contains($events[0]['title'], 'umorzone postępowanie egzekucyjne'), 'tytuł musi nazywać rodzaj wpisu');
    // Układ kartowy portalu (zapasowy odczyt całej sekcji, bez dwukropków).
    $card = $r->krzEventsFromText('Umorzone postępowania egzekucyjne Lista wyszukanych postępowań Organ wydający orzeczenie Komornik Sądowy przy Sądzie Rejonowym w Przykładowie Sygnatura Km 396/25 Data ujawnienia 17.11.2025 Data umorzenia 16.10.2025', ['name'=>'Przykład sp. z o.o.','krs'=>'0001234567','type'=>'company']);
    assert_eq('Km 396/25', $card[0]['signature'] ?? null, 'sygnatura z układu kartowego');
    assert_eq('enforcement_discontinued', $card[0]['proceeding_status'] ?? null);
    // Umorzone postępowanie UPADŁOŚCIOWE nie może trafić do gałęzi egzekucji.
    $up = $r->riskFromText('postępowanie upadłościowe umorzone, egzekucyjne zabezpieczenie zniesione');
    assert_true($up['status'] !== 'enforcement_discontinued', 'upadłość ma pierwszeństwo przed egzekucją');
}

// To samo obwieszczenie KRZ przechwycone dwa razy z różnym wycinkiem strony
// (portal renderuje wyniki w ramkach, więc raz łapie się nagłówek z danymi
// podmiotu, raz sama tabela postępowań — patrz zdarzenia 1955/1956 z 27.10.2026).
// Klucz deduplikacji musi być TEN SAM, inaczej każdy przebieg dokłada do bazy
// kolejną kopię tej samej sprawy, z własnym poziomem ryzyka.
function test_krz_same_proceeding_dedupes_across_different_captures(): void {
    $r = new RiskAnalyzer();
    $subject = ['id'=>7,'name'=>'Jan Przykładowy','pesel'=>'80010112345'];
    $bogaty = 'h prowadzących działalność gospodarczą Imię Jan Nazwisko Przykładowy Firma Jan Przykładowy "Warsztat" Miejsce zamieszkania Kraków PESEL 80010112345 Inny identyfikator Zwiń postępowania '
        .'Postępowania restrukturyzacyjne Lista wyszukanych postępowań Rodzaj postępowania postępowanie o zatwierdzenie układu Sygnatura KR1S/GRz-nu/18/2023 Data rejestracji 01.02.2023 Data zakończenia Status w toku';
    $ubogi = 'nia Postępowania restrukturyzacyjne Lista wyszukanych postępowań Rodzaj postępowania postępowanie o zatwierdzenie układu Sygnatura KR1S/GRz-nu/18/2023 Data rejestracji 01.02.2023 Data zakończenia Status w toku [Kryterium wyszukiwania: 80010112345]';

    $a = $r->krzEventsFromText($bogaty, $subject);
    $b = $r->krzEventsFromText($ubogi, $subject);
    assert_true(count($a) >= 1 && count($b) >= 1, 'oba przechwycenia dają zdarzenie KRZ');
    assert_eq($a[0]['signature'], 'KR1S/GRz-nu/18/2023', 'sygnatura z bogatszego przechwycenia');
    assert_eq($b[0]['signature'], 'KR1S/GRz-nu/18/2023', 'sygnatura z uboższego przechwycenia');
    assert_true(isset($a[0]['dedupe_hash']) && isset($b[0]['dedupe_hash']), 'zdarzenie z sygnaturą niesie własny klucz deduplikacji');
    assert_eq($a[0]['dedupe_hash'], $b[0]['dedupe_hash'], 'ten sam klucz mimo innej treści — inaczej powstaje duplikat');
    assert_true($a[0]['description'] !== $b[0]['description'], 'test byłby pusty, gdyby opisy były identyczne');
}

// Klucz nie może sklejać różnych podmiotów ani różnych spraw.
function test_krz_dedupe_key_separates_subjects_and_signatures(): void {
    $wspolny = RiskAnalyzer::krzDedupeHash(7, 'KR1S/GRz-nu/18/2023', '2023-02-01');
    assert_true($wspolny !== RiskAnalyzer::krzDedupeHash(8, 'KR1S/GRz-nu/18/2023', '2023-02-01'), 'inny podmiot = inny klucz');
    assert_true($wspolny !== RiskAnalyzer::krzDedupeHash(7, 'KR1S/GRz-nu/19/2023', '2023-02-01'), 'inna sygnatura = inny klucz');
    assert_true($wspolny !== RiskAnalyzer::krzDedupeHash(7, 'KR1S/GRz-nu/18/2023', '2024-01-04'), 'inna data obwieszczenia = inny klucz');
    assert_eq($wspolny, RiskAnalyzer::krzDedupeHash(7, ' kr1s/grz-nu/18/2023 ', '2023-02-01'), 'spacje i wielkość liter nie tworzą duplikatu');
}

// Sam wiersz-nagłówek tabeli KRZ (same etykiety kolumn, zero danych) nie jest
// postępowaniem. Luźny wzorzec „Sygnatura …" brał z niego „Data rejestracji
// Data zakończenia Status" jako sygnaturę i tworzył zdarzenie z niczego
// (zdarzenie #239 w bazie produkcyjnej, lipiec 2026).
function test_krz_table_header_is_not_a_proceeding(): void {
    $r = new RiskAnalyzer();
    $events = $r->krzEventsFromText('Rodzaj postępowania Sygnatura Data rejestracji Data zakończenia Status', ['id'=>12,'name'=>'Jan Przykładowy','pesel'=>'80010112345']);
    foreach ($events as $e) {
        assert_true(!isset($e['signature']) || $e['signature'] === null || preg_match('/\d/', (string)$e['signature']) === 1,
            'etykiety kolumn nie mogą trafić do pola sygnatury');
    }
}
