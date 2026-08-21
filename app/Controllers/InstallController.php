<?php
namespace Duir\Controllers;

use Duir\Repository;
use Duir\Support\Http;
use Duir\Support\Csrf;

final class InstallController extends BaseController
{
    public function __construct(private Repository $repo, private string $root) {}

    public function show(?string $error = null): void
    {
        if ($this->repo->schemaReady() && $this->repo->userCount() > 0) {
            http_response_code(403);
            echo 'Instalator jest wyłączony, bo istnieje już konto administratora.';
            return;
        }
        echo '<!doctype html><html lang="pl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalacja DUiR</title><link rel="stylesheet" href="/assets/app.css"><body><div class="wrap"><header class="top"><div><h1>Instalacja DUiR</h1><p class="muted">Dziennik Upadłościowy i Restrukturyzacyjny</p></div></header>';
        echo '<form class="card" method="post" action="/setup">'.Csrf::field();
        if ($error) echo '<p class="error">'.Http::e($error).'</p>';
        echo '<p>Ten kreator utworzy tabele w bazie danych i pierwsze konto administratora. Dane połączenia z bazą muszą być wcześniej wpisane w pliku <code>.env</code>.</p><div class="formgrid">';
        echo '<div><label>Imię i nazwisko / nazwa administratora</label><input name="name" required></div>';
        echo '<div><label>E-mail administratora</label><input type="email" name="email" required></div>';
        echo '<div><label>Hasło administratora</label><input type="password" name="password" required minlength="12"></div>';
        echo '<div><label>Powtórz hasło</label><input type="password" name="password_confirm" required minlength="12"></div>';
        echo '</div><button class="btn primary">Utwórz bazę i administratora</button></form></div></body></html>';
    }

    public function run(): void
    {
        if ($this->repo->schemaReady() && $this->repo->userCount() > 0) {
            http_response_code(403);
            echo 'Instalator jest wyłączony.';
            return;
        }
        try {
            $this->repo->installSchema($this->root.'/database/schema.sql');
            $this->repo->createUser([
                'name' => $_POST['name'] ?? '',
                'email' => $_POST['email'] ?? '',
                'password' => $_POST['password'] ?? '',
                'password_confirm' => $_POST['password_confirm'] ?? '',
                'role' => 'admin',
                'active' => '1',
            ]);
            \Duir\Support\Http::redirect('/login?installed=1');
        } catch (\Throwable $e) {
            $this->show($e->getMessage());
        }
    }
}
