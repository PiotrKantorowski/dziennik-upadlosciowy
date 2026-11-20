<?php
namespace Duir\KrzPisma;

/**
 * Terminy w toku postępowania: dokument KRZ (kod wzoru) → czynność, pismo, termin.
 * Liczenie: art. 111–112 k.c. (dzień zdarzenia się nie wlicza; tydzień = 7 dni,
 * koniec w dniu o tej samej nazwie) i art. 115 k.c. (sobota albo dzień ustawowo
 * wolny → następny dzień roboczy). „pewne" = false: początek biegu albo formularz
 * wymaga potwierdzenia (pytania z analizy „pisma w toku", pkt 6).
 */
final class KrzTerminy
{
    private const UPADLOSC = ['dni' => 30, 'od' => 'obwieszczenie', 'pismo' => '30048', 'czynnosc' => 'Zgłoszenie wierzytelności',
        'podstawa' => 'art. 51 ust. 1 pkt 4 p.u.', 'pewne' => true,
        'uwaga' => 'Data ogłoszenia upadłości kończy naliczanie odsetek z masy (art. 92 ust. 1 p.u.). Zgłoszenie po terminie: zryczałtowane koszty (art. 235 p.u.).'];
    private const KONSUMENCKA = ['pewne' => false, 'uwaga' => 'Upadłość konsumencka — sprawdź termin wskazany w postanowieniu.'];
    private const ZAZALENIE_LISTA = ['dni' => 7, 'od' => 'doręczenie', 'pismo' => '30052', 'czynnosc' => 'Zażalenie na postanowienie w przedmiocie sprzeciwu',
        'podstawa' => 'art. 259 ust. 2 i art. 224 p.u.', 'pewne' => false, 'uwaga' => 'Początek biegu (doręczenie / zamieszczenie / obwieszczenie, art. 224 ust. 1–3 p.u.) do potwierdzenia — pytanie 4.'];
    private const ZAZALENIE_SPIS = ['dni' => 7, 'od' => 'doręczenie', 'pismo' => '20054', 'czynnosc' => 'Zażalenie na postanowienie w przedmiocie sprzeciwu do spisu',
        'podstawa' => 'p.r. — do potwierdzenia', 'pewne' => false, 'uwaga' => 'Termin i początek biegu w postępowaniu restrukturyzacyjnym do potwierdzenia.'];
    private const SPRZECIW_SPIS = ['dni' => 14, 'od' => 'obwieszczenie', 'pismo' => '20051', 'czynnosc' => 'Sprzeciw do spisu wierzytelności (umieszczenie 20051 / pominięcie 20052)',
        'podstawa' => 'art. 91 ust. 1–2 p.r.', 'pewne' => true, 'uwaga' => 'Dotyczy postępowania układowego i sanacyjnego. Dowody wyłącznie z dokumentu lub opinii biegłego (art. 93 ust. 1 p.r.).'];

