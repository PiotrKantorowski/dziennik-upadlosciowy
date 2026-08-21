<?php
use Duir\Services\Mailer;
// Raport dzienny jest PER PODMIOT: każdy podmiot z nowymi wpisami ma własną
// sekcję (nazwa + jego wpisy), a zbiorczo raportowany jest tylko stan monitoringu.
function test_daily_mail_body_is_grouped_per_subject(): void {
    $body = (new Mailer())->buildDailyBody(['risk'=>'wysoki','summary'=>'','events'=>[
        ['risk'=>'wysoki','subject_name'=>'Alfa Sp. z o.o.','source'=>'KRZ','title'=>'KRZ: postępowanie układowe','publication_date'=>'2026-07-12'],
        ['risk'=>'średni','subject_name'=>'Beta S.A.','source'=>'MSIG','title'=>'MSiG: ogłoszenie','publication_date'=>'2026-07-13'],
    ]]);
    assert_true(str_contains($body, 'Raport dzienny DUiR'));
    assert_true(str_contains($body, 'ALFA SP. Z O.O.'), 'sekcja pierwszego podmiotu');
    assert_true(str_contains($body, 'BETA S.A.'), 'sekcja drugiego podmiotu');
    assert_true(str_contains($body, 'postępowanie układowe'));
    assert_true(str_contains($body, 'HTML'));
}

// Brak nowych wpisów => krótka informacja zamiast pustych sekcji; a podmioty,
// których nie udało się sprawdzić, są wskazane wprost (feedback kancelarii).
function test_daily_mail_body_reports_monitoring_failures(): void {
    $body = (new Mailer())->buildDailyBody(['risk'=>'niski','summary'=>'','events'=>[],'monitoring'=>[
        ['id'=>1,'name'=>'Alfa Sp. z o.o.','pending'=>true,'sources'=>['KRS'=>['status'=>'success','at'=>date('Y-m-d H:i:s')],'KRZ'=>['status'=>'pending','at'=>date('Y-m-d H:i:s')],'MSIG'=>['status'=>'no_results','at'=>date('Y-m-d H:i:s')]]],
    ]]);
    assert_true(str_contains($body, 'Brak nowych wpisów'));
    assert_true(str_contains($body, 'NIE UDAŁO SIĘ W PEŁNI SPRAWDZIĆ'));
    assert_true(str_contains($body, 'Alfa Sp. z o.o.'));
    assert_true(str_contains($body, 'KRZ'));
}

// Bramka automatycznej wysyłki: raz dziennie, nie przed godziną wysyłki,
// preferencyjnie po pustej kolejce, po godzinie granicznej mimo kolejki.
function test_daily_auto_send_gate(): void {
    $today = '2026-07-13';
    $should = fn($now,$sent,$empty) => \Duir\Services\ReportService::shouldAutoSendDaily($now,$today,$sent,$empty);
    assert_true(!$should('09:59', null, true), 'przed godziną wysyłki — nie');
    assert_true($should('10:01', null, true), 'po godzinie + pusta kolejka — tak');
    assert_true(!$should('10:01', null, false), 'kolejka pracuje — czekaj');
    assert_true($should('10:46', null, false), 'po godzinie granicznej — wysyłaj mimo kolejki');
    assert_true(!$should('11:00', $today, true), 'już wysłano dziś — nie dubluj');
    assert_true($should('10:05', '2026-07-12', true), 'wczorajsza flaga nie blokuje');
}

// Regresja z 2026-07-14: raport wyszedł 10:00:03 ze stanem sprzed doby, bo kolejka
// była pusta TYLKO dlatego, że dzisiejsze zadania jeszcze nie powstały (wtyczki
// startują od 10:00). Pusta kolejka bez dzisiejszych wyników KRZ/MSiG => czekaj
// do godziny granicznej.
function test_daily_auto_send_waits_for_todays_sweep(): void {
    $today = '2026-07-13';
    $should = fn($now,$empty,$sweep) => \Duir\Services\ReportService::shouldAutoSendDaily($now,$today,null,$empty,$sweep);
    assert_true(!$should('10:01', true, false), 'pusta kolejka, ale sweep dziś nic nie dostarczył — czekaj');
    assert_true($should('10:01', true, true), 'pusta kolejka + dzisiejsze wyniki — wysyłaj');
    assert_true($should('10:46', true, false), 'po godzinie granicznej — wysyłaj nawet bez sweepa (z uczciwą sekcją stanu)');
    assert_true(!$should('10:20', false, true), 'kolejka jeszcze pracuje — czekaj mimo częściowych wyników');
}

function test_daily_auto_send_skips_polish_weekends_and_holidays(): void {
    $gate = fn(string $date) => \Duir\Services\ReportService::shouldAutoSendDaily('11:00', $date, null, true);
    assert_true(!$gate('2026-07-12'), 'niedziela — raport automatyczny nie wychodzi');
    assert_true($gate('2026-07-13'), 'zwykły poniedziałek — raport wychodzi');
    assert_true(!$gate('2026-04-06'), 'Poniedziałek Wielkanocny — nie wychodzi');
    assert_true(!$gate('2026-06-04'), 'Boże Ciało — nie wychodzi');
    assert_true(!$gate('2026-12-24'), 'Wigilia jest ustawowo wolna od 2025 r.');
    assert_true(!$gate('2026-11-11'), 'stałe święto ustawowe — nie wychodzi');
}
function test_recipients_accepts_semicolon_comma_and_space(): void {
    $r = (new Mailer())->recipients('a@example.com; b@example.com, c@example.com');
    assert_eq(count($r), 3);
}

function test_production_cron_injects_daily_report_service(): void {
    $cron = file_get_contents(dirname(__DIR__).'/cron.php');
    assert_true(str_contains($cron, 'ReportService'), 'zalecany cron.php musi znać usługę raportu dziennego');
    assert_true(str_contains($cron, 'new ReportService($repo)'), 'cron.php musi przekazać ReportService do CronController');
}
