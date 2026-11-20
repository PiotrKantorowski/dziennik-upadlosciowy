<?php
namespace Duir\KrzPisma;

/**
 * Pisma KRZ (prototyp): katalog wzorów, model danych wzoru 30048
 * „Zgłoszenie wierzytelności w postępowaniu upadłościowym", walidacja
 * i karta wypełnienia ułożona w kolejności metryk formularza PUZ.
 *
 * Kolejność i nazwy sekcji: Instrukcja wierzyciela KRZ v1.02 (rozdz. 4).
 * Zakres treści: art. 240 p.u. Pola typów wierzytelności w KRZ są znane
 * tylko ze zrzutów ekranu instrukcji — do potwierdzenia na żywym portalu.
 * Nic tu nie wysyła pism: pismo podpisuje i wysyła pełnomocnik w KRZ.
 *
 * Moduł jest samodzielny (bez zależności od monitoringu DUiR), żeby dało się
 * go przenieść do LexPilota — patrz README.md w tym katalogu.
 */
final class KrzForms
{
    public const SUPPORTED = ['30048' => 'Zgłoszenie wierzytelności w postępowaniu upadłościowym'];

    public const ADDRESS = ['kraj'=>'Kraj','kod'=>'Kod pocztowy','miejscowosc'=>'Miejscowość','ulica'=>'Ulica','nr_budynku'=>'Numer budynku','nr_lokalu'=>'Numer lokalu'];
    public const CONTACT = ['telefon'=>'Telefon','email'=>'E-mail'];
    public const CREDITOR = ['oznaczenie'=>'Oznaczenie wierzyciela','nazwa'=>'Nazwa','imie'=>'Imię','nazwisko'=>'Nazwisko','pesel'=>'PESEL','krs'=>'KRS','nip'=>'NIP','inne_id'=>'Inne dane identyfikujące','forma_prawna'=>'Rejestr i forma prawna','siedziba'=>'Miejsce zamieszkania / siedziba'];
    public const REPRESENTATIVE = ['imie'=>'Imię','nazwisko'=>'Nazwisko','pesel'=>'PESEL','funkcja'=>'Funkcja / podstawa umocowania'];
    public const ATTORNEY = ['rodzaj'=>'Oznaczenie pełnomocnika','do_doreczen'=>'Pełnomocnik wyłącznie do doręczeń','nazwa'=>'Nazwa (kancelaria)','imie'=>'Imię','nazwisko'=>'Nazwisko','id_konta'=>'Identyfikator konta KRZ'];
    public const CLAIM = ['typ'=>'Typ wierzytelności','tytul'=>'Tytuł / podstawa (np. faktura, umowa)','kwota'=>'Kwota','waluta'=>'Waluta','wymagalnosc'=>'Data wymagalności','odsetki_rodzaj'=>'Rodzaj odsetek','odsetki_od'=>'Odsetki od','odsetki_do'=>'Odsetki do','kategoria'=>'Kategoria zaspokojenia','zabezpieczenie'=>'Zabezpieczenia','przedmiot_zabezpieczenia'=>'Przedmiot zabezpieczenia (upadły nie jest dłużnikiem osobistym)','stan_sprawy'=>'Stan sprawy (postępowanie sądowe, administracyjne, polubowne)','dowody'=>'Dowody'];

    public const CLAIM_TYPES = ['glowna'=>'należność główna','odsetki'=>'odsetki','inna'=>'inna, w tym koszty'];
    public const CATEGORIES = ['I'=>'kategoria I','II'=>'kategoria II','III'=>'kategoria III','IV'=>'kategoria IV'];

    /** Wiersze katalogu: [kod, tytul, od, kto]. Linie z # to komentarz. */
    public static function catalog(string $path): array
    {
        $rows = [];
        $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
        foreach ($lines as $i => $line) {
            if ($line === '' || $line[0] === '#' || str_starts_with($line, "kod\t")) continue;
            $p = explode("\t", $line);
            if (count($p) !== 4) continue;
            $rows[] = ['kod'=>$p[0], 'tytul'=>$p[1], 'od'=>$p[2], 'kto'=>$p[3]];
        }
        return $rows;
    }

