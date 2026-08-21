<?php
use Duir\Services\ReportService;

function test_llm_payload_has_no_personal_data(): void {
    // Sztuczne zdarzenie z pełnym kompletem PII, tak jak zwraca je latestEventsSince() (JOIN z subjects).
    $event = [
        'source'=>'KRZ',
        'title'=>'KRZ: postępowanie KR15/GRz-nu/175/2025',
        'risk'=>'wysoki',
        'risk_reason'=>'Zakończone negatywnie postępowanie.',
        'description'=>'Rafał Mucharski, zam. ul. Testowa 1, PESEL 80010112345, opis skrapowany z portalu.',
        'subject_name'=>'Rafał Mucharski',
        'pesel'=>'80010112345',
        'nip'=>'1234563218',
        'regon'=>'123456785',
        'krs'=>'0000123456',
        'raw_json'=>'{"chunk":"..."}',
    ];

    $payload = ReportService::redactEventsForLlm([$event]);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    // Zredagowany payload zawiera tylko bezpieczne pola.
    assert_eq(array_keys($payload[0]), ['source','title','risk','risk_reason'], 'redacted payload should expose only safe fields');

    // Żadna dana osobowa/identyfikator nie może trafić do stringu wysyłanego do LLM.
    assert_true(!str_contains($json, '80010112345'), 'PESEL must not appear in LLM payload');
    assert_true(!str_contains($json, '1234563218'), 'NIP must not appear in LLM payload');
    assert_true(!str_contains($json, '123456785'), 'REGON must not appear in LLM payload');
    assert_true(!str_contains($json, '0000123456'), 'KRS must not appear in LLM payload');
    assert_true(!str_contains($json, 'Rafał Mucharski'), 'subject name must not appear in LLM payload');
    assert_true(!str_contains($json, 'ul. Testowa 1'), 'description with PII must not appear in LLM payload');

    // Pola potrzebne do streszczenia są zachowane.
    assert_true(str_contains($json, 'KRZ'), 'source should be preserved');
    assert_true(str_contains($json, 'Zakończone negatywnie'), 'risk_reason should be preserved');
}

// Redakcja dla OCENY sytuacji = MINIMALIZACJA (dobór, nie maskowanie): do LLM idą
// tylko dane niezbędne — rodzaj ogłoszenia, ryzyko, SYGNATURA, data i wyłuskane
// istotne dane postępowania. Pełna treść ogłoszenia (z adresami/danymi osób) NIE.
function test_llm_assessment_payload_is_minimized(): void {
    $event = [
        'source'=>'MSIG','title'=>'MSiG: Ogłoszenie o ogłoszeniu upadłości',
        'risk'=>'wysoki','risk_reason'=>'Aktywne postępowanie upadłościowe.',
        'signature'=>'GRz1/GUp/12/2025','publication_date'=>'2025-05-06',
        'description'=>'Sąd Rejonowy w Rzeszowie ogłosił upadłość dłużnika Rafał Mucharski, '
            .'zam. ul. Testowa 1, PESEL 80010112345. Wzywa się wierzycieli do zgłoszenia '
            .'wierzytelności w terminie trzydziestu dni od dnia obwieszczenia.',
        'subject_name'=>'Rafał Mucharski','pesel'=>'80010112345','nip'=>'1234563218',
    ];
    $payload = ReportService::redactEventsForLlmAssessment([$event]);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    assert_eq(array_keys($payload[0]), ['source','title','risk','risk_reason','publication_date','signature','istotne_dane']);
    // SYGNATURA i data — jawnie potrzebne do zaleceń, trafiają do LLM.
    assert_true(str_contains($json, 'GRz1/GUp/12/2025'), 'sygnatura sprawy jest potrzebna do zaleceń');
    assert_true(str_contains($json, '2025-05-06'), 'data publikacji jest potrzebna do zaleceń');
    // Meritum (co wynika) — trafia do LLM jako istotne dane.
    assert_true(str_contains($json, 'w terminie trzydziestu dni'), 'termin ma trafić do LLM');
    assert_true(str_contains($json, 'zgłoszenia wierzytelności'), 'czynność ma trafić do LLM');
    assert_true(str_contains($json, 'Sąd Rejonowy w Rzeszowie'), 'sąd prowadzący ma trafić do LLM');
    // Dane ZBĘDNE do oceny (dobór, nie dump): nazwisko, adres i numery NIE są przekazywane.
    foreach (['Rafał Mucharski','ul. Testowa 1','80010112345','1234563218'] as $zbedne) {
        assert_true(!str_contains($json, $zbedne), 'dane zbędne dla oceny nie idą do LLM: '.$zbedne);
    }
}

// Baza wiedzy do ocen ładuje się z knowledge/*.md (bez frontmattera YAML).
function test_assessment_knowledge_loads(): void {
    $kb = ReportService::loadAssessmentKnowledge();
    assert_true(mb_strlen($kb) > 5000, 'baza wiedzy powinna się załadować (jest: '.mb_strlen($kb).' znaków)');
    assert_true(!str_starts_with($kb, '---'), 'frontmatter YAML ma być odcięty');
    assert_true(str_contains($kb, 'IF') && str_contains($kb, 'THEN'), 'reguły decyzyjne IF-THEN obecne');
}
