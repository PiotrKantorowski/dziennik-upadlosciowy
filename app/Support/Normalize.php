<?php
namespace Duir\Support;

final class Normalize
{
    public static function text(?string $value): string
    {
        $value = trim((string)$value);
        $value = preg_replace('/\s+/u', ' ', $value) ?: '';
        return $value;
    }

    public static function digits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string)$value) ?: '';
    }

    public static function fold(?string $value): string
    {
        $v = mb_strtolower((string)$value, 'UTF-8');
        $map = ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ż'=>'z','ź'=>'z'];
        return strtr($v, $map);
    }

    public static function compactKey(?string $value): string
    {
        return preg_replace('/[^a-z0-9]+/i', '', self::fold((string)$value)) ?: '';
    }

    public static function dateOrNull(mixed $value): ?string
    {
        $s = self::text(is_scalar($value) ? (string)$value : '');
        if (!$s) return null;
        if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $s, $m)) return "$m[1]-$m[2]-$m[3]";
        if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $s, $m)) return "$m[3]-$m[2]-$m[1]";
        return null;
    }

    public static function safeFilename(string $value): string
    {
        $v = self::compactKey($value);
        return substr($v ?: 'raport', 0, 60);
    }
}
