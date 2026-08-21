<?php
use Duir\Support\Csrf;
use Duir\Config;
use Duir\Bootstrap;

// --- CSRF ---

function test_csrf_token_is_generated_and_stable(): void {
    $_SESSION = [];
    $t1 = Csrf::token();
    assert_true(strlen($t1) === 64, 'token powinien mieć 64 znaki hex (32 bajty)');
    $t2 = Csrf::token();
    assert_eq($t1, $t2, 'token powinien być stały w obrębie sesji');
}

function test_csrf_verify_accepts_valid_token(): void {
    $_SESSION = [];
    $t = Csrf::token();
    assert_true(Csrf::verify($t), 'poprawny token powinien przejść weryfikację');
}

function test_csrf_verify_rejects_wrong_token(): void {
    $_SESSION = [];
    Csrf::token();
    assert_true(!Csrf::verify('nieprawidlowy'), 'zły token odrzucony');
}

function test_csrf_verify_rejects_empty(): void {
    $_SESSION = [];
    Csrf::token();
    assert_true(!Csrf::verify(''), 'pusty token odrzucony');
}

function test_csrf_verify_rejects_when_no_session_token(): void {
    $_SESSION = [];
    assert_true(!Csrf::verify('cokolwiek'), 'brak tokenu w sesji => odrzucenie');
}

function test_csrf_field_contains_token(): void {
    $_SESSION = [];
    $t = Csrf::token();
    $html = Csrf::field();
    assert_true(str_contains($html, 'name="_csrf"'), 'pole ukryte _csrf');
    assert_true(str_contains($html, $t), 'pole zawiera aktualny token');
}

// --- Słaby token bridge (fail-closed na produkcji) ---

function test_weak_bridge_token_detects_default(): void {
    Config::set('KRZ_BRIDGE_TOKEN', 'change-me-bridge-token');
    assert_true(Bootstrap::isWeakBridgeToken(), 'domyślny placeholder jest słaby');
}

function test_weak_bridge_token_detects_empty(): void {
    Config::set('KRZ_BRIDGE_TOKEN', '');
    assert_true(Bootstrap::isWeakBridgeToken(), 'pusty token jest słaby');
}

function test_weak_bridge_token_detects_short(): void {
    Config::set('KRZ_BRIDGE_TOKEN', 'short123');
    assert_true(Bootstrap::isWeakBridgeToken(), 'token < 16 znaków jest słaby');
}

function test_strong_bridge_token_accepted(): void {
    Config::set('KRZ_BRIDGE_TOKEN', bin2hex(random_bytes(24)));
    assert_true(!Bootstrap::isWeakBridgeToken(), 'losowy długi token nie jest słaby');
}

// --- password_hash / password_verify (mechanizm logowania) ---

function test_password_hash_roundtrip(): void {
    $hash = password_hash('Tajne-Haslo-1', PASSWORD_DEFAULT);
    assert_true(password_verify('Tajne-Haslo-1', $hash), 'poprawne hasło weryfikuje się');
    assert_true(!password_verify('zle', $hash), 'złe hasło odrzucone');
}

// Auto-konfiguracja LLM: dostawca rozpoznawany po FORMACIE klucza, endpointy tylko
// ze stałej mapy (SSRF-safe). Nieznany format -> null (ustawienia ręczne).
function test_llm_provider_detected_from_key_format(): void {
    [$base,$model] = \Duir\Controllers\SettingsController::detectLlmProvider('AQ.Ab8-testowy-klucz');
    assert_true(str_contains($base, 'generativelanguage.googleapis.com'), 'klucz AQ. => Gemini');
    [$base2,] = \Duir\Controllers\SettingsController::detectLlmProvider('AIzaSyTestowy');
    assert_true(str_contains($base2, 'generativelanguage.googleapis.com'), 'klucz AIza => Gemini');
    [$base3,$model3] = \Duir\Controllers\SettingsController::detectLlmProvider('sk-proj-testowy');
    assert_true(str_contains($base3, 'api.openai.com'), 'klucz sk- => OpenAI');
    [$base4,] = \Duir\Controllers\SettingsController::detectLlmProvider('sk-ant-testowy');
    assert_true(str_contains($base4, 'api.anthropic.com'), 'klucz sk-ant- => Anthropic');
    assert_true(\Duir\Controllers\SettingsController::detectLlmProvider('xyz-123') === null, 'nieznany format => null');
}
