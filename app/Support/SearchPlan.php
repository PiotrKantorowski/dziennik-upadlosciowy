<?php
namespace Duir\Support;

final class SearchPlan
{
    public static function identifiers(array $subject): array
    {
        $ids = [];
        foreach (['krs','nip','regon','pesel'] as $field) {
            $v = Normalize::digits($subject[$field] ?? '');
            if ($v !== '') $ids[$field] = $v;
        }
        return $ids;
    }

    public static function hasHardIdentifier(array $subject): bool
    {
        return (bool)self::identifiers($subject);
    }

    public static function krzQuery(array $subject): array
    {
        $type = (string)($subject['type'] ?? 'company');
        $priority = match ($type) {
            'business_person' => ['pesel','nip','regon','krs'],
            'natural_person' => ['pesel','nip','regon','krs'],
            default => ['krs','nip','regon','pesel'],
        };
        foreach ($priority as $field) {
            $v = Normalize::digits($subject[$field] ?? '');
            if ($v !== '') return [$field, $v];
        }
        $name = Normalize::text($subject['name'] ?? '');
        return ['name', $name];
    }

    // Pojedyncze zapytanie dla zadania MSiG realizowanego przez wtyczkę Chrome.
    // W ODRÓŻNIENIU od krzQuery: publiczna wyszukiwarka MSiG NIE wyszukuje po PESEL
    // (to dana osobowa, nieindeksowana w treści ogłoszeń), więc dla osoby fizycznej
    // mającej tylko PESEL używamy nazwy. Kolejność twardych ID: krs/nip/regon —
    // KRS pierwszy, bo ogłoszenia MSiG są indeksowane po nazwie i numerze KRS;
    // wyszukiwanie po NIP dawało fałszywe "brak wyników" dla spółek rejestrowych.
    // Zwraca [queryKey, query] albo ['', ''] gdy nie ma na czym oprzeć wyszukiwania.
    public static function msigTaskQuery(array $subject): array
    {
        foreach (['krs','nip','regon'] as $field) {
            $v = Normalize::digits($subject[$field] ?? '');
            if ($v !== '') return [$field, $v];
        }
        $name = Normalize::text($subject['name'] ?? '');
        if ($name !== '' && !self::isWeakName($name)) return ['name', $name];
        return ['', ''];
    }

    public static function isWeakName(string $name): bool
    {
        $fold = Normalize::fold($name);
        $tokens = array_values(array_filter(preg_split('/[^a-z0-9]+/u', $fold) ?: []));
        $generic = ['kancelaria','firma','spolka','sp','sa','s','z','oo','zoo','uslugi','handel','polska','group','company','przedsiebiorstwo','podmiot'];
        if (!$tokens) return true;
        $strong = array_values(array_filter($tokens, fn($t) => strlen($t) >= 4 && !in_array($t, $generic, true)));
        return count($strong) < 2 && strlen(Normalize::compactKey($name)) < 14;
    }

    public static function nameMatches(string $needle, string $haystack): bool
    {
        if (self::isWeakName($needle)) return false;
        $needleKey = Normalize::compactKey($needle);
        $hayKey = Normalize::compactKey($haystack);
        if (strlen($needleKey) >= 14 && str_contains($hayKey, $needleKey)) return true;
        $generic = ['kancelaria','firma','spolka','sp','sa','s','z','oo','zoo','uslugi','handel','polska','group','company','przedsiebiorstwo','podmiot'];
        $tokens = array_values(array_filter(preg_split('/[^a-z0-9]+/u', Normalize::fold($needle)) ?: [], fn($t) => strlen($t) >= 4 && !in_array($t, $generic, true)));
        if (count($tokens) < 2) return false;
        $hits = 0;
        $foldHay = Normalize::fold($haystack);
        foreach ($tokens as $t) if (str_contains($foldHay, $t)) $hits++;
        return $hits >= min(3, count($tokens));
    }
}
