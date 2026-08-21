<?php
namespace Duir\Services;

use Duir\Support\Normalize;
use Duir\Support\SearchPlan;

final class RiskAnalyzer
{
    private const SIG_RE = '/\b(?:[A-Z]{2}\d[A-Z]|[A-Z]{2}\d{2})\/[A-Za-zĄĆĘŁŃÓŚŻŹąćęłńóśżź]{1,8}(?:-[A-Za-zĄĆĘŁŃÓŚŻŹąćęłńóśżź]{1,8})?\/\d{1,7}\/\d{2,4}\b/u';

    public function krzEventsFromText(string $text, array $subject, ?string $url = null): array
    {
        $plain = Normalize::text($text);
        $fold = Normalize::fold($plain);
        if ($this->looksLikePortalChromeOnly($fold)) return [];
        if ($this->looksLikeNoResults($fold)) return [];
        $chunks = $this->splitProceedingChunks($plain);
        if (!$chunks && preg_match('/post[eę]powan|restrukturyzac|upad[łl]o|umorzen|oddalen|zako[ńn]czon|uk[łl]ad/ui', $plain)) $chunks = [$plain];
        $events = [];
        foreach ($chunks as $chunk) {
            $event = $this->eventFromKrzChunk($chunk, $subject, $url);
            if ($event) $events[] = $event;
        }
        return $events;
    }

    public function isConfirmedNoResults(string $text): bool
    {
        return $this->looksLikeNoResults(Normalize::fold($text));
    }

    public function textMatchesSubject(string $text, array $subject): bool
    {
        // Nie zlepiamy wszystkich cyfr strony w jeden ciąg. Taki ciąg potrafił
        // „zbudować" KRS/NIP z dat, sygnatur i numerów obcego wyniku. Uznajemy
        // tylko samodzielny token o długości właściwej dla KRS/NIP/REGON/PESEL.
        $tokens = [];
        if (preg_match_all('/(?<!\d)\d[\d\s-]{7,18}\d(?!\d)/u', $text, $matches)) {
            foreach ($matches[0] as $token) {
                $digits = Normalize::digits($token);
                if (in_array(strlen($digits), [9, 10, 11, 14], true)) $tokens[$digits] = true;
            }
        }
        foreach (SearchPlan::identifiers($subject) as $id) {
            if (in_array(strlen($id), [9, 10, 11, 14], true) && isset($tokens[$id])) return true;
        }
        if (SearchPlan::nameMatches((string)($subject['name'] ?? ''), $text)) return true;
        // Pełna nazwa rejestrowa bywa zapisana jako alias, podczas gdy użytkownik
        // podał na karcie skrót. Alias jest równie dobrym potwierdzeniem tożsamości.
        foreach (preg_split('/[\r\n;]+/u', (string)($subject['aliases'] ?? '')) ?: [] as $alias) {
            if (SearchPlan::nameMatches(trim($alias), $text)) return true;
        }
        return false;
    }

