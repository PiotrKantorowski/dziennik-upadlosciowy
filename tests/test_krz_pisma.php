<?php
use Duir\KrzPisma\KrzForms;

function krz_pisma_sample(): array {
    return KrzForms::fromPost([
        'sygnatura' => 'RZ1Z/GUp/12/2026',
        'tryb' => 'pelnomocnik',
        'wierzyciel' => ['oznaczenie'=>'podmiot','nazwa'=>'Przykład sp. z o.o.','krs'=>'0000897641','nip'=>'5170383178',
            'kraj'=>'Polska','kod'=>'35-345','miejscowosc'=>'Rzeszów','ulica'=>'Baczyńskiego','nr_budynku'=>'6B','email'=>'biuro@example.com'],
        'reprezentanci' => [['imie'=>'Jan','nazwisko'=>'Kowalski','pesel'=>'44051401359','funkcja'=>'prezes zarządu'], ['imie'=>'','nazwisko'=>'']],
        'pelnomocnik' => ['rodzaj'=>'profesjonalny','imie'=>'Anna','nazwisko'=>'Nowak','id_konta'=>'K-1','kraj'=>'Polska','kod'=>'35-345','miejscowosc'=>'Rzeszów','nr_budynku'=>'6B','telefon'=>'123'],
        'wierzytelnosci' => [
            ['typ'=>'glowna','tytul'=>'FV 1/2026','kwota'=>'1 234,50','waluta'=>'PLN','kategoria'=>'II','dowody'=>'faktura','odsetki_od'=>'2026-01-01'],
            ['typ'=>'odsetki','tytul'=>'odsetki od FV 1/2026','kwota'=>'100','kategoria'=>'III','odsetki_od'=>'2026-01-15','odsetki_do'=>'2026-06-30','dowody'=>'wyliczenie'],
            ['typ'=>'glowna','waluta'=>'PLN','kategoria'=>''],
        ],
        'bank' => 'Bank', 'rachunek' => 'PL61109010140000071219812874',
        'zalaczniki' => "pelnomocnictwo.pdf\n\nfaktura.pdf",
    ]);
}

function test_krz_pisma_catalog_has_all_patterns(): void {
    $rows = KrzForms::catalog(dirname(__DIR__).'/app/KrzPisma/wzory.tsv');
    assert_eq(367, count($rows), 'katalog wzorów KRZ');
    $codes = array_column($rows, 'kod');
    foreach (['30048','20177','30001','70016','70070'] as $c) assert_true(in_array($c, $codes, true), "brak wzoru $c");
    foreach ($rows as $r) assert_true(in_array($r['kto'], ['uczestnik','organ'], true), 'kto: '.$r['kod']);
    assert_eq(count($codes), count(array_unique($codes)), 'kody wzorów muszą być unikalne');
}

function test_krz_pisma_id_validators(): void {
    assert_true(KrzForms::validPesel('44051401359'));
    assert_true(!KrzForms::validPesel('44051401358'));
    assert_true(KrzForms::validNip('517-038-31-78'));
    assert_true(!KrzForms::validNip('5170383179'));
    assert_true(KrzForms::validIban('PL61 1090 1014 0000 0712 1981 2874'));
    assert_true(KrzForms::validIban('61109010140000071219812874'), 'NRB bez PL');
    assert_true(!KrzForms::validIban('PL61109010140000071219812875'));
    assert_eq(1234.5, KrzForms::amount('1 234,50'));
    assert_eq(1234.5, KrzForms::amount('1.234,50'));
    assert_true(KrzForms::amount('abc') === null);
}

function test_krz_pisma_from_post_cleans_rows(): void {
    $d = krz_pisma_sample();
    assert_eq(1, count($d['reprezentanci']), 'pusty reprezentant odrzucony');
    assert_eq(2, count($d['wierzytelnosci']), 'pozycja z samymi wartościami domyślnymi odrzucona');
    assert_eq('1234.50', $d['wierzytelnosci'][0]['kwota']);
    assert_eq('0000897641', KrzForms::fromPost(['wierzyciel'=>['krs'=>'897641']])['wierzyciel']['krs'], 'KRS z zerami wiodącymi');
    $junk = KrzForms::fromPost(['tryb'=>'x','wierzyciel'=>['oznaczenie'=>'x','obce'=>'pole'],'wierzytelnosci'=>[['typ'=>'zly','kwota'=>'5']]]);
    assert_eq('pelnomocnik', $junk['tryb']);
    assert_eq('podmiot', $junk['wierzyciel']['oznaczenie']);
    assert_true(!isset($junk['wierzyciel']['obce']), 'nieznane pola odrzucone');
    assert_eq('glowna', $junk['wierzytelnosci'][0]['typ']);
}

