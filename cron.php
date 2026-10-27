<?php
// CRON uruchamiany wyłącznie z wiersza poleceń (cron konta). Wywołanie przez HTTP
// dostaje 403 jeszcze przed bootstrapem i połączeniem z bazą (23.10.2026,
// wcześniej token w adresie URL, który wyciekł).
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false, 'error'=>'CRON uruchamiany wyłącznie z CLI.'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
// Wpis w dzienniku: znacznik czasu przed odpowiedzią JSON i nowa linia po niej
// (Http::json kończy skrypt przez exit, stąd funkcja zamykająca).
echo '['.date('Y-m-d H:i:s').'] ';
register_shutdown_function(static function (): void { echo "\n"; });

require_once __DIR__.'/app/Bootstrap.php';
\Duir\Bootstrap::init(__DIR__);

use Duir\{Database,Repository,Config};
use Duir\Controllers\CronController;
use Duir\Services\{CheckService,KrsClient,RiskAnalyzer,ReportService};

header('X-Content-Type-Options: nosniff');

try {
    $repo = new Repository((new Database())->pdo());
    if ($repo->schemaReady()) {
        \Duir\Config::apply($repo->allSettings());
    }
    $controller = new CronController($repo, new CheckService($repo, new KrsClient(), new RiskAnalyzer()), __DIR__, new ReportService($repo));
    $controller->run();
} catch (Throwable $e) {
    http_response_code(500);
    if (Config::bool('APP_DEBUG')) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    } else {
        error_log((string)$e);
        echo json_encode(['ok'=>false,'error'=>'Wystąpił błąd CRON. Sprawdź storage/logs/php-error.log.'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
}
