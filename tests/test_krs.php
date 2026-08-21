<?php
use Duir\Services\KrsClient;
use Duir\Services\RiskAnalyzer;
function test_krs_history_generates_risk_event(): void {
    $client = new KrsClient();
    $profile = $client->parseProfile([], ['dzial6'=>['postepowanie'=>'zakończone umorzeniem postępowanie restrukturyzacyjne']], '0000123456');
    $events = (new RiskAnalyzer())->krsEventsFromProfile($profile);
    assert_true(count($events) >= 1, 'KRS historical event expected');
    // Brak odpisu aktualnego przy istniejącym pełnym => podmiot wykreślony (heurystyka
    // parseProfile) => ryzyko KRYTYCZNE: podmiot przestał istnieć.
    assert_eq($events[0]['risk'], 'krytyczny');
}

// Zakończone postępowanie UPADŁOŚCIOWE zależy od TYPU podmiotu: osoba PRAWNA przestaje
// istnieć → KRYTYCZNE; osoba FIZYCZNA/JDG nadal istnieje → WYSOKIE. Wcześniej goły
// rdzeń 'upadlosc' oznaczał sprawy zakończone mylnie jako „aktywne/wysokie".
function test_closed_bankruptcy_severity_depends_on_entity_type(): void {
    $r = new RiskAnalyzer();
    $active = $r->riskFromText('Postępowanie upadłościowe — sygnatura: V GU 20/12; w toku.', true);
    assert_eq('wysoki', $active['risk'], 'trwająca upadłość = wysokie');
    $closedText = 'Postępowanie upadłościowe; dataZakonczeniaPostepowania: 16.07.2019; UPADŁOŚĆ OBEJMUJĄCA LIKWIDACJĘ MAJĄTKU DŁUŻNIKA.';
    assert_eq('krytyczny', $r->riskFromText($closedText, true)['risk'], 'osoba prawna: zakończona upadłość = krytyczne');
    assert_eq('wysoki', $r->riskFromText($closedText, false)['risk'], 'osoba fizyczna: zakończona upadłość = wysokie (nie śmierć)');
    // Upadłość zakończona ZAWARCIEM układu = podmiot przetrwał → nie krytyczne.
    $uklad = $r->riskFromText('Postępowanie upadłościowe zakończone — zatwierdzono uklad; wykonano uklad.', true);
    assert_true($uklad['risk'] !== 'krytyczny', 'upadłość zakończona układem nie jest krytyczna');
}

// isLegalPerson: spółka/KRS = osoba prawna; JDG/osoba fizyczna = nie.
function test_is_legal_person_by_type_and_krs(): void {
    assert_true(RiskAnalyzer::isLegalPerson(['type'=>'company']), 'spółka = osoba prawna');
    assert_true(RiskAnalyzer::isLegalPerson(['krs'=>'0000159483']), 'podmiot z KRS = osoba prawna');
    assert_true(!RiskAnalyzer::isLegalPerson(['type'=>'business_person']), 'JDG = nie osoba prawna');
    assert_true(!RiskAnalyzer::isLegalPerson(['type'=>'natural_person']), 'osoba fizyczna = nie osoba prawna');
}

