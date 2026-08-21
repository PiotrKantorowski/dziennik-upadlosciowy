<?php
namespace Duir\Controllers;

use Duir\Repository;
use Duir\Support\Http;
use Duir\Support\Csrf;

final class SettingsController extends BaseController
{
    // Wyłącznie te klucze można edytować z formularza ustawień.
    // Zapobiega nadpisaniu np. LLM_API_BASE/KRS_API_BASE na adres atakującego (łańcuch SSRF).
    private const EDITABLE_KEYS = [
        'REPORT_TO'=>'Adres raportów',
        'KRZ_BRIDGE_TOKEN'=>'Token wtyczki KRZ',
        'SMTP_HOST'=>'SMTP host',
        'SMTP_PORT'=>'SMTP port',
        'SMTP_USER'=>'SMTP user',
        'SMTP_PASSWORD'=>'SMTP password',
        'SMTP_FROM'=>'SMTP from',
        'SMTP_TLS'=>'SMTP TLS',
        'LLM_API_KEY'=>'LLM API key',
        'LLM_MODEL'=>'LLM model',
        'CEIDG_API_KEY'=>'CEIDG — klucz API (potwierdzanie JDG)',
        'REGON_API_KEY'=>'REGON/GUS — klucz API',
    ];

    public function __construct(private Repository $repo) {}
    public function show(): void
    {
        $this->header('Ustawienia');
        // Wynik ostatniego testu LLM (flash z sesji) — pokazany raz, nad formularzem.
        if (!empty($_SESSION['llm_test_result'])) {
            $r = $_SESSION['llm_test_result']; unset($_SESSION['llm_test_result']);
            $cls = !empty($r['ok']) ? 'okbox' : 'error';
            echo '<p class="'.$cls.'">'.Http::e((string)($r['message'] ?? '')).'</p>';
        }
        echo '<form class="card" method="post" action="/settings">'.Csrf::field().'<div class="formgrid">';
        foreach(self::EDITABLE_KEYS as $k=>$label){$v=(string)$this->repo->setting($k,'');$masked=str_contains($k,'KEY')||str_contains($k,'TOKEN')||str_contains($k,'PASSWORD')?($v?'******':''):$v;echo '<div><label>'.$label.'</label><input name="'.$k.'" value="'.Http::e($masked).'"></div>';}
        echo '</div><p class="muted">Wpisanie ****** zachowuje dotychczasowy sekret. Po wpisaniu NOWEGO klucza LLM endpoint i model dobiorą się automatycznie (OpenAI / Google Gemini / Anthropic) i połączenie zostanie od razu przetestowane — pola „LLM model" nie musisz wypełniać.</p><button class="btn primary">Zapisz</button></form>';
        echo '<form class="card" method="post" action="/settings/test-llm">'.Csrf::field()
            .'<p class="muted">Sprawdza połączenie z modelem językowym na AKTUALNIE zapisanej konfiguracji (klucz + endpoint z .env + model). Wynik pokaże dokładny błąd, jeśli coś nie gra.</p>'
            .'<button class="btn secondary">🔌 Test połączenia z LLM</button></form>';
        $this->footer();
    }

    public function testLlm(): void
    {
        $_SESSION['llm_test_result'] = (new \Duir\Services\LlmClient())->test();
        Http::redirect('/settings');
    }

    // Rozpoznanie dostawcy LLM po FORMACIE klucza. Zwraca [endpoint, model domyślny]
    // albo null, gdy format nieznany. Endpointy pochodzą WYŁĄCZNIE z tej stałej mapy
    // (nigdy z inputu użytkownika) — whitelist EDITABLE_KEYS i ochrona przed SSRF
    // pozostają nienaruszone.
    public static function detectLlmProvider(string $key): ?array
    {
        $key = trim($key);
        if (str_starts_with($key, 'sk-ant-')) return ['https://api.anthropic.com/v1', 'claude-haiku-4-5'];
        if (str_starts_with($key, 'sk-'))     return ['https://api.openai.com/v1', 'gpt-4o-mini'];
        if (str_starts_with($key, 'AIza') || str_starts_with($key, 'AQ.')) {
            return ['https://generativelanguage.googleapis.com/v1beta/openai', 'gemini-flash-lite-latest'];
        }
        return null;
    }

    // Dla Gemini: dopytaj API o listę modeli dostępnych dla TEGO klucza i wybierz
    // najlepszy lekki model wg preferencji. Gdy sieć/format zawiedzie — zostaje default.
    private function pickGeminiModel(string $key, string $fallback): string
    {
        try {
            $data = (new \Duir\Support\Client())->getJson('https://generativelanguage.googleapis.com/v1beta/models?pageSize=100&key='.rawurlencode($key));
            $available = [];
            foreach (($data['models'] ?? []) as $m) $available[str_replace('models/','', (string)($m['name'] ?? ''))] = true;
            foreach (['gemini-flash-lite-latest','gemini-2.5-flash-lite','gemini-2.0-flash-lite','gemini-flash-latest','gemini-2.0-flash'] as $pref) {
                if (isset($available[$pref])) return $pref;
            }
        } catch (\Throwable) {}
        return $fallback;
    }

    /**
     * Auto-konfiguracja LLM po wpisaniu klucza: endpoint i model ustawiają się same
     * na podstawie formatu klucza, a połączenie jest od razu testowane. Użytkownik
     * nie musi znać nazw endpointów ani modeli — wystarczy wkleić klucz i Zapisz.
     */
    private function autoConfigureLlm(string $key): void
    {
        $detected = self::detectLlmProvider($key);
        if (!$detected) {
            $_SESSION['llm_test_result'] = ['ok'=>false,'message'=>'Nie rozpoznano dostawcy po formacie klucza — endpoint i model trzeba ustawić ręcznie (obsługiwane klucze: OpenAI "sk-...", Google Gemini "AIza..."/"AQ....", Anthropic "sk-ant-...").'];
            return;
        }
        [$base, $model] = $detected;
        if (str_contains($base, 'generativelanguage')) $model = $this->pickGeminiModel($key, $model);
        $this->repo->setSetting('LLM_API_BASE', $base);
        $this->repo->setSetting('LLM_MODEL', $model);
        \Duir\Config::set('LLM_API_BASE', $base);
        \Duir\Config::set('LLM_MODEL', $model);
        \Duir\Config::set('LLM_API_KEY', $key);
        $test = (new \Duir\Services\LlmClient())->test();
        $prefix = $test['ok'] ? 'Auto-konfiguracja LLM zakończona. ' : 'Auto-konfiguracja LLM ustawiona, ale test nie przeszedł. ';
        $_SESSION['llm_test_result'] = ['ok'=>$test['ok'], 'message'=>$prefix.(string)$test['message']];
    }
    public function save(): void
    {
        $changed = [];
        foreach (array_keys(self::EDITABLE_KEYS) as $k) {
            if (!array_key_exists($k, $_POST)) continue;
            $v = (string)$_POST[$k];
            if ($v === '******') continue;
            $this->repo->setSetting($k, $v);
            $changed[] = $k;
        }
        $this->repo->audit('settings.updated', 'settings', null, ['keys'=>$changed]);
        // Wpisano nowy klucz LLM -> endpoint i model dobierają się same (+ test od razu).
        if (in_array('LLM_API_KEY', $changed, true) && trim((string)$_POST['LLM_API_KEY']) !== '') {
            $this->autoConfigureLlm(trim((string)$_POST['LLM_API_KEY']));
        }
        Http::redirect('/settings');
    }
}
