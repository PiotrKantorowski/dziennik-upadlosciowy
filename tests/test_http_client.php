<?php
use Duir\Support\Client;
use Duir\Services\KrsClient;

// Regresja 2026-08-31 (user: awarie KRS/CEIDG na produkcji dawały identyczny, ślepy
// komunikat "Nie udało się pobrać URL" niezależnie od przyczyny — brak połączenia,
// błąd HTTP i uszkodzony JSON wyglądały tak samo). ROOT CAUSE #1: Client::getJson()
// używał file_get_contents bez żadnego ponowienia — jedno mignięcie sieci (DNS/TCP/TLS)
// kończyło zapytanie od razu, mimo że powtórzenie za chwilę mogłoby się udać.
function test_http_client_retries_transport_failures_with_backoff(): void {
    $client = new Client();
    // Port 1 na localhost jest zarezerwowany (TCPMUX) i nikt tam nie nasłuchuje —
    // połączenie jest odrzucane NATYCHMIAST, bez sieci/DNS, więc test jest szybki
    // i deterministyczny, a mimo to realnie ćwiczy pełną ścieżkę retry+backoff.
    $start = microtime(true);
    $threw = false;
    try { $client->getJson('http://127.0.0.1:1/nieistniejacy-port'); }
    catch (\RuntimeException $e) {
        $threw = true;
        assert_true(str_contains($e->getMessage(), 'Błąd połączenia'), 'komunikat musi nazywać przyczynę (curl errno+opis), nie być ślepy');
    }
    assert_true($threw, 'odmowa połączenia musi w końcu rzucić wyjątek, nie zwrócić cicho pustego wyniku');
    $elapsedMs = (microtime(true) - $start) * 1000;
    // 2 odstępy między 3 próbami: 400ms + 900ms = 1300ms minimum.
    assert_true($elapsedMs >= 1200, 'muszą wystąpić realne ponowienia z odstępem (3 próby), a nie jedna próba: '.round($elapsedMs).'ms');
}

// Adres z nieobsługiwanym schematem/literówką w konfiguracji to wada URL, nie sieci —
// ponowienie dałoby identyczny wynik, więc MUSI kończyć się od razu (bez 1300ms zwłoki).
function test_http_client_does_not_retry_malformed_url(): void {
    $client = new Client();
    $start = microtime(true);
    $threw = false;
    try { $client->getJson('duir-invalid-scheme://offline'); }
    catch (\RuntimeException $e) { $threw = true; }
    assert_true($threw, 'nieobsługiwany schemat URL musi rzucić wyjątek');
    $elapsedMs = (microtime(true) - $start) * 1000;
    assert_true($elapsedMs < 500, 'błąd URL (nie sieci) nie może czekać na ponowienia: '.round($elapsedMs).'ms');
}

// ROOT CAUSE #2: KrsClient::safeGet() łapał KAŻDY \Throwable i zwracał null — więc
// fetchProfile() przy podwójnej awarii (OdpisAktualny + OdpisPelny) nie miał czym
// wytłumaczyć przyczyny; karta podmiotu ("🔍 Diagnoza") pokazywała pustkę.
function test_krs_client_preserves_fetch_error_detail_on_total_failure(): void {
    \Duir\Config::set('KRS_API_BASE', 'http://127.0.0.1:1/api-krs-offline');
    $profile = (new KrsClient())->fetchProfile(['krs'=>'0000186267']);
    assert_eq('error', $profile['status'] ?? null, 'podwójna awaria API KRS musi dać status error');
    $errors = $profile['raw_json']['fetch_errors'] ?? null;
    assert_true(is_array($errors) && count($errors) === 2, 'oba wywołania (OdpisAktualny + OdpisPelny) muszą zostawić własny ślad błędu');
    foreach ($errors as $err) {
        assert_true(str_contains($err, 'Błąd połączenia'), 'przyczyna musi być zapisana czytelnie, nie zgubiona jako null: '.$err);
    }
}
