<?php
// Generuje hash hasła administratora panelu do zmiennej APP_ADMIN_PASSWORD_HASH w .env.
// Użycie: php bin/hash_password.php 'TwojeHaslo'

if ($argc < 2 || !isset($argv[1]) || $argv[1] === '') {
    fwrite(STDERR, "Użycie: php bin/hash_password.php 'TwojeHaslo'\n");
    fwrite(STDERR, "Skopiuj wynik do .env jako: APP_ADMIN_PASSWORD_HASH=...\n");
    exit(1);
}

echo password_hash($argv[1], PASSWORD_DEFAULT).PHP_EOL;
