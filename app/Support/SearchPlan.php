<?php
namespace Duir\Support;

final class SearchPlan
{
    public static function identifiers(array $subject): array
    {
        $ids = [];
        foreach (['krs','nip','regon','pesel'] as $field) {
            $v = $field === 'krs' ? Normalize::krs($subject[$field] ?? '') : Normalize::digits($subject[$field] ?? '');
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
            $v = $field === 'krs' ? Normalize::krs($subject[$field] ?? '') : Normalize::digits($subject[$field] ?? '');
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
            $v = $field === 'krs' ? Normalize::krs($subject[$field] ?? '') : Normalize::digits($subject[$field] ?? '');
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

    private const GENERIC = ['kancelaria','firma','spolka','sp','sa','s','z','oo','zoo','uslugi','handel','polska','group','company','przedsiebiorstwo','podmiot','c','k','j','akcyjna','ograniczona','odpowiedzialnoscia','cywilna','komandytowa','jawna'];
    // Końcówki fleksyjne (po fold: ą→a, ę→e), którymi odmiana imienia/nazwiska różni się od mianownika.
    private const ENDINGS = ['','a','e','i','y','u','o','em','om','ie','iem','owi','ego','emu','iego','iemu','im','ym','ej','iej','ow','owie','ach','ami'];

    /** Słowa nazwy po fold; łącznik zostaje w słowie, więc „Nowak-Zielińska" ≠ „Nowak". */
    private static function words(string $value): array
    {
        preg_match_all('/[\p{L}\p{N}]+(?:-[\p{L}\p{N}]+)*/u', Normalize::fold($value), $m);
        return $m[0];
    }

    /**
     * @param string $type typ podmiotu (company/business_person/natural_person/unknown);
     *        dla osób imię i nazwisko muszą stać obok siebie, z tolerancją odmiany.
     */
    public static function nameMatches(string $needle, string $haystack, string $type = 'company'): bool
    {
        $words = self::words($needle);
        if (!$words) return false;
        $hay = self::words($haystack);
        $person = in_array($type, ['business_person','natural_person'], true)
            || ($type !== 'company' && count($words) <= 3 && !array_intersect($words, self::GENERIC));
        // Imię + nazwisko: dla JDG pierwsze dwa słowa nazwy, dla osoby cała nazwa.
        $personWords = $type === 'business_person' ? array_slice($words, 0, 2) : $words;
        if ($person && count($personWords) >= 2 && count($personWords) <= 4
            && self::wordsNear($personWords, $hay, count($personWords) + 1, true)) return true;
        if ($type === 'natural_person') return false;
        // Cała nazwa jako fraza z granicami słów (dawniej podciąg ciągu bez spacji).
        if (strlen(Normalize::compactKey($needle)) >= 14
            && str_contains(' '.implode(' ', $hay).' ', ' '.implode(' ', $words).' ')) return true;
        if (self::isWeakName($needle)) return false;
        $strong = array_values(array_unique(array_filter($words, fn($t) => strlen($t) >= 4 && !in_array($t, self::GENERIC, true))));
        if (count($strong) < 2) return false;
        $need = min(3, count($strong));
        return self::wordsNear($strong, $hay, count($strong) + 2, false, $need);
    }

    /** Czy co najmniej $need słów z $words występuje (jako całe słowa) w oknie $window kolejnych słów strony. */
    private static function wordsNear(array $words, array $hay, int $window, bool $inflect, ?int $need = null): bool
    {
        $need ??= count($words);
        $n = count($hay);
        for ($i = 0; $i < $n; $i++) {
            $found = [];
            for ($j = $i; $j < min($n, $i + $window); $j++) {
                foreach ($words as $k => $w) {
                    if (!isset($found[$k]) && ($hay[$j] === $w || ($inflect && self::inflectedForm($w, $hay[$j])))) { $found[$k] = true; break; }
                }
            }
            if (count($found) >= $need) return true;
        }
        return false;
    }

    /** Czy $form to odmieniona postać imienia/nazwiska $base (Jan→Jana, Wróbel→Wróbla, Kowalski→Kowalskiego). */
    private static function inflectedForm(string $base, string $form): bool
    {
        // Nazwiska przymiotnikowe: rodzaj musi się zgadzać (Kowalski ≠ Kowalska).
        $male = '/(sk|ck|dzk)(i|iego|iemu|im)$/';
        $female = '/(sk|ck|dzk)(a|iej)$/';
        if (preg_match($male, $base) && preg_match($female, $form)) return false;
        if (preg_match($female, $base) && preg_match($male, $form)) return false;
        // Ruchome e: Wróbel→Wróbl-a, Marek→Mark-a, Kozieł→Kozł-a.
        foreach (array_unique([$base, preg_replace('/i?e([^aeiouy])$/', '$1', $base)]) as $b) {
            // Rdzeń: mianownik bez końcowej samogłoski (Jan, Kowalsk-, Ann-).
            $stem = preg_replace('/[aeiouy]$/', '', $b);
            if (strlen($stem) < 2 || !str_starts_with($form, $stem)) continue;
            if (in_array(substr($form, strlen($stem)), self::ENDINGS, true)) return true;
        }
        return false;
    }
}
