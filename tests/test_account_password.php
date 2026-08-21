<?php
use Duir\Repository;

function duir_account_repo(): array {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, name TEXT, password_hash TEXT, role TEXT, active INT, last_login_at TEXT, created_at TEXT, updated_at TEXT)');
    $hash = password_hash('ObecneHaslo123', PASSWORD_DEFAULT);
    $pdo->prepare('INSERT INTO users (id,email,name,password_hash,role,active) VALUES (1,?,?,?,?,1)')
        ->execute(['jan@example.com','Jan Testowy',$hash,'user']);
    return [new Repository($pdo), $pdo];
}

// Zła weryfikacja obecnego hasła — MUSI odrzucić (rzuca przed UPDATE).
function test_change_password_rejects_wrong_current(): void {
    [$repo] = duir_account_repo();
    $threw = false;
    try { $repo->changeOwnPassword(1, 'zleObecne', 'NoweHaslo123456', 'NoweHaslo123456'); }
    catch (\InvalidArgumentException $e) { $threw = true; assert_true(str_contains($e->getMessage(), 'Obecne hasło')); }
    assert_true($threw, 'złe obecne hasło musi być odrzucone');
}

// Za krótkie nowe hasło (<12).
function test_change_password_rejects_short_new(): void {
    [$repo] = duir_account_repo();
    $threw = false;
    try { $repo->changeOwnPassword(1, 'ObecneHaslo123', 'krotkie', 'krotkie'); }
    catch (\InvalidArgumentException $e) { $threw = true; assert_true(str_contains($e->getMessage(), '12 znaków')); }
    assert_true($threw, 'za krótkie nowe hasło odrzucone');
}

// Niezgodne powtórzenie.
function test_change_password_rejects_mismatch(): void {
    [$repo] = duir_account_repo();
    $threw = false;
    try { $repo->changeOwnPassword(1, 'ObecneHaslo123', 'NoweHaslo123456', 'INNE_Haslo123456'); }
    catch (\InvalidArgumentException $e) { $threw = true; assert_true(str_contains($e->getMessage(), 'identyczne')); }
    assert_true($threw, 'niezgodne nowe hasła odrzucone');
}

// Nowe = obecne — odrzucone (wymuszamy realną zmianę).
function test_change_password_rejects_same_as_current(): void {
    [$repo] = duir_account_repo();
    $threw = false;
    try { $repo->changeOwnPassword(1, 'ObecneHaslo123', 'ObecneHaslo123', 'ObecneHaslo123'); }
    catch (\InvalidArgumentException $e) { $threw = true; assert_true(str_contains($e->getMessage(), 'różnić')); }
    assert_true($threw, 'nowe hasło identyczne z obecnym odrzucone');
}