// Awaria Białej Listy MF przy rozwiązywaniu KRS z NIP (timeout/5xx) NIE może
// wyglądać jak "brak numeru KRS do sprawdzenia" — to musi być odróżnialny błąd
// do powtórzenia. Wymuszamy awarię warstwy HTTP bez sieci: MF_WHITELIST_BASE
// wskazuje nieobsługiwany schemat URL, więc Client::getJson() zwróci false
// z file_get_contents i rzuci \RuntimeException — tak samo jak przy timeout/5xx.
function test_krs_transient_whitelist_failure_is_distinguishable_from_no_identifier(): void {
    \Duir\Config::set('MF_WHITELIST_BASE', 'duir-invalid-scheme://offline');
    // Podmiot bez KRS, ale z NIP -> wymusza rozwiązanie przez Białą Listę MF, które padnie.
    $profileOnFailure = (new KrsClient())->fetchProfile(['nip'=>'8130000000']);

    // Czysty przypadek "naprawdę brak identyfikatora": brak KRS i brak NIP,
    // więc Biała Lista nie jest w ogóle wołana.
    $profileNoId = (new KrsClient())->fetchProfile(['name'=>'Podmiot bez numerów']);

    assert_eq('no_identifier', $profileNoId['status'], 'brak KRS i NIP => no_identifier');
    assert_true($profileOnFailure['status'] !== 'no_identifier', 'awaria Bialej Listy nie moze byc raportowana jako no_identifier');
    assert_eq('error', $profileOnFailure['status'], 'przejsciowa awaria rozwiazania KRS => status error (zgodny z CheckService)');
    assert_true($profileOnFailure['status_label'] !== $profileNoId['status_label'], 'komunikat awarii musi sie roznic od komunikatu braku identyfikatora');
    assert_true(str_contains((string)$profileOnFailure['status_label'], 'Białą Listę MF'), 'komunikat awarii musi wskazywac zrodlo (Biala Lista MF)');
}

// Odpis PEŁNY zawiera historię wpisów, w tym rutynowe adnotacje "wykreślono" przy
// każdej zmianie danych. Status bieżący musi pochodzić z odpisu AKTUALNEGO —
// inaczej niemal każda dojrzała, aktywna spółka byłaby oznaczana jako wykreślona.
function test_krs_active_company_with_historical_deletions_stays_active(): void {
    $client = new KrsClient();
    $profile = $client->parseProfile(
        ['dane'=>['nazwa'=>'Aktywna Sp. z o.o.','formaPrawna'=>'sp. z o.o.']],
        ['dzial1'=>['zmiana'=>'wykreślono poprzedni adres siedziby'],'dzial6'=>[]],
        '0000123456'
    );
    assert_eq('active', $profile['status'], 'adnotacje "wykreślono" w odpisie pełnym nie mogą oznaczać wykreślenia podmiotu');
    assert_eq('aktywny', $profile['status_label']);
}

// Podmiot wykreślony z KRS nie ma odpisu aktualnego (API zwraca błąd), ale ma odpis
// pełny — sam ten układ odpowiedzi musi być rozpoznany jako wykreślenie.
function test_krs_missing_actual_with_full_extract_means_deleted(): void {
    $client = new KrsClient();
    $profile = $client->parseProfile([], ['dzial1'=>['dane'=>'historyczne']], '0000123456');
    assert_eq('wykreślony', $profile['status_label'], 'brak odpisu aktualnego przy istniejącym pełnym => podmiot wykreślony');
}

// Struktura działu 6 jak w realnym odpisie (case: Budpol S.A., V GU 55/13).
// Wcześniej opisy zdarzeń KRS zawierały surowe ścinki JSON-a; teraz muszą być
// czytelnym tekstem "nagłówek — pole: wartość" bez składni JSON.
function duir_test_dzial6_upadlosc(): array {
    return ['odpis'=>['dane'=>['dzial6'=>[
        'postepowanieUpadlosciowe'=>[[
            'informacjaOOgloszeniuUpadlosci'=>[['organWydajacy'=>'SĄD REJONOWY W RZESZOWIE WYDZIAŁ V GOSPODARCZY','sygnatura'=>'V GU 55/13','data'=>'23.01.2014','nrWpisuWprow'=>'19']],
            'sposobProwadzeniaPostepowania'=>[['sposobProwadzeniaPostepowania'=>'UPADŁOŚĆ OBEJMUJĄCA LIKWIDACJĘ MAJĄTKU DŁUŻNIKA','nrWpisuWprow'=>'19']],
            'daneSyndyka'=>[['nazwa'=>[['nazwa'=>'KANCELARIA SYNDYKÓW SP. Z O.O.','nrWpisuWprow'=>'19']],'krs'=>[['krs'=>'0000476408']]]],
        ]],
    ]]]];
}
function test_krs_proceedings_are_readable_not_raw_json(): void {
    $client = new KrsClient();
    $profile = $client->parseProfile(duir_test_dzial6_upadlosc(), [], '0000123456');
    assert_true(count($profile['proceedings']) === 1, 'jedna sekcja działu 6 => jedno postępowanie');
    $desc = $profile['proceedings'][0];
    assert_true(str_contains($desc, 'Postępowanie upadłościowe'), 'opis musi mieć polski nagłówek sekcji');
    assert_true(str_contains($desc, 'sygnatura: V GU 55/13'), 'opis musi zawierać sygnaturę akt');
    assert_true(str_contains($desc, 'syndyk — nazwa: KANCELARIA SYNDYKÓW SP. Z O.O.'), 'pola syndyka muszą mieć prefiks roli');
    assert_true(!str_contains($desc, '{') && !str_contains($desc, '":'), 'opis nie może zawierać surowego JSON-a');
    assert_true(!str_contains($desc, 'nrWpisuWprow'), 'numery porządkowe wpisów to szum — nie raportujemy');
}

