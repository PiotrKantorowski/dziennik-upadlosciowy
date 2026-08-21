<?php
namespace Duir\Services;

use Duir\Repository;

final class ReportService
{
    public function __construct(private Repository $repo, private ?LlmClient $llm = null, private ?PdfReport $pdf = null)
    { $this->llm ??= new LlmClient(); $this->pdf ??= new PdfReport(); }

    public function subjectSummary(array $subject, array $events, string $risk): string
    {
        $basis = array_slice(array_map(fn($e)=>($e['source']??'').': '.($e['title']??'')
            .(!empty($e['publication_date'])?' ('.$e['publication_date'].')':'')
            .' — '.($e['risk_reason']??''), $events), 0, 8);
        $fallback = $events ? ('Ryzyko '.$risk.' wynika głównie z: '.implode('; ', array_slice($basis, 0, 6))) : 'Brak potwierdzonych wpisów w KRZ/MSiG/KRS. Ryzyko niskie tylko wtedy, gdy źródła odpowiedziały poprawnie.';
        // RODO: nazwy podmiotu ani identyfikatorów nie wysyłamy do zewnętrznego LLM —
        // raport (PDF/e-mail) dodaje je poza tym tekstem. Do LLM idą tylko tytuły
        // zdarzeń i uzasadnienia ryzyka, jak w raporcie dziennym.
        $prompt = "Przygotuj podsumowanie wyniku monitoringu jednego kontrahenta. Nie podawaj jego nazwy ani identyfikatorów — raport doda je poza tym tekstem.\n"
            ."Poziom ryzyka: $risk.\n"
            ."Ustalenia (źródło: tytuł (data) — uzasadnienie):\n".implode("\n", $basis)."\n"
            ."Napisz 4–8 zdań albo krótkie punkty: 1) najważniejszy wniosek i uzasadnienie poziomu ryzyka, "
            ."2) co wykryto w poszczególnych źródłach (z sygnaturami i datami, jeśli są), "
            ."3) jedno–dwa zalecane działania (co zweryfikować ręcznie, jakiego terminu pilnować).";
        return $this->llm->summarize($prompt, $fallback);
    }

    /**
     * Ocena sytuacji i zalecenia dla prawnika — generowana przez LLM po każdym
     * sprawdzeniu z nowymi zdarzeniami i buforowana w tabeli reports (type=assessment).
     * Cel: nie przepisywać wpisów, tylko powiedzieć wprost CO SIĘ DZIEJE i CO ZROBIĆ.
     * Zwraca '' gdy LLM niedostępny (karta po prostu nie pokazuje sekcji).
     */
    public function subjectAssessment(array $subject, array $events): string
    {
        if (!$events) return '';
        $redacted = self::redactEventsForLlmAssessment(array_slice($events, 0, 20));
        $knowledge = self::loadAssessmentKnowledge();
        // Ogólny poziom ryzyka = najwyższy wśród zdarzeń — ocena ma się od niego zacząć.
        $rank = ['krytyczny'=>4,'wysoki'=>3,'średni'=>2,'niski'=>1];
        $overall = 'niski';
        foreach ($events as $ev) if (($rank[$ev['risk'] ?? 'niski'] ?? 1) > ($rank[$overall] ?? 1)) $overall = (string)$ev['risk'];
        // Sprawozdanie finansowe (KRS) — okres i data złożenia to fakty o sprawie,
        // nie dane osobowe; podajemy modelowi, żeby mógł je uwzględnić w ocenie.
        $finLine = '';
        $fin = $this->repo->latestFinancialCheck((int)($subject['id'] ?? 0));
        if ($fin) {
            $finLine = "Sprawozdanie finansowe (KRS): ostatnie za okres do ".(($fin['period_to'] ?? '') ?: 'nieznany')
                .", data złożenia ".(($fin['submitted_at'] ?? '') ?: 'brak informacji')
                .", terminowość: ".(string)($fin['status'] ?? 'nieustalona').".\n";
        }
        $prompt = "Oceń sytuację jednego kontrahenta na podstawie zdarzeń z monitoringu rejestrów (KRZ/MSiG/KRS). "
            ."Nie podawaj nazwy podmiotu ani identyfikatorów — interfejs pokazuje je obok.\n"
            .($knowledge !== '' ? "Wewnętrzna baza wiedzy kancelarii (red flags, matryca ryzyka, reguły postępowania) — STOSUJ ją przy formułowaniu znaczenia i zaleceń:\n---\n".$knowledge."\n---\n" : '')
            ."Wyliczony poziom ryzyka: $overall.\n".$finLine
            ."Zdarzenia (od najnowszych). Pole \"istotne_dane\" to wyłuskane z ogłoszeń KONKRETY "
            ."(terminy, czynności, sąd, kwoty), a \"signature\" to sygnatura sprawy — OPRZYJ na "
            ."nich STAN, ZNACZENIE i ZALECENIA:\n"
            .json_encode($redacted, JSON_UNESCAPED_UNICODE)."\n\n"
            ."Odpowiedz DOKŁADNIE w tej strukturze, po polsku, zwięźle:\n"
            ."OCENA RYZYKA: $overall — jedno zdanie ogólnej konkluzji (dlaczego taki poziom).\n"
            ."STAN: 1–2 zdania — co dzieje się z podmiotem teraz; NAZWIJ WPROST rodzaj każdego istotnego postępowania (np. upadłościowe, restrukturyzacyjne, o zatwierdzenie układu), nie pisz ogólnie „postępowanie\".\n"
            ."ZNACZENIE: 2–3 zdania — co KONKRETNIE z tych ogłoszeń wynika dla wierzyciela/kontrahenta (użyj faktów: terminów, etapu, kwot).\n"
            ."ZALECENIA:\n- 2 do 4 konkretnych czynności prawnika (np. zgłoszenie wierzytelności w podanym terminie, przegląd planu podziału, kontakt z syndykiem/nadzorcą, weryfikacja akt), z sygnaturami, terminami i datami z danych, jeśli są; dobieraj czynności zgodnie z regułami z bazy wiedzy.\n"
            ."Opieraj się WYŁĄCZNIE na przekazanych zdarzeniach i faktach; jeśli danego szczegółu nie ma w danych, napisz to wprost zamiast zgadywać.";
        return trim($this->llm->summarize($prompt, ''));
    }

