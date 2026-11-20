<?php
namespace Duir\KrzPisma;

use PDO;

/**
 * Pisma robocze KRZ w jednej tabeli (krz_pisma). Treść pisma to JSON z
 * KrzForms::fromPost() — ten sam kontrakt dostaje wtyczka.
 * Każde pismo należy do jednego konta (utworzyl): wszystkie odczyty i zmiany
 * są zawężone do właściciela, więc użytkownik nie widzi cudzych pism.
 * Tabela zakłada się sama przy pierwszym użyciu (jak plugin_instances w DUiR).
 */
final class DraftStore
{
    private static bool $ready = false;

    public function __construct(private PDO $pdo) {}

    private function ensure(): void
    {
        if (self::$ready) return;
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS krz_pisma ('
            .'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, wzor VARCHAR(16) NOT NULL, tytul VARCHAR(255) NOT NULL, '
            .'dane_json MEDIUMTEXT NOT NULL, utworzyl BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, '
            .'INDEX idx_krz_pisma_owner (utworzyl, updated_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        self::$ready = true;
    }

    public function all(int $owner): array
    {
        $this->ensure();
        $st = $this->pdo->prepare('SELECT id,wzor,tytul,updated_at FROM krz_pisma WHERE utworzyl=? ORDER BY updated_at DESC, id DESC');
        $st->execute([$owner]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Pismo właściciela albo null (także gdy pismo istnieje, ale należy do kogoś innego). */
    public function find(int $id, int $owner): ?array
    {
        $this->ensure();
        $st = $this->pdo->prepare('SELECT * FROM krz_pisma WHERE id=? AND utworzyl=?');
        $st->execute([$id, $owner]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['dane'] = json_decode((string)$row['dane_json'], true) ?: [];
        return $row;
    }

    /** Zapis nowego pisma albo zmiana własnego; zwraca id albo null, gdy pismo nie należy do właściciela. */
    public function save(?int $id, string $wzor, array $data, int $owner): ?int
    {
        $this->ensure();
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $title = mb_substr(KrzForms::title($data), 0, 255, 'UTF-8');
        if ($id) {
            if (!$this->find($id, $owner)) return null;
            $st = $this->pdo->prepare('UPDATE krz_pisma SET tytul=?, dane_json=?, updated_at=NOW() WHERE id=? AND utworzyl=?');
            $st->execute([$title, $json, $id, $owner]);
            return $id;
        }
        $st = $this->pdo->prepare('INSERT INTO krz_pisma (wzor,tytul,dane_json,utworzyl,created_at,updated_at) VALUES (?,?,?,?,NOW(),NOW())');
        $st->execute([$wzor, $title, $json, $owner]);
        return (int)$this->pdo->lastInsertId();
    }

    public function delete(int $id, int $owner): bool
    {
        $this->ensure();
        $st = $this->pdo->prepare('DELETE FROM krz_pisma WHERE id=? AND utworzyl=?');
        $st->execute([$id, $owner]);
        return $st->rowCount() > 0;
    }
}