    /** Dane z formularza (tylko znane pola, przycięte; puste wiersze list odrzucone). */
    public static function fromPost(array $d): array
    {
        $pick = static function (array $src, array $keys): array {
            $out = [];
            foreach (array_keys($keys) as $k) $out[$k] = self::text(is_scalar($src[$k] ?? null) ? (string)$src[$k] : '');
            return $out;
        };
        $rows = static function (mixed $list, array $keys, array $ignore) use ($pick): array {
            $out = [];
            foreach (is_array($list) ? $list : [] as $row) {
                if (!is_array($row)) continue;
                $r = $pick($row, $keys);
                $meaningful = array_diff_key($r, array_flip($ignore));
                if (implode('', $meaningful) !== '') $out[] = $r;
            }
            return $out;
        };
        $w = is_array($d['wierzyciel'] ?? null) ? $d['wierzyciel'] : [];
        $p = is_array($d['pelnomocnik'] ?? null) ? $d['pelnomocnik'] : [];
        $data = [
            'sygnatura' => self::text((string)($d['sygnatura'] ?? '')),
            'upadly' => self::text((string)($d['upadly'] ?? '')),
            'tryb' => ($d['tryb'] ?? '') === 'samodzielnie' ? 'samodzielnie' : 'pelnomocnik',
            'wierzyciel' => $pick($w, self::CREDITOR + self::ADDRESS + self::CONTACT),
            'reprezentanci' => $rows($d['reprezentanci'] ?? [], self::REPRESENTATIVE, []),
            'pelnomocnik' => $pick($p, self::ATTORNEY + self::ADDRESS + self::CONTACT),
            // Typ i waluta mają wartości domyślne w formularzu, więc nie świadczą o treści wiersza.
            'wierzytelnosci' => $rows($d['wierzytelnosci'] ?? [], self::CLAIM, ['typ','waluta','kategoria']),
            'bank' => self::text((string)($d['bank'] ?? '')),
            'rachunek' => self::text((string)($d['rachunek'] ?? '')),
            'zalaczniki' => trim((string)($d['zalaczniki'] ?? '')),
        ];
        $data['wierzyciel']['oznaczenie'] = $data['wierzyciel']['oznaczenie'] === 'osoba' ? 'osoba' : 'podmiot';
        // KRZ wymaga KRS z zerami wiodącymi do 10 cyfr (ten sam wymóg co w monitoringu DUiR).
        $krs = str_replace(' ', '', $data['wierzyciel']['krs']);
        if (preg_match('/^\d{1,9}$/', $krs)) $data['wierzyciel']['krs'] = str_pad($krs, 10, '0', STR_PAD_LEFT);
        $data['pelnomocnik']['rodzaj'] = $data['pelnomocnik']['rodzaj'] === 'inny' ? 'inny' : 'profesjonalny';
        $data['pelnomocnik']['do_doreczen'] = ($data['pelnomocnik']['do_doreczen'] ?? '') !== '' ? '1' : '';
        foreach ($data['wierzytelnosci'] as &$c) {
            if (!isset(self::CLAIM_TYPES[$c['typ']])) $c['typ'] = 'glowna';
            if (!isset(self::CATEGORIES[$c['kategoria']])) $c['kategoria'] = '';
            $c['waluta'] = strtoupper($c['waluta'] ?: 'PLN');
            $amount = self::amount($c['kwota']);
            $c['kwota'] = $amount === null ? $c['kwota'] : number_format($amount, 2, '.', '');
        }
        unset($c);
        return $data;
    }

    public static function title(array $d): string
    {
        $w = $d['wierzyciel'] ?? [];
        $who = ($w['oznaczenie'] ?? '') === 'osoba' ? trim(($w['imie'] ?? '').' '.($w['nazwisko'] ?? '')) : (string)($w['nazwa'] ?? '');
        return 'Zgłoszenie wierzytelności — '.($who ?: 'wierzyciel bez nazwy').(($d['sygnatura'] ?? '') ? ' — '.$d['sygnatura'] : '');
    }

