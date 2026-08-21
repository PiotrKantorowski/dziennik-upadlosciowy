<?php
namespace Duir\Services;

use Duir\Repository;

final class CheckService
{
    public function __construct(private Repository $repo, private KrsClient $krs, private RiskAnalyzer $risk) {}

    public function checkSubject(array $subject): void
    {
        $id = (int)$subject['id'];
        $this->repo->purgeLegacyKrsJsonEvents($id);
        // Osoby fizyczne (z działalnością i bez) NIE podlegają KRS — sprawdzanie
        // ich w KRS kończyło się fałszywym błędem "Brak numeru KRS". Dla nich
        // rejestr statusu to CEIDG (potwierdzenie: aktywna/zawieszona/wykreślona).
        $type = (string)($subject['type'] ?? 'company');
        if (in_array($type, ['business_person','natural_person'], true)) {
            try {
                $ceidg = (new CeidgClient())->confirm($subject);
                $status = (string)($ceidg['status'] ?? 'error');
                if ($status === 'success') {
                    $this->repo->addCheck($id, 'CEIDG', 'success', $ceidg['label'] ?? null, $ceidg);
                    foreach ($this->risk->ceidgEventsFromProfile($ceidg) as $event) $this->repo->addEvent($id, $event);
                    // Pełna firma z CEIDG (i REGON) do pustych pól/aliasów — PRZED zleceniem
                    // zadań, żeby KRZ/MSiG szukały po pełnej nazwie, nie samym imieniu.
                    $this->repo->applyCeidgProfileToSubject($id, $ceidg);
                    $subject = $this->repo->findSubject($id) ?: $subject;
                } elseif ($status === 'no_results') {
                    $this->repo->addCheck($id, 'CEIDG', 'no_results', $ceidg['label'] ?? null, $ceidg);
                } elseif ($status === 'skipped') {
                    // Świadome pominięcie (brak klucza/identyfikatora) to informacja, nie błąd.
                    $this->repo->addCheck($id, 'CEIDG', 'no_results', $ceidg['label'] ?? null, $ceidg);
                } else {
                    $this->repo->addCheck($id, 'CEIDG', 'error', $ceidg['label'] ?? 'Błąd CEIDG.', $ceidg);
                }
            } catch (\Throwable $e) { $this->repo->addCheck($id, 'CEIDG', 'error', 'CEIDG: '.$e->getMessage()); }
            $this->repo->createKrzTask($subject);
            $this->repo->createMsigTask($subject);
            $this->repo->updateSubjectCheck($id, 'queued_krz');
            return;
        }
        try {
            $profile = $this->krs->fetchProfile($subject);
            if (($profile['status'] ?? '') === 'no_identifier') {
                // Biała Lista nie zna numeru KRS dla tego NIP — podmiot najpewniej
                // NIE podlega KRS (typowo: JDG błędnie oznaczona jako spółka).
                // Potwierdzamy w CEIDG i automatycznie korygujemy typ — od typu
                // zależy zakładka wyszukiwania w KRZ (osoby fizyczne mają własną).
                try { $ceidg = (new CeidgClient())->confirm($subject); }
                catch (\Throwable $e) { $ceidg = ['status'=>'error','label'=>'CEIDG: '.$e->getMessage()]; }
                if (($ceidg['status'] ?? '') === 'success') {
                    $this->repo->updateSubjectType($id, 'business_person');
                    $this->repo->addCheck($id, 'KRS', 'no_results', 'Podmiot nie figuruje w KRS — potwierdzono wpis w CEIDG; typ przełączono na „osoba fizyczna prowadząca działalność".');
                    $this->repo->addCheck($id, 'CEIDG', 'success', $ceidg['label'] ?? null, $ceidg);
                    foreach ($this->risk->ceidgEventsFromProfile($ceidg) as $event) $this->repo->addEvent($id, $event);
                    $this->repo->applyCeidgProfileToSubject($id, $ceidg);
                    $subject = $this->repo->findSubject($id) ?: $subject;
                } elseif (!empty($profile['whitelist_known'])) {
                    // Autorytatywnie i BEZ klucza CEIDG: Biała Lista ZNA ten NIP,
                    // ale nie ma dla niego numeru KRS => podmiot nie podlega KRS
                    // (JDG). Korygujemy typ, żeby KRZ szukał w zakładce osób.
                    $this->repo->updateSubjectType($id, 'business_person');
                    $this->repo->addCheck($id, 'KRS', 'no_results', 'Biała Lista zna ten NIP bez numeru KRS — podmiot nie podlega KRS; typ przełączono na „osoba fizyczna prowadząca działalność".'
                        .(!empty($profile['whitelist_name']) ? ' Nazwa w wykazie VAT: '.$profile['whitelist_name'].'.' : ''));
                    $subject = $this->repo->findSubject($id) ?: $subject;
                } else {
                    $hint = in_array(($ceidg['status'] ?? ''), ['skipped','no_results'], true) ? ' '.(string)($ceidg['label'] ?? '') : '';
                    $this->repo->addCheck($id, 'KRS', 'no_results', 'Brak numeru KRS dla podanych identyfikatorów — jeśli to osoba fizyczna, ustaw typ „osoba fizyczna prowadząca działalność" (Edytuj).'.$hint);
                }
            } else {
                $this->repo->addCheck($id, 'KRS', ($profile['status'] ?? '') === 'error' ? 'error' : 'success', $profile['status_label'] ?? null, $profile);
                if (isset($profile['financial_check']) && is_array($profile['financial_check'])) $this->repo->saveFinancialCheck($id, $profile['financial_check']);
                foreach ($this->risk->krsEventsFromProfile($profile) as $event) $this->repo->addEvent($id, $event);
                // Odpis KRS zna numer KRS (rozwiązany z NIP przez Białą Listę), REGON
                // i pełną nazwę rejestrową — zapisujemy je do podmiotu PRZED zleceniem
                // KRZ/MSiG, żeby te zadania szukały po twardym KRS, a nie po NIP czy
                // skróconej nazwie (MSiG indeksuje ogłoszenia po nazwie i KRS, nie NIP).
                if (($profile['status'] ?? '') !== 'error') {
                    if ($this->repo->applyKrsProfileToSubject($id, $profile)) {
                        $subject = $this->repo->findSubject($id) ?: $subject;
                    }
                }
            }
        } catch (\Throwable $e) { $this->repo->addCheck($id, 'KRS', 'error', $e->getMessage()); }

        // MSiG jest sprawdzany WYŁĄCZNIE przez darmowy, oficjalny portal
        // wyszukiwarka-msig.ms.gov.pl za pośrednictwem wtyczki Chrome — patrz
        // createMsigTask() niżej. Rozwiązanie świadomie nie korzysta z żadnego
        // płatnego API iMSiG/MGBI.
        $this->repo->createKrzTask($subject);
        $this->repo->createMsigTask($subject);
        $this->repo->updateSubjectCheck($id, 'queued_krz');
    }

