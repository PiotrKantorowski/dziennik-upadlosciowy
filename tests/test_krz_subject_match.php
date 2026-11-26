<?php
use Duir\Services\RiskAnalyzer;
function test_manual_krz_capture_requires_subject_match(): void {
    $r = new RiskAnalyzer();
    assert_true($r->textMatchesSubject('KRS 0000123456 KR15/GRz-nu/175/2025', ['name'=>'ABC','krs'=>'0000123456']));
    assert_true(!$r->textMatchesSubject('KRS 0009999999 KR15/GRz-nu/175/2025', ['name'=>'ABC','krs'=>'0000123456']));
}

function test_subject_match_does_not_join_unrelated_digits_or_accept_foreign_person(): void {
    $r = new RiskAnalyzer();
    $company = [
        'name'=>'MEBLE BIUROWE KRAKÓW SPÓŁKA Z OGRANICZONĄ ODPOWIEDZIALNOŚCIĄ',
        'krs'=>'0000123456', 'nip'=>'6821752158', 'regon'=>'123456789',
    ];
    assert_true(!$r->textMatchesSubject(
        'Rafał Mucharski, KRS 0000999999, NIP 1112223344. Daty i numery: 00001 / 23456.',
        $company
    ), 'obca osoba nie może pasować do spółki');
    assert_true(!$r->textMatchesSubject(
        'Pierwszy numer 00001, drugi numer 23456 — cyfry są w oddzielnych polach.',
        $company
    ), 'oddzielne cyfry nie mogą zostać zlepione w KRS');
    assert_true($r->textMatchesSubject(
        'Dłużnik: MEBLE BIUROWE KRAKÓW spółka z ograniczoną odpowiedzialnością.',
        $company
    ), 'pełna nazwa nadal powinna być prawidłowym dopasowaniem');
    assert_true($r->textMatchesSubject(
        'NIP dłużnika: 682-175-21-58.',
        $company
    ), 'identyfikator z separatorami używanymi przez portal powinien pasować');
}