    /** ['errors'=>[], 'warnings'=>[]]: errors = KRZ nie przepuści albo brak wymogu art. 240 p.u.; warnings = zalecane. */
    public static function validate(array $d): array
    {
        $e = []; $w = [];
        $sig = (string)($d['sygnatura'] ?? '');
        if ($sig === '') $e[] = 'Brak sygnatury postępowania upadłościowego (np. „WA1M/GUp/123/2026").';
        elseif (!preg_match('#/GUp#i', $sig)) $w[] = 'Sygnatura nie wygląda na sygnaturę postępowania upadłościowego (GUp, GUp-s, GUp-Zw). Sprawdź, czy to właściwe postępowanie.';

        $cr = $d['wierzyciel'] ?? [];
        if (($cr['oznaczenie'] ?? '') === 'osoba') {
            if (($cr['imie'] ?? '') === '' || ($cr['nazwisko'] ?? '') === '') $e[] = 'Wierzyciel: podaj imię i nazwisko.';
            if (($cr['pesel'] ?? '') === '' && ($cr['inne_id'] ?? '') === '') $e[] = 'Wierzyciel: podaj PESEL albo inne dane identyfikujące (art. 240 pkt 1 p.u.).';
        } else {
            if (($cr['nazwa'] ?? '') === '') $e[] = 'Wierzyciel: podaj nazwę.';
            if (($cr['krs'] ?? '') === '' && ($cr['nip'] ?? '') === '' && ($cr['inne_id'] ?? '') === '') $e[] = 'Wierzyciel: podaj KRS, NIP albo inne dane identyfikujące (art. 240 pkt 1 p.u.).';
            if (!($d['reprezentanci'] ?? [])) $e[] = 'Wierzyciel nie jest osobą fizyczną: KRZ wymaga co najmniej jednego reprezentanta.';
        }
        self::checkIds($cr, 'Wierzyciel', $e);
        foreach (($d['reprezentanci'] ?? []) as $i => $r) {
            if (($r['imie'] ?? '') === '' || ($r['nazwisko'] ?? '') === '') $e[] = 'Reprezentant '.($i+1).': podaj imię i nazwisko.';
            self::checkIds($r, 'Reprezentant '.($i+1), $e);
        }
        self::checkAddress($cr, 'Wierzyciel', $e, $w);

        if (($d['tryb'] ?? '') === 'pelnomocnik') {
            $at = $d['pelnomocnik'] ?? [];
            if (($at['imie'] ?? '') === '' || ($at['nazwisko'] ?? '') === '') $e[] = 'Pełnomocnik: podaj imię i nazwisko.';
            if (($at['id_konta'] ?? '') === '') $w[] = 'Pełnomocnik: bez identyfikatora konta KRZ pełnomocnik nie zostanie przypisany jako uczestnik i nie dostanie korespondencji w KRZ.';
            self::checkAddress($at, 'Pełnomocnik', $e, $w);
        }

        $claims = $d['wierzytelnosci'] ?? [];
        if (!$claims) $e[] = 'Dodaj co najmniej jedną wierzytelność (art. 240 pkt 2 p.u.).';
        foreach ($claims as $i => $c) {
            $n = 'Wierzytelność '.($i+1);
            $amount = self::amount((string)($c['kwota'] ?? ''));
            if ($amount === null || $amount <= 0) $e[] = $n.': podaj kwotę większą od zera.';
            if (($c['kategoria'] ?? '') === '') $e[] = $n.': wskaż kategorię zaspokojenia (art. 240 pkt 4 p.u.).';
            if (($c['tytul'] ?? '') === '') $w[] = $n.': opisz tytuł wierzytelności (faktura, umowa, wyrok).';
            if (($c['dowody'] ?? '') === '') $w[] = $n.': wskaż dowody istnienia wierzytelności (art. 240 pkt 3 p.u.); brak wydłuży postępowanie o wezwanie do uzupełnienia braków.';
            if (($c['typ'] ?? '') === 'odsetki') {
                if (($c['odsetki_od'] ?? '') === '' || ($c['odsetki_do'] ?? '') === '') $w[] = $n.': podaj okres naliczenia odsetek; z masy co do zasady tylko za okres do dnia ogłoszenia upadłości (art. 92 ust. 1 p.u., wyjątek: ust. 2).';
                if (!in_array($c['kategoria'] ?? '', ['', 'III', 'IV'], true)) $w[] = $n.': odsetki od należności z wyższych kategorii należą co do zasady do kategorii III (art. 342 ust. 1 pkt 3 p.u.); sprawdź kategorię.';
            }
            if (($c['waluta'] ?? 'PLN') !== 'PLN') $w[] = $n.': wierzytelność w walucie obcej — sprawdź, jak ją wykazać w formularzu KRZ i czy wymaga przeliczenia na złote.';
        }

        $acc = (string)($d['rachunek'] ?? '');
        if ($acc === '') $w[] = 'Brak numeru rachunku bankowego wierzyciela (art. 240 pkt 9 p.u., jeśli wierzyciel ma rachunek).';
        elseif (!self::validIban($acc)) $e[] = 'Numer rachunku bankowego ma błędną sumę kontrolną.';

        return ['errors'=>$e, 'warnings'=>$w];
    }

