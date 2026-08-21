<?php
use Duir\Services\{Mailer,PdfReport};
function test_mail_body_contains_risk_and_pdf_notice(): void {
    $m = new Mailer();
    $body = $m->buildSubjectBody(['name'=>'ABC'], 'wysoki', [['x'=>1]], 'Ryzyko wynika z KRZ.');
    assert_true(str_contains($body, 'Stopień ryzyka: wysoki'));
    assert_true(str_contains($body, 'HTML'));
}
function test_pdf_has_pdf_header(): void {
    $pdf = (new PdfReport())->subjectPdf(['name'=>'ABC'], [], 'niski', 'test');
    assert_true(str_starts_with($pdf, '%PDF-1.4'));
}
function test_mail_subject_strips_header_injection(): void {
    $injected = "Raport DUiR: Podmiot\r\nBcc: evil@example.com";
    $safe = Mailer::sanitizeHeader($injected);
    assert_true(!str_contains($safe, "\r"), 'temat nie może zawierać CR');
    assert_true(!str_contains($safe, "\n"), 'temat nie może zawierać LF');
    assert_true(str_contains($safe, 'Bcc: evil@example.com') === false || substr_count($safe, "\n") === 0);
    assert_eq($safe, 'Raport DUiR: PodmiotBcc: evil@example.com');
}

// Raport HTML: samodzielna strona z podsumowaniem LLM, sytuacją aktualną i historią;
// treści zdarzeń przechodzą przez escaping (bez wstrzykiwania HTML ze skrapowanych stron).
function test_subject_report_html_renders(): void {
    $svc = new \Duir\Services\ReportService(new \Duir\Repository(new PDO('sqlite::memory:')));
    $events = [
        ['source'=>'MSIG','title'=>'MSiG: Ogłoszenie o możliwości przeglądania planu podziału','risk'=>'wysoki','risk_reason'=>'Aktywne postępowanie.','publication_date'=>'2025-05-06','signature'=>'BMSIG-1/2025','description'=>"Poz. 1. TESTOWA SA w Rzeszowie. sygn. akt V GUp 1/14."],
        ['source'=>'MSIG','title'=>'MSiG: starszy wpis <script>alert(1)</script>','risk'=>'średni','risk_reason'=>'x','publication_date'=>'2022-01-01','signature'=>null,'description'=>''],
    ];
    $html = $svc->renderSubjectReportHtml(['id'=>1,'name'=>'TESTOWA SA','krs'=>'0000123456'], $events, 'wysoki', "**Wniosek:** upadłość.\n* punkt", false);
    assert_true(str_starts_with($html, '<!doctype html'), 'pełny dokument HTML');
    assert_true(str_contains($html, 'TESTOWA SA'));
    assert_true(str_contains($html, 'Sytuacja aktualna'));
    assert_true(str_contains($html, 'Historia (starsze wpisy: 1)'));
    assert_true(str_contains($html, '<b>Wniosek:</b>'), 'markdown LLM zamieniony na HTML');
    assert_true(!str_contains($html, '<script>alert(1)</script>'), 'treści zdarzeń muszą być escapowane');
    assert_true(str_contains($html, 'Drukuj / zapisz jako PDF'), 'wersja przeglądarkowa ma pasek narzędzi');
    $email = $svc->renderSubjectReportHtml(['id'=>1,'name'=>'TESTOWA SA'], $events, 'wysoki', 'Wniosek.', true);
    assert_true(!str_contains($email, 'Drukuj / zapisz'), 'wersja e-mail bez paska narzędzi');
}

// Raport dzienny jest PER PODMIOT: sekcja na każdy podmiot z nowymi wpisami
// (podmiot z najwyższym ryzykiem pierwszy), zbiorczo tylko stan monitoringu.
function test_daily_report_html_maps_events_to_subjects(): void {
    $svc = new \Duir\Services\ReportService(new \Duir\Repository(new PDO('sqlite::memory:')));
    $events = [
        ['subject_id'=>2,'subject_name'=>'BETA <script>x</script>','source'=>'KRS','title'=>'KRS: status','risk'=>'średni','publication_date'=>null,'created_at'=>'2026-07-12 06:10:00'],
        ['subject_id'=>1,'subject_name'=>'ALFA SA','source'=>'MSIG','title'=>'MSiG: plan podziału','risk'=>'wysoki','publication_date'=>'2026-07-11','created_at'=>'2026-07-12 06:00:00'],
    ];
    $monitoring = [
        ['id'=>1,'name'=>'ALFA SA','pending'=>false,'sources'=>['KRS'=>['status'=>'success','at'=>date('Y-m-d H:i:s')],'KRZ'=>['status'=>'pending','at'=>date('Y-m-d H:i:s')],'MSIG'=>['status'=>'success','at'=>date('Y-m-d H:i:s')]]],
    ];
    $html = $svc->renderDailyReportHtml($events, 'wysoki', '', [1=>'Ocena testowa ALFY.'], $monitoring);
    assert_true(str_contains($html, 'ALFA SA'));
    assert_true(!str_contains($html, '<script>x</script>'), 'nazwy podmiotów muszą być escapowane');
    assert_true(str_contains($html, 'MSiG: plan podziału'));
    // Podmiot o wyższym ryzyku (ALFA=wysoki) ma sekcję PRZED podmiotem średnim.
    assert_true(mb_strpos($html, 'ALFA SA') < mb_strpos($html, 'BETA '), 'sortowanie sekcji po ryzyku');
    assert_true(str_contains($html, 'Ocena testowa ALFY.'), 'buforowana ocena AI w sekcji podmiotu');
    assert_true(str_contains($html, 'Stan monitoringu'), 'zbiorcza tabela stanu monitoringu');
    assert_true(str_contains($html, 'Nie udało się w pełni sprawdzić'), 'sekcja niepowodzeń przy wiszącym KRZ');
}
