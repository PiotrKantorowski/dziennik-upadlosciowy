<?php
namespace Duir\Controllers;

use Duir\Support\Http;
use Duir\Support\Normalize;
use Duir\Support\Csrf;

abstract class BaseController
{
    protected function header(string $title): void
    {
        $isAuth = ($_SESSION['auth'] ?? false) === true;
        $isAdmin = (($_SESSION['user_role'] ?? '') === 'admin');
        $user = $isAuth ? '<span class="nav-user">'.Http::e($_SESSION['user_name'] ?? $_SESSION['user_email'] ?? 'użytkownik').'</span>' : '';
        $logout = $isAuth
            ? '<form method="post" action="/logout" class="nav-logout">'.Csrf::field().'<button class="btn">Wyloguj</button></form>'
            : '';
        $adminLinks = $isAdmin ? '<a class="btn" href="/settings">Ustawienia</a><a class="btn" href="/users">Użytkownicy</a>' : '';
        $accountLink = $isAuth ? '<a class="btn" href="/account">Moje konto</a>' : '';
        echo '<!doctype html><html lang="pl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.Http::e($title).'</title><link rel="stylesheet" href="/assets/app.css"><body><div class="wrap"><header class="top"><div><h1>'.Http::e($title).'</h1><p class="muted">Dziennik Upadłościowy i Restrukturyzacyjny</p></div><nav><a class="btn" href="/">Podmioty</a><a class="btn" href="/reports/daily">Raport dzienny</a>'.$adminLinks.$accountLink.$user.$logout.'</nav></header>';
    }
    protected function footer(): void { echo '</div></body></html>'; }
    protected function riskChip(string $risk): string
    {
        $class = preg_replace('/[^a-z0-9_-]+/i', '-', Normalize::fold($risk));
        return '<span class="chip risk-'.Http::e($class).'">ryzyko: '.Http::e($risk).'</span>';
    }
    protected function sourceBadge(?array $check): string
    {
        if (!$check) return '<span class="chip muted">brak sprawdzenia</span>';
        $status = (string)($check['status'] ?? '');
        // Kody techniczne (success/error/running...) tłumaczymy na polski — klasa CSS
        // zostaje oparta o kod, więc kolory działają bez zmian.
        $labels = ['success'=>'sprawdzono','error'=>'błąd','running'=>'w trakcie','pending'=>'oczekuje','no_results'=>'brak wyników'];
        $class = preg_replace('/[^a-z0-9_-]+/i', '-', Normalize::fold($status));
        return '<span class="chip status-'.Http::e($class).'">'.Http::e($labels[$status] ?? $status).'</span>';
    }
}