    /** Suma kwot według kategorii i typu (tylko PLN; inne waluty osobno). Do kontroli z sekcją VI KRZ. */
    public static function summary(array $d): array
    {
        $out = ['pln'=>[], 'inne'=>[], 'razem_pln'=>0.0];
        foreach (($d['wierzytelnosci'] ?? []) as $c) {
            $a = self::amount((string)($c['kwota'] ?? '')) ?? 0.0;
            $cat = ($c['kategoria'] ?? '') ?: '—';
            if (($c['waluta'] ?? 'PLN') === 'PLN') {
                $out['pln'][$cat][$c['typ']] = ($out['pln'][$cat][$c['typ']] ?? 0) + $a;
                $out['razem_pln'] += $a;
            } else {
                $out['inne'][$c['waluta']] = ($out['inne'][$c['waluta']] ?? 0) + $a;
            }
        }
        ksort($out['pln']);
        return $out;
    }

    /** Karta wypełnienia: [metryka, pole, wartość, kto uzupełnia] w kolejności formularza PUZ. */
    public static function card(array $d): array
    {
        $rows = [];
        $add = static function (string $m, string $f, string $v, string $src = 'pełnomocnik') use (&$rows) { $rows[] = [$m, $f, $v, $src]; };
        $add('I. Organ', 'Syndyk (organ pozasądowy)', '', 'KRZ uzupełnia automatycznie');
        $add('II. Wnoszący pismo', 'Wnoszący', ($d['tryb'] ?? '') === 'samodzielnie' ? 'samodzielnie' : 'pełnomocnik (w tym ustanowiony przez przedstawiciela)');

        $m = 'III. Dane wierzyciela';
        $cr = $d['wierzyciel'] ?? [];
        $add($m, 'Oznaczenie wierzyciela', ($cr['oznaczenie'] ?? '') === 'osoba' ? 'osoba fizyczna' : 'podmiot niebędący osobą fizyczną');
        $keys = ($cr['oznaczenie'] ?? '') === 'osoba' ? ['imie','nazwisko','pesel','nip','inne_id','siedziba'] : ['nazwa','krs','nip','inne_id','forma_prawna','siedziba'];
        foreach ($keys as $k) if (($cr[$k] ?? '') !== '') $add($m, self::CREDITOR[$k], (string)$cr[$k]);
        foreach (self::ADDRESS + self::CONTACT as $k => $label) if (($cr[$k] ?? '') !== '') $add($m.' — adres i kontakt', $label, (string)$cr[$k]);
        foreach (($d['reprezentanci'] ?? []) as $i => $r) {
            foreach (self::REPRESENTATIVE as $k => $label) if (($r[$k] ?? '') !== '') $add($m.' — reprezentant '.($i+1), $label, (string)$r[$k]);
        }
        if (($d['tryb'] ?? '') === 'pelnomocnik') {
            $m = 'III. Dane pełnomocnika procesowego';
            $at = $d['pelnomocnik'] ?? [];
            $add($m, 'Oznaczenie pełnomocnika', ($at['rodzaj'] ?? '') === 'inny' ? 'pełnomocnik inny' : 'profesjonalny');
            if (($at['do_doreczen'] ?? '') !== '') $add($m, self::ATTORNEY['do_doreczen'], 'tak');
            foreach (['nazwa','imie','nazwisko','id_konta'] as $k) if (($at[$k] ?? '') !== '') $add($m, self::ATTORNEY[$k], (string)$at[$k]);
            foreach (self::ADDRESS + self::CONTACT as $k => $label) if (($at[$k] ?? '') !== '') $add($m.' — adres i kontakt', $label, (string)$at[$k]);
        }
        $add('III. Upadły', 'Upadły', (string)($d['upadly'] ?? ''), 'KRZ uzupełnia po „Dodaj upadłego"');

        foreach (($d['wierzytelnosci'] ?? []) as $i => $c) {
            $m = 'IV. Wierzytelność '.($i+1).' ('.(self::CLAIM_TYPES[$c['typ']] ?? $c['typ']).')';
            foreach (self::CLAIM as $k => $label) {
                if ($k === 'typ' || ($c[$k] ?? '') === '') continue;
                if (str_starts_with($k, 'odsetki_') && $c['typ'] !== 'odsetki') continue;
                $v = $k === 'kategoria' ? (self::CATEGORIES[$c[$k]] ?? $c[$k]) : (string)$c[$k];
                $add($m, $label, $v);
            }
        }
        $add('V. Dodatkowe informacje', 'Nazwa banku', (string)($d['bank'] ?? ''));
        $add('V. Dodatkowe informacje', 'Numer rachunku', (string)($d['rachunek'] ?? ''));
        $add('VI. Podsumowanie wierzytelności', 'Sumy według kategorii', '', 'KRZ liczy automatycznie — porównaj z podsumowaniem poniżej');
        foreach (preg_split('/\R/', (string)($d['zalaczniki'] ?? '')) ?: [] as $z) {
            if (trim($z) !== '') $add('VII. Załączniki', 'Plik do dołączenia', trim($z), 'pełnomocnik dodaje plik ręcznie');
        }
        return $rows;
    }

