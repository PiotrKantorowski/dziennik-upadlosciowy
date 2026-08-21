<?php
function test_subject_controller_shows_sources_before_actions(): void {
    $src = file_get_contents(dirname(__DIR__).'/app/Controllers/SubjectController.php');
    assert_true(strpos($src, 'source-summary') < strpos($src, 'actions bottom'), 'source results should be before bottom actions');
    assert_true(str_contains($src, 'Dane podmiotu'));
    assert_true(str_contains($src, 'Najnowszy wpis w KRZ'));
}
function test_krz_troubleshooting_doc_exists(): void {
    assert_true(is_file(dirname(__DIR__).'/docs/KRZ_TROUBLESHOOTING.md'));
}
