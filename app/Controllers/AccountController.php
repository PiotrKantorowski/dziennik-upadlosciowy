<?php
namespace Duir\Controllers;

use Duir\Repository;
use Duir\Support\Http;
use Duir\Support\Csrf;

/**
 * Panel „Moje konto" — dostępny dla KAŻDEGO zalogowanego użytkownika (nie tylko
 * administratora). Pozwala zobaczyć własne dane i samodzielnie zmienić hasło.
 * Zmiany hasła dokonuje sam użytkownik (wpisuje obecne i nowe) — bezpieczne
 * self-service, bez udziału administratora.
 */
final class AccountController extends BaseController
{
    public function __construct(private Repository $repo) {}

    private function currentId(): int { return (int)($_SESSION['user_id'] ?? 0); }

    public function show(string $flash = '', bool $ok = false): void
    {
        $u = $this->repo->findUser($this->currentId());
        if (!$u) { http_response_code(403); echo 'Wymagane logowanie.'; return; }
        $this->header('Moje konto');
        if ($flash !== '') echo '<p class="'.($ok?'okbox':'error').'">'.Http::e($flash).'</p>';
        echo '<section class="card"><h2>Dane konta</h2><div class="meta-grid">'
            .'<div><span>Imię i nazwisko / nazwa</span><b>'.Http::e($u['name']).'</b></div>'
            .'<div><span>E-mail / login</span><b>'.Http::e($u['email']).'</b></div>'
            .'<div><span>Rola</span><b>'.Http::e($u['role']==='admin'?'administrator':'użytkownik').'</b></div>'
            .'<div><span>Ostatnie logowanie</span><b>'.Http::e($u['last_login_at'] ?: '—').'</b></div>'
            .'</div></section>';
        echo '<section class="card"><h2>Zmiana hasła</h2>'
            .'<form method="post" action="/account/password">'.Csrf::field().'<div class="formgrid">'
            .'<div><label>Obecne hasło</label><input type="password" name="current" required autocomplete="current-password"></div>'
            .'<div></div>'
            .'<div><label>Nowe hasło (min. 12 znaków)</label><input type="password" name="new" required minlength="12" autocomplete="new-password"></div>'
            .'<div><label>Powtórz nowe hasło</label><input type="password" name="confirm" required minlength="12" autocomplete="new-password"></div>'
            .'</div><p class="muted">Nowe hasło musi mieć co najmniej 12 znaków i różnić się od obecnego.</p>'
            .'<button class="btn primary">Zmień hasło</button></form></section>';
        $this->footer();
    }

    public function changePassword(): void
    {
        try {
            $this->repo->changeOwnPassword(
                $this->currentId(),
                (string)($_POST['current'] ?? ''),
                (string)($_POST['new'] ?? ''),
                (string)($_POST['confirm'] ?? '')
            );
            $this->show('Hasło zostało zmienione.', true);
        } catch (\Throwable $e) {
            $this->show($e->getMessage(), false);
        }
    }
}