    public static function rule(?string $kod): ?array
    {
        $r = match ($kod) {
            '11176OB' => ['dni' => 14, 'od' => 'obwieszczenie', 'pismo' => '30050', 'czynnosc' => 'Sprzeciw do listy wierzytelności',
                'podstawa' => 'art. 256 ust. 1 p.u.', 'pewne' => true, 'uwaga' => 'Sprzeciw tylko na twierdzeniach ze zgłoszenia (art. 258 ust. 1 p.u.). Opłata: 1/5 opłaty stosunkowej (art. 76b u.k.s.c.).'],
            '50007OB' => ['dni' => 14, 'od' => 'obwieszczenie', 'pismo' => '30050', 'czynnosc' => 'Sprzeciw do uzupełnienia listy wierzytelności',
                'podstawa' => 'art. 256 p.u. — do potwierdzenia dla uzupełnienia listy', 'pewne' => false, 'uwaga' => 'Podstawa terminu dla uzupełnienia listy do potwierdzenia.'],
            '11172ZA' => ['dni' => 7, 'od' => 'doręczenie', 'pismo' => '30048', 'czynnosc' => 'Uzupełnienie braków zgłoszenia (korekta wysłanego pisma)',
                'podstawa' => 'art. 241 p.u. w zw. z art. 130 § 1 k.p.c.', 'pewne' => false,
                'uwaga' => 'Rygor: zwrot zgłoszenia. Początek biegu: doręczenie czy zamieszczenie pouczenia (art. 224 ust. 5 p.u.) — pytanie 5.'],
            '11171ZA' => ['dni' => 7, 'od' => 'doręczenie', 'pismo' => null, 'czynnosc' => 'Skarga na zarządzenie syndyka o zwrocie zgłoszenia (wnosi się do syndyka)',
                'podstawa' => 'art. 242a ust. 4 p.u.', 'pewne' => true, 'uwaga' => 'Formularz KRZ dla skargi do ustalenia — pytanie 8.'],
            '11217OB' => ['dni' => 14, 'od' => 'obwieszczenie', 'pismo' => '30115', 'czynnosc' => 'Zarzuty przeciwko planowi podziału',
                'podstawa' => 'art. 349 ust. 1 p.u.', 'pewne' => true, 'uwaga' => 'Opłata do potwierdzenia — pytanie 3.'],
            '11218OB' => ['dni' => 14, 'od' => 'obwieszczenie', 'pismo' => '30116', 'czynnosc' => 'Zarzuty przeciwko oddzielnemu planowi podziału',
                'podstawa' => 'art. 349 ust. 1 p.u.', 'pewne' => true, 'uwaga' => 'Opłata do potwierdzenia — pytanie 3.'],
            '50016OB', '10216OB' => self::SPRZECIW_SPIS,
            '11179PO', '11182PO', '11183PO', '11184PO' => self::ZAZALENIE_LISTA,
            '10141PO', '10142PO', '10143PO', '10144PO', '10145PO', '10146PO', '10147PO' => self::ZAZALENIE_SPIS,
            '11051OB', '11052OB', '11056OB', '11317OB' => self::UPADLOSC,
            '14001OB', '14009OB', '10187OB' => self::KONSUMENCKA + self::UPADLOSC,
            default => null,
        };
        return $r;
    }

    /** Koniec terminu: start + dni (art. 111–112 k.c.), przesunięty według art. 115 k.c. */
    public static function end(string $startIso, int $days): ?string
    {
        $tz = new \DateTimeZone('Europe/Warsaw');
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $startIso, $tz);
        if (!$d || $d->format('Y-m-d') !== $startIso) return null;
        $d = $d->modify('+'.$days.' days');
        while (!self::isWorkingDay($d->format('Y-m-d'))) $d = $d->modify('+1 day');
        return $d->format('Y-m-d');
    }

    /** Ile dni zostało do końca terminu (ujemne = po terminie). */
    public static function daysLeft(string $endIso, ?string $todayIso = null): int
    {
        $tz = new \DateTimeZone('Europe/Warsaw');
        $a = new \DateTimeImmutable($todayIso ?? 'today', $tz);
        $b = new \DateTimeImmutable($endIso, $tz);
        return (int)$a->diff($b)->format('%r%a');
    }

    /** Dzień nie jest sobotą, niedzielą ani świętem ustawowym (24.12 wolne od 2025 r.). */
    public static function isWorkingDay(string $date): bool
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Europe/Warsaw'));
        if (!$d || (int)$d->format('N') >= 6) return false;
        $y = (int)$d->format('Y');
        $fixed = ['01-01','01-06','05-01','05-03','08-15','11-01','11-11','12-25','12-26'];
        if ($y >= 2025) $fixed[] = '12-24';
        if (in_array($d->format('m-d'), $fixed, true)) return false;
        $easter = self::easter($y);
        return !in_array($date, [$easter->modify('+1 day')->format('Y-m-d'), $easter->modify('+60 days')->format('Y-m-d')], true);
    }

    private static function easter(int $y): \DateTimeImmutable
    {
        // Algorytm Meeusa/Jonesa/Butchera (bez rozszerzenia calendar).
        $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100; $d = intdiv($b, 4); $e = $b % 4;
        $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31); $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $month, $day), new \DateTimeZone('Europe/Warsaw'));
    }
}