/** @return array{0:\Duir\Repository,1:\PDO} */
function capture_guard_test_repository(): array {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->sqliteCreateFunction('NOW', static fn() => date('Y-m-d H:i:s'), 0);
    $pdo->exec('CREATE TABLE subjects (
        id INTEGER PRIMARY KEY, name TEXT, krs TEXT, nip TEXT, regon TEXT, pesel TEXT,
        aliases TEXT, type TEXT, service_mode TEXT, last_checked_at TEXT, last_status TEXT, updated_at TEXT
    )');
    $pdo->exec('CREATE TABLE krz_tasks (
        id INTEGER PRIMARY KEY, subject_id INTEGER, status TEXT, claimed_by TEXT,
        finished_at TEXT, error TEXT, raw_json TEXT
    )');
    $pdo->exec('CREATE TABLE msig_tasks (
        id INTEGER PRIMARY KEY, subject_id INTEGER, status TEXT, claimed_by TEXT,
        finished_at TEXT, error TEXT, raw_json TEXT
    )');
    $pdo->exec('CREATE TABLE source_checks (
        id INTEGER PRIMARY KEY AUTOINCREMENT, subject_id INTEGER, source TEXT, status TEXT,
        message TEXT, raw_json TEXT, checked_at TEXT
    )');
    $pdo->exec("INSERT INTO subjects (id,name,krs,nip,regon,pesel,aliases,type,service_mode)
        VALUES (1,'MEBLE BIUROWE KRAKÓW SPÓŁKA Z O.O.','0000123456','6821752158','123456789','', '', 'company','office_monitoring')");
    $repo = new \Duir\Repository($pdo);
    // SQLite nie obsługuje MySQL-owego SHOW COLUMNS; tabela testowa ma już kolumnę.
    $ready = new ReflectionProperty($repo, 'subjectServiceModeReady');
    $ready->setValue($repo, true);
    return [$repo, $pdo];
}

function test_browser_task_claim_is_bound_to_source_subject_and_computer(): void {
    [$repo, $pdo] = capture_guard_test_repository();
    $pdo->exec("INSERT INTO krz_tasks (id,subject_id,status,claimed_by) VALUES (10,1,'running','pc-a')");
    $pdo->exec("INSERT INTO msig_tasks (id,subject_id,status,claimed_by) VALUES (20,1,'running','pc-b')");
    assert_true($repo->taskClaimIsValid('KRZ', 10, 1, 'pc-a'));
    assert_true(!$repo->taskClaimIsValid('KRZ', 10, 1, 'pc-b'));
    assert_true(!$repo->taskClaimIsValid('KRZ', 10, 2, 'pc-a'));
    assert_true(!$repo->taskClaimIsValid('MSIG', 20, 1, 'pc-a'));
    assert_true($repo->taskClaimIsValid('MSIG', 20, 1, 'pc-b'));
}

function test_trusted_capture_rejects_every_foreign_item_before_saving_any_event(): void {
    [$repo, $pdo] = capture_guard_test_repository();
    $pdo->exec("INSERT INTO krz_tasks (id,subject_id,status,claimed_by) VALUES (11,1,'running','pc-a')");
    $service = new \Duir\Services\CheckService($repo, new \Duir\Services\KrsClient(), new RiskAnalyzer());
    $result = $service->ingestKrz(1, '', null, [
        'task_id'=>11, 'claim_token'=>'pc-a', 'trusted_match'=>true,
        'items'=>[
            ['text'=>'KRS 0000123456. Otwarto postępowanie restrukturyzacyjne.'],
            ['text'=>'Rafał Mucharski, KRS 0000999999. Otwarto postępowanie upadłościowe.'],
        ],
    ]);
    assert_eq($result['reason'] ?? null, 'item_not_matching_subject');
    assert_eq((int)$pdo->query('SELECT COUNT(*) FROM source_checks WHERE source="KRZ" AND status="error"')->fetchColumn(), 1);
    assert_eq((string)$pdo->query('SELECT status FROM krz_tasks WHERE id=11')->fetchColumn(), 'error');
}

function test_no_results_and_known_markers_require_the_active_claim(): void {
    [$repo, $pdo] = capture_guard_test_repository();
    $pdo->exec("INSERT INTO krz_tasks (id,subject_id,status,claimed_by) VALUES (12,1,'running','pc-a'),(13,1,'running','pc-b'),(14,1,'running','pc-a')");
    $pdo->exec("INSERT INTO msig_tasks (id,subject_id,status,claimed_by) VALUES (21,1,'running','pc-a')");
    $service = new \Duir\Services\CheckService($repo, new \Duir\Services\KrsClient(), new RiskAnalyzer());

    $ok = $service->ingestKrz(1, 'KRS 0000123456. Nie zostały znalezione żadne pozycje dla podanych kryteriów.', null, [
        'task_id'=>12, 'claim_token'=>'pc-a',
    ]);
    assert_eq($ok['status'] ?? null, 'no_results');
    assert_eq((string)$pdo->query('SELECT status FROM krz_tasks WHERE id=12')->fetchColumn(), 'done');

    $wrongComputer = $service->ingestKrz(1, 'Nie zostały znalezione żadne pozycje dla podanych kryteriów.', null, [
        'task_id'=>13, 'claim_token'=>'pc-a', 'trusted_match'=>true,
    ]);
    assert_eq($wrongComputer['reason'] ?? null, 'invalid_task_claim');
    assert_eq((string)$pdo->query('SELECT status FROM krz_tasks WHERE id=13')->fetchColumn(), 'running');

    $claimWithoutCriterion = $service->ingestKrz(1, 'Brak wyników.', null, [
        'task_id'=>14, 'claim_token'=>'pc-a',
    ]);
    assert_eq($claimWithoutCriterion['reason'] ?? null, 'no_results_not_bound_to_subject');
    assert_eq((string)$pdo->query('SELECT status FROM krz_tasks WHERE id=14')->fetchColumn(), 'running');

    $manualBound = $service->ingestKrz(1, 'KRS 0000123456. Brak wyników.', null, []);
    assert_eq($manualBound['status'] ?? null, 'no_results');
    assert_eq((string)$pdo->query('SELECT status FROM krz_tasks WHERE id=13')->fetchColumn(), 'running', 'ręczny import nie zamyka kolejki');
    $manualAnonymous = $service->ingestKrz(1, 'Brak wyników.', null, []);
    assert_eq($manualAnonymous['reason'] ?? null, 'no_results_not_bound_to_subject');
    assert_true($service->textConfirmsNoResultsForSubject('KRS 0000123456. Brak wyników.', 1));
    assert_true(!$service->textConfirmsNoResultsForSubject('KRS 0000999999. Brak wyników.', 1));

    $known = $service->ingestMsig(1, '', null, [
        'task_id'=>21, 'claim_token'=>'pc-a', 'items'=>[['known'=>true,'download_id'=>'abc']],
    ]);
    assert_eq($known['status'] ?? null, 'success');
    assert_eq((string)$pdo->query('SELECT status FROM msig_tasks WHERE id=21')->fetchColumn(), 'done');
}

function test_backend_contract_validates_each_item_even_when_extension_says_trusted(): void {
    $src = file_get_contents(dirname(__DIR__).'/app/Services/CheckService.php');
    assert_true(!str_contains($src, '$trusted ='), 'trusted_match must not create a validation bypass');
    assert_true(substr_count($src, 'item_not_matching_subject') >= 2, 'KRZ and MSiG must reject a foreign item separately');
    assert_true(str_contains($src, "taskClaimIsValid('KRZ'"));
    assert_true(str_contains($src, "taskClaimIsValid('MSIG'"));
}

/** 26.11.2026: CW CAPITAL - adres kończy się liczbą („Robotnicza 40”) tuż przed KRS i NIP w wierszu wyników KRZ. */
function test_identifier_glued_by_space_to_neighbouring_number_still_matches(): void {
    $r = new RiskAnalyzer();
    $row = 'CW CAPITAL Spółka z ograniczoną odpowiedzialnością 53-608 Wrocław, ul. Robotnicza 40 0000891019 8681980617 Umorzone postępowania egzekucyjne';
    $s = ['name'=>'Inna nazwa skrócona', 'krs'=>'0000891019', 'nip'=>'8681980617'];
    assert_true($r->textMatchesSubject($row, ['name'=>'X', 'krs'=>'0000891019']), 'KRS obok numeru domu musi pasować');
    assert_true($r->textMatchesSubject($row, ['name'=>'X', 'nip'=>'8681980617']), 'NIP po KRS musi pasować');
    assert_true(!$r->textMatchesSubject($row, ['name'=>'X', 'krs'=>'0000400000']), 'z części nie wolno budować nowych identyfikatorów');
    assert_true(!$r->textMatchesSubject('ul. Robotnicza 40 0000891019', ['name'=>'X', 'krs'=>'4000008910']), 'sklejenie dwóch liczb nie jest identyfikatorem');
    assert_true($r->textMatchesSubject('NIP 868 198 06 17', ['name'=>'X', 'nip'=>'8681980617']), 'NIP ze spacjami nadal pasuje');
}

/** 26.11.2026: CW CAPITAL - 3 umorzone egzekucje w KRZ, zapisana 1. Pierwsza pozycja zamykała zadanie, kolejne dostawały invalid_task_claim. */
function test_next_items_of_same_capture_are_accepted_only_with_same_claim(): void {
    [$repo, $pdo] = capture_guard_test_repository();
    $pdo->exec("INSERT INTO subjects (id,name,krs,type,service_mode) VALUES (2,'INNA SPÓŁKA','0000999999','company','office_monitoring')");
    $pdo->exec("INSERT INTO krz_tasks (id,subject_id,status,claimed_by) VALUES (30,1,'running','claim-a'),(31,1,'running','claim-b')");
    assert_true($repo->markKrzTaskDone(1, 'KRZ — 1 postępowanie', ['n'=>1], 30), 'pierwsza pozycja zamyka zadanie');
    assert_eq((string)$pdo->query('SELECT status FROM krz_tasks WHERE id=30')->fetchColumn(), 'done');
    assert_true(!$repo->taskClaimIsValid('KRZ', 30, 1, 'claim-a'), 'zamknięte zadanie nie jest już dzierżawą');
    assert_true($repo->taskClaimJustCaptured('KRZ', 30, 1, 'claim-a'), 'kolejna pozycja tego samego przechwycenia');
    assert_true(!$repo->taskClaimJustCaptured('KRZ', 30, 1, 'claim-b'), 'inny komputer');
    assert_true(!$repo->taskClaimJustCaptured('KRZ', 30, 2, 'claim-a'), 'inny podmiot');
    assert_true(!$repo->taskClaimJustCaptured('KRZ', 31, 1, 'claim-b'), 'otwarte zadanie idzie zwykłą ścieżką');
    assert_true(!$repo->taskClaimJustCaptured('MSIG', 30, 1, 'claim-a'), 'inna kolejka');
    assert_true($repo->markKrzTaskDone(1, 'KRZ — kolejne postępowanie', ['n'=>2], 30), 'kontrola kolejnej pozycji się zapisuje');
    assert_eq((int)$pdo->query("SELECT COUNT(*) FROM source_checks WHERE source='KRZ' AND status='success'")->fetchColumn(), 2);
    $pdo->exec("UPDATE krz_tasks SET finished_at='2000-01-01 00:00:00' WHERE id=30");
    assert_true(!$repo->taskClaimJustCaptured('KRZ', 30, 1, 'claim-a'), 'spóźniony wynik po dzierżawie');
    $pdo->exec("UPDATE krz_tasks SET status='error', finished_at=NOW() WHERE id=30");
    assert_true(!$repo->taskClaimJustCaptured('KRZ', 30, 1, 'claim-a'), 'zadanie zakończone błędem nie przyjmuje pozycji');
    assert_true(!$repo->markKrzTaskDone(2, 'obcy', null, 30), 'cudze zadanie nie zapisuje kontroli');
    $src = file_get_contents(dirname(__DIR__).'/app/Services/CheckService.php');
    assert_true(str_contains($src, "taskClaimJustCaptured('KRZ', \$taskId, \$subjectId, \$claimToken)"), 'ingestKrz przyjmuje kolejne pozycje przechwycenia');
}
