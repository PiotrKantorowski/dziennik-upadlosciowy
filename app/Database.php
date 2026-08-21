<?php
namespace Duir;

use PDO;

final class Database
{
    public function pdo(): PDO
    {
        $dsn = (string)Config::get('DB_DSN', 'mysql:host=127.0.0.1;dbname=duir;charset=utf8mb4');
        $user = (string)Config::get('DB_USER', 'root');
        $password = (string)Config::get('DB_PASSWORD', '');
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    }
}