// Odpis pełny powiela postępowania z odpisu aktualnego — to samo postępowanie
// nie może generować dwóch zdarzeń ("aktualne" + "historyczne").
function test_krs_same_proceeding_in_actual_and_full_is_deduplicated(): void {
    $client = new KrsClient();
    $profile = $client->parseProfile(duir_test_dzial6_upadlosc(), duir_test_dzial6_upadlosc(), '0000123456');
    $events = (new RiskAnalyzer())->krsEventsFromProfile($profile);
    $proceedings = array_values(array_filter($events, fn($e)=>in_array($e['event_type'],['postępowanie_aktualne','postępowanie_historyczne'],true)));
    assert_true(count($proceedings) === 1, 'to samo postępowanie z obu odpisów => jedno zdarzenie, jest: '.count($proceedings));
    assert_eq('postępowanie_aktualne', $proceedings[0]['event_type']);
    assert_eq('wysoki', $proceedings[0]['risk']);
}

// Regresja z realnego odpisu: sam pusty dział 6 (także z pustym kontenerem
// rozwiązanie/unieważnienie) nie jest postępowaniem. Wcześniej fallback znajdował
// nazwę klucza "dzial6" w JSON-ie i tworzył dwa fałszywe alerty z surowym odpisem.
function test_krs_empty_dzial6_does_not_generate_raw_proceeding_events(): void {
    $client = new KrsClient();
    $actual = ['odpis'=>['dane'=>[
        'dzial3'=>['sprawozdania'=>[['zaOkresOdDo'=>'OD 01.01.2024 DO 31.12.2024']]],
        'dzial4'=>[], 'dzial5'=>[], 'dzial6'=>[],
    ]]];
    $full = ['odpis'=>['dane'=>[
        'dzial3'=>['sprawozdania'=>[['zaOkresOdDo'=>'OD 01.01.2024 DO 31.12.2024','nrWpisuWprow'=>'64']]],
        'dzial4'=>[], 'dzial5'=>[],
        'dzial6'=>['rozwiazanieUniewaznienie'=>['okreslenieOkolicznosci'=>[]]],
    ]]];

    $profile = $client->parseProfile($actual, $full, '0000123456');
    assert_eq([], $profile['proceedings'], 'pusty aktualny dział 6 => brak postępowań');
    assert_eq([], $profile['historical_proceedings'], 'pusty historyczny kontener działu 6 => brak postępowań');
    assert_eq(0, count((new RiskAnalyzer())->krsEventsFromProfile($profile)), 'pusty dział 6 nie może tworzyć alertu KRS');
}

