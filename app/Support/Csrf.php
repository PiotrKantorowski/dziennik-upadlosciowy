<?php
namespace Duir\Support;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="'.Http::e(self::token()).'">';
    }

    public static function verify(string $token): bool
    {
        $expected = (string)($_SESSION['csrf'] ?? '');
        if ($expected === '' || $token === '') return false;
        return hash_equals($expected, $token);
    }
}