    // Komunikat statusu źródła NAZYWA rzecz po imieniu zamiast podawać samą liczbę:
    // „MSiG — 7 ogłoszeń: plan podziału; lista wierzytelności; …". Prawnik od razu
    // widzi, CO wykryto, bez otwierania karty. $forms = [l. poj., 2–4, 5+].
    private static function captureSummary(string $src, int $count, array $forms, array $kinds): string
    {
        $noun = self::plural($count, $forms[0], $forms[1], $forms[2]);
        $msg = $src.' — '.$count.' '.$noun;
        $kinds = array_values(array_filter(array_map('trim', $kinds)));
        if ($kinds) {
            $shown = array_slice($kinds, 0, 5);
            $more = count($kinds) - count($shown);
            $msg .= ': '.implode('; ', $shown).($more > 0 ? ' (+'.$more.')' : '');
        }
        return mb_substr($msg, 0, 400, 'UTF-8').'.';
    }

    // Polska liczba mnoga: 1 → $one; 2–4 (bez 12–14) → $few; reszta → $many.
    private static function plural(int $n, string $one, string $few, string $many): string
    {
        $n = abs($n);
        if ($n === 1) return $one;
        $mod10 = $n % 10; $mod100 = $n % 100;
        if ($mod10 >= 2 && $mod10 <= 4 && !($mod100 >= 12 && $mod100 <= 14)) return $few;
        return $many;
    }