    public function krsEventsFromProfile(array $profile): array
    {
        $events = [];
        if (($profile['status'] ?? '') === 'error' || ($profile['status'] ?? '') === 'no_identifier') return $events;
        $statusLabel = Normalize::fold($profile['status_label'] ?? '');
        if (str_contains($statusLabel, 'wykresl')) {
            // Wykreślenie z KRS = podmiot przestał istnieć → ryzyko KRYTYCZNE.
            $events[] = [
                'source'=>'KRS', 'event_type'=>'status_krs', 'title'=>'KRS: podmiot wykreślony z rejestru',
                'description'=>$profile['status_label'] ?? '', 'risk'=>'krytyczny',
                'risk_reason'=>'Podmiot został wykreślony z KRS — przestał istnieć jako osoba prawna. Dalsza współpraca i dochodzenie roszczeń wymagają ustalenia następcy prawnego albo wspólników/członków zarządu odpowiedzialnych za zobowiązania.',
                'source_url'=>$profile['registry_url'] ?? null, 'raw_json'=>$profile,
            ];
        } elseif (preg_match('/upadlos|restrukturyzac|likwidac/u', $statusLabel)) {
            $events[] = [
                'source'=>'KRS', 'event_type'=>'status_krs', 'title'=>'KRS: status podmiotu wymaga uwagi',
                'description'=>$profile['status_label'] ?? '', 'risk'=>'wysoki',
                'risk_reason'=>'Status KRS wskazuje na upadłość, restrukturyzację albo likwidację.',
                'source_url'=>$profile['registry_url'] ?? null, 'raw_json'=>$profile,
            ];
        }
        // Odpis pełny zawiera te same postępowania co aktualny (plus historię),
        // więc bez deduplikacji każde aktywne postępowanie pojawiałoby się dwa
        // razy: raz jako "aktualne", raz jako "historyczne". Klucz deduplikacji:
        // nagłówek sekcji (tekst przed "—") + sygnatura, jeśli występuje.
        $seen = [];
        foreach (($profile['proceedings'] ?? []) as $p) {
            $txt = is_array($p) ? json_encode($p, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : (string)$p;
            $txt = self::readableKrsDescription($txt);
            if ($txt === '') continue;
            $seen[$this->proceedingKey($txt)] = true;
            $events[] = $this->riskFromProceedingText('KRS', $txt, $profile['registry_url'] ?? null, true, $profile);
        }
        foreach (($profile['historical_proceedings'] ?? []) as $p) {
            $txt = is_array($p) ? json_encode($p, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : (string)$p;
            $txt = self::readableKrsDescription($txt);
            if ($txt === '') continue;
            if (isset($seen[$this->proceedingKey($txt)])) continue;
            $events[] = $this->riskFromProceedingText('KRS', $txt, $profile['registry_url'] ?? null, false, $profile);
        }
        if (isset($profile['financial_check']) && is_array($profile['financial_check'])) {
            $fc = $profile['financial_check'];
            if (in_array(($fc['status'] ?? ''), ['late','missing'], true)) {
                $events[] = [
                    'source'=>'KRS','event_type'=>'sprawozdanie_finansowe','title'=>'KRS: problem ze sprawozdaniem finansowym',
                    'description'=>$fc['reason'] ?? 'Brak albo opóźnienie sprawozdania finansowego.',
                    'risk'=>'średni','risk_reason'=>$fc['reason'] ?? 'Należy ręcznie sprawdzić RDF/KRS.',
                    'raw_json'=>$fc,
                ];
            }
        }
        return array_values(array_filter($events));
    }

    // Zdarzenia ryzyka z potwierdzenia CEIDG (osoby fizyczne z działalnością):
    // wykreślenie = wysokie, zawieszenie = średnie, aktywna = brak zdarzenia.
    public function ceidgEventsFromProfile(array $profile): array
    {
        $status = strtoupper((string)($profile['ceidg_status'] ?? ''));
        if (str_contains($status, 'WYKRESLON')) {
            // Osoba fizyczna: wykreślenie z CEIDG kończy DZIAŁALNOŚĆ, ale osoba nadal
            // istnieje (to nie „przestał istnieć" jak przy osobie prawnej) → WYSOKIE,
            // nie krytyczne. Odpowiada całym majątkiem, więc roszczenie bywa ściągalne.
            return [[
                'source'=>'CEIDG','event_type'=>'status_ceidg','title'=>'CEIDG: działalność wykreślona',
                'description'=>(string)($profile['label'] ?? ''),'risk'=>'wysoki',
                'risk_reason'=>'Działalność gospodarcza została wykreślona z CEIDG — kontrahent nie prowadzi już firmy; osoba fizyczna nadal istnieje i odpowiada całym majątkiem. Zweryfikuj podstawy dalszej współpracy i ściągalność roszczeń.',
                'raw_json'=>$profile,
            ]];
        }
        if (str_contains($status, 'ZAWIESZON')) {
            return [[
                'source'=>'CEIDG','event_type'=>'status_ceidg','title'=>'CEIDG: działalność zawieszona',
                'description'=>(string)($profile['label'] ?? ''),'risk'=>'średni',
                'risk_reason'=>'Zawieszenie działalności bywa sygnałem problemów płynnościowych — rozważ ograniczenie ekspozycji i weryfikację bieżących zobowiązań.',
                'raw_json'=>$profile,
            ]];
        }
        return [];
    }

    public function msigEventFromDetail(array $detail, array $subject): array
    {
        $text = self::tidyPortalText((string)($detail['text'] ?? $detail['title'] ?? ''));
        $risk = $this->riskFromText($text, self::isLegalPerson($subject));
        // Tytułem zdarzenia jest RODZAJ ogłoszenia (rozdział MSiG, np. "Ogłoszenie
        // o możliwości przeglądania planu podziału"), nie powtórzona nazwa spółki —
        // dopiero to mówi prawnikowi, CO się wydarzyło.
        $section = self::msigSectionFromText($text);
        $title = $section ? 'MSiG: '.$section : (string)($detail['title'] ?? 'MSiG: ogłoszenie dotyczące podmiotu');
        return [
            'source'=>'MSIG','event_type'=>'ogłoszenie_msig','title'=>mb_substr($title,0,180,'UTF-8'),
            'description'=>$text,'signature'=>$detail['signature'] ?? null,'publication_date'=>$detail['publication_date'] ?? null,
            'proceeding_status'=>$risk['status'],'risk'=>$risk['risk'],'risk_reason'=>$risk['reason'],'source_url'=>$detail['url'] ?? null,'raw_json'=>$detail,
        ];
    }

    /**
     * Usuwa z przechwyconego tekstu portalu elementy interfejsu (przyciski
     * "Zamknij/Pobierz/Poprzedni/Następny", samotne "x") i nadmiarowe puste linie.
     * Public static: używane też przy RENDEROWANIU raportów, żeby oczyścić
     * również zdarzenia zapisane w bazie przed wprowadzeniem tego czyszczenia.
     */
    public static function tidyPortalText(string $text): string
    {
        $junk = '/^\s*(zamknij|pobierz|drukuj|poprzedni[ae]?|nast[eę]pn[yae]+|tre[śs][ćc] og[łl]oszenia|x|szukaj|wyczy[śs][ćc])\s*$/iu';
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (preg_match($junk, $line)) continue;
            $lines[] = rtrim($line);
        }
        $out = implode("\n", $lines);
        $out = preg_replace('/\n{3,}/', "\n\n", $out) ?? $out;
        return trim($out);
    }

    /**
     * Opis postępowania KRS musi być tekstem wyekstrahowanym strukturalnie, nigdy
     * zserializowanym odpisem. To druga linia obrony dla profili utworzonych przez
     * starszy parser albo dostarczonych z innego miejsca niż KrsClient.
     */
    public static function readableKrsDescription(string $text): string
    {
        $text = trim($text);
        if ($text === '') return '';
        $first = $text[0] ?? '';
        if ($first === '{' || $first === '[') {
            json_decode($text, true);
            if (json_last_error() === JSON_ERROR_NONE) return '';
        }
        // Stary fallback wycinał fragment odpisu wokół słowa „dzial6", więc zapis
        // mógł zaczynać się w połowie dokumentu i nie być już poprawnym JSON-em.
        // Układ "nazwaPola":... wraz z nawiasami strukturalnymi rozpoznaje także
        // taki ucięty dump, bez odrzucania zwykłego, czytelnego opisu tekstowego.
        if (preg_match('/"[^"\r\n]{1,100}"\s*:/u', $text) && preg_match('/[{}\[\]]/', $text)) return '';
        return Normalize::text($text);
    }

    // Wyciąga rodzaj ogłoszenia z metadanych MSiG: ostatni człon pola
    // "Rozdział/nazwa rozdziału: III. .../4. Ogłoszenie o możliwości..." —
    // z odciętym numeracyjnym prefiksem ("4. ").
    public static function msigSectionFromText(string $text): ?string
    {
        if (!preg_match('/Rozdzia[łl]\/nazwa rozdzia[łl]u:\s*([^\n]+)/iu', $text, $m)) return null;
        $parts = array_map('trim', explode('/', $m[1]));
        $last = $parts ? end($parts) : '';
        $last = preg_replace('/^\d+\.\s*/', '', $last) ?? $last;
        return $last !== '' ? $last : null;
    }

    // Czy podmiot jest osobą PRAWNĄ (spółka/KRS)? Kluczowe dla „krytyczny": osoba
    // prawna po zakończonej upadłości/wykreśleniu PRZESTAJE ISTNIEĆ (krytyczne), a
    // osoba fizyczna/JDG nadal żyje — zakończenie działalności to dla niej „wysokie",
    // nie „krytyczne" (nie oznacza śmierci). Nieznany typ: osoba prawna, jeśli ma KRS.
    public static function isLegalPerson(array $subject): bool
    {
        $type = (string)($subject['type'] ?? '');
        if ($type === 'company') return true;
        if ($type === 'business_person' || $type === 'natural_person') return false;
        return !empty($subject['krs']);
    }

    public function riskFromText(string $text, bool $isLegalPerson = true): array
    {
        $f = Normalize::fold($text);
        // ZAKOŃCZONE postępowanie UPADŁOŚCIOWE. Dla OSOBY PRAWNEJ = podmiot najpewniej
        // przestał istnieć (likwidacja majątku / wykreślenie po upadłości) → KRYTYCZNE
        // (poza skalą). Dla OSOBY FIZYCZNEJ/JDG = działalność zakończona, ale osoba
        // nadal istnieje → WYSOKIE. Wyjątek dla obu: upadłość zakończona ZAWARCIEM/
        // WYKONANIEM układu (podmiot przetrwał) — wtedy niżej. Ta gałąź jest PRZED
        // „aktywne", bo dawniej goły rdzeń 'upadlosc' mylnie oznaczał sprawy zakończone.
        $bankruptcy = str_contains($f, 'upadlos');
        $closedSignal = str_contains($f, 'zakoncz'); // zakończenie/zakończono/zakończenia/zakończone
        $recoveredByUklad = str_contains($f, 'zatwierdzono uklad') || str_contains($f, 'wykonano uklad')
            || str_contains($f, 'uklad zostal zatwierdzony') || str_contains($f, 'uklad wykonany');
        if ($bankruptcy && $closedSignal && !$recoveredByUklad) {
            if ($isLegalPerson) {
                return ['risk'=>'krytyczny','status'=>'found_bankruptcy_closed','reason'=>'Zakończone postępowanie upadłościowe (w tym likwidacyjne) — osoba prawna najprawdopodobniej przestała istnieć. Ryzyko krytyczne: przed dalszą współpracą zweryfikuj byt prawny podmiotu i realną ściągalność roszczeń.'];
            }
            return ['risk'=>'wysoki','status'=>'found_bankruptcy_closed','reason'=>'Zakończone postępowanie upadłościowe — działalność zakończona, ale osoba fizyczna nadal istnieje i odpowiada całym majątkiem; zweryfikuj ściągalność roszczeń i podstawy dalszej współpracy.'];
        }
        // Dział 5 odpisu KRS: KURATOR ustanowiony wobec podmiotu (najczęściej art. 42 KC —
        // brak organów uprawnionych do reprezentacji). Podmiot NADAL ISTNIEJE, ale zwykły
        // obrót z nim jest utrudniony/wątpliwy — samodzielny, poważny sygnał NIEZALEŻNY od
        // upadłości/restrukturyzacji, więc sprawdzany PRZED resztą gałęzi. Znaleziony na
        // produkcji (2026-08-11): kurator od 2017 r. z powodu braku organów spółki — do tej
        // pory NIEWIDOCZNY, bo KrsClient odczytywał tylko dział 6, nigdy działu 5.
        // Nie przykrywamy dokładniejszej diagnozy, gdy „kurator" pojawia się tylko jako
        // ROLA wewnątrz aktywnego postępowania upadłościowego (rzadki przypadek w dziale 6)
        // — wtedy niżej i tak wypadnie 'wysoki'/found_active z precyzyjniejszym powodem.
        if (str_contains($f, 'kurator') && !$bankruptcy) {
            return ['risk'=>'wysoki','status'=>'kurator','reason'=>'Sąd ustanowił kuratora wobec podmiotu — najczęściej z powodu braku organów uprawnionych do jego reprezentacji. Ustal, kto i na jakich zasadach może obecnie działać w imieniu kontrahenta, oraz czy toczy się postępowanie o jego likwidację (art. 42 k.c.).'];
        }
        $closedNegative = ['z zakonczonym niepowodzeniem','niepowodzeniem','umorzen','umorzono','oddalen','oddalono','odmow','odmowiono','nie zatwierdz','niezatwierdz','bezskutecz','odrzuc','uchylenie ukladu','uchylono uklad'];
        foreach ($closedNegative as $n) if (str_contains($f,$n)) return ['risk'=>'wysoki','status'=>'found_closed_negative','reason'=>'Treść wskazuje na zakończone negatywnie albo problematyczne postępowanie; taki wpis jest istotny nawet po formalnym zakończeniu sprawy.'];
        // Najpierw jednoznaczne sygnały zakończenia — inaczej gołe rdzenie z $active ('restrukturyzac',
        // 'upadlosc') łapałyby też prawomocnie zakończone sprawy i błędnie oznaczały je jako aktywne.
        $closed = ['zakończone','zakonczone','zakończenie','zakonczenie','zatwierdzono uklad','wykonano uklad','prawomocnie zakonczono'];
        foreach ($closed as $h) if (str_contains($f, Normalize::fold($h))) return ['risk'=>'średni','status'=>'found_closed_positive_or_neutral','reason'=>'Wykryto historyczne/zakończone postępowanie. Nawet bez negatywnego rozstrzygnięcia wymaga ono krótkiej kontroli prawnika.'];
        $active = ['w toku','otwarto postepowanie','otwarcie postepowania','ogloszono upadlosc','ustanowiono syndyka','ustanowiono nadzorce','ustanowiono zarzadce','przyspieszone postepowanie ukladowe','sanacyjn','restrukturyzac','upadlosc','upadlosci'];
        foreach ($active as $h) if (str_contains($f,$h)) return ['risk'=>'wysoki','status'=>'found_active','reason'=>'Treść wskazuje na aktywne albo istotne postępowanie upadłościowe/restrukturyzacyjne.'];
        if (str_contains($f,'likwidac')) return ['risk'=>'średni','status'=>'likwidacja','reason'=>'Treść wskazuje na likwidację albo zmianę istotną dla wierzyciela.'];
        // Reorganizacje korporacyjne (połączenie, podział, przekształcenie, wydzielenie
        // majątku) to zdarzenia przenoszące majątek i zobowiązania między podmiotami —
        // dla wierzyciela nigdy nie są "niskim ryzykiem" (art. 529 i nast. KSH,
        // odpowiedzialność za zobowiązania spółki dzielonej).
        foreach (['polaczen','podzial','przeksztalcen','wydzielenie','fuzj'] as $h) {
            if (str_contains($f,$h)) return ['risk'=>'średni','status'=>'reorganizacja','reason'=>'Reorganizacja (połączenie, podział albo przekształcenie) przenosi majątek i zobowiązania między podmiotami — ustal, która spółka przejęła aktywa i kto odpowiada za istniejące długi.'];
        }
        return ['risk'=>'niski','status'=>'informacja','reason'=>'Wpis o charakterze informacyjnym — nie wskazuje na postępowanie upadłościowe, restrukturyzacyjne ani reorganizację; wystarczy krótka kontrola.'];
    }

    // Rozpoznaje RODZAJ postępowania KRZ z treści wiersza wyników. To kluczowa
    // informacja merytoryczna — „postępowanie o zatwierdzenie układu" znaczy co
    // innego niż „upadłość". Kolejność od najbardziej szczegółowego.
    private function krzProceedingKind(string $chunk): ?string
    {
        $f = Normalize::fold($chunk);
        $map = [
            'zakazie prowadzenia dzialalnosci' => 'orzeczenie o zakazie prowadzenia działalności',
            'zakaz prowadzenia dzialalnosci' => 'orzeczenie o zakazie prowadzenia działalności',
            'zatwierdzenie ukladu' => 'postępowanie o zatwierdzenie układu',
            'przyspieszone postepowanie ukladowe' => 'przyspieszone postępowanie układowe',
            'postepowanie ukladowe' => 'postępowanie układowe',
            'postepowanie sanacyjne' => 'postępowanie sanacyjne',
            'restrukturyzac' => 'postępowanie restrukturyzacyjne',
            'ogloszenie upadlosci' => 'ogłoszenie upadłości',
            'upadlosc konsumenck' => 'upadłość konsumencka',
            'postepowanie upadlosciowe' => 'postępowanie upadłościowe',
            'upadlos' => 'postępowanie upadłościowe',
            'wykonaniu planu splaty' => 'wykonanie planu spłaty',
            'umorzeniu zobowiazan' => 'umorzenie zobowiązań',
        ];
        foreach ($map as $needle => $label) if (str_contains($f, $needle)) return $label;
        return null;
    }

    private function eventFromKrzChunk(string $chunk, array $subject, ?string $url): ?array
    {
        $risk = $this->riskFromText($chunk, self::isLegalPerson($subject));
        preg_match(self::SIG_RE, $chunk, $m);
        $signature = $m[0] ?? null;
        if (!$signature && !preg_match('/post[eę]powan|restrukturyzac|upad[łl]o|umorzen|oddalen|zako[ńn]czon|uk[łl]ad/ui', $chunk)) return null;
        // Tytuł niesie RODZAJ postępowania (a nie tylko sygnaturę) — dzięki temu
        // i karta, i ocena LLM mówią WPROST, jakie to postępowanie.
        $kind = $this->krzProceedingKind($chunk);
        $title = 'KRZ: '.($kind ?: 'postępowanie').($signature ? ' ('.$signature.')' : '');
        return [
            'source'=>'KRZ','event_type'=>'postępowanie_krz','title'=>$title,'description'=>Normalize::text($chunk),'signature'=>$signature,
            'publication_date'=>$this->firstDate($chunk),'proceeding_status'=>$risk['status'],'risk'=>$risk['risk'],'risk_reason'=>$risk['reason'],
            // RODO: nie osadzamy całego $subject (PESEL/NIP/KRS/REGON) w raw_json — event ma subject_id (FK)
            // i jest łączony JOIN-em z tabelą subjects tam, gdzie dane podmiotu są potrzebne.
            'source_url'=>$url,'raw_json'=>['chunk'=>$chunk],
        ];
    }

    // Klucz deduplikacji postępowania: nagłówek sekcji działu 6 + sygnatura akt
    // (gdy jest). Celowo NIE cały tekst — odpis pełny ma zwykle więcej pól niż
    // aktualny, więc pełne teksty tego samego postępowania różnią się drobiazgami.
    private function proceedingKey(string $text): string
    {
        $header = trim(mb_substr($text, 0, mb_strpos($text, '—') !== false ? mb_strpos($text, '—') : 40, 'UTF-8'));
        preg_match('/sygnatura:\s*([^;]+)/ui', $text, $m);
        $sig = isset($m[1]) ? trim($m[1]) : '';
        if ($sig === '' && preg_match(self::SIG_RE, $text, $m2)) $sig = $m2[0];
        return Normalize::fold($header.'|'.$sig);
    }

    private function riskFromProceedingText(string $source, string $text, ?string $url, bool $current, array $raw): array
    {
        // Postępowania z KRS dotyczą osoby prawnej (podmiot ma numer KRS).
        $risk = $this->riskFromText($text, self::isLegalPerson($raw));
        if (!$current && $risk['risk'] === 'niski') {
            $risk = ['risk'=>'średni','status'=>'found_closed_positive_or_neutral','reason'=>'Historyczne postępowanie w rejestrze może mieć znaczenie dla oceny kontrahenta.'];
        }
        // Nagłówek sekcji działu 6 (tekst przed „—") niesie RODZAJ postępowania
        // (np. „Postępowanie upadłościowe"). Wstawiamy go w tytuł, żeby karta i ocena
        // LLM mówiły WPROST, co to za postępowanie, a nie tylko „aktualne/historyczne".
        $pos = mb_strpos($text, '—');
        $kind = $pos !== false ? trim(mb_substr($text, 0, $pos, 'UTF-8')) : '';
        $when = $current ? 'aktualne' : 'historyczne/zakończone';
        $title = ($kind !== '' && mb_strlen($kind) <= 70) ? "$source: $kind ($when)" : "$source: $when postępowanie";
        return [
            'source'=>$source,'event_type'=>$current?'postępowanie_aktualne':'postępowanie_historyczne','title'=>$title,
            'description'=>$text,'publication_date'=>$this->firstDate($text),'proceeding_status'=>$risk['status'],'risk'=>$risk['risk'],'risk_reason'=>$risk['reason'],'source_url'=>$url,'raw_json'=>$raw,
        ];
    }

    private function splitProceedingChunks(string $text): array
    {
        preg_match_all(self::SIG_RE, $text, $matches, PREG_OFFSET_CAPTURE);
        if (!$matches[0]) return [];
        $chunks = [];
        foreach ($matches[0] as $i => $match) {
            $start = max(0, $match[1] - 350);
            $end = isset($matches[0][$i+1]) ? $matches[0][$i+1][1] : min(strlen($text), $match[1] + 2200);
            $chunks[] = substr($text, $start, max(500, $end-$start));
        }
        return $chunks;
    }

    private function looksLikeNoResults(string $fold): bool
    {
        // "nie zostaly znalezione zadne pozycje..." — dokładne brzmienie komunikatu
        // braku wyników w module Wyszukiwanie podmiotów portalu KRZ (zweryfikowane
        // na żywym portalu 2026-07-12).
        return (bool)preg_match('/\b(brak wynikow|brak danych spelniajacych|lista jest pusta|0 wynikow|liczba podmiotow:\s*0|nie zostaly znalezione (zadne )?pozycje|nie znaleziono (zadnych )?(wynikow|danych|podmiotow|pozycji)|nie odnaleziono (zadnych )?(wynikow|danych|podmiotow))\b/u', $fold);
    }

    private function looksLikePortalChromeOnly(string $fold): bool
    {
        $bad = ['przejdz do glownej tresci','deklaracja dostepnosci','polityka cookies','zaloguj'];
        $hits = 0; foreach ($bad as $b) if (str_contains($fold,$b)) $hits++;
        $hasProceeding = preg_match('/postepowan|restrukturyzac|upadlos|sygnatur|data rejestracji|data zakonczenia/u', $fold);
        return $hits >= 3 && !$hasProceeding;
    }

    private function firstDate(string $text): ?string
    {
        if (preg_match('/\b\d{4}-\d{2}-\d{2}\b|\b\d{2}\.\d{2}\.\d{4}\b/u', $text, $m)) return Normalize::dateOrNull($m[0]);
        return null;
    }
}
