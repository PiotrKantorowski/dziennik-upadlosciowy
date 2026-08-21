<?php
use Duir\Config;
use Duir\Bootstrap;

function test_placeholder_bridge_token_from_env_example_is_rejected(): void {
    Config::set('KRZ_BRIDGE_TOKEN', 'change-me-bridge-token-32chars');
    assert_true(Bootstrap::isWeakBridgeToken(), 'placeholder z .env.example musi być uznany za słaby');
}

function test_short_31_char_bridge_token_is_rejected(): void {
    Config::set('KRZ_BRIDGE_TOKEN', str_repeat('a', 31));
    assert_true(Bootstrap::isWeakBridgeToken(), 'token krótszy niż 32 znaki musi być odrzucony');
}

function test_cron_token_has_same_weak_secret_guard(): void {
    Config::set('CRON_TOKEN', 'change-me-cron-token-32chars');
    assert_true(Bootstrap::isWeakCronToken(), 'placeholder CRON_TOKEN musi być uznany za słaby');
    Config::set('CRON_TOKEN', bin2hex(random_bytes(24)));
    assert_true(!Bootstrap::isWeakCronToken(), 'losowy długi CRON_TOKEN powinien przejść');
}

function test_ceidg_uses_current_v3_endpoint(): void {
    $src = file_get_contents(dirname(__DIR__).'/app/Services/CeidgClient.php');
    assert_true(str_contains($src, 'https://dane.biznes.gov.pl/api/ceidg/v3/firmy?'), 'CEIDG must use the current official API v3 endpoint');
    assert_true(!str_contains($src, '/api/ceidg/v2/'), 'retired CEIDG API v2 must not be used');
}

function test_cron_uses_due_subject_rotation_not_alphabetical_first_batch_forever(): void {
    $repo = file_get_contents(dirname(__DIR__).'/app/Repository.php');
    $svc = file_get_contents(dirname(__DIR__).'/app/Services/CheckService.php');
    assert_true(str_contains($repo, 'subjectsDueForCheck'), 'Repository musi mieć osobną kolejkę podmiotów dla CRON');
    assert_true(str_contains($repo, 'last_checked_at ASC'), 'CRON musi sortować po najdawniej sprawdzonych');
    assert_true(str_contains($svc, 'subjectsDueForCheck($limit)'), 'CheckService z limitem musi używać rotacji, nie pełnej listy alfabetycznej');
}

function test_auth_service_refreshes_admin_role_from_database(): void {
    $auth = file_get_contents(dirname(__DIR__).'/app/Services/AuthService.php');
    assert_true(str_contains($auth, '$_SESSION[\'user_role\'] = (string)$user[\'role\']'), 'currentUser musi odświeżać rolę w sesji');
    assert_true(str_contains($auth, '$user = $this->currentUser();'), 'isAdmin musi sprawdzać aktualny stan konta, nie tylko starą sesję');
}

function test_repository_protects_last_active_admin(): void {
    $repo = file_get_contents(dirname(__DIR__).'/app/Repository.php');
    assert_true(str_contains($repo, 'activeAdminCount'), 'Repository musi liczyć aktywnych administratorów');
    assert_true(str_contains($repo, 'ostatniego aktywnego administratora'), 'Nie wolno zablokować/demotować ostatniego admina');
}

function test_htaccess_blocks_private_directories_before_file_passthrough(): void {
    $ht = file_get_contents(dirname(__DIR__).'/.htaccess');
    $blockPos = strpos($ht, 'RewriteRule ^(app|bin|database|docs|storage|tests|chrome_extension|knowledge)');
    $passPos = strpos($ht, 'RewriteCond %{REQUEST_FILENAME} -f');
    assert_true($blockPos !== false, '.htaccess musi blokować prywatne katalogi przez rewrite');
    assert_true($passPos !== false && $blockPos < $passPos, 'Blokada musi być przed regułą przepuszczającą istniejące pliki');
}

// Podmiot bez KRS (JDG błędnie oznaczona jako spółka) NIE może kończyć się błędem KRS:
// CheckService potwierdza wpis w CEIDG i koryguje typ (od typu zależy zakładka KRZ).
function test_checkservice_autocorrects_person_type_contract(): void {
    $src = file_get_contents(dirname(__DIR__).'/app/Services/CheckService.php');
    assert_true(str_contains($src, "updateSubjectType(\$id, 'business_person')"), 'auto-korekta typu na osobę fizyczną');
    assert_true(str_contains($src, "'no_identifier'"), 'gałąź braku KRS');
    assert_true(!str_contains($src, "'no_identifier'], true) ? 'error'"), 'brak KRS nie może być raportowany jako błąd');
    $repo = file_get_contents(dirname(__DIR__).'/app/Repository.php');
    assert_true(str_contains($repo, 'function updateSubjectType'), 'Repository::updateSubjectType istnieje');
}
