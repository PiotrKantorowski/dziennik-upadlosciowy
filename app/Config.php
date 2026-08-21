<?php
namespace Duir;

final class Config
{
    private static array $values = [];
    private static string $root = '';

    public static function load(string $root): void
    {
        self::$root = $root;
        self::$values = $_ENV + $_SERVER;
        $file = $root . '/.env';
        if (is_file($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                    $value = substr($value, 1, -1);
                }
                if (!array_key_exists($key, self::$values)) self::$values[$key] = $value;
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$values[$key] ?? getenv($key) ?: $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::$values[$key] = $value;
    }

    public static function apply(array $values): void
    {
        foreach ($values as $key => $value) self::set((string)$key, $value);
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default ? '1' : '0');
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    public static function root(): string { return self::$root; }
}
