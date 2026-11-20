<?php
use Duir\KrzPisma\{KrzDokument,KrzTerminy};

function krz_doc_catalog(): array { return KrzDokument::catalog(dirname(__DIR__).'/app/KrzPisma/dokumenty.tsv'); }

function test_krz_documents_catalog_loaded(): void {
    $cat = krz_doc_catalog();
    assert_true(count($cat) > 1100, 'katalog dokumentów KRZ');
    foreach (['11176OB','11171ZA','11172ZA','11217OB','11051OB','11182PO'] as $k) assert_true(isset($cat[$k]), "brak $k");
}

function test_krz_document_read_by_code(): void {
    $text = "Sąd Rejonowy dla Łodzi-Śródmieścia w Łodzi\nSygn. akt LD1M/GUp/16/2026\n11176OB\n"
        ."Obwieszczenie zarządzenia sędziego-komisarza z dnia 20.11.2026 r. o obwieszczeniu daty złożenia listy wierzytelności.\n"
        ."Akta zgłoszeń: LD1M/GUp-s-Zw/16/2026. Kwota 12345.67 zł.";
    $r = KrzDokument::read($text, krz_doc_catalog());
    assert_eq('11176OB', $r['kod']);
    assert_eq('kod wzoru w treści', $r['rozpoznanie']);
    assert_eq('2026-11-20', $r['data_dokumentu']);
    assert_eq('postępowanie upadłościowe (GUp)', $r['sygnatury']['LD1M/GUp/16/2026'] ?? null);
    assert_eq('zgłoszenia wierzytelności (Zw)', $r['sygnatury']['LD1M/GUp-s-Zw/16/2026'] ?? null);
    assert_eq([], $r['ostrzezenia']);
}

function test_krz_document_read_by_title_and_assigned_term(): void {
    $text = "ZARZĄDZENIE SYNDYKA O WEZWANIU DO UZUPEŁNIENIA BRAKÓW FORMALNYCH ZGŁOSZENIA WIERZYTELNOŚCI\n"
        ."Syndyk wzywa wierzyciela do uzupełnienia braków w terminie tygodnia pod rygorem zwrotu. WA1M/GUp-s-Zw/3/2026";
    $r = KrzDokument::read($text, krz_doc_catalog());
    assert_eq('11172ZA', $r['kod'], 'rozpoznanie po tytule');
    assert_true(str_contains(implode(' ', $r['ostrzezenia']), 'po tytule'), 'ostrzeżenie o rozpoznaniu bez kodu');
    assert_eq('tygodnia', $r['termin_w_tresci']);
    assert_true($r['data_dokumentu'] === null, 'brak daty = puste pole, nie zgadujemy');
}

function test_krz_document_ambiguous_or_unknown_stays_empty(): void {
    $two = KrzDokument::read('11176OB oraz 11171ZA', krz_doc_catalog());
    assert_true($two['kod'] === null && str_contains($two['ostrzezenia'][0], 'kilka kodów'));
    $none = KrzDokument::read('Faktura VAT nr 1/2026 z dnia 2026-01-31', krz_doc_catalog());
    assert_true($none['kod'] === null && str_contains($none['ostrzezenia'][0], 'Nie rozpoznano'));
    assert_eq(['2026-01-31'], $none['daty']);
    assert_true(KrzDokument::iso('31.02.2026') === null, 'nieistniejąca data odrzucona');
}

function test_krz_deadlines_follow_kc_rules(): void {
    // 2 tygodnie od piątku 20.11.2026 → piątek 4.12.2026
    assert_eq('2026-12-04', KrzTerminy::end('2026-11-20', 14));
    // koniec 24.12.2026 (wolne od 2025), 25–27.12 → poniedziałek 28.12
    assert_eq('2026-12-28', KrzTerminy::end('2026-12-10', 14));
    // koniec 11.11 (święto) → 12.11
    assert_eq('2026-11-12', KrzTerminy::end('2026-10-28', 14));
    // koniec w sobotę 14.11 → poniedziałek 16.11 (art. 115 k.c.)
    assert_eq('2026-11-16', KrzTerminy::end('2026-11-07', 7));
    // Poniedziałek Wielkanocny 6.04.2026 → 7.04; Boże Ciało 4.06.2026 → 5.06
    assert_eq('2026-04-07', KrzTerminy::end('2026-03-30', 7));
    assert_eq('2026-06-05', KrzTerminy::end('2026-05-28', 7));
    // 30 dni na zgłoszenie od 5.10.2026 → 4.11.2026 (środa)
    assert_eq('2026-11-04', KrzTerminy::end('2026-10-05', 30));
    assert_true(KrzTerminy::end('2026-02-30', 7) === null);
    assert_eq(-2, KrzTerminy::daysLeft('2026-11-18', '2026-11-20'));
}

function test_krz_deadline_rules_cover_triggers(): void {
    $s = KrzTerminy::rule('11176OB');
    assert_true($s['pismo'] === '30050' && $s['dni'] === 14 && $s['od'] === 'obwieszczenie' && $s['pewne']);
    assert_true(KrzTerminy::rule('11172ZA')['pewne'] === false, 'uzupełnienie braków: początek biegu do potwierdzenia');
    assert_eq('art. 242a ust. 4 p.u.', KrzTerminy::rule('11171ZA')['podstawa']);
    assert_eq(30, KrzTerminy::rule('11051OB')['dni']);
    assert_true(KrzTerminy::rule('10187OB')['pewne'] === false, 'konsumencka do potwierdzenia');
    assert_true(KrzTerminy::rule('99999XX') === null);
    foreach (['11176OB','11172ZA','11171ZA','11217OB','11218OB','50016OB','11182PO','10145PO','11051OB','14001OB'] as $k) {
        assert_true(isset(krz_doc_catalog()[$k]), "reguła dla kodu spoza katalogu: $k");
    }
}
