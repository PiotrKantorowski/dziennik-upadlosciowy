<?php
require_once __DIR__.'/app/Bootstrap.php';
\Duir\Bootstrap::init(__DIR__);

use Duir\{Database,Repository,Config};
use Duir\Controllers\{SubjectController,KrzApiController,MsigApiController,ReportController,SettingsController,UserController,AccountController,InstallController,CronController};
use Duir\Services\{CheckService,KrsClient,RiskAnalyzer,ReportService,AuthService};
use Duir\Support\{Http,Csrf};

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$isApi = str_starts_with($path, '/api/') || $path === '/cron/run';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'");

try {
    $repo = new Repository((new Database())->pdo());
} catch (Throwable $e) {
    http_response_code(503);
    if (Config::bool('APP_DEBUG')) {
        echo '<pre>Nie można połączyć z bazą danych: '.htmlspecialchars($e->getMessage(), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'</pre>';
    } else {
        echo '<pre>Nie można połączyć z bazą danych. Sprawdź konfigurację DB_DSN, DB_USER i DB_PASSWORD w pliku .env.</pre>';
    }
    return;
}

if ($repo->schemaReady()) {
    \Duir\Config::apply($repo->allSettings());
}

$risk = new RiskAnalyzer();
$check = new CheckService($repo, new KrsClient(), $risk);
$reports = new ReportService($repo);
$subjects = new SubjectController($repo,$check,$reports);
$krz = new KrzApiController($repo,$check,$reports);
$msigApi = new MsigApiController($repo,$check,$reports);
$reportCtl = new ReportController($repo,$reports);
$settings = new SettingsController($repo);
$users = new UserController($repo);
$account = new AccountController($repo);
$install = new InstallController($repo, __DIR__);
$cron = new CronController($repo, $check, __DIR__, $reports);
$auth = new AuthService($repo);

$renderLogin = function (string $error = ''): void {
    http_response_code($error === '' ? 200 : 401);
    $err = $error !== '' ? '<p class="error">'.Http::e($error).'</p>' : '';
    $installed = isset($_GET['installed']) ? '<p class="okbox">Instalacja zakończona. Zaloguj się kontem administratora.</p>' : '';
    echo '<!doctype html><html lang="pl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Logowanie — DUiR</title><link rel="stylesheet" href="/assets/app.css"><body><div class="wrap"><header class="top"><div><h1>Logowanie</h1><p class="muted">Dziennik Upadłościowy i Restrukturyzacyjny</p></div></header>'
        .'<form class="card" method="post" action="/login">'.Csrf::field().$installed.$err
        .'<div><label>E-mail</label><input type="email" name="email" autofocus required></div>'
        .'<div><label>Hasło</label><input type="password" name="password" required></div>'
        .'<button class="btn primary">Zaloguj</button></form></div></body></html>';
};

$requireAdmin = function () use ($auth): void {
    if (!$auth->isAdmin()) {
        http_response_code(403);
        echo 'Ta część panelu jest dostępna tylko dla administratora.';
        exit;
    }
};

try {
    if ($path === '/cron/run') { $cron->run(); return; }

    if ($isApi) {
        if ($path==='/api/krz/ping') { $krz->ping(); return; }
        if ($path==='/api/krz/worklist') { $krz->worklist(); return; }
        if ($path==='/api/krz/subjects') { $krz->subjects(); return; }
        if ($path==='/api/krz/ingest' && $method==='POST') { $krz->ingest(); return; }
        if ($path==='/api/krz/run-finished' && $method==='POST') { $krz->runFinished(); return; }
        if ($path==='/api/msig/ping') { $msigApi->ping(); return; }
        if ($path==='/api/msig/worklist') { $msigApi->worklist(); return; }
        if ($path==='/api/msig/ingest' && $method==='POST') { $msigApi->ingest(); return; }
        if ($path==='/api/msig/run-finished' && $method==='POST') { $msigApi->runFinished(); return; }
        http_response_code(404); echo '404'; return;
    }

    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();

    if ($method === 'POST' && !Csrf::verify((string)($_POST['_csrf'] ?? ''))) {
        http_response_code(403);
        echo 'Nieprawidłowy token CSRF, odśwież stronę i spróbuj ponownie.';
        return;
    }

    if ($path === '/setup' && $method === 'GET') { $install->show(); return; }
    if ($path === '/setup' && $method === 'POST') { $install->run(); return; }

    if (!$repo->schemaReady() || $repo->userCount() === 0) {
        if ($method === 'GET') { Http::redirect('/setup'); }
        http_response_code(503); echo 'Aplikacja wymaga uruchomienia instalatora.'; return;
    }

    if ($path === '/login' && $method === 'GET') {
        if (($_SESSION['auth'] ?? false) === true) { Http::redirect('/'); }
        $renderLogin();
        return;
    }
    if ($path === '/login' && $method === 'POST') {
        $user = $auth->attempt((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''));
        if ($user) { $auth->login($user); Http::redirect('/'); }
        $renderLogin('Nieprawidłowy login albo hasło.');
        return;
    }
    if ($path === '/logout' && $method === 'POST') { $auth->logout(); Http::redirect('/login'); }

    if (($_SESSION['auth'] ?? false) !== true || !$auth->currentUser()) {
        if ($method === 'GET') { Http::redirect('/login'); }
        http_response_code(401); echo 'Wymagane logowanie.'; return;
    }

    if ($path === '/' && $method==='GET') { $subjects->index(); return; }
    if ($path === '/subjects/new' && $method==='GET') { $subjects->createForm(); return; }
    if ($path === '/subjects/lookup' && $method==='GET') { $subjects->lookup(); return; }
    if ($path === '/subjects/create' && $method==='POST') { $subjects->create(); return; }
    if ($path === '/checks/all' && $method==='POST') { $subjects->checkAll(); return; }
    if (preg_match('#^/subjects/(\d+)$#',$path,$m) && $method==='GET') { $subjects->show((int)$m[1]); return; }
    if (preg_match('#^/subjects/(\d+)/status$#',$path,$m) && $method==='GET') { $subjects->status((int)$m[1]); return; }
    if (preg_match('#^/subjects/(\d+)/edit$#',$path,$m) && $method==='GET') { $subjects->editForm((int)$m[1]); return; }
    if (preg_match('#^/subjects/(\d+)/update$#',$path,$m) && $method==='POST') { $subjects->update((int)$m[1]); return; }
    if (preg_match('#^/subjects/(\d+)/delete$#',$path,$m) && $method==='POST') { $subjects->delete((int)$m[1]); return; }
    if (preg_match('#^/subjects/(\d+)/check$#',$path,$m) && $method==='POST') { $subjects->check((int)$m[1]); return; }
    if (preg_match('#^/subjects/(\d+)/reassess$#',$path,$m) && $method==='POST') { $subjects->reassess((int)$m[1]); return; }
    if (preg_match('#^/subjects/(\d+)/pdf$#',$path,$m) && $method==='GET') { $subjects->pdf((int)$m[1]); return; }
    if (preg_match('#^/subjects/(\d+)/report$#',$path,$m) && $method==='GET') { $subjects->report((int)$m[1]); return; }
    if (preg_match('#^/subjects/(\d+)/send$#',$path,$m) && $method==='POST') { $subjects->send((int)$m[1]); return; }
    if ($path==='/reports/daily' && $method==='GET') { $reportCtl->daily(); return; }
    if ($path==='/reports/daily/pdf' && $method==='GET') { $reportCtl->dailyPdf(); return; }
    if ($path==='/reports/daily/send' && $method==='POST') { $reportCtl->dailySend(); return; }

    // Panel „Moje konto" — dostępny dla KAŻDEGO zalogowanego użytkownika.
    if ($path==='/account' && $method==='GET') { $account->show(); return; }
    if ($path==='/account/password' && $method==='POST') { $account->changePassword(); return; }

    if ($path==='/settings' && $method==='GET') { $requireAdmin(); $settings->show(); return; }
    if ($path==='/settings' && $method==='POST') { $requireAdmin(); $settings->save(); return; }
    if ($path==='/settings/test-llm' && $method==='POST') { $requireAdmin(); $settings->testLlm(); return; }
    if ($path==='/users' && $method==='GET') { $requireAdmin(); $users->index(); return; }
    if ($path==='/users/new' && $method==='GET') { $requireAdmin(); $users->createForm(); return; }
    if ($path==='/users/create' && $method==='POST') { $requireAdmin(); $users->create(); return; }
    if (preg_match('#^/users/(\d+)/edit$#',$path,$m) && $method==='GET') { $requireAdmin(); $users->editForm((int)$m[1]); return; }
    if (preg_match('#^/users/(\d+)/update$#',$path,$m) && $method==='POST') { $requireAdmin(); $users->update((int)$m[1]); return; }

    http_response_code(404); echo '404';
} catch (Throwable $e) {
    http_response_code(500);
    if (Config::bool('APP_DEBUG')) {
        echo '<pre>Internal error: '.htmlspecialchars($e->getMessage(), ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8').'</pre>';
    } else {
        error_log((string)$e);
        echo '<pre>Wystąpił błąd. Spróbuj ponownie później.</pre>';
    }
}
