<?php
namespace Duir\Services;

use Duir\Config;
use Duir\Support\Client;
use Duir\Support\Normalize;

final class KrsClient
{
    // Wynik ostatniego zapytania do Białej Listy (ustawiany w resolveKrsByNip):
    // czy wykaz VAT w ogóle ZNA ten NIP i pod jaką nazwą — potrzebne do odróżnienia
    // "JDG bez KRS" od "nieznanego identyfikatora".
    private bool $lastWhitelistKnown = false;
    private ?string $lastWhitelistName = null;

    public function __construct(private ?Client $http = null) { $this->http ??= new Client(); }

    public function fetchProfile(array $subject): array
    {
        $krs = Normalize::digits($subject['krs'] ?? '');
        if (!$krs && Normalize::digits($subject['nip'] ?? '')) {
            $nip = Normalize::digits($subject['nip']);
            try {
                $krs = $this->resolveKrsByNip($nip);
            } catch (\RuntimeException $e) {
                // Przejściowa awaria Białej Listy MF (timeout/5xx) — NIE mylić z podmiotem
                // bez numeru KRS. Zwracamy status 'error' (zgodny z logiką CheckService),
                // ale z opisowym komunikatem, że sprawdzenie należy powtórzyć.
                return ['source'=>'KRS','status'=>'error','status_label'=>'Błąd rozwiązania numeru KRS przez Białą Listę MF (przejściowy) — spróbuj ponownie później. NIP: '.$nip.'.'];
            }
        }
        if (!$krs) {
            // Rozróżnienie istotne dla auto-korekty typu: Biała Lista ZNA ten NIP,
            // ale bez numeru KRS => podmiot nie podlega KRS (JDG), a nie "brak danych".
            return [
                'source'=>'KRS','status'=>'no_identifier','status_label'=>'Brak numeru KRS do sprawdzenia.',
                'whitelist_known'=>$this->lastWhitelistKnown,'whitelist_name'=>$this->lastWhitelistName,
            ];
        }
        $base = rtrim((string)Config::get('KRS_API_BASE','https://api-krs.ms.gov.pl/api/krs'), '/');
        $actual = $this->safeGet("$base/OdpisAktualny/$krs?rejestr=P&format=json");
        $full = $this->safeGet("$base/OdpisPelny/$krs?rejestr=P&format=json");
        if (!$actual && !$full) return ['source'=>'KRS','status'=>'error','status_label'=>'Nie udało się pobrać odpisu KRS.','krs'=>$krs,'registry_url'=>'https://wyszukiwarka-krs.ms.gov.pl/podmiot/'.$krs];
        $profile = $this->parseProfile($actual ?: [], $full ?: [], $krs);
        $profile['financial_check'] = (new FinancialStatementChecker())->fromKrsProfile($profile);
        return $profile;
    }

