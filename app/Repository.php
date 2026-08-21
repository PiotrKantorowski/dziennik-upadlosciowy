<?php
namespace Duir;

use Duir\Support\Normalize;
use Duir\Support\SearchPlan;
use PDO;

final class Repository
{
    public function __construct(private PDO $pdo) {}
    private bool $subjectServiceModeReady = false;

    public function pdo(): PDO { return $this->pdo; }


    public function schemaReady(): bool
    {
        try {
            $st = $this->pdo->query("SHOW TABLES LIKE 'users'");
            if (!$st->fetchColumn()) return false;
            $st = $this->pdo->query("SHOW TABLES LIKE 'subjects'");
            return (bool)$st->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }

    public function installSchema(string $schemaPath): void
    {
        if (!is_file($schemaPath)) throw new \RuntimeException('Nie znaleziono pliku schema.sql.');
        $sql = file_get_contents($schemaPath);
        if ($sql === false || trim($sql) === '') throw new \RuntimeException('Plik schema.sql jest pusty.');
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($this->splitSql($sql) as $statement) {
            $statement = trim($statement);
            if ($statement !== '') $this->pdo->exec($statement);
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    private function splitSql(string $sql): array
    {
        $out = [];
        $buf = '';
        $inString = false;
        $quote = '';
        $len = strlen($sql);
        for ($i=0; $i<$len; $i++) {
            $ch = $sql[$i];
            $prev = $i > 0 ? $sql[$i-1] : '';
            if (($ch === "'" || $ch === '"') && $prev !== '\\') {
                if (!$inString) { $inString = true; $quote = $ch; }
                elseif ($quote === $ch) { $inString = false; $quote = ''; }
            }
            if ($ch === ';' && !$inString) { $out[] = $buf; $buf = ''; continue; }
            $buf .= $ch;
        }
        if (trim($buf) !== '') $out[] = $buf;
        return $out;
    }

    public function users(): array
    {
        return $this->pdo->query('SELECT id,email,name,role,active,last_login_at,created_at,updated_at FROM users ORDER BY role ASC, name ASC')->fetchAll();
    }

    public function userCount(): int
    {
        try { return (int)$this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(); }
        catch (\Throwable) { return 0; }
    }

    public function activeAdminCount(?int $excludeId = null): int
    {
        if ($excludeId !== null) {
            $st = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE role="admin" AND active=1 AND id<>?');
            $st->execute([$excludeId]);
            return (int)$st->fetchColumn();
        }
        return (int)$this->pdo->query('SELECT COUNT(*) FROM users WHERE role="admin" AND active=1')->fetchColumn();
    }

    public function findUser(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM users WHERE id=?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function findUserByEmail(string $email): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM users WHERE email=?');
        $st->execute([mb_strtolower(trim($email))]);
        return $st->fetch() ?: null;
    }

    public function createUser(array $data): int
    {
        $this->assertUserInput($data, false);
        $email = mb_strtolower(trim((string)($data['email'] ?? '')));
        if ($this->findUserByEmail($email)) throw new \InvalidArgumentException('Użytkownik o tym adresie e-mail już istnieje.');
        $name = Normalize::text($data['name'] ?? '');
        $role = (($data['role'] ?? 'user') === 'admin') ? 'admin' : 'user';
        $active = !isset($data['active']) || (string)$data['active'] === '1' ? 1 : 0;
        $hash = password_hash((string)$data['password'], PASSWORD_DEFAULT);
        $st = $this->pdo->prepare('INSERT INTO users (email,name,password_hash,role,active,created_at,updated_at) VALUES (?,?,?,?,?,NOW(),NOW())');
        $st->execute([$email, $name, $hash, $role, $active]);
        $id = (int)$this->pdo->lastInsertId();
        $this->audit('user.created', 'users', $id, ['email'=>$email,'role'=>$role]);
        return $id;
    }

    public function updateUser(int $id, array $data): void
    {
        $this->assertUserInput($data, true);
        $existingUser = $this->findUser($id);
        if (!$existingUser) throw new \InvalidArgumentException('Nie znaleziono użytkownika do aktualizacji.');
        $email = mb_strtolower(trim((string)($data['email'] ?? '')));
        $emailOwner = $this->findUserByEmail($email);
        if ($emailOwner && (int)$emailOwner['id'] !== $id) throw new \InvalidArgumentException('Użytkownik o tym adresie e-mail już istnieje.');
        $name = Normalize::text($data['name'] ?? '');
        $role = (($data['role'] ?? 'user') === 'admin') ? 'admin' : 'user';
        $active = !isset($data['active']) || (string)$data['active'] === '1' ? 1 : 0;
        if (($role !== 'admin' || $active !== 1) && $this->activeAdminCount($id) < 1) {
            throw new \InvalidArgumentException('Nie można odebrać uprawnień ani zablokować ostatniego aktywnego administratora.');
        }
        $password = (string)($data['password'] ?? '');
        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $st = $this->pdo->prepare('UPDATE users SET email=?, name=?, password_hash=?, role=?, active=?, updated_at=NOW() WHERE id=?');
            $st->execute([$email, $name, $hash, $role, $active, $id]);
        } else {
            $st = $this->pdo->prepare('UPDATE users SET email=?, name=?, role=?, active=?, updated_at=NOW() WHERE id=?');
            $st->execute([$email, $name, $role, $active, $id]);
        }
        $this->audit('user.updated', 'users', $id, ['email'=>$email,'role'=>$role,'active'=>$active]);
    }

    public function touchUserLogin(int $id): void
    {
        $st = $this->pdo->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?');
        $st->execute([$id]);
    }

    /**
     * Samodzielna zmiana hasła przez zalogowanego użytkownika (panel „Moje konto").
     * Weryfikuje OBECNE hasło, wymaga min. 12 znaków i zgodności powtórzenia.
     * Rzuca \InvalidArgumentException z czytelnym komunikatem przy błędzie.
     */
    public function changeOwnPassword(int $id, string $current, string $new, string $confirm): void
    {
        $user = $this->findUser($id);
        if (!$user) throw new \InvalidArgumentException('Nie znaleziono konta.');
        if (!password_verify($current, (string)$user['password_hash'])) throw new \InvalidArgumentException('Obecne hasło jest nieprawidłowe.');
        if (strlen($new) < 12) throw new \InvalidArgumentException('Nowe hasło musi mieć co najmniej 12 znaków.');
        if ($new !== $confirm) throw new \InvalidArgumentException('Nowe hasła nie są identyczne.');
        if (password_verify($new, (string)$user['password_hash'])) throw new \InvalidArgumentException('Nowe hasło musi różnić się od obecnego.');
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $st = $this->pdo->prepare('UPDATE users SET password_hash=?, updated_at=NOW() WHERE id=?');
        $st->execute([$hash, $id]);
        $this->audit('user.password_changed_self', 'users', $id, []);
    }

    private function assertUserInput(array $data, bool $allowEmptyPassword): void
    {
        $email = mb_strtolower(trim((string)($data['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Podaj prawidłowy adres e-mail użytkownika.');
        if (Normalize::text($data['name'] ?? '') === '') throw new \InvalidArgumentException('Nazwa użytkownika jest wymagana.');
        $password = (string)($data['password'] ?? '');
        $confirm = (string)($data['password_confirm'] ?? '');
        if ($password === '' && $allowEmptyPassword) return;
        if (strlen($password) < 12) throw new \InvalidArgumentException('Hasło musi mieć co najmniej 12 znaków.');
        if ($password !== $confirm) throw new \InvalidArgumentException('Hasła nie są identyczne.');
    }

    public function subjects(): array
    {
        $this->ensureSubjectServiceModeColumn();
        return $this->pdo->query('SELECT * FROM subjects ORDER BY monitored DESC, name ASC')->fetchAll();
    }

    public function subjectsDueForCheck(int $limit): array
    {
        $limit = max(1, min(1000, $limit));
        $st = $this->pdo->prepare('SELECT * FROM subjects WHERE monitored=1 ORDER BY CASE WHEN last_checked_at IS NULL THEN 0 ELSE 1 END, last_checked_at ASC, id ASC LIMIT :limit');
        $st->bindValue(':limit', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    public function findSubject(int $id): ?array
    {
        $this->ensureSubjectServiceModeColumn();
        $st = $this->pdo->prepare('SELECT * FROM subjects WHERE id=?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function createSubject(array $data): int
    {
        $this->ensureSubjectServiceModeColumn();
        $this->assertSubjectInput($data);
        $type = $data['type'] ?? '';
        if (!$type || $type === 'auto' || $type === 'unknown') $type = $this->guessType($data);
        $serviceMode = (string)$data['service_mode'];
        // Tryb obsługi jest jedynym źródłem prawdy dla harmonogramu: oba tryby
        // stałe są monitorowane, weryfikacja jednorazowa kończy się po pierwszym
        // sprawdzeniu wykonanym automatycznie przy utworzeniu podmiotu.
        $monitored = $serviceMode === 'one_time' ? 0 : 1;
        $st = $this->pdo->prepare('INSERT INTO subjects (name,krs,nip,regon,pesel,aliases,type,email,service_mode,monitored,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
        $st->execute([
            Normalize::text($data['name'] ?? ''), Normalize::digits($data['krs'] ?? ''), Normalize::digits($data['nip'] ?? ''),
            Normalize::digits($data['regon'] ?? ''), Normalize::digits($data['pesel'] ?? ''), Normalize::text($data['aliases'] ?? ''),
            $type, Normalize::text($data['email'] ?? ''), $serviceMode, $monitored,
        ]);
        $id = (int)$this->pdo->lastInsertId();
        $this->audit('subject.created', 'subjects', $id, ['name'=>$data['name'] ?? '', 'type'=>$type, 'service_mode'=>$serviceMode]);
        return $id;
    }

    public function updateSubject(int $id, array $data): void
    {
        $this->ensureSubjectServiceModeColumn();
        $this->assertSubjectInput($data);
        $type = $data['type'] ?? 'unknown';
        if ($type === 'auto' || $type === '') $type = $this->guessType($data);
        $serviceMode = (string)$data['service_mode'];
        $monitored = $serviceMode === 'one_time' ? 0 : 1;
        $st = $this->pdo->prepare('UPDATE subjects SET name=?,krs=?,nip=?,regon=?,pesel=?,aliases=?,type=?,email=?,service_mode=?,monitored=?,updated_at=NOW() WHERE id=?');
        $st->execute([
            Normalize::text($data['name'] ?? ''), Normalize::digits($data['krs'] ?? ''), Normalize::digits($data['nip'] ?? ''),
            Normalize::digits($data['regon'] ?? ''), Normalize::digits($data['pesel'] ?? ''), Normalize::text($data['aliases'] ?? ''),
            $type, Normalize::text($data['email'] ?? ''), $serviceMode, $monitored, $id,
        ]);
        $this->audit('subject.updated', 'subjects', $id, ['type'=>$type, 'service_mode'=>$serviceMode]);
    }

    /** Samonaprawiająca migracja dla istniejących instalacji bez ponownego setupu. */
    private function ensureSubjectServiceModeColumn(): void
    {
        if ($this->subjectServiceModeReady) return;
        $st = $this->pdo->query("SHOW COLUMNS FROM subjects LIKE 'service_mode'");
        if (!$st->fetchColumn()) {
            // NULL celowo: istniejących podmiotów nie przypisujemy automatycznie do
            // żadnej kategorii — użytkownik ma dokonać świadomego wyboru.
            $this->pdo->exec("ALTER TABLE subjects ADD COLUMN service_mode ENUM('office_monitoring','client_monitoring','one_time') NULL AFTER email");
        }
        $this->subjectServiceModeReady = true;
    }

    public function deleteSubject(int $id): void
    {
        $st = $this->pdo->prepare('DELETE FROM subjects WHERE id=?');
        $st->execute([$id]);
        $this->audit('subject.deleted', 'subjects', $id, []);
    }

    // Automatyczna korekta typu podmiotu (np. „spółka" -> „osoba fizyczna
    // prowadząca działalność", gdy Biała Lista nie zna KRS, a CEIDG potwierdza wpis).
    // Od typu zależy ZAKŁADKA wyszukiwania w KRZ — zły typ = fałszywy brak wyników.
    public function updateSubjectType(int $id, string $type): void
    {
        $st = $this->pdo->prepare('UPDATE subjects SET type=?, updated_at=NOW() WHERE id=?');
        $st->execute([$type, $id]);
        $this->audit('subject.type_autocorrected', 'subjects', $id, ['type'=>$type]);
    }

    public function updateSubjectCheck(int $id, string $status): void
    {
        $st = $this->pdo->prepare('UPDATE subjects SET last_checked_at=NOW(), last_status=?, updated_at=NOW() WHERE id=?');
        $st->execute([$status, $id]);
    }

    /**
     * Uzupełnia dane podmiotu z autorytatywnego odpisu KRS: wpisuje KRS/REGON/NIP
     * TYLKO w puste pola (nigdy nie nadpisuje wartości podanych przez użytkownika),
     * a pełną nazwę rejestrową dopisuje do aliasów, gdy różni się od nazwy w bazie.
     * Dzięki temu kolejne wyszukiwania KRZ/MSiG dostają twardy identyfikator (KRS)
     * zamiast szukać po NIP albo skróconej nazwie, których portale nie indeksują.
     * Zwraca listę faktycznie zmienionych pól.
     */
    public function applyKrsProfileToSubject(int $id, array $profile): array
    {
        $s = $this->findSubject($id);
        if (!$s) return [];
        $updates = [];
        foreach (['krs','regon','nip'] as $field) {
            $value = Normalize::digits((string)($profile[$field] ?? ''));
            if ($value !== '' && Normalize::digits((string)($s[$field] ?? '')) === '') $updates[$field] = $value;
        }
        $legal = Normalize::text((string)($profile['legal_name'] ?? ''));
        $nameChanged = false;
        if ($legal !== '' && Normalize::compactKey($legal) !== Normalize::compactKey((string)$s['name'])) {
            $aliases = Normalize::text((string)($s['aliases'] ?? ''));
            if (!str_contains(Normalize::fold($aliases), Normalize::fold($legal))) {
                $updates['aliases'] = $aliases === '' ? $legal : $aliases."\n".$legal;
                $nameChanged = true;
            }
        }
        if (!$updates) return [];
        $set = implode(', ', array_map(fn($k) => "`$k`=?", array_keys($updates)));
        $st = $this->pdo->prepare("UPDATE subjects SET $set, updated_at=NOW() WHERE id=?");
        $st->execute([...array_values($updates), $id]);
        $this->audit('subject.enriched_from_krs', 'subjects', $id, $updates);
        // Nazwa w rejestrze różni się od zapisanej w DUiR (np. spółka się przemianowała) —
        // zapisana jako alias TRWALE, ale żeby użytkownik faktycznie to ZAUWAŻYŁ (nie tylko
        // w ukrytej kolumnie aliases), tworzymy zdarzenie: pojawi się w raporcie dziennym
        // i na karcie podmiotu, tak jak każde inne ustalenie z KRS.
        if ($nameChanged) {
            $this->addEvent($id, [
                'source'=>'KRS','event_type'=>'nazwa_zaktualizowana','title'=>'KRS: zaktualizowano nazwę podmiotu w rejestrze',
                'description'=>"Nazwa zapisana w DUiR: \"{$s['name']}\". Aktualna nazwa w rejestrze KRS: \"$legal\". Poprzednia nazwa zachowana jako alias (dopisana do pola „Aliasy”); identyfikatory (KRS/NIP/REGON) się nie zmieniły.",
                'risk'=>'niski','risk_reason'=>'Zmiana nazwy w rejestrze nie jest sama w sobie sygnałem ryzyka, ale utrudnia rozpoznanie kontrahenta w innych źródłach (KRZ/MSiG) — zweryfikuj, czy dalsza korespondencja/dokumentacja używa aktualnej nazwy.',
            ]);
        }
        return array_keys($updates);
    }

    /**
     * Osoby fizyczne prowadzące działalność: uzupełnia PEŁNĄ firmę z CEIDG (i REGON,
     * jeśli podany) do PUSTYCH pól / aliasów — analogicznie do wersji KRS. Dzięki temu
     * karta i wyszukiwanie KRZ/MSiG mają pełną nazwę firmy, a nie tylko imię i nazwisko.
     */
    public function applyCeidgProfileToSubject(int $id, array $ceidg): array
    {
        $s = $this->findSubject($id);
        if (!$s) return [];
        $updates = [];
        $regon = Normalize::digits((string)($ceidg['raw_json']['regon'] ?? $ceidg['regon'] ?? ''));
        if ($regon !== '' && Normalize::digits((string)($s['regon'] ?? '')) === '') $updates['regon'] = $regon;
        $legal = Normalize::text((string)($ceidg['legal_name'] ?? ''));
        $nameChanged = false;
        if ($legal !== '' && Normalize::compactKey($legal) !== Normalize::compactKey((string)$s['name'])) {
            $aliases = Normalize::text((string)($s['aliases'] ?? ''));
            if (!str_contains(Normalize::fold($aliases), Normalize::fold($legal))) {
                $updates['aliases'] = $aliases === '' ? $legal : $aliases."\n".$legal;
                $nameChanged = true;
            }
        }
        if (!$updates) return [];
        $set = implode(', ', array_map(fn($k) => "`$k`=?", array_keys($updates)));
        $st = $this->pdo->prepare("UPDATE subjects SET $set, updated_at=NOW() WHERE id=?");
        $st->execute([...array_values($updates), $id]);
        $this->audit('subject.enriched_from_ceidg', 'subjects', $id, $updates);
        if ($nameChanged) {
            $this->addEvent($id, [
                'source'=>'CEIDG','event_type'=>'nazwa_zaktualizowana','title'=>'CEIDG: zaktualizowano nazwę firmy w rejestrze',
                'description'=>"Nazwa zapisana w DUiR: \"{$s['name']}\". Aktualna firma w CEIDG: \"$legal\". Poprzednia nazwa zachowana jako alias (dopisana do pola „Aliasy”); identyfikatory się nie zmieniły.",
                'risk'=>'niski','risk_reason'=>'Zmiana nazwy firmy w CEIDG nie jest sama w sobie sygnałem ryzyka, ale utrudnia rozpoznanie kontrahenta w innych źródłach — zweryfikuj, czy dalsza korespondencja/dokumentacja używa aktualnej nazwy.',
            ]);
        }
        return array_keys($updates);
    }

    /**
     * Zamyka zadania KRZ/MSiG wiszące w pending/running dłużej niż $maxAgeHours.
     * Bez tego zadanie, którego wtyczka nigdy nie dokończyła (zamknięta
     * przeglądarka, ubity service worker MV3), zostawało "running" bezterminowo
     * i karta podmiotu sugerowała, że sprawdzanie wciąż trwa. Wywoływane z CRON-a.
     */
    public function expireStaleTasks(int $maxAgeHours = 24): int
    {
        $count = 0;
        $st = $this->pdo->prepare('SELECT id, subject_id FROM krz_tasks WHERE status IN ("pending","running") AND requested_at < NOW() - INTERVAL ? HOUR');
        $st->execute([$maxAgeHours]);
        foreach ($st->fetchAll() as $t) {
            $this->markKrzError((int)$t['subject_id'], 'KRZ: zadanie przeterminowane — wtyczka Chrome nie dostarczyła wyniku w '.$maxAgeHours.' h (sprawdź, czy przeglądarka z wtyczką jest uruchomiona).', ['task_id'=>(int)$t['id'],'expired'=>true], (int)$t['id']);
            $count++;
        }
        $st = $this->pdo->prepare('SELECT id, subject_id FROM msig_tasks WHERE status IN ("pending","running") AND requested_at < NOW() - INTERVAL ? HOUR');
        $st->execute([$maxAgeHours]);
        foreach ($st->fetchAll() as $t) {
            $this->markMsigError((int)$t['subject_id'], 'MSiG: zadanie przeterminowane — wtyczka Chrome nie dostarczyła wyniku w '.$maxAgeHours.' h (sprawdź, czy przeglądarka z wtyczką jest uruchomiona).', ['task_id'=>(int)$t['id'],'expired'=>true], (int)$t['id']);
            $count++;
        }
        return $count;
    }

    /**
     * Czy dla podmiotu wisi jeszcze zadanie KRZ lub MSiG w kolejce wtyczki
     * (pending/running)? Używane przez pasek postępu na karcie podmiotu, żeby
     * pokazać "sprawdzanie w toku" i odpytywać status, dopóki coś się dzieje.
     */
    public function subjectHasPendingBrowserTask(int $id): bool
    {
        $st = $this->pdo->prepare('SELECT (SELECT COUNT(*) FROM krz_tasks WHERE subject_id=? AND status IN ("pending","running")) + (SELECT COUNT(*) FROM msig_tasks WHERE subject_id=? AND status IN ("pending","running"))');
        $st->execute([$id, $id]);
        return (int)$st->fetchColumn() > 0;
    }

    /** Czy JAKIEKOLWIEK zadanie wtyczki (KRZ/MSiG) czeka lub trwa — bramka auto-raportu dziennego. */
    public function anyPendingBrowserTasks(): bool
    {
        $st = $this->pdo->query('SELECT (SELECT COUNT(*) FROM krz_tasks WHERE status IN ("pending","running")) + (SELECT COUNT(*) FROM msig_tasks WHERE status IN ("pending","running"))');
        return (int)$st->fetchColumn() > 0;
    }

    // Czy DZISIEJSZY przebieg wtyczek już COKOLWIEK dostarczył (wynik/brak/błąd
    // KRZ lub MSiG z dzisiejszą datą)? „Pusta kolejka" sama w sobie NIE znaczy, że
    // sweep się odbył — o godzinie wysyłki raportu kolejka bywa pusta dlatego, że
    // dzisiejsze zadania jeszcze nie powstały (raport szedł ze stanem sprzed doby).
    public function sweepDeliveredToday(): bool
    {
        $st = $this->pdo->query("SELECT COUNT(*) FROM checks WHERE source IN ('KRZ','MSIG') AND status IN ('success','no_results','error') AND DATE(checked_at)=CURDATE()");
        return (int)$st->fetchColumn() > 0;
    }

    /**
     * Stan dzisiejszego monitoringu per podmiot — do zbiorczej części raportu
     * dziennego („kogo monitorowano, kogo nie udało się sprawdzić").
     * Dla każdego monitorowanego podmiotu: ostatni status każdego źródła
     * (rejestr statusowy zależny od typu podmiotu) + czy coś wisi w kolejce.
     */
    public function dailyMonitoringStatus(): array
    {
        $out = [];
        foreach ($this->subjects() as $s) {
            if ((int)($s['monitored'] ?? 0) !== 1) continue;
            $id = (int)$s['id'];
            $isPerson = in_array((string)($s['type'] ?? ''), ['business_person','natural_person'], true);
            $sources = $isPerson ? ['CEIDG','KRZ','MSIG'] : ['KRS','KRZ','MSIG'];
            $row = ['id'=>$id,'name'=>(string)$s['name'],'pending'=>$this->subjectHasPendingBrowserTask($id),'sources'=>[]];
            foreach ($sources as $src) {
                $c = $this->latestCheckBySource($id, $src);
                $row['sources'][$src] = [
                    'status'=>(string)($c['status'] ?? 'none'),
                    'at'=>(string)($c['checked_at'] ?? ''),
                    // Przyczyna do sekcji błędów raportu — bez niej „błąd sprawdzenia"
                    // nie mówi prawnikowi nic i wymaga wejścia do panelu.
                    'message'=>mb_substr((string)($c['message'] ?? ''), 0, 200, 'UTF-8'),
                ];
            }
            $out[] = $row;
        }
        return $out;
    }

    public function addCheck(int $subjectId, string $source, string $status, ?string $message = null, ?array $raw = null): int
    {
        // Deduplikacja błędów: identyczny błąd tego samego źródła w krótkim oknie
        // NIE jest dopisywany ponownie — jeden nieudany przebieg wtyczki potrafił
        // zostawić kilkanaście identycznych wierszy i zasypać historię sprawdzeń.
        if ($status === 'error') {
            $st = $this->pdo->prepare('SELECT id FROM source_checks WHERE subject_id=? AND source=? AND status="error" AND ((message=? ) OR (message IS NULL AND ? IS NULL)) AND checked_at>? ORDER BY id DESC LIMIT 1');
            $st->execute([$subjectId, $source, $message, $message, date('Y-m-d H:i:s', time() - 3600)]);
            $existing = $st->fetchColumn();
            if ($existing !== false) {
                // Duplikat nie tworzy nowego wiersza, ale ODŚWIEŻA czas i diagnostykę
                // (raw_json z pageText) — inaczej dedup ukrywałby próbkę strony
                // z najnowszego przebiegu, czyli jedyny materiał do debugowania.
                $up = $this->pdo->prepare('UPDATE source_checks SET checked_at=NOW(), raw_json=COALESCE(?, raw_json) WHERE id=?');
                $up->execute([$raw ? json_encode($raw, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null, (int)$existing]);
                return (int)$existing;
            }
        }
        $st = $this->pdo->prepare('INSERT INTO source_checks (subject_id,source,status,message,raw_json,checked_at) VALUES (?,?,?,?,?,NOW())');
        $st->execute([$subjectId, $source, $status, $message, $raw ? json_encode($raw, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null]);
        return (int)$this->pdo->lastInsertId();
    }

    public function latestChecks(int $subjectId, int $limit = 50): array
    {
        $limit = max(1, min(1000, $limit));
        $st = $this->pdo->prepare('SELECT * FROM source_checks WHERE subject_id=? ORDER BY checked_at DESC, id DESC LIMIT '.$limit);
        $st->execute([$subjectId]);
        return $st->fetchAll();
    }

    public function latestCheckBySource(int $subjectId, string $source): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM source_checks WHERE subject_id=? AND source=? ORDER BY checked_at DESC, id DESC LIMIT 1');
        $st->execute([$subjectId, $source]);
        return $st->fetch() ?: null;
    }

    // GOTCHA wykryta na produkcji (raport 2026-07-14): kolumna events.risk była
    // ENUM bez 'krytyczny' — MySQL w trybie nie-strict UCINAŁ zapis do pustego
    // stringa. Skutek: zdarzenie „podmiot wykreślony z KRS" miało puste ryzyko,
    // a ocena LLM i nagłówek raportu liczyły max z POZOSTAŁYCH zdarzeń (zaniżone).
    // Migracja samonaprawiająca + korekta uszkodzonych wierszy ('' => 'krytyczny',
    // bo 'krytyczny' to jedyna wartość spoza starego ENUM, jaką aplikacja zapisuje).
    private static bool $riskEnumChecked = false;
    private function ensureRiskEnumHasCritical(): void
    {
        if (self::$riskEnumChecked) return;
        self::$riskEnumChecked = true;
        try {
            $st = $this->pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="events" AND COLUMN_NAME="risk"');
            $st->execute();
            $type = (string)$st->fetchColumn();
            if ($type !== '' && str_starts_with(strtolower($type), 'enum') && !str_contains($type, 'krytyczny')) {
                $this->pdo->exec("ALTER TABLE events MODIFY risk ENUM('niski','średni','wysoki','krytyczny') NOT NULL DEFAULT 'niski'");
                $this->pdo->exec("UPDATE events SET risk='krytyczny' WHERE risk=''");
            }
        } catch (\Throwable $e) { /* sqlite w testach / brak uprawnień ALTER — addEvent działa dalej */ }
    }

    public function addEvent(int $subjectId, array $event): int
    {
        $this->ensureRiskEnumHasCritical();
        $hash = $event['dedupe_hash'] ?? $this->eventHash($subjectId, $event);
        $sql = 'INSERT INTO events (subject_id,source,event_type,title,description,signature,publication_date,proceeding_status,risk,risk_reason,source_url,dedupe_hash,raw_json,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE risk=VALUES(risk), risk_reason=VALUES(risk_reason), description=VALUES(description), raw_json=VALUES(raw_json), updated_at=NOW()';
        $st = $this->pdo->prepare($sql);
        $st->execute([
            $subjectId, $event['source'] ?? 'KRZ', $event['event_type'] ?? 'informacja', $event['title'] ?? 'Informacja', $event['description'] ?? null,
            $event['signature'] ?? null, $event['publication_date'] ?? null, $event['proceeding_status'] ?? null,
            $event['risk'] ?? 'niski', $event['risk_reason'] ?? null, $event['source_url'] ?? null, $hash,
            isset($event['raw_json']) ? json_encode($event['raw_json'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function latestEvents(int $subjectId, int $limit = 100): array
    {
        $st = $this->pdo->prepare('SELECT * FROM events WHERE subject_id=? ORDER BY COALESCE(publication_date, DATE(created_at)) DESC, created_at DESC, id DESC LIMIT '.$limit);
        $st->execute([$subjectId]);
        return $st->fetchAll();
    }

    /**
     * Usuwa wyłącznie techniczne, fałszywe zdarzenia KRS utworzone przez dawny
     * fallback, który zapisywał cały lub ucięty JSON odpisu jako opis postępowania.
     * Ocena AI oparta na takim rekordzie również jest unieważniana. Metoda jest
     * celowo idempotentna i może działać globalnie albo dla jednej karty.
     */
    public function purgeLegacyKrsJsonEvents(?int $subjectId = null): int
    {
        try {
            $sql = "SELECT id,subject_id,description FROM events WHERE source='KRS'".($subjectId !== null ? ' AND subject_id=?' : '');
            $st = $this->pdo->prepare($sql);
            $st->execute($subjectId !== null ? [$subjectId] : []);
            $bad = []; $subjects = [];
            foreach ($st->fetchAll() as $row) {
                $description = (string)($row['description'] ?? '');
                if ($description === '' || \Duir\Services\RiskAnalyzer::readableKrsDescription($description) !== '') continue;
                $bad[] = (int)$row['id'];
                $subjects[(int)$row['subject_id']] = true;
            }
            if (!$bad) return 0;
            $deleteEvent = $this->pdo->prepare('DELETE FROM events WHERE id=?');
            foreach ($bad as $id) $deleteEvent->execute([$id]);
            // Stara ocena mogła powtarzać fałszywy alert. Brak tabeli reports w
            // minimalistycznym teście/instalacji nie może zablokować samego cleanupu.
            try {
                $deleteAssessment = $this->pdo->prepare('DELETE FROM reports WHERE subject_id=? AND type="assessment"');
                foreach (array_keys($subjects) as $sid) $deleteAssessment->execute([$sid]);
            } catch (\Throwable) {}
            return count($bad);
        } catch (\Throwable) { return 0; }
    }

    public function latestEventsSince(string $since): array
    {
        $st = $this->pdo->prepare('SELECT e.*,s.name subject_name,s.krs,s.nip,s.regon,s.pesel FROM events e JOIN subjects s ON s.id=e.subject_id WHERE e.created_at>=? ORDER BY e.created_at DESC, e.id DESC');
        $st->execute([$since]);
        return $st->fetchAll();
    }

    public function latestEventBySource(int $subjectId, string $source): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM events WHERE subject_id=? AND source=? ORDER BY COALESCE(publication_date, DATE(created_at)) DESC, created_at DESC, id DESC LIMIT 1');
        $st->execute([$subjectId, $source]);
        return $st->fetch() ?: null;
    }

    public function maxRiskForSubject(int $subjectId): string
    {
        $rank = ['niski'=>1,'średni'=>2,'wysoki'=>3,'krytyczny'=>4];
        $risk = 'niski';
        foreach ($this->latestEvents($subjectId, 200) as $e) {
            if (($rank[$e['risk']] ?? 1) > ($rank[$risk] ?? 1)) $risk = $e['risk'];
        }
        return $risk;
    }

    // Sygnatury zdarzeń JUŻ zapisanych dla podmiotu w danym źródle (rezerwa/ogólne).
    public function seenSignatures(int $subjectId, string $source): array
    {
        $st = $this->pdo->prepare("SELECT DISTINCT signature FROM events WHERE subject_id=? AND source=? AND signature IS NOT NULL AND signature<>''");
        $st->execute([$subjectId, $source]);
        return array_values(array_filter(array_map(fn($r)=>(string)$r['signature'], $st->fetchAll())));
    }

    // Identyfikatory ogłoszeń MSiG już zapisanych dla podmiotu — „id" z linku POBIERZ
    // (…/Monitor/Download?id=NNNN) zapisanego w source_url. To jedyny stabilny klucz
    // WIDOCZNY w wierszu listy (sygnatura BMSiG jest dopiero w szczegółach), więc po
    // nim wtyczka pomija ponowne otwieranie znanych ogłoszeń (te się nie zmieniają).
    public function seenMsigDownloadIds(int $subjectId): array
    {
        $st = $this->pdo->prepare("SELECT source_url FROM events WHERE subject_id=? AND source='MSIG' AND source_url LIKE '%id=%'");
        $st->execute([$subjectId]);
        $ids = [];
        foreach ($st->fetchAll() as $r) {
            if (preg_match('/[?&]id=(\d+)/', (string)$r['source_url'], $m)) $ids[] = $m[1];
        }
        return array_values(array_unique($ids));
    }

    public function riskReasons(int $subjectId): array
    {
        $st = $this->pdo->prepare('SELECT risk,risk_reason,title,source FROM events WHERE subject_id=? ORDER BY FIELD(risk,"wysoki","średni","niski"), created_at DESC LIMIT 5');
        $st->execute([$subjectId]);
        return $st->fetchAll();
    }

    public function createKrzTask(array $subject): ?int
    {
        [$queryKey, $query] = SearchPlan::krzQuery($subject);
        if (!$query || ($queryKey === 'name' && SearchPlan::isWeakName($query))) {
            $this->addCheck((int)$subject['id'], 'KRZ', 'error', 'Nie zlecono KRZ: brak twardego identyfikatora albo nazwa jest zbyt ogólna.', ['query_key'=>$queryKey,'query'=>$query]);
            return null;
        }
        $kind = match ($subject['type'] ?? 'unknown') { 'business_person'=>'business_person', 'natural_person'=>'natural_person', default=>'company' };
        $this->invalidateStaleBrowserTasks('krz_tasks', (int)$subject['id'], $queryKey, $query, $kind);
        $existing = $this->findPendingKrzTask((int)$subject['id'], $queryKey, $query, $kind);
        if ($existing) {
            $this->addCheck((int)$subject['id'], 'KRZ', 'running', 'KRZ: zadanie było już w kolejce wtyczki Chrome.', ['task_id'=>(int)$existing['id'],'query_key'=>$queryKey,'query'=>$query]);
            $this->requestKrzSweep();
            return (int)$existing['id'];
        }
        $st = $this->pdo->prepare('INSERT INTO krz_tasks (subject_id,query,query_key,search_kind,status,requested_at) VALUES (?,?,?,?,"pending",NOW())');
        $st->execute([(int)$subject['id'], $query, $queryKey, $kind]);
        $id = (int)$this->pdo->lastInsertId();
        $this->addCheck((int)$subject['id'], 'KRZ', 'running', 'Zlecono sprawdzenie KRZ przez wtyczkę Chrome.', ['task_id'=>$id,'query_key'=>$queryKey,'query'=>$query,'search_kind'=>$kind]);
        $this->requestKrzSweep();
        return $id;
    }

    // Rozmiar paczki i dzierżawa zadań dla wtyczek Chrome. Wiele przeglądarek w
    // kancelarii odpytuje ten sam serwer: każda ATOMOWO rezerwuje własną paczkę
    // (UPDATE ... claimed_by), więc praca rozkłada się między włączone wtyczki
    // zamiast dublować. Dzierżawa 15 min > timeout pojedynczej karty (120 s),
    // a zadanie porzucone przez ubitą wtyczkę wraca do puli.
    // Jedno zadanie na rezerwację: przy wielu komputerach krótka paczka nie
    // blokuje pozostałych maszyn i nie trzyma czterech kolejnych podmiotów przez
    // czas pracy jednej wolnej/zawieszonej karty portalu.
    private const TASK_BATCH = 1;
    private const TASK_LEASE_MINUTES = 15;
    private static bool $claimColumnsChecked = false;

    // Samonaprawiająca migracja dla baz utworzonych przed wprowadzeniem kolumny
    // claimed_by (instalator tworzy ją od razu — patrz database/schema.sql).
    private function ensureTaskClaimColumns(): void
    {
        if (self::$claimColumnsChecked) return;
        self::$claimColumnsChecked = true;
        foreach (['krz_tasks','msig_tasks'] as $table) {
            $st = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME="claimed_by"');
            $st->execute([$table]);
            if ((int)$st->fetchColumn() === 0) {
                $this->pdo->exec("ALTER TABLE $table ADD COLUMN claimed_by VARCHAR(32) NULL");
            }
        }
    }

    // --- Heartbeat wtyczek (ile komputerów aktywnych) ---------------------
    private static bool $instancesTableChecked = false;
    private function ensurePluginInstancesTable(): void
    {
        if (self::$instancesTableChecked) return;
        self::$instancesTableChecked = true;
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS plugin_instances ('
            .'instance_id VARCHAR(64) NOT NULL PRIMARY KEY, label VARCHAR(120) NULL, '
            .'last_seen DATETIME NOT NULL, first_seen DATETIME NOT NULL) '
            .'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    // Odcisk aktywnej wtyczki (heartbeat). Wywoływane przy każdym pingu wtyczki —
    // po nim liczymy, ile RÓŻNYCH komputerów było aktywnych w ostatnich minutach.
    public function touchPluginInstance(string $instanceId, ?string $label = null): void
    {
        $instanceId = substr(trim($instanceId), 0, 64);
        if ($instanceId === '') return;
        $this->ensurePluginInstancesTable();
        $st = $this->pdo->prepare('INSERT INTO plugin_instances (instance_id,label,last_seen,first_seen) '
            .'VALUES (?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE last_seen=NOW(), label=COALESCE(VALUES(label),label)');
        $st->execute([$instanceId, ($label !== null && $label !== '') ? substr($label,0,120) : null]);
    }

    // Wtyczki widziane w ostatnich $minutes minutach = „aktywne komputery".
    public function activePluginInstances(int $minutes = 12): array
    {
        $this->ensurePluginInstancesTable();
        $st = $this->pdo->prepare('SELECT instance_id,label,last_seen FROM plugin_instances '
            .'WHERE last_seen > NOW() - INTERVAL ? MINUTE ORDER BY last_seen DESC');
        $st->execute([$minutes]);
        return $st->fetchAll();
    }

    // Atomowa rezerwacja paczki zadań: bierzemy pending oraz running z przeterminowaną
    // dzierżawą (wtyczka umarła w trakcie), oznaczamy własnym tokenem i zwracamy TYLKO
    // zarezerwowane wiersze. Dwie wtyczki odpytujące jednocześnie dostają rozłączne paczki.
    private function claimTasks(string $table): array
    {
        $this->ensureTaskClaimColumns();
        if (!in_array($table, ['krz_tasks','msig_tasks'], true)) {
            throw new \InvalidArgumentException('Nieznana kolejka zadań.');
        }
        $claim = bin2hex(random_bytes(12));
        $lease = (int)self::TASK_LEASE_MINUTES;
        $batch = (int)self::TASK_BATCH;
        // Dwa komputery mogą zapytać o worklistę w tej samej milisekundzie. Sam
        // UPDATE z materializowanym podzapytaniem nie wystarczał: drugi proces po
        // odblokowaniu mógł nadpisać claimed_by pierwszego. Locking read w krótkiej
        // transakcji serializuje WYŁĄCZNIE wybór jednego wiersza; portal jest
        // obsługiwany już po COMMIT, więc żaden lock nie trwa podczas pracy Chrome.
        $this->pdo->beginTransaction();
        try {
            // Zadania powstają wyłącznie przy jawnym sprawdzeniu. Nie filtrujemy
            // monitored=1, bo tryb jednorazowy ma monitored=0 przed pierwszym runem.
            $pick = $this->pdo->query(
                "SELECT id FROM $table "
                ."WHERE (status='pending' OR (status='running' AND started_at < NOW() - INTERVAL $lease MINUTE)) "
                ."ORDER BY requested_at ASC, id ASC LIMIT $batch FOR UPDATE"
            );
            $ids = array_map('intval', $pick->fetchAll(\PDO::FETCH_COLUMN));
            if (!$ids) {
                $this->pdo->commit();
                return [];
            }
            $marks = implode(',', array_fill(0, count($ids), '?'));
            // Powtórzony warunek jest drugą barierą bezpieczeństwa, gdy silnik po
            // oczekiwaniu na lock zwróciłby wiersz na podstawie starszego widoku.
            $up = $this->pdo->prepare(
                "UPDATE $table SET status='running', started_at=NOW(), claimed_by=? "
                ."WHERE id IN ($marks) "
                ."AND (status='pending' OR (status='running' AND started_at < NOW() - INTERVAL $lease MINUTE))"
            );
            $up->execute(array_merge([$claim], $ids));
            if ($up->rowCount() === 0) {
                $this->pdo->commit();
                return [];
            }
            $st = $this->pdo->prepare("SELECT t.*,s.name,s.krs,s.nip,s.regon,s.pesel,s.type FROM $table t JOIN subjects s ON s.id=t.subject_id WHERE t.claimed_by=? AND t.status='running' ORDER BY t.requested_at ASC");
            $st->execute([$claim]);
            $rows = $st->fetchAll();
            $this->pdo->commit();
            return $rows;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function pendingKrzWorklist(): array
    {
        return $this->claimTasks('krz_tasks');
    }


    public function monitoredKrzSubjects(): array
    {
        // Lista służy także ręcznemu dopasowaniu przycisku „Wyślij wynik”, dlatego
        // obejmuje podmioty jednorazowe po zakończeniu ich pierwszego przebiegu.
        $sql = 'SELECT id,name,krs,nip,regon,pesel,type FROM subjects ORDER BY name ASC';
        return $this->pdo->query($sql)->fetchAll();
    }

    public function markKrzRunning(int $taskId): void
    {
        $st = $this->pdo->prepare('UPDATE krz_tasks SET status="running", started_at=COALESCE(started_at,NOW()) WHERE id=?');
        $st->execute([$taskId]);
    }

    /**
     * Sprawdza, czy aktywne zadanie przeglądarkowe rzeczywiście należy do podmiotu.
     * Sam subject_id przesłany przez wtyczkę nie jest dowodem powiązania: karta może
     * zostać przejęta przez inny przebieg albo wysłać spóźniony komunikat.
     */
    public function taskBelongsToSubject(string $source, int $taskId, int $subjectId): bool
    {
        if ($taskId <= 0 || $subjectId <= 0) return false;
        $table = match (strtoupper($source)) {
            'KRZ' => 'krz_tasks',
            'MSIG' => 'msig_tasks',
            default => null,
        };
        if ($table === null) return false;
        $st = $this->pdo->prepare("SELECT 1 FROM $table WHERE id=? AND subject_id=? AND status IN ('pending','running') LIMIT 1");
        $st->execute([$taskId, $subjectId]);
        return (bool)$st->fetchColumn();
    }

    /**
     * Pełna walidacja dzierżawy. claimed_by jest nieprzewidywalnym tokenem paczki,
     * więc wynik może zakończyć wyłącznie komputer, który faktycznie dostał task.
     */
    public function taskClaimIsValid(string $source, int $taskId, int $subjectId, string $claimToken): bool
    {
        $claimToken = trim($claimToken);
        if ($taskId <= 0 || $subjectId <= 0 || $claimToken === '') return false;
        $table = match (strtoupper($source)) {
            'KRZ' => 'krz_tasks',
            'MSIG' => 'msig_tasks',
            default => null,
        };
        if ($table === null) return false;
        $st = $this->pdo->prepare("SELECT 1 FROM $table WHERE id=? AND subject_id=? AND claimed_by=? AND status='running' LIMIT 1");
        $st->execute([$taskId, $subjectId, $claimToken]);
        return (bool)$st->fetchColumn();
    }

    public function markKrzTaskDone(int $subjectId, ?string $message = null, ?array $raw = null, ?int $taskId = null): bool
    {
        // Brak taskId oznacza świadomy, ręczny import z przycisku w portalu. Taki
        // import zapisuje kontrolę, ale nigdy nie zamyka zadań kolejki w tle.
        if ($taskId !== null && !$this->updateKrzTask($subjectId, 'done', null, $raw, $taskId)) return false;
        $this->addCheck($subjectId, 'KRZ', 'success', $message ?: 'KRZ sprawdzony przez wtyczkę.', $raw);
        $this->updateSubjectCheck($subjectId, 'krz_done');
        return true;
    }

    public function markKrzNoResults(int $subjectId, ?array $raw = null, ?int $taskId = null): bool
    {
        if ($taskId !== null && !$this->updateKrzTask($subjectId, 'done', null, $raw, $taskId)) return false;
        $this->addCheck($subjectId, 'KRZ', 'no_results', 'KRZ: potwierdzony brak wyników dla identyfikatora.', $raw);
        $this->updateSubjectCheck($subjectId, 'krz_no_results');
        return true;
    }

    public function markKrzError(int $subjectId, string $error, ?array $raw = null, ?int $taskId = null): bool
    {
        if ($taskId !== null && !$this->updateKrzTask($subjectId, 'error', $error, $raw, $taskId)) return false;
        $this->addCheck($subjectId, 'KRZ', 'error', $error, $raw);
        $this->updateSubjectCheck($subjectId, 'krz_error');
        return true;
    }

    public function allSettings(): array
    {
        $rows = $this->pdo->query('SELECT `key`, value FROM settings')->fetchAll();
        $out = [];
        foreach ($rows as $row) $out[(string)$row['key']] = $row['value'];
        return $out;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        $st = $this->pdo->prepare('SELECT value FROM settings WHERE `key`=?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? $default : $v;
    }

    public function setSetting(string $key, string $value): void
    {
        $st = $this->pdo->prepare('INSERT INTO settings (`key`,`value`,updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE value=VALUES(value), updated_at=NOW()');
        $st->execute([$key, $value]);
    }

    public function requestKrzSweep(): void
    {
        $this->setSetting('krz_sweep_requested_at', date(DATE_ATOM));
    }

    public function createMsigTask(array $subject): ?int
    {
        // MSiG używa własnego doboru zapytania (bez PESEL — wyszukiwarka go nie obsługuje;
        // osoba fizyczna z samym PESEL trafia do wyszukiwania po nazwie).
        [$queryKey, $query] = SearchPlan::msigTaskQuery($subject);
        if (!$query || ($queryKey === 'name' && SearchPlan::isWeakName($query))) return null;
        $kind = match ($subject['type'] ?? 'unknown') { 'business_person'=>'business_person', 'natural_person'=>'natural_person', default=>'company' };
        $this->invalidateStaleBrowserTasks('msig_tasks', (int)$subject['id'], $queryKey, $query, $kind);
        $existing = $this->findPendingMsigTask((int)$subject['id'], $queryKey, $query, $kind);
        if ($existing) { $this->requestMsigSweep(); return (int)$existing['id']; }
        $st = $this->pdo->prepare('INSERT INTO msig_tasks (subject_id,query,query_key,search_kind,status,requested_at) VALUES (?,?,?,?,"pending",NOW())');
        $st->execute([(int)$subject['id'], $query, $queryKey, $kind]);
        $id = (int)$this->pdo->lastInsertId();
        $this->requestMsigSweep();
        return $id;
    }

    public function pendingMsigWorklist(): array
    {
        return $this->claimTasks('msig_tasks');
    }

    public function markMsigRunning(int $taskId): void
    {
        $st = $this->pdo->prepare('UPDATE msig_tasks SET status="running", started_at=COALESCE(started_at,NOW()) WHERE id=?');
        $st->execute([$taskId]);
    }

    public function markMsigTaskDone(int $subjectId, ?string $message = null, ?array $raw = null, ?int $taskId = null): bool
    {
        if ($taskId !== null && !$this->updateMsigTask($subjectId, 'done', null, $raw, $taskId)) return false;
        $this->addCheck($subjectId, 'MSIG', 'success', $message ?: 'MSiG sprawdzony przez wtyczkę.', $raw);
        $this->updateSubjectCheck($subjectId, 'msig_done');
        return true;
    }

    public function markMsigNoResults(int $subjectId, ?array $raw = null, ?int $taskId = null): bool
    {
        if ($taskId !== null && !$this->updateMsigTask($subjectId, 'done', null, $raw, $taskId)) return false;
        $this->addCheck($subjectId, 'MSIG', 'no_results', 'MSiG: potwierdzony brak wyników dla identyfikatora.', $raw);
        $this->updateSubjectCheck($subjectId, 'msig_no_results');
        return true;
    }

    public function markMsigError(int $subjectId, string $error, ?array $raw = null, ?int $taskId = null): bool
    {
        if ($taskId !== null && !$this->updateMsigTask($subjectId, 'error', $error, $raw, $taskId)) return false;
        $this->addCheck($subjectId, 'MSIG', 'error', $error, $raw);
        $this->updateSubjectCheck($subjectId, 'msig_error');
        return true;
    }

    public function requestMsigSweep(): void
    {
        $this->setSetting('msig_sweep_requested_at', date(DATE_ATOM));
    }

    /** Najświeższe zbadane sprawozdanie finansowe podmiotu (okres, data złożenia, terminowość). */
    public function latestFinancialCheck(int $subjectId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM financial_statement_checks WHERE subject_id=? ORDER BY id DESC LIMIT 1');
        $st->execute([$subjectId]);
        return $st->fetch() ?: null;
    }

    public function saveFinancialCheck(int $subjectId, array $check): void
    {
        $st = $this->pdo->prepare('INSERT INTO financial_statement_checks (subject_id,period_from,period_to,submitted_at,due_date,status,reason,raw_json,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())');
        $st->execute([$subjectId,$check['period_from']??null,$check['period_to']??null,$check['submitted_at']??null,$check['due_date']??null,$check['status']??'unknown',$check['reason']??null,json_encode($check,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }

    // Najświeższa zbuforowana ocena LLM dla podmiotu (reports.type='assessment').
    public function latestAssessment(int $subjectId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM reports WHERE subject_id=? AND type="assessment" ORDER BY id DESC LIMIT 1');
        $st->execute([$subjectId]);
        return $st->fetch() ?: null;
    }

    public function saveReport(?int $subjectId, string $type, string $title, string $summary, string $html, ?string $pdfPath): int
    {
        $st = $this->pdo->prepare('INSERT INTO reports (subject_id,type,title,summary,html,pdf_path,created_at) VALUES (?,?,?,?,?,?,NOW())');
        $st->execute([$subjectId,$type,$title,$summary,$html,$pdfPath]);
        return (int)$this->pdo->lastInsertId();
    }

    public function saveOutgoingMail(?int $subjectId, string $to, string $subject, string $status, ?string $error = null): void
    {
        $st = $this->pdo->prepare('INSERT INTO outgoing_mail (subject_id,recipient,subject,status,error,created_at) VALUES (?,?,?,?,?,NOW())');
        $st->execute([$subjectId,$to,$subject,$status,$error]);
    }

    public function audit(string $action, ?string $table = null, ?int $rowId = null, array $payload = []): void
    {
        try {
            $st = $this->pdo->prepare('INSERT INTO audit_log (action,table_name,row_id,payload_json,created_at) VALUES (?,?,?,?,NOW())');
            $st->execute([$action,$table,$rowId,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        } catch (\Throwable) {}
    }

    private function guessType(array $data): string
    {
        if (Normalize::digits($data['krs'] ?? '')) return 'company';
        if (Normalize::digits($data['nip'] ?? '') && Normalize::digits($data['pesel'] ?? '')) return 'business_person';
        if (Normalize::digits($data['pesel'] ?? '')) return 'natural_person';
        return 'company';
    }

    private function assertSubjectInput(array $data): void
    {
        if (Normalize::text($data['name'] ?? '') === '') throw new \InvalidArgumentException('Nazwa podmiotu jest wymagana.');
        $serviceModes = ['office_monitoring','client_monitoring','one_time'];
        if (!in_array((string)($data['service_mode'] ?? ''), $serviceModes, true)) {
            throw new \InvalidArgumentException('Wybierz tryb obsługi podmiotu.');
        }
        if (!SearchPlan::hasHardIdentifier($data) && empty($data['allow_name_only'])) {
            throw new \InvalidArgumentException('Podaj KRS, NIP, REGON albo PESEL. Sprawdzanie wyłącznie po nazwie wymaga świadomego zaznaczenia opcji.');
        }
    }

    /**
     * Unieważnia kolejkę utworzoną przed zmianą typu albo identyfikatora podmiotu.
     * W przeciwnym razie stary task „company" mógł po korekcie typu nadal otworzyć
     * zakładkę dla spółek, równolegle z nowym taskiem dla osoby fizycznej.
     */
    private function invalidateStaleBrowserTasks(string $table, int $subjectId, string $queryKey, string $query, string $searchKind): void
    {
        if (!in_array($table, ['krz_tasks', 'msig_tasks'], true)) return;
        $st = $this->pdo->prepare("UPDATE $table SET status='error', finished_at=NOW(), error='Zadanie unieważnione po zmianie danych lub typu podmiotu.' "
            ."WHERE subject_id=? AND status IN ('pending','running') AND (query_key<>? OR query<>? OR search_kind<>?)");
        $st->execute([$subjectId, $queryKey, $query, $searchKind]);
    }

    private function findPendingKrzTask(int $subjectId, string $queryKey, string $query, string $searchKind): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM krz_tasks WHERE subject_id=? AND query_key=? AND query=? AND search_kind=? AND status IN ("pending","running") ORDER BY requested_at DESC LIMIT 1');
        $st->execute([$subjectId, $queryKey, $query, $searchKind]);
        return $st->fetch() ?: null;
    }

    private function updateKrzTask(int $subjectId, string $status, ?string $error, ?array $raw, ?int $taskId): bool
    {
        // Wynik wtyczki bez konkretnego zadania nie może hurtowo zamknąć wszystkich
        // oczekujących zadań podmiotu. To była druga droga do pomieszania przebiegów.
        if (!$taskId || !$this->taskBelongsToSubject('KRZ', $taskId, $subjectId)) return false;
        $rawJson = $raw ? json_encode($raw, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null;
        $st = $this->pdo->prepare('UPDATE krz_tasks SET status=?, finished_at=NOW(), error=?, raw_json=? WHERE id=? AND subject_id=? AND status IN ("pending","running")');
        $st->execute([$status, $error, $rawJson, $taskId, $subjectId]);
        return $st->rowCount() === 1;
    }

    private function findPendingMsigTask(int $subjectId, string $queryKey, string $query, string $searchKind): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM msig_tasks WHERE subject_id=? AND query_key=? AND query=? AND search_kind=? AND status IN ("pending","running") ORDER BY requested_at DESC LIMIT 1');
        $st->execute([$subjectId, $queryKey, $query, $searchKind]);
        return $st->fetch() ?: null;
    }

    private function updateMsigTask(int $subjectId, string $status, ?string $error, ?array $raw, ?int $taskId): bool
    {
        if (!$taskId || !$this->taskBelongsToSubject('MSIG', $taskId, $subjectId)) return false;
        $rawJson = $raw ? json_encode($raw, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null;
        $st = $this->pdo->prepare('UPDATE msig_tasks SET status=?, finished_at=NOW(), error=?, raw_json=? WHERE id=? AND subject_id=? AND status IN ("pending","running")');
        $st->execute([$status, $error, $rawJson, $taskId, $subjectId]);
        return $st->rowCount() === 1;
    }

    private function eventHash(int $subjectId, array $e): string
    {
        return hash('sha256', $subjectId.'|'.($e['source']??'').'|'.($e['signature']??'').'|'.($e['title']??'').'|'.($e['publication_date']??'').'|'.substr((string)($e['description']??''),0,180));
    }
}