    public function ingestKrz(int $subjectId, string $text, ?string $url = null, array $meta = []): array
    {
        $subject = $this->repo->findSubject($subjectId) ?: [];
        if (!$subjectId || !$subject) { return ['ok'=>false,'status'=>'error','reason'=>'no_subject','events'=>0]; }
        $taskId = (int)($meta['task_id'] ?? 0) ?: null;
        $claimToken = trim((string)($meta['claim_token'] ?? $meta['claimToken'] ?? ''));
        $taskValid = $taskId !== null && $this->repo->taskClaimIsValid('KRZ', $taskId, $subjectId, $claimToken);
        $hasTaskContext = $taskId !== null || $claimToken !== '';
        if ($hasTaskContext && !$taskValid) {
            // Nie oznaczamy żadnego zadania błędem: przesłany task_id należy do
            // innego podmiotu/komputera (albo jest już zamknięty), więc nie wolno
            // go dotykać. Sam task_id nie jest poświadczeniem dzierżawy.
            return ['ok'=>false,'status'=>'error','reason'=>'invalid_task_claim','events'=>0];
        }

        // Nowy kontrakt: tablica pozycji (postępowania + treści obwieszczeń/postanowień),
        // każda z własną treścią i URL. Wtyczka schodzi do szczegółów obwieszczenia,
        // gdzie portal KRZ renderuje treść (w tym informacje z załączonych dokumentów).
        $items = (isset($meta['items']) && is_array($meta['items'])) ? array_values(array_filter($meta['items'], 'is_array')) : null;
        if ($items) {
            $allText = implode("\n---\n", array_map(fn($it) => (string)($it['text'] ?? ''), $items));
            $newItems = [];
            $confirmedNoResults = false;
            // Walidujemy KAŻDĄ pozycję przed zapisaniem pierwszego zdarzenia. Dzięki
            // temu jedna poprawna pozycja w agregacie nie „uwiarygodni" obcej osoby.
            foreach ($items as $it) {
                $itText = (string)($it['text'] ?? '');
                if ($itText === '') continue;
                if ($this->risk->isConfirmedNoResults($itText)) {
                    $confirmedNoResults = true;
                    continue;
                }
                if (!$this->risk->textMatchesSubject($itText, $subject)) {
                    $this->repo->markKrzError($subjectId, 'KRZ: jedna z przechwyconych pozycji dotyczy innego podmiotu — odrzucono cały wynik.', ['meta'=>$meta,'sample'=>mb_substr($itText,0,600)], $taskId);
                    return ['ok'=>false,'status'=>'error','reason'=>'item_not_matching_subject','events'=>0];
                }
                $newItems[] = $it;
            }
            $count = 0; $kinds = [];
            foreach ($newItems as $it) {
                $itText = (string)($it['text'] ?? '');
                $itUrl = $it['url'] ?? $url;
                foreach ($this->risk->krzEventsFromText($itText, $subject, $itUrl) as $event) {
                    $this->repo->addEvent($subjectId, $event);
                    $kind = trim(preg_replace('/^KRZ:\s*/u', '', (string)($event['title'] ?? '')) ?? '');
                    if ($kind !== '' && !in_array($kind, $kinds, true)) $kinds[] = $kind;
                    $count++;
                }
            }
            if ($count) {
                $this->repo->markKrzTaskDone($subjectId, self::captureSummary('KRZ', $count, ['postępowanie','postępowania','postępowań'], $kinds), ['meta'=>['task_id'=>$taskId,'items'=>count($items)],'events'=>$count], $taskId);
                return ['ok'=>true,'status'=>'success','events'=>$count];
            }
            if ($confirmedNoResults && !$newItems) {
                // Nawet poprawna dzierżawa nie dowodzi, że karta wyszukała właściwe
                // kryterium. Wtyczka dołącza wartość pola wyszukiwania do tekstu.
                if (!$this->risk->textMatchesSubject($allText."\n".$text, $subject)) return ['ok'=>false,'status'=>'error','reason'=>'no_results_not_bound_to_subject','events'=>0];
                $this->repo->markKrzNoResults($subjectId, ['meta'=>['task_id'=>$taskId]], $taskId);
                return ['ok'=>true,'status'=>'no_results','events'=>0];
            }
            $this->repo->markKrzError($subjectId, 'KRZ: przechwycone pozycje nie zawierają potwierdzonego postępowania.', ['meta'=>['task_id'=>$taskId,'items'=>count($items)]], $taskId);
            return ['ok'=>false,'status'=>'error','reason'=>'empty_or_not_announcement','events'=>0];
        }

        // Strona „brak wyników" z natury nie zawiera danych podmiotu, dlatego jej
        // bezpiecznym kontekstem jest aktywne zadanie albo ręczny zrzut zawierający
        // identyfikator/nazwę z pola wyszukiwania.
        if ($this->risk->isConfirmedNoResults($text)) {
            if (!$this->risk->textMatchesSubject($text, $subject)) return ['ok'=>false,'status'=>'error','reason'=>'no_results_not_bound_to_subject','events'=>0];
            $this->repo->markKrzNoResults($subjectId, ['meta'=>$meta], $taskId);
            return ['ok'=>true,'status'=>'no_results','events'=>0];
        }
        // trusted_match jest wyłącznie informacją diagnostyczną wtyczki. Nigdy nie
        // zastępuje serwerowego dopasowania treści do monitorowanego podmiotu.
        if (!$this->risk->textMatchesSubject($text, $subject)) {
            $this->repo->markKrzError($subjectId, 'KRZ: przechwycony wynik nie pasuje do monitorowanego podmiotu.', ['meta'=>$meta,'sample'=>mb_substr($text,0,600)], $taskId);
            return ['ok'=>false,'status'=>'error','reason'=>'empty_or_not_matching_subject','events'=>0];
        }
        $events = $this->risk->krzEventsFromText($text, $subject, $url);
        foreach ($events as $event) $this->repo->addEvent($subjectId, $event);
        if ($events) {
            $this->repo->markKrzTaskDone($subjectId, 'KRZ: przechwycono i przeanalizowano dane.', ['meta'=>$meta,'events'=>count($events)], $taskId);
            return ['ok'=>true,'status'=>'success','events'=>count($events)];
        }
        $this->repo->markKrzError($subjectId, 'KRZ: przechwycony tekst nie zawiera potwierdzonego wyniku ani postępowania.', ['meta'=>$meta,'sample'=>mb_substr($text,0,600)], $taskId);
        return ['ok'=>false,'status'=>'error','reason'=>'empty_or_not_announcement','events'=>0];
    }

