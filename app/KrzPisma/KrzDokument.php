<?php
namespace Duir\KrzPisma;

/**
 * Odczyt dokumentu z KRZ (obwieszczenie, postanowienie, zarządzenie) regułami, bez AI.
 * Zasada jak w easyEPU: nie zgadujemy — pole niepewne zostaje puste z ostrzeżeniem.
 *
 * 1) Typ: kod wzoru z zestawienia KRZ (np. 11176OB) znaleziony w tekście → pewny.
 *    Bez kodu: najdłuższy tytuł wzoru z katalogu zawarty w tekście → „po tytule".
 * 2) Sygnatury: format KRZ, np. LD1M/GUp/16/2026, WA1M/GUp-s-Zw/12/2026.
 * 3) Daty i termin wyznaczony w treści („w terminie tygodnia").
 */
final class KrzDokument
{
    private const SIG_RE = '~\b([A-Z]{2}\d[A-Z])/([A-Za-z]{1,8}(?:-[A-Za-z]{1,8}){0,3})/(\d{1,7})/(\d{2,4})\b~u';
    private const CODE_RE = '~(?<![\dA-Z])(\d{5,6}[A-Z]{2})(?![A-Za-z])~';
    private const MIN_TITLE = 30; // krótsze tytuły („Postanowienie Sądu dowolne") są zbyt ogólne

    /** Katalog [kod => tytuł] z dokumenty.tsv. */
    public static function catalog(string $path): array
    {
        $out = [];
        foreach (is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [] as $line) {
            if ($line === '' || $line[0] === '#' || str_starts_with($line, "kod\t")) continue;
            $p = explode("\t", $line, 2);
            if (count($p) === 2) $out[$p[0]] = $p[1];
        }
        return $out;
    }

    public static function read(string $text, array $catalog): array
    {
        $warn = [];
        $kod = null; $how = null;
        if (preg_match_all(self::CODE_RE, $text, $m)) {
            $known = array_values(array_unique(array_filter($m[1], fn($c) => isset($catalog[$c]))));
            if (count($known) === 1) { $kod = $known[0]; $how = 'kod wzoru w treści'; }
            elseif (count($known) > 1) $warn[] = 'W treści jest kilka kodów wzorów KRZ ('.implode(', ', $known).') — wskaż właściwy ręcznie.';
        }
        if ($kod === null && !$warn) {
            [$kod, $amb] = self::byTitle($text, $catalog);
            if ($kod !== null) { $how = 'tytuł wzoru w treści (bez kodu)'; $warn[] = 'Typ rozpoznany po tytule, nie po kodzie — sprawdź.'; }
            if ($amb) $warn[] = 'Tytuł pasuje do kilku wzorów ('.implode(', ', $amb).') — wskaż właściwy ręcznie.';
        }
        if ($kod === null && !$warn) $warn[] = 'Nie rozpoznano wzoru dokumentu KRZ.';

        $sig = [];
        if (preg_match_all(self::SIG_RE, $text, $s, PREG_SET_ORDER)) {
            foreach ($s as $x) $sig[$x[0]] = self::sigKind($x[2]);
        }
        $dates = self::dates($text);
        $docDate = null;
        if (preg_match('~\bz?\s*dnia\s+(\d{1,2}[.\-/]\d{1,2}[.\-/]\d{4}|\d{4}-\d{2}-\d{2})~iu', $text, $d)) $docDate = self::iso($d[1]);
        $assigned = null;
        if (preg_match('~w\s+terminie\s+(tygodnia|dwóch\s+tygodni|\d+\s+dni|\d+\s+tygodni|\d+-dniowym|tygodniowym)~iu', $text, $t)) $assigned = preg_replace('/\s+/u', ' ', mb_strtolower($t[1], 'UTF-8'));

        return [
            'kod' => $kod,
            'tytul' => $kod !== null ? $catalog[$kod] : null,
            'rozpoznanie' => $how,
            'sygnatury' => $sig,
            'daty' => $dates,
            'data_dokumentu' => $docDate,
            'termin_w_tresci' => $assigned,
            'ostrzezenia' => $warn,
        ];
    }

    /** Rodzaj sprawy po repertorium sygnatury. */
    public static function sigKind(string $rep): string
    {
        $r = mb_strtolower($rep, 'UTF-8');
        if (str_contains($r, 'zw')) return 'zgłoszenia wierzytelności (Zw)';
        if (str_starts_with($r, 'gup')) return 'postępowanie upadłościowe (GUp)';
        if ($r === 'gu') return 'wniosek o ogłoszenie upadłości (GU)';
        if (str_starts_with($r, 'gr')) return 'postępowanie restrukturyzacyjne (GR)';
        return 'inna';
    }

    public static function iso(string $d): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $d : null;
        if (preg_match('~^(\d{1,2})[.\-/](\d{1,2})[.\-/](\d{4})$~', $d, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        return null;
    }

    private static function dates(string $text): array
    {
        preg_match_all('~(?<!\d)(\d{1,2}[.\-/]\d{1,2}[.\-/]\d{4}|\d{4}-\d{2}-\d{2})(?!\d)~', $text, $m);
        return array_values(array_unique(array_filter(array_map([self::class, 'iso'], $m[1]))));
    }

    /** @return array{0:?string,1:array} kod albo null oraz lista kodów przy niejednoznaczności */
    private static function byTitle(string $text, array $catalog): array
    {
        $hay = self::norm($text);
        $best = []; $bestLen = 0;
        foreach ($catalog as $code => $title) {
            $needle = self::norm(preg_replace('/\([^)]*\)/u', '', $title) ?? $title);
            $len = mb_strlen($needle, 'UTF-8');
            if ($len < self::MIN_TITLE || $len < $bestLen || !str_contains($hay, $needle)) continue;
            if ($len > $bestLen) { $best = []; $bestLen = $len; }
            $best[] = $code;
        }
        if (count($best) === 1) return [$best[0], []];
        return [null, count($best) > 1 ? $best : []];
    }

    private static function norm(string $v): string
    {
        $v = mb_strtolower($v, 'UTF-8');
        $v = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $v) ?? $v;
        return trim($v);
    }
}