    /**
     * Baza wiedzy do ocen: pliki knowledge/*.md (kurowany zestaw z paczki "biały wywiad
     * kontrahentów" — red flags KRS/KRZ, matryca ryzyka, reguły IF-THEN, co robić po
     * wykryciu). Frontmatter YAML jest odcinany, całość przycinana do bezpiecznego
     * limitu promptu. Brak katalogu = pusta baza (ocena działa dalej, tylko ogólniej).
     */
    public static function loadAssessmentKnowledge(int $maxChars = 28000): string
    {
        $dir = dirname(__DIR__, 2).'/knowledge';
        if (!is_dir($dir)) return '';
        $parts = [];
        foreach (glob($dir.'/*.md') ?: [] as $file) {
            $body = (string)@file_get_contents($file);
            if ($body === '') continue;
            // Odetnij frontmatter (--- ... ---) z początku pliku.
            $body = preg_replace('/\A---\R.*?\R---\R/su', '', $body) ?? $body;
            $parts[] = trim($body);
        }
        if (!$parts) return '';
        return mb_substr(implode("\n\n", $parts), 0, $maxChars, 'UTF-8');
    }

    public function createSubjectReport(array $subject): array
    {
        $this->repo->purgeLegacyKrsJsonEvents((int)$subject['id']);
        $events = $this->repo->latestEvents((int)$subject['id']);
        $risk = $this->repo->maxRiskForSubject((int)$subject['id']);
        $summary = $this->subjectSummary($subject,$events,$risk);
        $pdfPath = $this->pdf->saveSubjectPdf(dirname(__DIR__,2).'/storage/reports', $subject, $events, $risk, $summary);
        $html = self::llmTextToHtml($summary);
        $id = $this->repo->saveReport((int)$subject['id'], 'subject', 'Raport: '.$subject['name'], $summary, $html, $pdfPath);
        return ['id'=>$id,'events'=>$events,'risk'=>$risk,'summary'=>$summary,'pdf_path'=>$pdfPath];
    }

