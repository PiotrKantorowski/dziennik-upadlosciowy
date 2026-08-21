<?php
namespace Duir\Services;

use Duir\Repository;

final class AuthService
{
    public function __construct(private Repository $repo) {}

    public function attempt(string $email, string $password): ?array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || $password === '') return null;
        $user = $this->repo->findUserByEmail($email);
        if (!$user || (int)$user['active'] !== 1) return null;
        if (!password_verify($password, (string)$user['password_hash'])) return null;
        $this->repo->touchUserLogin((int)$user['id']);
        unset($user['password_hash']);
        return $user;
    }

    public function currentUser(): ?array
    {
        $id = (int)($_SESSION['user_id'] ?? 0);
        if (!$id) return null;
        $user = $this->repo->findUser($id);
        if (!$user || (int)$user['active'] !== 1) {
            $_SESSION = [];
            return null;
        }
        unset($user['password_hash']);
        // Odświeżamy dane sesji z bazy przy każdym żądaniu. Dzięki temu zmiana roli,
        // blokada konta albo zmiana nazwy działa od razu, bez czekania na ponowne logowanie.
        $_SESSION['user_email'] = (string)$user['email'];
        $_SESSION['user_name'] = (string)$user['name'];
        $_SESSION['user_role'] = (string)$user['role'];
        $_SESSION['auth'] = true;
        return $user;
    }

    public function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['user_email'] = (string)$user['email'];
        $_SESSION['user_name'] = (string)$user['name'];
        $_SESSION['user_role'] = (string)$user['role'];
        $_SESSION['auth'] = true;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
        }
        session_destroy();
    }

    public function isAdmin(): bool
    {
        $user = $this->currentUser();
        return $user !== null && (($user['role'] ?? '') === 'admin');
    }
}
