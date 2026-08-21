<?php
namespace Duir\Controllers;

use Duir\Repository;
use Duir\Config;
use Duir\Services\Mailer;
use Duir\Support\Http;
use Duir\Support\Csrf;

final class UserController extends BaseController
{
    public function __construct(private Repository $repo) {}

    public function index(): void
    {
        $this->header('Użytkownicy');
        if (isset($_GET['created'])) {
            $m = (string)($_GET['mail'] ?? '');
            $msg = 'Konto zostało utworzone.'
                .($m==='ok' ? ' Powiadomienie e-mail wysłane do użytkownika.' : '')
                .($m==='err' ? ' Nie udało się wysłać powiadomienia e-mail — sprawdź konfigurację SMTP w Ustawieniach.' : '');
            echo '<p class="'.($m==='err'?'error':'okbox').'">'.Http::e($msg).'</p>';
        }
        echo '<div class="actions"><a class="btn primary" href="/users/new">Dodaj użytkownika</a></div>';
        echo '<section class="card"><h2>Konta dostępowe</h2><table><tr><th>Imię i nazwisko</th><th>E-mail</th><th>Rola</th><th>Status</th><th>Ostatnie logowanie</th><th>Akcje</th></tr>';
        foreach ($this->repo->users() as $u) {
            echo '<tr><td><b>'.Http::e($u['name']).'</b></td><td>'.Http::e($u['email']).'</td><td>'.Http::e($u['role']).'</td><td>'.((int)$u['active']===1?'<span class="chip ok">aktywne</span>':'<span class="chip muted">zablokowane</span>').'</td><td>'.Http::e($u['last_login_at'] ?: '—').'</td><td><a class="btn" href="/users/'.(int)$u['id'].'/edit">Edytuj</a></td></tr>';
        }
        echo '</table></section>';
        $this->footer();
    }

    public function createForm(): void
    {
        $this->header('Dodaj użytkownika');
        $this->form('/users/create');
        $this->footer();
    }

    public function editForm(int $id): void
    {
        $u = $this->repo->findUser($id);
        if (!$u) { http_response_code(404); echo 'Nie znaleziono użytkownika.'; return; }
        $this->header('Edytuj użytkownika');
        $this->form('/users/'.$id.'/update', $u, true);
        $this->footer();
    }

    private function form(string $action, array $u = [], bool $edit = false): void
    {
        echo '<form class="card" method="post" action="'.$action.'">'.Csrf::field().'<div class="formgrid">';
        echo '<div><label>Imię i nazwisko / nazwa</label><input name="name" required value="'.Http::e($u['name'] ?? '').'"></div>';
        echo '<div><label>E-mail / login</label><input type="email" name="email" required value="'.Http::e($u['email'] ?? '').'"></div>';
        echo '<div><label>Rola</label><select name="role">';
        foreach (['user'=>'użytkownik','admin'=>'administrator'] as $v=>$l) echo '<option value="'.$v.'" '.(($u['role'] ?? 'user')===$v?'selected':'').'>'.$l.'</option>';
        echo '</select></div>';
        echo '<div><label>Status</label><select name="active"><option value="1">aktywne</option><option value="0" '.((isset($u['active']) && !(int)$u['active'])?'selected':'').'>zablokowane</option></select></div>';
        echo '<div><label>'.($edit?'Nowe hasło (opcjonalnie)':'Hasło').'</label><input type="password" name="password" '.($edit?'':'required').'></div>';
        echo '<div><label>Powtórz hasło</label><input type="password" name="password_confirm" '.($edit?'':'required').'></div>';
        echo '</div><p class="muted">Administrator może tworzyć konta, blokować dostęp i resetować hasła. Zwykły użytkownik ma dostęp do monitoringu i raportów, ale nie zarządza kontami ani ustawieniami systemowymi.</p>';
        if (!$edit) echo '<label class="checkline"><input type="checkbox" name="notify" value="1" checked> Wyślij użytkownikowi powiadomienie e-mail o utworzeniu konta (link do logowania, bez hasła).</label>';
        echo '<button class="btn primary">Zapisz</button> <a class="btn" href="/users">Anuluj</a></form>';
    }

