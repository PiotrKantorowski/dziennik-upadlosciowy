<?php
namespace Duir\Controllers;

use Duir\Config;
use Duir\Bootstrap;
use Duir\Repository;
use Duir\Services\CheckService;
use Duir\Services\ReportService;
use Duir\Support\Http;

final class CronController
{
    public function __construct(private Repository $repo, private CheckService $check, private string $root, private ?ReportService $reports = null) {}

    public function run(): void
    {
        $token = (string)($_GET['token'] ?? ($_POST['token'] ?? ''));
        if ($token === '') {
            $token = $this->tokenFromCli();
        }
        $expected = (string)Config::get('CRON_TOKEN', '');
        if (Bootstrap::isWeakCronToken() && Config::get('APP_ENV', 'production') === 'production') {
            http_response_code(503);
            Http::json(['ok'=>false, 'error'=>'CRON_TOKEN nie jest bezpiecznie skonfigurowany. Ustaw losowy token 32+ znaki.']);
            return;
        }
        if ($expected === '' || !hash_equals($expected, $token)) {
            http_response_code(403);
            Http::json(['ok'=>false, 'error'=>'Nieprawidłowy CRON_TOKEN.']);
            return;
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
        $limit = max(1, (int)Config::get('CRON_BATCH_SIZE', 25));
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

    private function tokenFromCli(): string
    {
        global $argv;
        if (!is_array($argv)) return '';
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--token=')) return substr($arg, 8);
        }
        return '';
    }
}