    public static function amount(string $v): ?float
    {
        $v = str_replace([' ', "\u{00A0}", 'zł', 'PLN'], '', trim($v));
        if ($v === '') return null;
        if (str_contains($v, ',') && str_contains($v, '.')) $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
        return is_numeric($v) ? round((float)$v, 2) : null;
    }

    public static function validPesel(string $v): bool
    {
        if (!preg_match('/^\d{11}$/', $v)) return false;
        $wts = [1,3,7,9,1,3,7,9,1,3]; $s = 0;
        for ($i = 0; $i < 10; $i++) $s += $wts[$i] * (int)$v[$i];
        return (10 - $s % 10) % 10 === (int)$v[10];
    }

    public static function validNip(string $v): bool
    {
        $v = self::digits($v);
        if (strlen($v) !== 10) return false;
        $wts = [6,5,7,2,3,4,5,6,7]; $s = 0;
        for ($i = 0; $i < 9; $i++) $s += $wts[$i] * (int)$v[$i];
        return $s % 11 !== 10 && $s % 11 === (int)$v[9];
    }

    /** Polski NRB (26 cyfr) albo IBAN; suma kontrolna mod 97. */
    public static function validIban(string $v): bool
    {
        $v = strtoupper(preg_replace('/\s+/', '', $v) ?? '');
        if (preg_match('/^\d{26}$/', $v)) $v = 'PL'.$v;
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $v)) return false;
        $r = substr($v, 4).substr($v, 0, 4);
        $num = '';
        foreach (str_split($r) as $ch) $num .= ctype_alpha($ch) ? (string)(ord($ch) - 55) : $ch;
        $mod = 0;
        foreach (str_split($num, 7) as $chunk) $mod = (int)(($mod.$chunk) % 97);
        return $mod === 1;
    }

    private static function checkIds(array $x, string $who, array &$e): void
    {
        if (($x['pesel'] ?? '') !== '' && !self::validPesel((string)$x['pesel'])) $e[] = $who.': PESEL ma błędną sumę kontrolną.';
        if (($x['nip'] ?? '') !== '' && !self::validNip((string)$x['nip'])) $e[] = $who.': NIP ma błędną sumę kontrolną.';
        if (($x['krs'] ?? '') !== '' && !preg_match('/^\d{1,10}$/', str_replace(' ', '', (string)$x['krs']))) $e[] = $who.': KRS ma mieć do 10 cyfr.';
    }

    private static function checkAddress(array $x, string $who, array &$e, array &$w): void
    {
        foreach (['kraj'=>'kraj','miejscowosc'=>'miejscowość','nr_budynku'=>'numer budynku'] as $k => $label) {
            if (($x[$k] ?? '') === '') $e[] = $who.': brak pola „'.$label.'" w adresie korespondencyjnym.';
        }
        $pl = in_array(self::fold((string)($x['kraj'] ?? '')), ['polska','pl',''], true);
        if ($pl && ($x['kod'] ?? '') !== '' && !preg_match('/^\d{2}-\d{3}$/', (string)$x['kod'])) $e[] = $who.': kod pocztowy w formacie 00-000.';
        if ($pl && ($x['kod'] ?? '') === '') $e[] = $who.': brak kodu pocztowego.';
        if (($x['email'] ?? '') !== '' && !filter_var($x['email'], FILTER_VALIDATE_EMAIL)) $e[] = $who.': nieprawidłowy adres e-mail.';
        if (($x['telefon'] ?? '') === '' && ($x['email'] ?? '') === '') $w[] = $who.': brak danych kontaktowych (telefon lub e-mail).';
    }

    private static function text(?string $v): string { return preg_replace('/\s+/u', ' ', trim((string)$v)) ?? ''; }
    private static function digits(?string $v): string { return preg_replace('/\D+/', '', (string)$v) ?? ''; }
    private static function fold(?string $v): string
    {
        return strtr(mb_strtolower((string)$v, 'UTF-8'), ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ż'=>'z','ź'=>'z']);
    }
}
