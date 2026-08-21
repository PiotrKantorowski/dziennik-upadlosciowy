<?php
function test_mysql_schema_contains_core_tables(): void {
    $sql = file_get_contents(dirname(__DIR__).'/database/schema.sql');
    foreach (['subjects','source_checks','events','krz_tasks','financial_statement_checks','reports','outgoing_mail','settings','audit_log'] as $table) {
        assert_true(str_contains($sql, 'CREATE TABLE IF NOT EXISTS '.$table), 'missing table '.$table);
    }
}
function test_repository_contains_krz_task_contract(): void {
    $src = file_get_contents(dirname(__DIR__).'/app/Repository.php');
    assert_true(str_contains($src, 'createKrzTask'));
    assert_true(str_contains($src, 'pendingKrzWorklist'));
    assert_true(str_contains($src, 'markKrzNoResults'));
}
function test_mysql_schema_contains_msig_tasks_table(): void {
    $sql = file_get_contents(dirname(__DIR__).'/database/schema.sql');
    assert_true(str_contains($sql, 'CREATE TABLE IF NOT EXISTS msig_tasks'), 'missing table msig_tasks');
}
function test_repository_contains_msig_task_contract(): void {
    $src = file_get_contents(dirname(__DIR__).'/app/Repository.php');
    assert_true(str_contains($src, 'createMsigTask'));
    assert_true(str_contains($src, 'pendingMsigWorklist'));
    assert_true(str_contains($src, 'markMsigNoResults'));
}
// Wiele wtyczek w kancelarii = jedna wspólna kolejka. Worklista musi ATOMOWO
// rezerwować paczki (claimed_by + dzierżawa), a wtyczka pętlić po paczkach —
// inaczej każda przeglądarka wykonuje te same zadania podwójnie.
function test_worklist_distributes_tasks_between_extensions(): void {
    $src = file_get_contents(dirname(__DIR__).'/app/Repository.php');
    assert_true(str_contains($src, 'claimTasks'), 'worklista musi rezerwować zadania (claimTasks)');
    assert_true(str_contains($src, 'claimed_by'), 'rezerwacja po tokenie claimed_by');
    assert_true(str_contains($src, 'private const TASK_BATCH = 1'), 'każdy komputer powinien rezerwować tylko jedno zadanie naraz');
    assert_true(str_contains($src, 'taskClaimIsValid'), 'wynik musi potwierdzać token dzierżawy komputera');
    assert_true(str_contains($src, 'ensureTaskClaimColumns'), 'samonaprawiająca migracja kolumny claimed_by');
    assert_true(str_contains($src, 'beginTransaction()'), 'wybór zadania musi odbywać się w transakcji');
    assert_true(str_contains($src, 'FOR UPDATE'), 'równoległe komputery muszą blokować wybrany wiersz przed zmianą claimed_by');
    assert_true(str_contains($src, 'inTransaction()) $this->pdo->rollBack()'), 'błąd claimu musi zwalniać transakcję');
    assert_true(substr_count($src, "status='pending' OR (status='running'") >= 2, 'warunek claimowalności musi być powtórzony również w UPDATE');
    $schema = file_get_contents(dirname(__DIR__).'/database/schema.sql');
    assert_true(substr_count($schema, 'claimed_by') >= 2, 'claimed_by w schemacie obu tabel zadań');
    $bg = file_get_contents(dirname(__DIR__).'/chrome_extension/background.js');
    assert_true(str_contains($bg, 'round'), 'wtyczka pobiera kolejne paczki w pętli');
}

function test_pending_task_identity_includes_current_subject_kind(): void {
    $src = file_get_contents(dirname(__DIR__).'/app/Repository.php');
    assert_true(str_contains($src, 'invalidateStaleBrowserTasks'), 'stare zadanie musi zostać unieważnione po zmianie typu podmiotu');
    assert_true(substr_count($src, 'AND query=? AND search_kind=?') >= 2, 'KRZ i MSiG muszą deduplikować także po search_kind');
}

function test_subject_service_mode_is_required_and_drives_monitoring(): void {
    $schema = file_get_contents(dirname(__DIR__).'/database/schema.sql');
    $repo = file_get_contents(dirname(__DIR__).'/app/Repository.php');
    $ui = file_get_contents(dirname(__DIR__).'/app/Controllers/SubjectController.php');
    assert_true(str_contains($schema, "service_mode ENUM('office_monitoring','client_monitoring','one_time') NULL"));
    assert_true(str_contains($repo, 'ensureSubjectServiceModeColumn'), 'existing databases need an automatic service_mode migration');
    assert_true(str_contains($repo, "Wybierz tryb obsługi podmiotu."), 'server-side validation must reject an empty selection');
    assert_true(str_contains($repo, "\$monitored = \$serviceMode === 'one_time' ? 0 : 1;"), 'one-time mode must disable recurring monitoring');
    assert_true(!str_contains($repo, 'WHERE s.monitored=1 AND (t.status='), 'pierwsze zadania trybu jednorazowego muszą wejść do worklisty mimo monitored=0');
    assert_true(str_contains($ui, '<select name="service_mode" required>'), 'the form must require service mode');
    assert_true(str_contains($ui, '— wybierz —'), 'new subject form must start with an empty choice');
    assert_true(str_contains($ui, "'service_mode'=>'Tryb obsługi'"), 'subject card must display service mode');
}
