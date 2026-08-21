<?php
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
