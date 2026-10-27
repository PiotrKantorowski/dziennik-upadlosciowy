<?php
namespace Duir\Controllers;

use Duir\Config;
use Duir\Repository;
use Duir\Services\CheckService;
use Duir\Services\ReportService;
use Duir\Support\Http;

final class CronController
{
    public function __construct(private Repository $repo, private CheckService $check, private string $root, private ?ReportService $reports = null) {}

    public function run(): void
    {
        // Tylko CLI (cron konta: php cron.php). Zamyka też trasę /cron/run w index.php.
        // Token w adresie URL wyciekł 23.10.2026, więc HTTP nie uruchamia przebiegu wcale.
        if (PHP_SAPI !== 'cli') {
            Http::json(['ok'=>false, 'error'=>'CRON uruchamiany wyłącznie z CLI.'], 403);
        }

        $lockPath = $this->root.'/storage/cron.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
            Http::json(['ok'=>true, 'status'=>'skipped', 'message'=>'Poprzedni przebieg nadal trwa.']);
            return;
        }

        $started = microtime(true);
        // Najpierw domknij zadania KRZ/MSiG wiszące od poprzednich przebiegów
        // (wtyczka nie dostarczyła wyniku) — inaczej karta podmiotu bezterminowo
        // pokazywałaby "running", a kolejka rosłaby o duplikaty.
        $expired = $this->repo->expireStaleTasks();
        // CRON biegnie raz dziennie, więc limit MUSI obejmować wszystkie monitorowane
        // podmioty. Przy 25 i 33 podmiotach (19.10.2026) osiem podmiotów codziennie
        // wypadało z przebiegu — nie dostawały ani CEIDG/KRS, ani zadań KRZ/MSiG.
        $limit = max(1, (int)Config::get('CRON_BATCH_SIZE', 200));
        $checked = $this->check->checkAllMonitored($limit);
        $this->repo->setSetting('cron_last_run_at', date(DATE_ATOM));
        $this->repo->setSetting('cron_last_checked_count', (string)$checked);
        // Fallback wysyłki raportu dziennego: gdy CRON biegnie po godzinie wysyłki
        // (np. drugi wpis CRON ~10:30), a żadna wtyczka nie zdążyła wywołać bramki.
        $dailyMail = null;
        try { $dailyMail = $this->reports?->autoSendDailyReportIfDue(); } catch (\Throwable) {}
        $out = [
            'ok' => true,
            'checked_subjects' => $checked,
            'expired_tasks' => $expired,
            'batch_limit' => $limit,
            'daily_mail' => $dailyMail,
            'duration_sec' => round(microtime(true) - $started, 3),
            'note' => 'Sprawdzenie serwerowe wykonane. Zadania KRZ/MSiG są obsługiwane przez kolejkę/wtyczkę, jeżeli portal wymaga realnej sesji przeglądarki.',
        ];
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
        Http::json($out);
    }
}