    public function ingestMsig(int $subjectId, string $text, ?string $url = null, array $meta = []): array
    {
        $subject = $this->repo->findSubject($subjectId) ?: [];
        if (!$subjectId || !$subject) { return ['ok'=>false,'status'=>'error','reason'=>'no_subject','events'=>0]; }
        $taskId = (int)($meta['task_id'] ?? 0) ?: null;
        $claimToken = trim((string)($meta['claim_token'] ?? $meta['claimToken'] ?? ''));
        $taskValid = $taskId !== null && $this->repo->taskClaimIsValid('MSIG', $taskId, $subjectId, $claimToken);
        $hasTaskContext = $taskId !== null || $claimToken !== '';
        if ($hasTaskContext && !$taskValid) {
            return ['ok'=>false,'status'=>'error','reason'=>'invalid_task_claim','events'=>0];
        }

        // Nowy kontrakt: tablica ogłoszeń. Wtyczka otwiera SZCZEGÓŁY każdego
        // dopasowanego ogłoszenia MSiG i przekazuje pełną treść (nie tylko wiersz
        // listy) razem z sygnaturą, datą publikacji i adresem szczegółów.
        $items = (isset($meta['items']) && is_array($meta['items'])) ? array_values(array_filter($meta['items'], 'is_array')) : null;
        if ($items) {
            $allText = implode("\n---\n", array_map(fn($it) => (string)($it['text'] ?? ''), $items));
            $newItems = [];
            $known = 0;
            $confirmedNoResults = false;
            foreach ($items as $it) {
                // Znacznik już znanego ogłoszenia (wtyczka NIE otwierała szczegółów,
                // żeby oszczędzić czas): liczymy do domknięcia zadania, ale NIE
                // dotykamy istniejącego zdarzenia (żeby nie nadpisać bogatej treści).
                if (!empty($it['known'])) { $known++; continue; }
                $itText = (string)($it['text'] ?? '');
                if ($itText === '') continue;
                if ($this->risk->isConfirmedNoResults($itText)) {
                    $confirmedNoResults = true;
                    continue;
                }
                if (!$this->risk->textMatchesSubject($itText, $subject)) {
                    $this->repo->markMsigError($subjectId, 'MSiG: jedno z przechwyconych ogłoszeń dotyczy innego podmiotu — odrzucono cały wynik.', ['meta'=>$meta,'sample'=>mb_substr($itText,0,600)], $taskId);
                    return ['ok'=>false,'status'=>'error','reason'=>'item_not_matching_subject','events'=>0];
                }
                $newItems[] = $it;
            }
            // Same znaczniki „known" nie mają treści do dopasowania, więc wolno je
            // uznać wyłącznie w kontekście poprawnego zadania z serwera.
            if ($known && !$taskValid) {
                return ['ok'=>false,'status'=>'error','reason'=>'missing_valid_task_for_known_items','events'=>0];
            }
            $count = 0; $kinds = [];
            foreach ($newItems as $it) {
                $itText = (string)($it['text'] ?? '');
                $detail = [
                    'title' => (string)($it['title'] ?? '') ?: 'MSiG: ogłoszenie (wtyczka Chrome)',
                    'text' => $itText,
                    'signature' => $it['signature'] ?? null,
                    'publication_date' => $it['publication_date'] ?? null,
                    'url' => $it['url'] ?? $url,
                ];
                $event = $this->risk->msigEventFromDetail($detail, $subject);
                $this->repo->addEvent($subjectId, $event);
                $kind = trim(preg_replace('/^MSiG:\s*/u', '', (string)($event['title'] ?? '')) ?? '');
                if ($kind !== '' && !in_array($kind, $kinds, true)) $kinds[] = $kind;
                $count++;
            }
            if ($count || $known) {
                $msg = $count > 0
                    ? rtrim(self::captureSummary('MSiG', $count, ['ogłoszenie','ogłoszenia','ogłoszeń'], $kinds), '.').($known ? ' (+'.$known.' już znanych).' : '.')
                    : 'MSiG — brak nowych ogłoszeń ('.$known.' już znanych).';
                $this->repo->markMsigTaskDone($subjectId, $msg, ['meta'=>['task_id'=>$taskId,'items'=>count($items),'new'=>$count,'known'=>$known]], $taskId);
                return ['ok'=>true,'status'=>'success','events'=>$count];
            }
            if ($confirmedNoResults && !$newItems) {
                if (!$this->risk->textMatchesSubject($allText."\n".$text, $subject)) return ['ok'=>false,'status'=>'error','reason'=>'no_results_not_bound_to_subject','events'=>0];
                $this->repo->markMsigNoResults($subjectId, ['meta'=>['task_id'=>$taskId]], $taskId);
                return ['ok'=>true,'status'=>'no_results','events'=>0];
            }
            $this->repo->markMsigError($subjectId, 'MSiG: przechwycone ogłoszenia nie zawierają czytelnej treści.', ['meta'=>['task_id'=>$taskId,'items'=>count($items)]], $taskId);
            return ['ok'=>false,'status'=>'error','reason'=>'empty_or_not_announcement','events'=>0];
        }

        if ($this->risk->isConfirmedNoResults($text)) {
            if (!$this->risk->textMatchesSubject($text, $subject)) return ['ok'=>false,'status'=>'error','reason'=>'no_results_not_bound_to_subject','events'=>0];
            $this->repo->markMsigNoResults($subjectId, ['meta'=>$meta], $taskId);
            return ['ok'=>true,'status'=>'no_results','events'=>0];
        }
        if (!$this->risk->textMatchesSubject($text, $subject)) {
            $this->repo->markMsigError($subjectId, 'MSiG: przechwycony wynik nie pasuje do monitorowanego podmiotu.', ['meta'=>$meta,'sample'=>mb_substr($text,0,600)], $taskId);
            return ['ok'=>false,'status'=>'error','reason'=>'empty_or_not_matching_subject','events'=>0];
        }
        $detail = ['title'=>'MSiG: ogłoszenie (wtyczka Chrome)', 'text'=>$text, 'url'=>$url];
        $event = $this->risk->msigEventFromDetail($detail, $subject);
        $this->repo->addEvent($subjectId, $event);
        $this->repo->markMsigTaskDone($subjectId, 'MSiG: przechwycono i przeanalizowano dane.', ['meta'=>$meta], $taskId);
        return ['ok'=>true,'status'=>'success','events'=>1];
    }

