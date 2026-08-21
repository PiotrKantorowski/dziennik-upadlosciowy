<?php
function test_release_contract_files_exist(): void {
    $root = dirname(__DIR__);
    foreach (['VERSION','docs/INSTALL_LOCAL.md','docs/INSTALL_VPS.md','docs/SECURITY.md','database/schema.sql','chrome_extension/manifest.json'] as $file) {
        assert_true(is_file($root.'/'.$file), 'missing release contract file '.$file);
    }
}
function test_no_secret_files_in_release_source(): void {
    $root = dirname(__DIR__);
    foreach (['.env','data/dziennik.sqlite3','storage/reports/secret.pdf'] as $file) {
        assert_true(!is_file($root.'/'.$file), 'secret/generated file must not be present: '.$file);
    }
}