function test_krz_pisma_validation(): void {
    $v = KrzForms::validate(krz_pisma_sample());
    assert_eq([], $v['errors'], 'kompletne zgłoszenie bez błędów');
    $empty = KrzForms::validate(KrzForms::fromPost([]));
    $all = implode(' | ', $empty['errors']);
    foreach (['sygnatury', 'nazwę', 'reprezentanta', 'wierzytelność', 'Pełnomocnik'] as $needle) assert_true(str_contains($all, $needle), "brak błędu: $needle");
    $bad = krz_pisma_sample();
    $bad['wierzyciel']['nip'] = '5170383179';
    $bad['rachunek'] = 'PL00109010140000071219812874';
    $bad['wierzytelnosci'][1]['kategoria'] = 'II';
    $vb = KrzForms::validate($bad);
    assert_true(str_contains(implode(' ', $vb['errors']), 'NIP'), 'błędny NIP');
    assert_true(str_contains(implode(' ', $vb['errors']), 'rachunku'), 'błędny rachunek');
    assert_true(str_contains(implode(' ', $vb['warnings']), 'art. 342'), 'odsetki poza kat. III');
}

function test_krz_pisma_card_follows_krz_order(): void {
    $card = KrzForms::card(krz_pisma_sample());
    $metryki = array_values(array_unique(array_map(fn($r) => explode(' ', $r[0])[0], $card)));
    assert_eq(['I.','II.','III.','IV.','V.','VI.','VII.'], $metryki, 'kolejność metryk PUZ');
    $glowna = array_filter($card, fn($r) => str_starts_with($r[0], 'IV. Wierzytelność 1'));
    assert_true(!array_filter($glowna, fn($r) => str_starts_with($r[1], 'Odsetki')), 'pola odsetek tylko przy typie odsetki');
    $zal = array_filter($card, fn($r) => $r[0] === 'VII. Załączniki');
    assert_eq(2, count($zal), 'puste wiersze listy załączników pominięte');
    $s = KrzForms::summary(krz_pisma_sample());
    assert_eq(1334.5, $s['razem_pln']);
    assert_eq(100.0, $s['pln']['III']['odsetki']);
}

function test_krz_pisma_is_wired_and_read_only(): void {
    $root = dirname(__DIR__);
    $index = file_get_contents($root.'/index.php');
    foreach (["'/krz'", "'/krz/wzory'", "'/krz/pisma/zapisz' && \$method==='POST'", "'/api/krz/pisma'"] as $route) assert_true(str_contains($index, $route), "brak trasy $route");
    assert_true(str_contains(file_get_contents($root.'/app/Controllers/BaseController.php'), 'href="/krz"'), 'zakładka w menu');
    $api = file_get_contents($root.'/app/Controllers/KrzApiController.php');
    $fn = substr($api, strpos($api, 'function pismaList'), 600);
    assert_eq(2, substr_count($fn, '$this->guard();'), 'API pism wymaga tokenu wtyczki');
    // Moduł ma się dać przenieść do LexPilota: zero zależności od monitoringu DUiR.
    foreach (glob($root.'/app/KrzPisma/*.php') as $f) {
        $src = file_get_contents($f);
        assert_true(!preg_match('/use Duir\\\\(Repository|Services|Controllers|Support)/', $src), basename($f).' nie może zależeć od DUiR');
    }
    $manifest = json_decode(file_get_contents($root.'/chrome_extension/manifest.json'), true);
    $js = array_merge(...array_map(fn($cs) => $cs['js'] ?? [], $manifest['content_scripts']));
    assert_true(in_array('content_puz.js', $js, true));
    $bg = file_get_contents($root.'/chrome_extension/background.js');
    assert_true(str_contains($bg, 'importScripts("pisma_bg.js")') && str_contains($bg, 'handlePismaMessage'));
    // Prototyp niczego nie wpisuje i nie klika w KRZ.
    $puz = file_get_contents($root.'/chrome_extension/content_puz.js');
    foreach (['.click(', 'dispatchEvent', '.value =', 'submit'] as $forbidden) assert_true(!str_contains($puz, $forbidden), "content_puz.js: $forbidden");
}