    // Czy przekazany tekst strony potwierdza brak wyników? Używane przez kontrolery
    // API jako siatka bezpieczeństwa: nawet gdy wtyczka zgłosi błąd, serwer sprawdza
    // jej pageText i zamienia błąd na czysty "brak wyników", jeśli komunikat tam jest.
    public function textConfirmsNoResults(string $text): bool
    {
        return $text !== '' && $this->risk->isConfirmedNoResults($text);
    }

    /** Komunikat „brak wyników" jest ważny tylko razem z kryterium danego podmiotu. */
    public function textConfirmsNoResultsForSubject(string $text, int $subjectId): bool
    {
        if ($text === '' || !$this->risk->isConfirmedNoResults($text)) return false;
        $subject = $this->repo->findSubject($subjectId);
        return $subject !== null && $this->risk->textMatchesSubject($text, $subject);
    }

    public function checkAllMonitored(?int $limit = null): int
    {
        $count = 0;
        // Dla CRON-a używamy rotacji po najdawniej sprawdzonych podmiotach.
        // Poprzednia wersja przy limicie zawsze brała pierwszy alfabetyczny pakiet,
        // więc dalsze podmioty mogły nigdy nie wejść do automatycznego sprawdzenia.
        $subjects = $limit === null ? $this->repo->subjects() : $this->repo->subjectsDueForCheck($limit);
        foreach ($subjects as $s) {
            if ((int)$s['monitored'] !== 1) continue;
            if ($limit !== null && $count >= $limit) break;
            $this->checkSubject($s);
            $count++;
        }
        return $count;
    }
}