// Dział 5 (kurator) — BŁĄD naprawiony 2026-08-11: zaindeksowany na produkcji, KrsClient
// czytał WYŁĄCZNIE dział 6, więc kurator ustanowiony wobec Alfa Energia od 2017 r. (art. 42
// k.c., z powodu braku organów) był całkowicie niewidoczny w danych DUiR. Struktura fixture
// to DOSŁOWNY kształt żywej odpowiedzi api-krs.ms.gov.pl (KRS 0000111222, sprawdzone przez
// curl) — nie wymyślona (patrz [[feedback_fixtures_z_prawdziwych_odpowiedzi]]).
// findValue: pole, które zmieniło się w historii spółki (nazwa), jest w odpisie PEŁNYM
// listą wpisów [{nazwa,nrWpisuWykr,nrWpisuWprow}, ...]. BŁĄD naprawiony 2026-08-11:
// naiwna rekurencja brała PIERWSZY wpis (najstarsza nazwa) zamiast NAJNOWSZEGO. Struktura
// fixture to DOSŁOWNY kształt żywej odpowiedzi api-krs.ms.gov.pl (KRS 0000111222) —
// spółka zarejestrowana jako „ALFA ENERGIA” (wpis #1), przemianowana na „BETA GRUPA”
// (wpis #6), wykreślona (wpis #14). legal_name MUSI wskazać ostatnią nazwę, nie pierwszą.
// Adres to KILKA pól (ulica/nrDomu/kodPocztowy/miejscowość) złożonych w jeden tekst —
// findValue() szukał JEDNEJ wartości pod jedną nazwą klucza, więc adres był ZAWSZE
// pusty, nawet dla odpisu AKTUALNEGO (płaski obiekt, żadnej historii). Struktury
// fixture to DOSŁOWNE kształty żywych odpowiedzi api-krs.ms.gov.pl (sprawdzone przez
// curl dla BUDPOL — odpis aktualny — i MGK/BETA GRUPA — odpis pełny z 4 wpisami adresu).
function test_krs_address_is_composed_from_actual_flat_object(): void {
    $actual = ['odpis'=>['dane'=>['dzial1'=>['siedzibaIAdres'=>[
        'siedziba'=>['kraj'=>'POLSKA','wojewodztwo'=>'PODKARPACKIE','powiat'=>'M. WARSZAWA','gmina'=>'M. WARSZAWA','miejscowosc'=>'WARSZAWA'],
        'adres'=>['ulica'=>'SIEMIEŃSKIEGO','nrDomu'=>'14','miejscowosc'=>'WARSZAWA','kodPocztowy'=>'35-203','poczta'=>'WARSZAWA','kraj'=>'POLSKA'],
    ]]]]];
    $profile = (new KrsClient())->parseProfile($actual, [], '0000186267');
    assert_eq('SIEMIEŃSKIEGO 14, 35-203 WARSZAWA', $profile['address'], 'adres aktualny (płaski obiekt) ma być złożony z ulicy, numeru, kodu i miejscowości');
}

function test_krs_address_picks_most_recent_historical_entry_not_first(): void {
    $full = ['odpis'=>['dane'=>['dzial1'=>['siedzibaIAdres'=>[
        'adres'=>[
            ['ulica'=>'TARGOWA','nrDomu'=>'3','miejscowosc'=>'WARSZAWA','kodPocztowy'=>'35-064','poczta'=>'WARSZAWA','kraj'=>'POLSKA','nrWpisuWykr'=>'7','nrWpisuWprow'=>'1'],
            ['ulica'=>'UL. PRZYKŁADOWA','nrDomu'=>'3','miejscowosc'=>'WARSZAWA','kodPocztowy'=>'00-001','poczta'=>'WARSZAWA','kraj'=>'POLSKA','nrWpisuWykr'=>'14','nrWpisuWprow'=>'9'],
        ],
    ]]]]];
    $profile = (new KrsClient())->parseProfile([], $full, '0000111222');
    assert_eq('UL. PRZYKŁADOWA 3, 00-001 WARSZAWA', $profile['address'], 'adres ma być OSTATNIM (najwyższy nrWpisuWprow), nie pierwotnym');
}

