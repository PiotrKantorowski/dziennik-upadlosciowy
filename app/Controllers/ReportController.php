<?php
namespace Duir\Controllers;

use Duir\Config;
use Duir\Repository;
use Duir\Services\{ReportService,Mailer};
use Duir\Support\Http;
use Duir\Support\Csrf;

final class ReportController extends BaseController
{
    public function __construct(private Repository $repo, private ReportService $reports) {}
    public function daily(): void
    {
        $r=$this->reports->createDailyReport();
        $this->header('Raport dzienny');
        $sentToday = (string)$this->repo->setting('daily_report_sent_date') === date('Y-m-d');
        echo '<section class="card"><h2>'.($r['events'] ? 'Nowe wpisy: '.count($r['events']) : 'Brak nowych wpisów').'</h2>'
            .($r['events'] ? '<div class="chips">'.$this->riskChip($r['risk']).'</div>' : '<p class="muted">W ostatnich 24 godzinach żadne monitorowane źródło nie przyniosło nowych wpisów.</p>')
            .'<p class="muted">E-mail z raportem wychodzi automatycznie w polskie dni robocze po porannym przebiegu wtyczek (po '.ReportService::DAILY_SEND_AFTER.'). '
            .($sentToday ? 'Dzisiejszy raport został już wysłany.' : 'Dzisiejszy raport nie został jeszcze wysłany automatycznie.').'</p>'
            .'<div class="actions"><a class="btn" href="/reports/daily/pdf">Pobierz PDF</a>'
            .'<form method="post" action="/reports/daily/send">'.Csrf::field().'<button class="btn primary">Wyślij e-mail teraz</button></form></div></section>';
        // PER PODMIOT: sekcja na każdy podmiot z nowymi wpisami + buforowana ocena AI.
        if ($r['events']) {
            $bySubject = [];
            foreach ($r['events'] as $ev) $bySubject[(int)($ev['subject_id'] ?? 0)][] = $ev;
            foreach ($bySubject as $sid => $list) {
                $name = (string)($list[0]['subject_name'] ?? '—');
                echo '<section class="card"><h2><a href="/subjects/'.$sid.'">'.Http::e(mb_substr($name,0,80,'UTF-8')).'</a></h2>';
                echo '<table><tr><th>Źródło</th><th>Nowy wpis</th><th>Ryzyko</th><th>Data wpisu</th></tr>';
                foreach ($list as $ev) {
                    $when = ($ev['publication_date'] ?? '') ?: mb_substr((string)($ev['created_at'] ?? ''), 0, 10);
                    echo '<tr><td>'.Http::e($ev['source'] ?? '').'</td>'
                        .'<td>'.Http::e($ev['title'] ?? '').(!empty($ev['signature'])?' <span class="muted">'.Http::e($ev['signature']).'</span>':'').'</td>'
                        .'<td>'.$this->riskChip((string)($ev['risk'] ?? '')).'</td>'
                        .'<td>'.Http::e($when).'</td></tr>';
                }
                echo '</table>';
                $assessment = trim((string)(($r['assessments'] ?? [])[$sid] ?? ''));
                if ($assessment !== '') {
                    echo '<div style="margin-top:10px;border-left:4px solid #2448a8;background:#f7f9fc;border-radius:0 9px 9px 0;padding:12px 16px;line-height:1.6">'
                        .'<p class="muted" style="margin:0 0 6px;font-size:.8rem;text-transform:uppercase;letter-spacing:.05em">Ocena sytuacji (AI)</p>'
                        .ReportService::llmTextToHtml(mb_substr($assessment,0,1400,'UTF-8')).'</div>';
                }
                echo '</section>';
            }
        }
        // Zbiorczo WYŁĄCZNIE stan monitoringu: kogo sprawdzono, kogo się nie udało.
        $monitoring = $r['monitoring'] ?? [];
        if ($monitoring) {
            $failures = ReportService::monitoringFailures($monitoring);
            if ($failures) {
                echo '<section class="card" style="border-left:5px solid #b42318"><h2 style="color:#b42318">Nie udało się w pełni sprawdzić</h2><ul>';
                foreach ($failures as $f) echo '<li><b>'.Http::e($f['name']).'</b>: '.Http::e(implode('; ', $f['problems'])).'</li>';
                echo '</ul><p class="muted">Najczęstsza przyczyna: wtyczka Chrome nie działała na żadnym komputerze — sprawdź, czy przeglądarka z wtyczką jest uruchomiona.</p></section>';
            }
            echo '<section class="card"><h2>Stan monitoringu (ostatnie 24 h)</h2><table><tr><th>Podmiot</th><th>KRS</th><th>CEIDG</th><th>KRZ</th><th>MSiG</th></tr>';
            foreach ($monitoring as $m) {
                echo '<tr><td><b>'.Http::e(mb_substr((string)$m['name'],0,60,'UTF-8')).'</b></td>';
                foreach (['KRS','CEIDG','KRZ','MSIG'] as $src) {
                    if (!isset($m['sources'][$src])) { echo '<td class="muted">—</td>'; continue; }
                    [$txt,$fg,$bg] = ReportService::checkStatusLabel((string)$m['sources'][$src]['status']);
                    echo '<td><span style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:.78rem;font-weight:700;color:'.$fg.';background:'.$bg.'">'.Http::e($txt).'</span></td>';
                }
                echo '</tr>';
            }
            echo '</table></section>';
        }
        $this->footer();
    }
    public function dailyPdf(): void
    {
        $r=$this->reports->createDailyReport(); header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="raport-dzienny-duir.pdf"'); readfile($r['pdf_path']);
    }
    public function dailySend(): void
    {
        $r=$this->reports->createDailyReport(); $to=(string)Config::get('REPORT_TO',''); $m=new Mailer();
        $html=$this->reports->renderDailyReportHtml($r['events'], $r['risk'], $r['summary'], $r['assessments'] ?? [], $r['monitoring'] ?? []);
        // BEZ załącznika PDF — treścią maila jest raport HTML.
        try{$m->send($to,'Raport dzienny DUiR',$m->buildDailyBody($r),null,$html); $this->repo->saveOutgoingMail(null,$to,'Raport dzienny DUiR','sent');}
        catch(\Throwable $e){$this->repo->saveOutgoingMail(null,$to,'Raport dzienny DUiR','error',$e->getMessage());}
        Http::redirect('/reports/daily');
    }
}