    public function create(): void
    {
        try {
            $this->repo->createUser($_POST);
            // Powiadomienie e-mail o utworzeniu konta jest opcjonalne i NIEBLOKUJĄCE —
            // brak/awaria SMTP nie może cofnąć utworzenia konta.
            $mail = '';
            if (!empty($_POST['notify'])) $mail = $this->sendWelcomeEmail($_POST) ? 'ok' : 'err';
            Http::redirect('/users?created=1'.($mail!==''?'&mail='.$mail:''));
        } catch (\Throwable $e) {
            $this->header('Błąd zapisu użytkownika');
            echo '<section class="card"><p class="error">'.Http::e($e->getMessage()).'</p><p><a class="btn" href="/users/new">Wróć</a></p></section>';
            $this->footer();
        }
    }

    /**
     * Powiadomienie powitalne dla nowego konta: link do logowania i prośba
     * o zmianę hasła w panelu „Moje konto". CELOWO NIE zawiera hasła — hasło
     * ustala i przekazuje administrator odrębnym kanałem (bezpieczeństwo).
     */
    private function sendWelcomeEmail(array $data): bool
    {
        $to = mb_strtolower(trim((string)($data['email'] ?? '')));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
        $name = (string)($data['name'] ?? '');
        $role = (($data['role'] ?? 'user') === 'admin') ? 'administrator' : 'użytkownik';
        $base = rtrim((string)Config::get('APP_URL',''), '/');
        $loginUrl = ($base !== '' ? $base : '') . '/login';
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
        $text = "Witaj $name,\n\n"
            ."W systemie DUiR (Dziennik Upadłościowy i Restrukturyzacyjny) utworzono dla Ciebie konto.\n\n"
            ."Login (e-mail): $to\nRola: $role\nAdres logowania: $loginUrl\n\n"
            ."Hasło ustalił administrator i przekaże Ci je odrębnie. Po pierwszym zalogowaniu zmień hasło w panelu „Moje konto”.\n\n"
            ."Wiadomość wygenerowana automatycznie.";
        $html = '<div style="font-family:Segoe UI,system-ui,Arial;max-width:560px;margin:0 auto;color:#1a2233">'
            .'<h2 style="color:#2448a8">Twoje konto w DUiR zostało utworzone</h2>'
            .'<p>Witaj '.$e($name).',</p>'
            .'<p>W systemie <b>DUiR</b> (Dziennik Upadłościowy i Restrukturyzacyjny) utworzono dla Ciebie konto.</p>'
            .'<table style="font-size:14px;border-collapse:collapse"><tr><td style="color:#5b6472;padding:2px 14px 2px 0">Login (e-mail)</td><td><b>'.$e($to).'</b></td></tr>'
            .'<tr><td style="color:#5b6472;padding:2px 14px 2px 0">Rola</td><td>'.$e($role).'</td></tr></table>'
            .'<p style="margin:16px 0"><a href="'.$e($loginUrl).'" style="display:inline-block;padding:10px 18px;background:#2448a8;color:#fff;text-decoration:none;border-radius:8px">Zaloguj się</a></p>'
            .'<p style="color:#5b6472;font-size:13px">Hasło ustalił administrator i przekaże Ci je odrębnie. Po pierwszym logowaniu zmień je w panelu „Moje konto”.</p></div>';
        try { (new Mailer())->send($to, 'Twoje konto w DUiR zostało utworzone', $text, null, $html); return true; }
        catch (\Throwable) { return false; }
    }

    public function update(int $id): void
    {
        try {
            $this->repo->updateUser($id, $_POST);
            Http::redirect('/users');
        } catch (\Throwable $e) {
            $this->header('Błąd zapisu użytkownika');
            echo '<section class="card"><p class="error">'.Http::e($e->getMessage()).'</p><p><a class="btn" href="/users/'.$id.'/edit">Wróć</a></p></section>';
            $this->footer();
        }
    }
}