function test_krs_legal_name_picks_most_recent_historical_entry_not_first(): void {
    $full = ['odpis'=>['dane'=>['dzial1'=>['danePodmiotu'=>[
        'nazwa'=>[
            ['nazwa'=>'ALFA ENERGIA SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ','nrWpisuWykr'=>'6','nrWpisuWprow'=>'1'],
            ['nazwa'=>'BETA GRUPA SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ','nrWpisuWykr'=>'14','nrWpisuWprow'=>'6'],
        ],
    ]]]]];
    $profile = (new KrsClient())->parseProfile([], $full, '0000111222');
    assert_eq('BETA GRUPA SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ', $profile['legal_name'], 'legal_name ma być OSTATNIĄ nazwą, nie pierwotną');
}

// UWAGA: Repository::applyKrsProfileToSubject (event „nazwa_zaktualizowana") nie ma
// tu testu jednostkowego — Repository na starcie wykonuje `SHOW COLUMNS FROM subjects`
// (migracja service_mode, niezwiązana z tą zmianą), co SQLite odrzuca składniowo, a
// addEvent() używa `INSERT ... ON DUPLICATE KEY UPDATE` (dialekt MySQL). Zweryfikowane
// ręcznie na produkcji po wdrożeniu (patrz pamięć projektu / CHANGELOG) — zamiast fixture'a
// z sqlite: ponowny check Alfa Energia utworzył zdarzenie „KRS: zaktualizowano nazwę…".

function test_krs_dzial5_kurator_is_extracted_and_flagged_high_risk(): void {
    $full = ['odpis'=>['dane'=>[
        'dzial4'=>[], 'dzial6'=>['rozwiazanieUniewaznienie'=>['okreslenieOkolicznosci'=>[]]],
        'dzial5'=>['kurator'=>[[
            'nazwisko'=>[['nazwisko'=>['nazwiskoICzlon'=>'C*********'],'nrWpisuWykr'=>'13','nrWpisuWprow'=>'12']],
            'imiona'=>[['imiona'=>['imie'=>'P****'],'nrWpisuWykr'=>'13','nrWpisuWprow'=>'12']],
            'identyfikator'=>[['pesel'=>'8**********','nrWpisuWykr'=>'13','nrWpisuWprow'=>'12']],
            'podstawaPowolaniaZakresDzialania'=>[[
                'podstawaPowolaniaZakresDzialania'=>'POSTANOWIENIE SĄDU REJONOWEGO W RZESZOWIE XII WYDZIAŁU GOSPODARCZEGO KRS Z 30.06.2017R. O USTANOWIENIU KURATORA W CELU NIEZWŁOCZNEGO PODJĘCIA CZYNNOŚCI ZMIERZAJĄCYCH DO POWOŁANIA ORGANÓW REPREZENTACJI, A W RAZIE BEZSKUTECZNOŚCI TYCH CZYNNOŚCI - DO JEJ LIKWIDACJI (ART. 42 K.C.)',
                'nrWpisuWykr'=>'13','nrWpisuWprow'=>'12',
            ]],
            'dataPowolania'=>[['dataPowolania'=>'30.06.2017','nrWpisuWykr'=>'13','nrWpisuWprow'=>'12']],
            'dataDoKtorejMaDzialac'=>[],
        ]]],
    ]]];
    $profile = (new KrsClient())->parseProfile([], $full, '0000111222');
    assert_true(count($profile['historical_proceedings']) >= 1, 'kurator z działu 5 musi trafić do postępowań — wcześniej dział 5 był całkowicie ignorowany');
    $text = implode(' | ', $profile['historical_proceedings']);
    assert_true(str_contains($text, 'Kurator'), 'nagłówek ma jednoznacznie nazywać wpis kuratorem');
    assert_true(str_contains($text, 'ART. 42 K.C.'), 'podstawa powołania (najważniejsza treść wpisu) ma trafić do opisu');
    assert_true(!str_contains($text, 'nrWpisu'), 'numery porządkowe wpisów są szumem i mają być odfiltrowane');

    $events = (new RiskAnalyzer())->krsEventsFromProfile($profile);
    $kuratorEvents = array_values(array_filter($events, fn($e) => ($e['proceeding_status'] ?? '') === 'kurator'));
    assert_true(count($kuratorEvents) === 1, 'kurator ma wygenerować dokładnie jedno zdarzenie ryzyka');
    assert_eq('wysoki', $kuratorEvents[0]['risk'], 'kurator z powodu braku organów = wysokie ryzyko (podmiot istnieje, ale trudno z nim zawierać czynności)');
}