    public function parseProfile(array $actual, array $full, string $krs): array
    {
        $raw = $actual ?: $full;
        $allText = json_encode([$actual,$full], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '';
        // Status BIEŻĄCY ustalamy WYŁĄCZNIE z odpisu aktualnego. Odpis pełny zawiera
        // całą historię wpisów — w tym rutynowe adnotacje "wykreślono" przy każdej
        // zmianie danych oraz dawno zakończone postępowania z działu 6 — więc szukanie
        // w nim gołych rdzeni ('wykresl', 'restrukturyzac') oznaczałoby niemal każdą
        // dojrzałą spółkę jako wykreśloną albo "w restrukturyzacji" (fałszywy alarm).
        // Historia z odpisu pełnego jest raportowana osobno: historical_proceedings.
        $foldActual = Normalize::fold(json_encode($actual, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '');
        $status = 'active'; $label = 'aktywny';
        foreach (['w upadlosci'=>'w upadłości','restrukturyzac'=>'w restrukturyzacji','w likwidacji'=>'w likwidacji','wykresl'=>'wykreślony'] as $needle=>$human) {
            if (str_contains($foldActual,$needle)) { $status=$needle; $label=$human; break; }
        }
        // Podmiot wykreślony z KRS nie ma odpisu aktualnego (API zwraca błąd), ale ma
        // odpis pełny — sam ten układ odpowiedzi oznacza wykreślenie.
        if ($status === 'active' && !$actual && $full) { $status = 'wykresl'; $label = 'wykreślony'; }
        $financial = $this->extractFinancialReport($raw, $allText);
        return [
            'source'=>'KRS','status'=>$status,'status_label'=>$label,'legal_name'=>$this->findValue($raw, ['nazwa','firma','nazwaPodmiotu']),
            'krs'=>$krs,'nip'=>$this->findValue($raw, ['nip','NIP']),'regon'=>$this->findValue($raw, ['regon','REGON']),
            'legal_form'=>$this->findValue($raw, ['formaPrawna','formaprawna']),'address'=>$this->extractAddress($raw),
            'registered_at'=>$this->firstDateNear($allText, 'dataRejestracji'), 'last_entry_at'=>$this->firstDateNear($allText, 'dataOstatniego'),
            'registry_url'=>'https://wyszukiwarka-krs.ms.gov.pl/podmiot/'.$krs,
            'proceedings'=>$this->extractProceedings($actual), 'historical_proceedings'=>$this->extractProceedings($full),
            'financial_report'=>$financial, 'raw_json'=>['actual'=>$actual,'full'=>$full],
        ];
    }

    /**
     * Rozwiązuje numer KRS na podstawie NIP przez Białą Listę MF.
     *
     * Rozróżnia dwa przypadki, które wcześniej były cicho mylone:
     *  - podmiot nie ma numeru KRS (albo brak NIP na wejściu) => zwraca '' (pusty string),
     *  - przejściowa awaria źródła (timeout/5xx/niepoprawny JSON) => rzuca \RuntimeException,
     *    aby wywołujący fetchProfile() mógł to zgłosić jako błąd do powtórzenia, a nie
     *    jako potwierdzony brak identyfikatora.
     */
    private function resolveKrsByNip(string $nip): string
    {
        if (!$nip) return '';
        $base = rtrim((string)Config::get('MF_WHITELIST_BASE','https://wl-api.mf.gov.pl/api'), '/');
        // getJson() rzuca \RuntimeException przy błędzie sieci/HTTP/JSON — celowo NIE łapiemy,
        // żeby awaria transient nie wyglądała jak brak KRS.
        $data = $this->http->getJson("$base/search/nip/$nip?date=".date('Y-m-d'));
        $subject = $data['result']['subject'] ?? null;
        $this->lastWhitelistKnown = is_array($subject) && $subject !== [];
        $this->lastWhitelistName = $this->lastWhitelistKnown ? Normalize::text((string)($subject['name'] ?? '')) : null;
        return Normalize::digits((string)($subject['krs'] ?? ''));
    }

    private function safeGet(string $url): ?array
    {
        try { return $this->http->getJson($url); } catch (\Throwable) { return null; }
    }

    private function findValue(mixed $data, array $keys): ?string
    {
        if (is_array($data)) {
            foreach ($data as $k=>$v) {
                if (in_array((string)$k, $keys, true)) {
                    if (is_scalar($v)) return Normalize::text((string)$v);
                    if (is_array($v)) {
                        // Odpis PEŁNY przechowuje pola, które mogły się zmienić w historii
                        // spółki (nazwa, adres, siedziba...), jako LISTĘ wpisów: [{pole:
                        // wartość, nrWpisuWykr, nrWpisuWprow}, ...]. Bez rozstrzygnięcia,
                        // KTÓRY wpis jest ostatni, naiwna rekurencja brała PIERWSZY element
                        // tablicy — czyli NAJSTARSZĄ wartość historyczną, nie ostatnią przed
                        // wykreśleniem. Błąd znaleziony na produkcji 2026-08-11: „ALFA ENERGIA"
                        // (pierwotna nazwa, wpis #1) pokazywane mimo zmiany nazwy na „BETA GRUPA
                        // WARSZAWA" (wpis #6) na lata przed wykreśleniem (wpis #14).
                        $latest = $this->mostRecentWpisEntry($v);
                        if ($latest !== null) {
                            $found = $this->findValue($latest, $keys);
                            if ($found !== null && $found !== '') return $found;
                        }
                    }
                }
                $found = $this->findValue($v, $keys); if ($found) return $found;
            }
        }
        return null;
    }

    // Z listy wpisów [{...,nrWpisuWprow}, ...] wybiera ten o NAJWYŻSZYM numerze
    // wpisu wprowadzającego — czyli najpóźniej wprowadzoną (najbardziej aktualną)
    // wersję pola, niezależnie od tego, czy została już wykreślona kolejnym wpisem.
    private function mostRecentWpisEntry(array $entries): ?array
    {
        $best = null; $bestNr = -1;
        foreach ($entries as $entry) {
            if (!is_array($entry) || !isset($entry['nrWpisuWprow'])) continue;
            $nr = (int)$entry['nrWpisuWprow'];
            if ($nr > $bestNr) { $bestNr = $nr; $best = $entry; }
        }
        return $best;
    }

    // Adres to KILKA pól złożonych w jeden tekst (ulica/nrDomu/kodPocztowy/miejscowość),
    // nie jedna wartość pod jedną nazwą klucza — findValue() z zasady nie mógł tego
    // rozwiązać (szuka DOKŁADNIE jednego scalar-a). Dlatego adres był ZAWSZE pusty,
    // nawet dla odpisu AKTUALNEGO, gdzie 'adres' jest zwykłym płaskim obiektem (bez
    // historii). Priorytet: 'adres' (pełny adres pocztowy) przed 'siedziba' (tylko
    // miejscowość/powiat/województwo, bez ulicy) — dwa ODDZIELNE przebiegi, bo jedno
    // wyszukiwanie po obu kluczach naraz trafiałoby na to, co w JSON-ie występuje
    // pierwsze (przez przypadek 'siedziba'), nie na bardziej treściwe 'adres'.
    private function extractAddress(array $data): ?string
    {
        $node = $this->findAddressNode($data, 'adres') ?? $this->findAddressNode($data, 'siedziba');
        if ($node === null) return null;
        $ulica = trim((string)($node['ulica'] ?? ''));
        $numer = trim((string)($node['nrDomu'] ?? ''));
        $lokal = trim((string)($node['nrLokalu'] ?? ''));
        if ($lokal !== '' && $numer !== '') $numer .= '/'.$lokal;
        $kod = trim((string)($node['kodPocztowy'] ?? ''));
        $miasto = trim((string)($node['miejscowosc'] ?? ($node['poczta'] ?? '')));
        if ($ulica === '' && $numer === '' && $miasto === '') {
            // Węzeł 'siedziba' bez ulicy — zostaje miejscowość/powiat/województwo.
            $powiat = trim((string)($node['powiat'] ?? ''));
            $woj = trim((string)($node['wojewodztwo'] ?? ''));
            $m = trim((string)($node['miejscowosc'] ?? ''));
            $parts = array_filter([$m, $powiat !== '' ? 'pow. '.$powiat : '', $woj]);
            return $parts ? implode(', ', $parts) : null;
        }
        $linia1 = trim($numer !== '' ? trim($ulica.' '.$numer) : $ulica);
        $linia2 = trim(trim($kod.' '.$miasto));
        $parts = array_filter([$linia1, $linia2]);
        return $parts ? implode(', ', $parts) : null;
    }

    // Szuka węzła o podanej nazwie klucza, który wygląda jak obiekt adresu (ma pole
    // 'ulica' albo 'miejscowosc'/'powiat'/'wojewodztwo'). Jeśli trafi na LISTĘ
    // historycznych wpisów (odpis pełny), bierze NAJNOWSZY tak jak przy nazwie.
    private function findAddressNode(mixed $data, string $key): ?array
    {
        if (!is_array($data)) return null;
        foreach ($data as $k => $v) {
            if ((string)$k === $key && is_array($v)) {
                if ($this->isWpisEntryList($v)) {
                    $latest = $this->mostRecentWpisEntry($v);
                    if ($latest !== null) return $latest;
                } elseif (isset($v['ulica']) || isset($v['miejscowosc']) || isset($v['powiat']) || isset($v['wojewodztwo'])) {
                    return $v;
                }
            }
            $found = $this->findAddressNode($v, $key);
            if ($found !== null) return $found;
        }
        return null;
    }

    // Czy to LISTA historycznych wpisów (odpis pełny), a nie płaski obiekt (odpis
    // aktualny)? Rozstrzyga obecność 'nrWpisuWprow' w pierwszym elemencie listy.
    private function isWpisEntryList(array $v): bool
    {
        return array_is_list($v) && is_array($v[0] ?? null) && isset($v[0]['nrWpisuWprow']);
    }

    // Znane sekcje działu 6 odpisu KRS i ich polskie nagłówki. Sekcje spoza mapy
    // też są raportowane (z nazwą klucza jako nagłówkiem) — struktura API bywa
    // rozszerzana i nie wolno cicho gubić nowych rodzajów postępowań.
    private const D6_SECTIONS = [
        'postepowanieUpadlosciowe' => 'Postępowanie upadłościowe',
        'postepowanieUkladowe' => 'Postępowanie układowe',
        'postepowanieRestrukturyzacyjne' => 'Postępowanie restrukturyzacyjne',
        'postepowanieNaprawcze' => 'Postępowanie naprawcze',
        'likwidacja' => 'Likwidacja',
        'rozwiazanieUniewaznienie' => 'Rozwiązanie / unieważnienie',
        'zawieszenieWznowienieDzialalnosci' => 'Zawieszenie / wznowienie działalności',
        'polaczeniePodzialPrzeksztalcenie' => 'Połączenie / podział / przekształcenie',
        'zarzadKomisaryczny' => 'Zarząd komisaryczny / przymusowy',
        'umorzenieEgzekucji' => 'Umorzenie egzekucji',
    ];

    // Dział 4 odpisu: zaległości i egzekucja — bezpośrednie sygnały niewypłacalności.
    // Dział 5: kurator — podmiot bez organów uprawnionych do reprezentacji (art. 42 k.c.).
    // Oba działy były CAŁKOWICIE ignorowane (odczytywany był wyłącznie dział 6) — błąd
    // znaleziony na produkcji 2026-08-11: kurator ustanowiony od 2017 r., zero wzmianki
    // w danych DUiR mimo aktywnego, poważnego wpisu w odpisie. Sekcje spoza map są i tu
    // raportowane pod nazwą klucza — nieznana treść nie ginie po cichu.
    private const D4_SECTIONS = [
        'zaleglosciPodatkoweCelne' => 'Zaległości podatkowe i celne',
        'informacjaOOddaleniuWnioskuOOgloszenieUpadlosci' => 'Oddalenie wniosku o ogłoszenie upadłości (brak majątku)',
        'informacjaOUmorzeniuProwadzonejEgzekucji' => 'Umorzenie egzekucji (brak majątku dłużnika)',
        'zabezpieczenieMajatkuDluznika' => 'Zabezpieczenie majątku dłużnika',
    ];

    private const D5_SECTIONS = [
        'kurator' => 'Kurator ustanowiony wobec podmiotu',
    ];

    // Etykiety pól wewnątrz sekcji (klucze API -> polskie nazwy do opisu zdarzenia),
    // wspólne dla działów 4-6.
    private const D6_FIELDS = [
        'organWydajacy' => 'organ wydający',
        'sygnatura' => 'sygnatura',
        'data' => 'data',
        'sposobProwadzeniaPostepowania' => 'sposób prowadzenia',
        'okreslenieOkolicznosci' => 'okoliczności',
        'opisPolaczeniaPodzialuPrzeksztalcenia' => 'opis',
        'opisZakonczeniaProcesuUpadlosci' => 'zakończenie',
        'informacjaOUchyleniuUkladu' => 'uchylenie układu',
        'nazwa' => 'nazwa',
        'krs' => 'KRS',
        'nip' => 'NIP',
        'pesel' => 'PESEL',
        'imiona' => 'imiona',
        'imie' => 'imię',
        'nazwisko' => 'nazwisko',
        'nazwiskoICzlon' => 'nazwisko',
        'sposobReprezentacji' => 'sposób reprezentacji',
        'podstawaPowolaniaZakresDzialania' => 'podstawa powołania i zakres działania',
        'dataPowolania' => 'data powołania',
        'dataDoKtorejMaDzialac' => 'data, do której ma działać',
    ];

    // Podmioty pełniące funkcję w postępowaniu — ich pola dostają prefiks,
    // żeby "nazwa: KANCELARIA SYNDYKÓW..." nie mieszała się z nazwą dłużnika.
    private const D6_ROLES = [
        'daneSyndyka' => 'syndyk',
        'daneNadzorcy' => 'nadzorca',
        'daneZarzadcy' => 'zarządca',
        'daneLikwidatorow' => 'likwidator',
        'daneKuratora' => 'kurator',
    ];

    private function extractProceedings(array $data): array
    {
        // Preferowana ścieżka: strukturalny odczyt działów 4, 5 i 6 odpisu — czytelne
        // "nagłówek — pole: wartość; ..." zamiast surowych ścinków JSON-a, które
        // wcześniej lądowały w opisach zdarzeń (bug prezentacji). BŁĄD naprawiony
        // 2026-08-11: dotychczas czytany był WYŁĄCZNIE dział 6 — dział 4 (zaległości,
        // egzekucja) i dział 5 (kurator) były całkowicie ignorowane, nawet gdy miały
        // treść (znaleziony na produkcji: kurator z art. 42 k.c. od 2017 r., zero
        // wzmianki w danych DUiR).
        $departments = ['dzial4' => self::D4_SECTIONS, 'dzial5' => self::D5_SECTIONS, 'dzial6' => self::D6_SECTIONS];
        $parts = [];
        $foundAnyDzial = false;
        foreach ($departments as $dzialKey => $sectionMap) {
            $dzial = $this->findNode($data, [$dzialKey, str_replace('dzial', 'dział', $dzialKey)]);
            if ($dzial === null) continue;
            $foundAnyDzial = true;
            $numer = substr($dzialKey, -1);
            foreach ($dzial as $key => $section) {
                if (!is_array($section) || !$section) continue;
                $pairs = [];
                $this->collectPairs($section, $pairs, (string)$key, '');
                if (!$pairs) continue;
                $header = $sectionMap[(string)$key] ?? ('Dział '.$numer.': '.$key);
                $body = [];
                foreach ($pairs as $label => $values) $body[] = $label.': '.implode(', ', array_keys($values));
                $parts[] = mb_substr($header.' — '.implode('; ', $body), 0, 900, 'UTF-8');
            }
        }
        if ($foundAnyDzial) {
            // Samo istnienie rozpoznanego działu jest rozstrzygające także wtedy, gdy
            // jest pusty albo zawiera wyłącznie puste kontenery. Nie wolno wtedy skanować
            // całego odpisu: nazwa klucza "dzial6" tworzyła z pustej sekcji fałszywe
            // postępowanie i wpychała surowy JSON do opisu zdarzenia.
            return array_values(array_unique($parts));
        }
        // Fallback wyłącznie dla odpowiedzi bez ŻADNEGO z rozpoznawalnych działów 4-6.
        // Przeszukujemy WARTOŚCI tekstowe, nie zserializowany JSON z nazwami pól —
        // puste klucze typu "postepowanieUpadlosciowe" nie są dowodem postępowania.
        $values = [];
        $this->collectTextValues($data, $values);
        $text = implode("\n", $values);
        $fold = Normalize::fold($text);
        if (!preg_match('/upadlos|restrukturyzac|likwidac|postepowan|umorzen|oddalen|uklad/u', $fold)) return [];
        $parts = [];
        foreach (['upadłość','restrukturyzacja','postępowanie','umorzenie','oddalenie','układ','likwidacja'] as $word) {
            $pos = mb_stripos($text, $word, 0, 'UTF-8');
            if ($pos !== false) $parts[] = mb_substr($text, max(0,$pos-350), 1500, 'UTF-8');
        }
        return array_values(array_unique(array_filter($parts ?: [$text])));
    }

    // Tekst awaryjny budujemy wyłącznie z niepustych wartości łańcuchowych. Dzięki
    // temu nazwy pól i puste tablice z kontraktu API nie udają treści postępowania.
    private function collectTextValues(mixed $node, array &$out): void
    {
        if (is_array($node)) {
            foreach ($node as $value) $this->collectTextValues($value, $out);
            return;
        }
        if (!is_string($node)) return;
        $value = Normalize::text($node);
        if ($value !== '') $out[] = $value;
    }

    // Zwraca pierwszy węzeł-tablicę o jednym z podanych kluczy (przeszukiwanie w głąb).
    private function findNode(mixed $data, array $keys): ?array
    {
        if (!is_array($data)) return null;
        foreach ($data as $k => $v) {
            if (in_array((string)$k, $keys, true) && is_array($v)) return $v;
        }
        foreach ($data as $v) {
            $found = $this->findNode($v, $keys);
            if ($found !== null) return $found;
        }
        return null;
    }

    // Spłaszcza sekcję działu 6 do par "etykieta => wartości". Indeksy numeryczne
    // dziedziczą klucz rodzica (odpis pełny opakowuje wartości w listy wpisów),
    // pola nrWpisu* (numery porządkowe wpisów rejestrowych) są pomijane jako szum.
    private function collectPairs(mixed $node, array &$out, string $lastKey, string $role): void
    {
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                $key = is_int($k) ? $lastKey : (string)$k;
                $nextRole = is_int($k) ? $role : (self::D6_ROLES[$key] ?? $role);
                $this->collectPairs($v, $out, $key, $nextRole);
            }
            return;
        }
        if ($node === null) return;
        if (preg_match('/^nrWpisu/i', $lastKey)) return;
        $value = Normalize::text((string)$node);
        if ($value === '' || $value === '------,' || preg_match('/^[-,.\s]+$/', $value)) return;
        $label = self::D6_FIELDS[$lastKey] ?? $lastKey;
        if ($role !== '') $label = $role.' — '.$label;
        $out[$label][$value] = true;
    }

