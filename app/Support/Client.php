<?php
namespace Duir\Support;

final class Client
{
    // Ponawiamy WYŁĄCZNIE awarie, które kolejna próba może naprawić: brak połączenia
    // (DNS/TCP/TLS) i przeciążenie źródła (5xx/429). Błąd 4xx (poza 429) to odpowiedź
    // serwera na konkretne zapytanie — ponowienie da dokładnie ten sam wynik.
    private const MAX_ATTEMPTS = 3;
    private const RETRY_DELAY_MS = [400, 900];

    public function getJson(string $url, array $headers = [], int $timeout = 15): array
    {
        $raw = $this->request('GET', $url, $headers, $timeout, null);
        $data = json_decode($raw, true);
        if (!is_array($data)) throw new \RuntimeException('Źródło nie zwróciło poprawnego JSON: '.$url);
        return $data;
    }

    public function postJson(string $url, array $payload, array $headers = [], int $timeout = 20): array
    {
        $raw = $this->request('POST', $url, $headers, $timeout, json_encode($payload, JSON_UNESCAPED_UNICODE));
        $data = json_decode($raw, true);
        return is_array($data) ? $data : ['raw'=>$raw];
    }

    private function request(string $method, string $url, array $headers, int $timeout, ?string $body): string
    {
        // Brak cURL na jakimś SAPI współdzielonego hostingu byłby zaskoczeniem, ale
        // gdyby wystąpił, wolimy zdegradować się do starej ścieżki niż wyłączyć
        // wszystkie zewnętrzne źródła (KRS/CEIDG/Biała Lista) naraz.
        if (!function_exists('curl_init')) return $this->requestViaStreams($method, $url, $headers, $timeout, $body);

        $ctxHeaders = ['Accept: application/json', 'User-Agent: DUiR-PHP-MySQL/1.0'];
        if ($body !== null) $ctxHeaders[] = 'Content-Type: application/json';
        foreach ($headers as $k => $v) $ctxHeaders[] = is_int($k) ? $v : "$k: $v";

        $lastError = null;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $ctxHeaders,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
            ]);
            if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            // curl_close() usunięte: od PHP 8.0 nic nie robi, a od 8.5 zgłasza Deprecated (uchwyt zwalnia się sam).

            // CURLE_UNSUPPORTED_PROTOCOL(1)/CURLE_URL_MALFORMAT(3) to wada samego adresu
            // (literówka w konfiguracji, zły schemat) — kolejna próba da identyczny wynik.
            if (in_array($errno, [1, 3], true)) {
                throw new \RuntimeException("Nieprawidłowy adres URL (curl $errno: $error): $url");
            }
            if ($errno !== 0) {
                // Odmowa połączenia, DNS, TLS, timeout — dokładnie ta klasa awarii,
                // która wcześniej ginęła jako gołe "Nie udało się pobrać URL".
                $lastError = "Błąd połączenia (curl $errno: $error): $url";
            } elseif ($status >= 500 || $status === 429) {
                $lastError = "Źródło zwróciło błąd HTTP $status: $url";
            } elseif ($status < 200 || $status >= 300) {
                throw new \RuntimeException("Źródło zwróciło błąd HTTP $status: $url");
            } else {
                return $raw === false ? '' : $raw;
            }
            if ($attempt < self::MAX_ATTEMPTS) usleep((self::RETRY_DELAY_MS[$attempt - 1] ?? 1000) * 1000);
        }
        throw new \RuntimeException($lastError ?? ('Nie udało się pobrać URL: '.$url));
    }

    private function requestViaStreams(string $method, string $url, array $headers, int $timeout, ?string $body): string
    {
        $ctxHeaders = ['Accept: application/json', 'User-Agent: DUiR-PHP-MySQL/1.0'];
        if ($body !== null) $ctxHeaders[] = 'Content-Type: application/json';
        foreach ($headers as $k => $v) $ctxHeaders[] = is_int($k) ? $v : "$k: $v";
        $opts = ['method'=>$method, 'timeout'=>$timeout, 'ignore_errors'=>true, 'header'=>implode("\r\n", $ctxHeaders)];
        if ($body !== null) $opts['content'] = $body;
        $ctx = stream_context_create(['http' => $opts]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) throw new \RuntimeException('Nie udało się pobrać URL: '.$url);
        $code = null;
        foreach (($http_response_header ?? []) as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) $code = (int)$m[1];
        }
        if ($code !== null && ($code < 200 || $code >= 300)) throw new \RuntimeException("Źródło zwróciło błąd HTTP $code: $url");
        return $raw;
    }
}