// Gdy nietypowa odpowiedź nie ma węzła dzial6, fallback może czytać tylko wartości,
// nie nazwy pustych pól kontraktu API.
function test_krs_fallback_ignores_empty_proceeding_field_names(): void {
    $profile = (new KrsClient())->parseProfile([
        'schema'=>[
            'postepowanieUpadlosciowe'=>[],
            'postepowanieRestrukturyzacyjne'=>[],
            'opis'=>'brak danych w sekcji',
        ],
    ], [], '0000123456');
    assert_eq([], $profile['proceedings'], 'puste nazwy pól nie są treścią postępowania');
}

// Obrona warstwy ryzyka: nawet profil pochodzący ze starego parsera nie może
// zamienić kompletnego dokumentu JSON w opis/alert widoczny użytkownikowi.
function test_krs_risk_analyzer_rejects_raw_json_proceeding_description(): void {
    $raw = json_encode(['odpis'=>['dane'=>['dzial6'=>[]]]], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $truncatedRaw = '"nrWpisuWprow":"52"}]},{"pozycja":[{"zaOkresOdDo":"OD 01.01.2024 DO 31.12.2024"}]},"dzial6":[]}}';
    $events = (new RiskAnalyzer())->krsEventsFromProfile([
        'status'=>'active', 'status_label'=>'aktywny', 'krs'=>'0000123456',
        'proceedings'=>[$raw, $truncatedRaw], 'historical_proceedings'=>[],
    ]);
    assert_eq(0, count($events), 'surowy JSON nie może zostać zdarzeniem KRS');
    assert_eq('', RiskAnalyzer::readableKrsDescription((string)$raw), 'surowy JSON nie ma czytelnego opisu');
    assert_eq('', RiskAnalyzer::readableKrsDescription($truncatedRaw), 'ucięty fragment JSON także nie ma czytelnego opisu');
}

function test_legacy_krs_json_events_are_removed_and_assessment_invalidated(): void {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) return;
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE events (id INTEGER PRIMARY KEY, subject_id INTEGER, source TEXT, description TEXT)');
    $pdo->exec('CREATE TABLE reports (id INTEGER PRIMARY KEY, subject_id INTEGER, type TEXT)');
    $bad = json_encode(['odpis'=>['dane'=>['dzial3'=>['sprawozdania'=>[]],'dzial6'=>[]]]], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $insert = $pdo->prepare('INSERT INTO events (id,subject_id,source,description) VALUES (?,?,?,?)');
    $insert->execute([1,7,'KRS',$bad]);
    $insert->execute([2,7,'KRS','Postępowanie upadłościowe — sygnatura: V GU 55/13']);
    $insert->execute([3,7,'KRZ',$bad]);
    $pdo->exec("INSERT INTO reports (id,subject_id,type) VALUES (1,7,'assessment'),(2,7,'subject')");

    $removed = (new \Duir\Repository($pdo))->purgeLegacyKrsJsonEvents(7);
    assert_eq(1, $removed, 'cleanup usuwa tylko fałszywy alert KRS z JSON-em');
    assert_eq(2, (int)$pdo->query('SELECT COUNT(*) FROM events')->fetchColumn(), 'czytelny KRS i inne źródło pozostają');
    assert_eq(0, (int)$pdo->query("SELECT COUNT(*) FROM reports WHERE type='assessment'")->fetchColumn(), 'ocena LLM oparta na fałszywym alercie musi wygasnąć');
    assert_eq(1, (int)$pdo->query("SELECT COUNT(*) FROM reports WHERE type='subject'")->fetchColumn(), 'pozostałych raportów nie usuwamy');
}