    private function extractFinancialReport(array $raw, string $text): array
    {
        // Daty sprawozdań bierzemy WYŁĄCZNIE z jawnie nazwanych pól odpisu.
        // Wcześniejszy fallback ("weź najświeższą datę z całego JSON-a jako datę
        // złożenia, ostatni 31 grudnia jako koniec okresu") fabrykował daty:
        // najnowsza data w odpisie to zwykle data ostatniego wpisu rejestrowego,
        // więc niemal każda spółka wychodziła "po terminie" (fałszywy alarm).
        // Gdy pól nie ma, zwracamy null-e — FinancialStatementChecker odpowie
        // wtedy statusem 'unknown' i żaden alert nie powstanie.
        $periodTo = $this->findDateByKeys($raw, ['okresDo','dataDo','dataKoncowa','dzienBilansowy','koniecOkresu']);
        $periodFrom = $this->findDateByKeys($raw, ['okresOd','dataOd','dataPoczatkowa','poczatekOkresu']);
        $submitted = $this->findDateByKeys($raw, ['dataZlozenia','dataZłożenia','dataWplywu','dataWpływu','dataPrzeslania','dataPrzesłania']);
        $firstEnd = $this->findDateByKeys($raw, ['koniecPierwszegoRokuObrotowego','dzienKonczacyPierwszyRokObrotowy']);
        return ['period_from'=>$periodFrom, 'period_to'=>$periodTo, 'submitted_at'=>$submitted, 'first_financial_year_end'=>$firstEnd, 'source_dates_count'=>substr_count($text,'data')];
    }

    private function findDateByKeys(mixed $data, array $keys): ?string
    {
        if (is_array($data)) {
            foreach ($data as $k=>$v) {
                if (in_array((string)$k, $keys, true) && is_scalar($v)) {
                    $date = Normalize::dateOrNull((string)$v); if ($date) return $date;
                }
                $found = $this->findDateByKeys($v, $keys); if ($found) return $found;
            }
        }
        return null;
    }

    private function firstDateNear(string $text, string $needle): ?string
    {
        $pos = stripos($text, $needle); if ($pos === false) return null;
        return Normalize::dateOrNull(substr($text, $pos, 120));
    }
}
