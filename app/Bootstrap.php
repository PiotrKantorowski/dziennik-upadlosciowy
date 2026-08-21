<?php
namespace {
    if (!function_exists('mb_strtolower')) { function mb_strtolower(string $s, ?string $enc=null): string { return strtolower($s); } }
    if (!function_exists('mb_substr')) { function mb_substr(string $s, int $start, ?int $len=null, ?string $enc=null): string { return $len === null ? substr($s, $start) : substr($s, $start, $len); } }
    if (!function_exists('mb_stripos')) { function mb_stripos(string $h, string $n, int $o=0, ?string $enc=null): int|false { return stripos($h, $n, $o); } }
}
namespace Duir {
final class Bootstrap
{
    public static function init(string $root): void
    {
        spl_autoload_register(function (string $class) use ($root): void {
            $prefix = 'Duir\\';
            if (!str_starts_with($class, $prefix)) return;
            $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
            $file = $root . '/app/' . $rel . '.php';
            if (is_file($file)) require_once $file;
        });
        Config::load($root);
        date_default_timezone_set(Config::get('APP_TIMEZONE', 'Europe/Warsaw'));
        if (!is_dir($root . '/storage/reports')) @mkdir($root . '/storage/reports', 0775, true);
        if (!is_dir($root . '/storage/logs')) @mkdir($root . '/storage/logs', 0775, true);

        // Wyjątki nie mogą wyciekać do klienta poza trybem debug.
        ini_set('display_errors', Config::bool('APP_DEBUG') ? '1' : '0');
        ini_set('log_errors', '1');
        ini_set('error_log', $root . '/storage/logs/php-error.log');

        // Ostrzeżenie o słabym/domyślnym tokenie bridge poza produkcją (na produkcji guard blokuje twardo).
        if (self::isWeakBridgeToken() && Config::get('APP_ENV', 'production') !== 'production') {
            error_log('[WARN] KRZ_BRIDGE_TOKEN jest słaby/domyślny — nie używaj tej konfiguracji na produkcji.');
        }
    }

    public static function isWeakSecret(?string $token): bool
    {
        $token = trim((string)$token);
        if ($token === '' || strlen($token) < 32) return true;
        $fold = strtolower($token);
        foreach (['change-me', 'changeme', 'placeholder', 'example', 'default', '32chars', 'password', 'haslo'] as $bad) {
            if (str_contains($fold, $bad)) return true;
        }
        return false;
    }

    public static function isWeakBridgeToken(): bool
    {
        return self::isWeakSecret((string)Config::get('KRZ_BRIDGE_TOKEN', ''));
    }

    public static function isWeakCronToken(): bool
    {
        return self::isWeakSecret((string)Config::get('CRON_TOKEN', ''));
    }
}
}
