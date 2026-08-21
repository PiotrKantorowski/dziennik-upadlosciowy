<?php
namespace Duir\Services;

use Duir\Config;
use Duir\Support\Client;

final class LlmClient
{
    // Persona i reguły pisania raportów monitoringu. Cel: podsumowania mają być
    // przejrzyste, czytelne i wartościowe merytorycznie dla prawnika — wniosek
    // na początku, fakty ze źródeł, praktyczne zalecenia — bez dopowiadania
    // czegokolwiek ponad przekazane dane.
    private const SYSTEM_PROMPT = 'Jesteś asystentem prawnym kancelarii i piszesz podsumowania monitoringu '
        .'upadłościowo-restrukturyzacyjnego (źródła: KRZ, MSiG, KRS). Zasady: '
        .'1) Opierasz się WYŁĄCZNIE na przekazanych danych — niczego nie dopowiadasz ani nie zgadujesz; '
        .'gdy danych brakuje albo źródło zgłosiło błąd, piszesz to wprost. '
        .'2) Zaczynasz od najważniejszego wniosku: poziom ryzyka i jego główna przyczyna. '
        .'3) Potem zwięźle podajesz ustalenia z poszczególnych źródeł (z sygnaturą i datą, jeśli są). '
        .'4) Kończysz jednym–dwoma praktycznymi zaleceniami (np. co zweryfikować ręcznie w rejestrze, '
        .'jakiego terminu pilnować). '
        .'5) Piszesz prostym, rzeczowym językiem polskim; krótkie zdania; bez żargonu i pustych formułek. '
        .'6) Nie formułujesz porady prawnej ani obietnic — informujesz i wskazujesz, co sprawdzić.';

    public function __construct(private ?Client $http = null) { $this->http ??= new Client(); }

    /**
     * Test połączenia z LLM na potrzeby przycisku w Ustawieniach: w odróżnieniu od
     * summarize() NIE połyka błędu, tylko zwraca go wprost razem z użytą konfiguracją —
     * bez tego "LLM nie działa" jest niediagnozowalne z poziomu przeglądarki.
     */
    public function test(): array
    {
        $key = (string)Config::get('LLM_API_KEY','');
        $base = rtrim((string)Config::get('LLM_API_BASE','https://api.openai.com/v1'), '/');
        $model = (string)Config::get('LLM_MODEL','gpt-4o-mini');
        if (!$key) return ['ok'=>false,'message'=>"Brak klucza API — uzupełnij pole 'LLM API key'. (endpoint: $base, model: $model)"];
        try {
            $data = $this->http->postJson($base.'/chat/completions', [
                'model'=>$model,'temperature'=>0,'max_tokens'=>60,
                'messages'=>[['role'=>'user','content'=>'Odpowiedz dokładnie dwoma słowami po polsku: "połączenie działa".']],
            ], ['Authorization'=>'Bearer '.$key], 25);
            $content = trim((string)($data['choices'][0]['message']['content'] ?? ''));
            if ($content === '') return ['ok'=>false,'message'=>"Połączenie nawiązane, ale model nie zwrócił treści (endpoint: $base, model: $model). Sprawdź nazwę modelu."];
            return ['ok'=>true,'message'=>"Działa ✓ — endpoint: $base, model: $model, odpowiedź: ".mb_substr($content, 0, 160)];
        } catch (\Throwable $e) {
            return ['ok'=>false,'message'=>"Błąd wywołania LLM (endpoint: $base, model: $model): ".$e->getMessage()];
        }
    }

    public function summarize(string $prompt, string $fallback): string
    {
        $key = (string)Config::get('LLM_API_KEY','');
        if (!$key) return $fallback;
        $base = rtrim((string)Config::get('LLM_API_BASE','https://api.openai.com/v1'), '/');
        $model = (string)Config::get('LLM_MODEL','gpt-4o-mini');
        try {
            $data = $this->http->postJson($base.'/chat/completions', [
                'model'=>$model,'temperature'=>0.1,'max_tokens'=>700,
                'messages'=>[
                    ['role'=>'system','content'=>self::SYSTEM_PROMPT],
                    ['role'=>'user','content'=>$prompt],
                ],
            ], ['Authorization'=>'Bearer '.$key], 25);
            return trim((string)($data['choices'][0]['message']['content'] ?? $fallback)) ?: $fallback;
        } catch (\Throwable $e) {
            // Fallback ma być cichy dla UŻYTKOWNIKA, ale nie dla administratora —
            // bez tego logu "LLM nie działa" jest niediagnozowalne (raport wygląda
            // jak zwykła mechaniczna sklejka i nie wiadomo dlaczego).
            error_log('LlmClient: wywołanie LLM nie powiodło się (base='.$base.', model='.$model.'): '.$e->getMessage());
            return $fallback;
        }
    }
}