    public function createDailyReport(): array
    {
        $this->repo->purgeLegacyKrsJsonEvents();
        $since = (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s');
        $events = $this->repo->latestEventsSince($since);
        $max = 'niski'; $rank=['niski'=>1,'średni'=>2,'wysoki'=>3,'krytyczny'=>4];
        foreach ($events as $e) if (($rank[$e['risk']]??1)>($rank[$max]??1)) $max=$e['risk'];
        // Raport dzienny jest PER PODMIOT (feedback kancelarii): zbiorcza narracja
        // LLM nie mówiła, KOGO dotyczą wpisy. Zamiast niej: sekcje per podmiot
        // z buforowaną oceną z karty (reports.type=assessment — zero nowych
        // wywołań LLM), a zbiorczo TYLKO stan monitoringu (kogo sprawdzono,
        // kogo nie udało się sprawdzić).
        $summary = $events ? '' : 'Brak nowych wpisów w ostatnich 24 godzinach.';
        $assessments = [];
        foreach ($events as $e) {
            $sid = (int)($e['subject_id'] ?? 0);
            if ($sid && !isset($assessments[$sid])) {
                $a = $this->repo->latestAssessment($sid);
                if ($a && trim((string)($a['summary'] ?? '')) !== '') $assessments[$sid] = (string)$a['summary'];
            }
        }
        $monitoring = [];
        try { $monitoring = $this->repo->dailyMonitoringStatus(); } catch (\Throwable) {}
        $subject = ['id'=>0,'name'=>'Raport dzienny DUiR'];
        $pdfPath = $this->pdf->saveSubjectPdf(dirname(__DIR__,2).'/storage/reports',$subject,$events,$max,$summary);
        $id = $this->repo->saveReport(null, 'daily', 'Raport dzienny DUiR', $summary, self::llmTextToHtml($summary), $pdfPath);
        return ['id'=>$id,'events'=>$events,'risk'=>$max,'summary'=>$summary,'pdf_path'=>$pdfPath,'assessments'=>$assessments,'monitoring'=>$monitoring];
    }

    /**
     * Bramka automatycznej wysyłki raportu dziennego. Czysta funkcja — testowalna
     * bez bazy. Zasady: raz dziennie, nie przed godziną wysyłki (poranny przebieg
     * wtyczek startuje ~10:00), preferencyjnie po opróżnieniu kolejki wtyczek;
     * po godzinie granicznej wysyłamy MIMO niepustej kolejki — raport wtedy
     * uczciwie pokaże, kogo NIE udało się sprawdzić (np. wtyczka nie działa).
     */
    public const DAILY_SEND_AFTER = '10:00';
    public const DAILY_SEND_FORCE_AFTER = '10:45';

    /**
     * Dzień roboczy w Polsce: poniedziałek–piątek z wyłączeniem świąt ustawowych.
     * Liczymy również święta ruchome; 24 grudnia jest dniem wolnym od 2025 r.
     * Dni „oddawane” za święto w sobotę zależą od pracodawcy, więc nie da się ich
     * bezpiecznie wywnioskować globalnie i nie są tu automatycznie dodawane.
     */
    public static function isPolishBusinessDay(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
        $tz = new \DateTimeZone('Europe/Warsaw');
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);
        if (!$day || $day->format('Y-m-d') !== $date) return false;
        if ((int)$day->format('N') >= 6) return false;

        $fixed = ['01-01','01-06','05-01','05-03','08-15','11-01','11-11','12-24','12-25','12-26'];
        if (in_array($day->format('m-d'), $fixed, true)) return false;

        // Algorytm Meeusa/Jonesa/Butchera — Wielkanoc gregoriańska bez zależności
        // od opcjonalnego rozszerzenia PHP calendar.
        $year = (int)$day->format('Y');
        $a = $year % 19; $b = intdiv($year, 100); $c = $year % 100;
        $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4); $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $easterDay = (($h + $l - 7 * $m + 114) % 31) + 1;
        $easter = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $easterDay), $tz);
        $movable = [
            $easter->format('Y-m-d'),              // Wielkanoc (niedziela)
            $easter->modify('+1 day')->format('Y-m-d'),  // Poniedziałek Wielkanocny
            $easter->modify('+49 days')->format('Y-m-d'),// Zielone Świątki (niedziela)
            $easter->modify('+60 days')->format('Y-m-d'),// Boże Ciało
        ];
        return !in_array($date, $movable, true);
    }

    public static function shouldAutoSendDaily(string $nowHi, string $today, ?string $sentDate, bool $queueEmpty, bool $sweepDeliveredToday = true): bool
    {
        if ($sentDate === $today) return false;
        if (!self::isPolishBusinessDay($today)) return false;
        if ($nowHi < self::DAILY_SEND_AFTER) return false;
        // „Pusta kolejka" wystarcza TYLKO gdy dzisiejszy przebieg wtyczek już coś
        // dostarczył. O 10:00 kolejka bywa pusta, bo zadania jeszcze nie powstały
        // (wtyczki startują od 10:00) — wysłany wtedy raport oceniał kompletność
        // sprzed doby i straszył „brak sprawdzenia z 24 h" tuż przed sweepem.
        if ($queueEmpty && $sweepDeliveredToday) return true;
        return $nowHi >= self::DAILY_SEND_FORCE_AFTER;
    }

    /**
     * Automatyczna wysyłka raportu dziennego (wołana z runFinished/ping/CRON).
     * Flaga daty jest ustawiana PRZED wysyłką — dwa równoległe wywołania nie
     * wyślą dwóch maili; nieudana wysyłka ląduje w outgoing_mail ze statusem error.
     * Zwraca 'sent' | 'error' | null (nie było do wysłania).
     */
    public function autoSendDailyReportIfDue(): ?string
    {
        $to = (string)\Duir\Config::get('REPORT_TO', '');
        if (trim($to) === '') return null;
        $today = date('Y-m-d');
        $sent = $this->repo->setting('daily_report_sent_date');
        $queueEmpty = true; $sweepToday = true;
        try { $queueEmpty = !$this->repo->anyPendingBrowserTasks(); } catch (\Throwable) {}
        try { $sweepToday = $this->repo->sweepDeliveredToday(); } catch (\Throwable) {}
        if (!self::shouldAutoSendDaily(date('H:i'), $today, $sent !== null ? (string)$sent : null, $queueEmpty, $sweepToday)) return null;
        $this->repo->setSetting('daily_report_sent_date', $today);
        try {
            $r = $this->createDailyReport();
            $mailer = new Mailer();
            $html = $this->renderDailyReportHtml($r['events'], $r['risk'], $r['summary'], $r['assessments'] ?? [], $r['monitoring'] ?? []);
            $mailer->send($to, 'Raport dzienny DUiR', $mailer->buildDailyBody($r), null, $html);
            $this->repo->saveOutgoingMail(null, $to, 'Raport dzienny DUiR (auto)', 'sent');
            return 'sent';
        } catch (\Throwable $e) {
            // Rezerwacja daty chroni przed podwójną wysyłką równoległych pingów,
            // ale po zwykłym błędzie SMTP musi zostać zwolniona, aby następny ping
            // tego samego dnia mógł ponowić próbę.
            try { $this->repo->setSetting('daily_report_sent_date', ''); } catch (\Throwable) {}
            $this->repo->saveOutgoingMail(null, $to, 'Raport dzienny DUiR (auto)', 'error', $e->getMessage());
            return 'error';
        }
    }

    /**
     * Redukuje zdarzenia do bezpiecznego zestawu pól przed wysłaniem do zewnętrznego LLM.
     * Zwraca WYŁĄCZNIE source/title/risk/risk_reason — bez subject_name, krs, nip, regon, pesel,
     * raw_json i description (te pola mogą zawierać dane osobowe ze skrapowanego tekstu).
     * public + static, żeby dało się to jednostkowo przetestować bez Repository/LlmClient.
     */
    public static function redactEventsForLlm(array $events): array
    {
        return array_map(fn($e)=>[
            'source'=>$e['source'] ?? '',
            'title'=>$e['title'] ?? '',
            'risk'=>$e['risk'] ?? '',
            'risk_reason'=>$e['risk_reason'] ?? '',
        ], array_values($events));
    }

    /**
     * Raport podmiotu jako samodzielna strona HTML — używana w przeglądarce
     * (/subjects/{id}/report, z przyciskiem Drukuj/zapisz PDF) i jako treść
     * e-maila (multipart/alternative). Style wyłącznie inline — klienci poczty
     * ignorują arkusze. $forEmail pomija pasek narzędzi i skrypty.
     */
    public function renderSubjectReportHtml(array $subject, array $events, string $risk, string $summary, bool $forEmail = false): string
    {
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
        $riskColors = ['krytyczny'=>['#ffffff','#7a120b'],'wysoki'=>['#912018','#fee4e2'],'średni'=>['#93370d','#fff6dc'],'niski'=>['#027a48','#ecfdf3']];
        $chip = function (string $txt, string $key = '') use ($riskColors, $e): string {
            [$c,$b] = $riskColors[$key] ?? ['#344054','#eef2f6'];
            return '<span style="display:inline-block;padding:3px 11px;border-radius:999px;font-size:12px;font-weight:700;color:'.$c.';background:'.$b.';margin-right:6px">'.$e($txt).'</span>';
        };
        $current = []; $history = [];
        foreach ($events as $ev) { $src = (string)($ev['source'] ?? ''); if (!isset($current[$src])) $current[$src] = $ev; else $history[] = $ev; }
        $ids = [];
        foreach (['krs'=>'KRS','nip'=>'NIP','regon'=>'REGON','pesel'=>'PESEL'] as $k=>$l) if (!empty($subject[$k])) $ids[] = $l.' '.$e($subject[$k]);

        $h = '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>Raport DUiR — '.$e($subject['name'] ?? '').'</title>'
            .($forEmail ? '' : '<style>@media print{.no-print{display:none!important}body{background:#fff!important}}</style>')
            .'</head><body style="margin:0;background:#f4f6fa;font-family:Segoe UI,system-ui,Arial,sans-serif;color:#1a2233">'
            .'<div style="max-width:820px;margin:0 auto;padding:26px 18px">';
        if (!$forEmail) {
            $sid = (int)($subject['id'] ?? 0);
            $h .= '<div class="no-print" style="display:flex;gap:10px;margin-bottom:14px">'
                .'<a href="/subjects/'.$sid.'" style="padding:9px 14px;border:1px solid #d6deeb;border-radius:9px;background:#fff;color:#1a2233;text-decoration:none;font-weight:650">← Wróć do karty</a>'
                .'<a href="javascript:window.print()" style="padding:9px 14px;border-radius:9px;background:#2448a8;color:#fff;text-decoration:none;font-weight:650">🖨 Drukuj / zapisz jako PDF</a>'
                .'</div>';
        }
        // Nagłówek raportu
        $h .= '<div style="background:#fff;border:1px solid #e3e9f2;border-radius:14px;padding:22px 24px;margin-bottom:14px">'
            .'<div style="font-size:12px;letter-spacing:.08em;color:#8a94a6;font-weight:700;text-transform:uppercase">Raport DUiR — monitoring KRZ / MSiG / KRS</div>'
            .'<h1 style="margin:8px 0 6px;font-size:21px;line-height:1.35">'.$e($subject['name'] ?? '').'</h1>'
            .($ids ? '<div style="color:#5b6472;font-size:13px;margin-bottom:10px">'.implode(' &nbsp;·&nbsp; ', $ids).'</div>' : '')
            .'<div>'.$chip('ryzyko: '.$risk, $risk).$chip('wygenerowano '.date('Y-m-d H:i')).'</div>'
            .'</div>';
        // Podsumowanie LLM
        if (trim($summary) !== '') {
            $h .= '<div style="background:#fff;border:1px solid #e3e9f2;border-left:5px solid #2448a8;border-radius:14px;padding:20px 24px;margin-bottom:14px">'
                .'<h2 style="margin:0 0 10px;font-size:15px;color:#2448a8">Podsumowanie i zalecenia</h2>'
                .'<div style="font-size:14px;line-height:1.65">'.self::llmTextToHtml($summary).'</div>'
                .'<div style="margin-top:10px;color:#8a94a6;font-size:11.5px">Wygenerowane automatycznie (AI) — zweryfikuj przed podjęciem czynności.</div>'
                .'</div>';
        }
        // Sytuacja aktualna
        if ($current) {
            $h .= '<h2 style="font-size:14px;letter-spacing:.06em;text-transform:uppercase;color:#5b6472;margin:20px 4px 10px">Sytuacja aktualna</h2>';
            foreach ($current as $src => $ev) {
                $desc = RiskAnalyzer::tidyPortalText((string)($ev['description'] ?? ''));
                if ($src === 'MSIG') $desc = PdfReport::msigEssence($desc, (string)($subject['name'] ?? ''));
                $desc = mb_substr($desc, 0, 700, 'UTF-8');
                $meta = $chip($src).$chip('ryzyko: '.($ev['risk'] ?? ''), (string)($ev['risk'] ?? ''));
                // Daty OPISANE i ROZDZIELONE: „wpis" = data wpisu w rejestrze,
                // „sprawdzono" = kiedy DUiR to odczytał (created_at zdarzenia).
                if (!empty($ev['publication_date'])) $meta .= $chip('data wpisu: '.$ev['publication_date']);
                if (!empty($ev['created_at'])) $meta .= $chip('sprawdzono: '.mb_substr((string)$ev['created_at'],0,16));
                if (!empty($ev['signature'])) $meta .= $chip('sygn.: '.$ev['signature']);
                $h .= '<div style="background:#fff;border:1px solid #e3e9f2;border-radius:14px;padding:18px 22px;margin-bottom:10px">'
                    .'<div style="margin-bottom:8px">'.$meta.'</div>'
                    .'<div style="font-weight:700;font-size:14.5px;margin-bottom:6px">'.$e($ev['title'] ?? 'Informacja').'</div>'
                    .(!empty($ev['risk_reason']) ? '<div style="font-size:13px;color:#5b6472;margin-bottom:8px">'.$e($ev['risk_reason']).'</div>' : '')
                    .($desc !== '' ? '<div style="font-size:12.5px;color:#3c4656;background:#f7f9fc;border-radius:9px;padding:10px 12px;line-height:1.55;white-space:pre-wrap">'.$e($desc).'</div>' : '')
                    .'</div>';
            }
        }
        // Historia
        if ($history) {
            $h .= '<h2 style="font-size:14px;letter-spacing:.06em;text-transform:uppercase;color:#5b6472;margin:20px 4px 10px">Historia (starsze wpisy: '.count($history).')</h2>'
                .'<div style="background:#fff;border:1px solid #e3e9f2;border-radius:14px;padding:6px 18px;margin-bottom:14px">'
                .'<table style="width:100%;border-collapse:collapse;font-size:12.5px">';
            foreach ($history as $ev) {
                [$c,$b] = $riskColors[(string)($ev['risk'] ?? '')] ?? ['#344054','#eef2f6'];
                $h .= '<tr>'
                    .'<td style="padding:9px 8px 9px 0;border-bottom:1px solid #eef2f7;white-space:nowrap;color:#5b6472">'.$e(($ev['publication_date'] ?? '') ?: '—').'</td>'
                    .'<td style="padding:9px 8px;border-bottom:1px solid #eef2f7"><b>['.$e($ev['source'] ?? '').']</b> '.$e($ev['title'] ?? '').(!empty($ev['signature']) ? ' <span style="color:#8a94a6">'.$e($ev['signature']).'</span>' : '').'</td>'
                    .'<td style="padding:9px 0 9px 8px;border-bottom:1px solid #eef2f7;text-align:right;white-space:nowrap"><span style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:700;color:'.$c.';background:'.$b.'">'.$e($ev['risk'] ?? '').'</span></td>'
                    .'</tr>';
            }
            $h .= '</table></div>';
        }
        if (!$events) $h .= '<div style="background:#fff;border:1px solid #e3e9f2;border-radius:14px;padding:18px 22px;color:#5b6472">Brak zdarzeń. Upewnij się, że źródła odpowiedziały poprawnie.</div>';
        $h .= '<div style="color:#8a94a6;font-size:11.5px;margin-top:6px">Źródła: Krajowy Rejestr Zadłużonych, Monitor Sądowy i Gospodarczy, Krajowy Rejestr Sądowy. Pełne treści wpisów — w karcie podmiotu w aplikacji DUiR.</div>'
            .'</div></body></html>';
        return $h;
    }

    /** Polska etykieta statusu sprawdzenia źródła — wspólna dla maila i strony raportu. */
    public static function checkStatusLabel(string $status): array
    {
        return match($status) {
            'success' => ['sprawdzono','#027a48','#ecfdf3'],
            'no_results' => ['brak wyników','#027a48','#ecfdf3'],
            'error' => ['BŁĄD','#912018','#fee4e2'],
            'running','pending' => ['w kolejce','#93370d','#fff6dc'],
            default => ['nie sprawdzano','#5b6472','#eef2f6'],
        };
    }

    /**
     * Podmioty, których dziś NIE udało się w pełni sprawdzić (błąd, zawieszona
     * kolejka albo brak sprawdzenia z ostatnich 24h w którymkolwiek źródle).
     * Zwraca [ [name, problems: ['KRZ — w kolejce', ...]] ].
     */
    public static function monitoringFailures(array $monitoring): array
    {
        $out = [];
        $cutoff = date('Y-m-d H:i:s', strtotime('-24 hours'));
        foreach ($monitoring as $m) {
            $problems = [];
            foreach (($m['sources'] ?? []) as $src => $c) {
                $status = (string)($c['status'] ?? 'none');
                $at = (string)($c['at'] ?? '');
                $msg = trim((string)($c['message'] ?? ''));
                $label = $src === 'MSIG' ? 'MSiG' : $src;
                // Komunikaty mówią, CO się stało i CO dalej — a nie tylko że „się nie udało".
                // Przy błędzie dołączamy przyczynę z ostatniego sprawdzenia (skróconą),
                // a przy starym wpisie datę ostatniego udanego — zamiast gołego „brak 24 h",
                // które wyglądało na sprzeczne z tabelą stanu (ta pokazuje OSTATNI wynik
                // niezależnie od wieku).
                if ($status === 'error') {
                    $problems[] = $label.' — błąd sprawdzenia'.($msg !== '' ? ': '.mb_substr($msg, 0, 140, 'UTF-8') : '').' (szczegóły: karta podmiotu → 🔍 Diagnoza)';
                } elseif (in_array($status, ['running','pending'], true)) {
                    $problems[] = $label.' — sprawdzanie w toku (wynik pojawi się w karcie podmiotu)';
                } elseif ($status === 'none') {
                    $problems[] = $label.' — nigdy nie sprawdzono';
                } elseif ($at < $cutoff) {
                    $problems[] = $label.' — brak świeżego sprawdzenia (ostatnie: '.mb_substr($at, 0, 16, 'UTF-8').')';
                }
            }
            if ($problems) $out[] = ['name'=>(string)($m['name'] ?? ''), 'problems'=>$problems];
        }
        return $out;
    }

    /**
     * Raport dzienny jako strona HTML — PER PODMIOT (feedback kancelarii: raport
     * zbiorczy nie mówił, kogo dotyczy). Struktura: nagłówek → sekcja na każdy
     * podmiot z nowymi wpisami (jego zdarzenia + buforowana ocena LLM z karty)
     * → zbiorczo WYŁĄCZNIE stan monitoringu: kogo sprawdzono, kogo się nie udało.
     */
    public function renderDailyReportHtml(array $events, string $risk, string $summary, array $assessments = [], array $monitoring = []): string
    {
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
        $riskColors = ['krytyczny'=>['#ffffff','#7a120b'],'wysoki'=>['#912018','#fee4e2'],'średni'=>['#93370d','#fff6dc'],'niski'=>['#027a48','#ecfdf3']];
        [$rc,$rb] = $riskColors[$risk] ?? ['#344054','#eef2f6'];
        $rank = ['krytyczny'=>4,'wysoki'=>3,'średni'=>2,'niski'=>1];

        // Grupowanie zdarzeń po podmiocie; podmioty z najwyższym ryzykiem na górze.
        $bySubject = [];
        foreach ($events as $ev) {
            $sid = (int)($ev['subject_id'] ?? 0);
            $bySubject[$sid]['name'] = (string)($ev['subject_name'] ?? '—');
            $bySubject[$sid]['events'][] = $ev;
            $r = $rank[$ev['risk'] ?? 'niski'] ?? 1;
            $bySubject[$sid]['maxRank'] = max($bySubject[$sid]['maxRank'] ?? 0, $r);
        }
        uasort($bySubject, fn($a,$b) => ($b['maxRank'] ?? 0) <=> ($a['maxRank'] ?? 0));

        $sections = '';
        foreach ($bySubject as $sid => $grp) {
            $rows = '';
            usort($grp['events'], fn($a,$b) => ($rank[$b['risk'] ?? 'niski'] ?? 1) <=> ($rank[$a['risk'] ?? 'niski'] ?? 1));
            foreach ($grp['events'] as $ev) {
                [$c,$b] = $riskColors[(string)($ev['risk'] ?? '')] ?? ['#344054','#eef2f6'];
                $rows .= '<tr>'
                    .'<td style="padding:8px 8px 8px 0;border-bottom:1px solid #eef2f7;white-space:nowrap;color:#5b6472">'.$e($ev['source'] ?? '').'</td>'
                    .'<td style="padding:8px 8px;border-bottom:1px solid #eef2f7">'.$e($ev['title'] ?? '').(!empty($ev['signature'])?' <span style="color:#8a94a6">'.$e($ev['signature']).'</span>':'').'</td>'
                    .'<td style="padding:8px 8px;border-bottom:1px solid #eef2f7;white-space:nowrap"><span style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:11px;font-weight:700;color:'.$c.';background:'.$b.'">'.$e($ev['risk'] ?? '').'</span></td>'
                    .'<td style="padding:8px 0 8px 8px;border-bottom:1px solid #eef2f7;white-space:nowrap;color:#5b6472">'.$e(($ev['publication_date'] ?? '') ?: mb_substr((string)($ev['created_at'] ?? ''),0,10)).'</td>'
                    .'</tr>';
            }
            $assessment = trim((string)($assessments[$sid] ?? ''));
            $sections .= '<div style="background:#fff;border:1px solid #e3e9f2;border-radius:14px;padding:20px 24px;margin-bottom:14px">'
                .'<h2 style="margin:0 0 12px;font-size:16px">'.$e(mb_substr($grp['name'],0,90,'UTF-8')).'</h2>'
                .'<table style="width:100%;border-collapse:collapse;font-size:12.5px">'
                .'<tr><th style="text-align:left;padding:8px 8px 8px 0;color:#8a94a6;font-size:11px;text-transform:uppercase">Źródło</th><th style="text-align:left;padding:8px 8px;color:#8a94a6;font-size:11px;text-transform:uppercase">Nowy wpis</th><th style="text-align:left;padding:8px 8px;color:#8a94a6;font-size:11px;text-transform:uppercase">Ryzyko</th><th style="text-align:left;padding:8px 0 8px 8px;color:#8a94a6;font-size:11px;text-transform:uppercase">Data wpisu</th></tr>'
                .$rows.'</table>'
                .($assessment !== ''
                    ? '<div style="margin-top:12px;border-left:4px solid #2448a8;background:#f7f9fc;border-radius:0 9px 9px 0;padding:12px 16px;font-size:13px;line-height:1.6">'
                        .'<div style="font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:#2448a8;font-weight:700;margin-bottom:6px">Ocena sytuacji (AI)</div>'
                        .self::llmTextToHtml(mb_substr($assessment, 0, 1400, 'UTF-8')).'</div>'
                    : '')
                .'</div>';
        }

        // Zbiorczo TYLKO stan monitoringu: kogo dziś sprawdzono i kogo się nie udało.
        $failures = self::monitoringFailures($monitoring);
        $monitoringHtml = '';
        if ($monitoring) {
            $mrows = '';
            foreach ($monitoring as $m) {
                $cells = '';
                foreach (['KRS'=>'KRS','CEIDG'=>'CEIDG','KRZ'=>'KRZ','MSIG'=>'MSiG'] as $src=>$label) {
                    if (!isset($m['sources'][$src])) { $cells .= '<td style="padding:7px 8px;border-bottom:1px solid #eef2f7;color:#c3cad6">—</td>'; continue; }
                    [$txt,$fg,$bg] = self::checkStatusLabel((string)$m['sources'][$src]['status']);
                    $cells .= '<td style="padding:7px 8px;border-bottom:1px solid #eef2f7;white-space:nowrap"><span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;color:'.$fg.';background:'.$bg.'">'.$e($txt).'</span></td>';
                }
                $mrows .= '<tr><td style="padding:7px 8px 7px 0;border-bottom:1px solid #eef2f7;font-weight:600">'.$e(mb_substr((string)$m['name'],0,60,'UTF-8')).'</td>'.$cells.'</tr>';
            }
            $failList = '';
            foreach ($failures as $f) $failList .= '<li style="margin:3px 0"><b>'.$e($f['name']).'</b>: '.$e(implode('; ', $f['problems'])).'</li>';
            // Przyczyna oparta na FAKTACH (heartbeat wtyczek), nie na domyśle. Gdy
            // wtyczki są aktywne, dawny tekst „wtyczka nie działała na żadnym
            // komputerze" wprowadzał w błąd.
            $activePlugins = -1;
            try { $activePlugins = count($this->repo->activePluginInstances(12)); } catch (\Throwable) {}
            $causeNote = $activePlugins === 0
                ? 'Żadna wtyczka Chrome nie zgłosiła się w ostatnich minutach — uruchom przeglądarkę z wtyczką, inaczej KRZ/MSiG się nie wykonają.'
                : ($activePlugins > 0
                    ? 'Wtyczki są aktywne ('.$activePlugins.' komp.) — zaległe sprawdzenia dokończą się w ciągu dnia; powtarzające się błędy sprawdź w karcie podmiotu (🔍 Diagnoza).'
                    : 'Sprawdź w panelu, czy przeglądarka z wtyczką jest uruchomiona (Skrót monitoringu pokazuje aktywne wtyczki).');
            $monitoringHtml = ($failList !== ''
                    ? '<div style="background:#fff;border:1px solid #f1c3bf;border-left:5px solid #b42318;border-radius:14px;padding:18px 22px;margin-bottom:14px">'
                        .'<h2 style="margin:0 0 8px;font-size:15px;color:#b42318">Nie udało się w pełni sprawdzić</h2>'
                        .'<ul style="margin:0;padding-left:18px;font-size:13px;line-height:1.55">'.$failList.'</ul>'
                        .'<div style="color:#5b6472;font-size:12px;margin-top:8px">'.$e($causeNote).'</div></div>'
                    : '')
                .'<h2 style="font-size:14px;letter-spacing:.06em;text-transform:uppercase;color:#5b6472;margin:20px 4px 10px">Stan monitoringu (ostatnie 24 h)</h2>'
                .'<div style="background:#fff;border:1px solid #e3e9f2;border-radius:14px;padding:6px 18px"><table style="width:100%;border-collapse:collapse;font-size:12.5px">'
                .'<tr><th style="text-align:left;padding:8px 8px 8px 0;color:#8a94a6;font-size:11px;text-transform:uppercase">Podmiot</th><th style="text-align:left;padding:8px;color:#8a94a6;font-size:11px;text-transform:uppercase">KRS</th><th style="text-align:left;padding:8px;color:#8a94a6;font-size:11px;text-transform:uppercase">CEIDG</th><th style="text-align:left;padding:8px;color:#8a94a6;font-size:11px;text-transform:uppercase">KRZ</th><th style="text-align:left;padding:8px;color:#8a94a6;font-size:11px;text-transform:uppercase">MSiG</th></tr>'
                .$mrows.'</table></div>';
        }

        return '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Raport dzienny DUiR</title></head>'
            .'<body style="margin:0;background:#f4f6fa;font-family:Segoe UI,system-ui,Arial,sans-serif;color:#1a2233"><div style="max-width:860px;margin:0 auto;padding:26px 18px">'
            .'<div style="background:#fff;border:1px solid #e3e9f2;border-radius:14px;padding:22px 24px;margin-bottom:14px">'
            .'<div style="font-size:12px;letter-spacing:.08em;color:#8a94a6;font-weight:700;text-transform:uppercase">Raport dzienny DUiR — monitoring KRZ / MSiG / KRS</div>'
            .'<h1 style="margin:8px 0 6px;font-size:20px">'.($sections !== '' ? 'Nowe wpisy: '.count($events).' (podmiotów: '.count($bySubject).')' : 'Brak nowych wpisów').'</h1>'
            .($sections !== '' ? '<span style="display:inline-block;padding:3px 11px;border-radius:999px;font-size:12px;font-weight:700;color:'.$rc.';background:'.$rb.'">najwyższe ryzyko: '.$e($risk).'</span>' : '')
            .'<span style="display:inline-block;padding:3px 11px;border-radius:999px;font-size:12px;font-weight:700;color:#344054;background:#eef2f6;'.($sections!==''?'margin-left:6px':'').'">'.date('Y-m-d H:i').'</span>'
            .'</div>'
            .($sections !== ''
                ? $sections
                : '<div style="background:#fff;border:1px solid #e3e9f2;border-radius:14px;padding:18px 22px;margin-bottom:14px;color:#5b6472">'.$e(trim($summary) !== '' ? $summary : 'Brak nowych wpisów w ostatnich 24 godzinach.').'</div>')
            .$monitoringHtml
            .'<div style="color:#8a94a6;font-size:11.5px;margin-top:10px">Pełne treści wpisów — w kartach podmiotów w aplikacji DUiR.</div>'
            .'</div></body></html>';
    }

    // Konwersja odpowiedzi LLM do CZYSTEGO tekstu (PDF, e-mail): znaczniki markdown
    // (**pogrubienia**, nagłówki #, punktory *) znikają albo są ujednolicane do "-".
    public static function llmTextToPlain(string $text): string
    {
        $t = preg_replace('/\*\*(.+?)\*\*/s', '$1', $text) ?? $text;
        $t = preg_replace('/^[ \t]*[\*•][ \t]+/mu', '- ', $t) ?? $t;
        $t = preg_replace('/^#{1,6}[ \t]*/m', '', $t) ?? $t;
        return trim($t);
    }

    // Bezpieczna konwersja odpowiedzi LLM do HTML: najpierw escape, potem TYLKO
    // proste **pogrubienia** i nowe linie — bez pełnego parsera markdown.
    public static function llmTextToHtml(string $text): string
    {
        $safe = htmlspecialchars($text, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
        $safe = preg_replace('/\*\*(.+?)\*\*/s', '<b>$1</b>', $safe) ?? $safe;
        $safe = preg_replace('/^[ \t]*\*[ \t]+/mu', '• ', $safe) ?? $safe;   // punktory markdown
        $safe = preg_replace('/^#{1,6}[ \t]*/m', '', $safe) ?? $safe;         // nagłówki markdown
        return nl2br($safe);
    }

    /**
     * Redakcja zdarzeń dla OCENY sytuacji = MINIMALIZACJA (dobór danych, NIE maskowanie).
     * Do LLM (Gemini, usługa zewnętrzna) trafiają wyłącznie dane NIEZBĘDNE do oceny
     * wypłacalności kontrahenta: rodzaj ogłoszenia (title), poziom ryzyka, SYGNATURA
     * i data postępowania oraz wyłuskane istotne dane (terminy, czynności, sąd, kwoty).
     * NIE wysyłamy pełnej treści ogłoszenia (adresy, dane rodzinne, dane osób trzecich
     * są zbędne dla oceny). Nie maskujemy — po prostu nie przekazujemy tego, co zbędne.
     * Podstawa przetwarzania monitoringu: uzasadniony interes administratora
     * (art. 6 ust. 1 lit. f RODO); zasada minimalizacji (art. 5 ust. 1 lit. c RODO)
     * realizowana właśnie przez ten dobór pól.
     */
    public static function redactEventsForLlmAssessment(array $events): array
    {
        return array_map(fn($e)=>[
            'source'=>$e['source'] ?? '',
            'title'=>$e['title'] ?? '',
            'risk'=>$e['risk'] ?? '',
            'risk_reason'=>$e['risk_reason'] ?? '',
            'publication_date'=>$e['publication_date'] ?? null,
            'signature'=>$e['signature'] ?? null,
            'istotne_dane'=>self::extractProceedingData((string)($e['description'] ?? '')),
        ], array_values($events));
    }

    /**
     * Wyłuskuje z treści ogłoszenia (MSiG/KRZ) TYLKO istotne dane postępowania metodą
     * doboru (allowlist): terminy, rodzaj czynności, sąd i kwoty. Zwraca wyłącznie
     * dopasowane frazy — nie przepisuje wolnego tekstu ogłoszenia. To realizacja
     * minimalizacji: do oceny idzie meritum, bez zbędnych danych osobowych/adresów.
     */
    public static function extractProceedingData(string $description): array
    {
        $text = RiskAnalyzer::tidyPortalText($description);
        if ($text === '') return [];
        $facts = [];
        $add = function (string $s) use (&$facts) {
            $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
            if ($s !== '' && mb_strlen($s, 'UTF-8') <= 160 && !in_array($s, $facts, true)) $facts[] = $s;
        };
        // Terminy — najważniejsze dla zaleceń („zgłoś wierzytelność w terminie…").
        foreach ([
            '/w terminie\s+[^.,;\n]{1,40}?(dni|tygodni\w*|miesi\w+|tygodnia|miesiąca)/iu',
            '/w ciągu\s+\d+\s+(dni|tygodni\w*|miesi\w+)/iu',
            '/do dnia\s+\d{1,2}[.\-\/]\d{1,2}[.\-\/]\d{4}/iu',
            '/w nieprzekraczalnym terminie[^.,;\n]{0,40}/iu',
        ] as $re) { if (preg_match_all($re, $text, $m)) foreach ($m[0] as $hit) $add($hit); }
        // Rodzaj czynności / etap postępowania — bezpieczne frazy przedmiotowe.
        foreach ([
            'zgłoszenia wierzytelności','zgłaszać wierzytelności','zgłoszenie wierzytelności',
            'lista wierzytelności','plan podziału','przeglądanie planu podziału','sprzeciw',
            'zarzuty','zatwierdzenie układu','propozycje układowe','otwarcie postępowania',
            'ogłoszenie upadłości','ustanowiono syndyka','ustanowiono nadzorcę','ustanowiono zarządcę',
            'sędzia-komisarz','postępowanie sanacyjne','przyspieszone postępowanie układowe',
            'umorzenie postępowania','zakończenie postępowania','oddalenie wniosku','układ',
        ] as $phrase) {
            if (mb_stripos($text, $phrase, 0, 'UTF-8') !== false) $add($phrase);
        }
        // Sąd prowadzący (nazwa sądu to nie dana osobowa). WZORZEC OGRANICZONY do
        // nazwy sądu + miejscowości (tokeny z wielkiej litery) — świadomie NIE łapie
        // dalszej części zdania, bo za nazwą sądu bywa nazwisko dłużnika.
        if (preg_match_all('/S[ąa]d(?:u)?\s+(?:Rejonow\w+|Okręgow\w+)(?:\s+(?:w|we|dla))?\s+[A-ZŁŚŻŹĆÓĄĘŃ][\p{L}.\-]+(?:[\s\-][A-ZŁŚŻŹĆÓĄĘŃ][\p{L}.\-]+){0,2}/u', $text, $m)) {
            foreach (array_slice($m[0], 0, 2) as $hit) $add($hit);
        }
        if (preg_match_all('/\d[\d\s.\x{00a0}]*(?:,\d{2})?\s*(?:zł|PLN)/iu', $text, $m)) {
            foreach (array_slice($m[0], 0, 4) as $hit) $add($hit);
        }
        return array_slice($facts, 0, 12);
    }
}
