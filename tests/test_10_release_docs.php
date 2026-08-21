<?php
function test_10_release_docs_exist(): void {
    $root = dirname(__DIR__);
    foreach (['RELEASE-NOTES-1.0.0.md','docs/CHROME_EXTENSION.md','docs/SMTP_CONFIG.md','docs/KRS_MSIG_SOURCES.md','docs/CHECKLISTA_WDROZENIOWA_1_0.md','docs/WERYFIKACJA_1_0.md'] as $file) {
        assert_true(is_file($root.'/'.$file), 'missing 1.0 doc '.$file);
    }
}
function test_version_is_1_0_0(): void {
    assert_eq(trim(file_get_contents(dirname(__DIR__).'/VERSION')), '1.0.0');
}
