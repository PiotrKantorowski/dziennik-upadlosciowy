<?php
namespace Duir\Support;

final class Client
{
    public function getJson(string $url, array $headers = [], int $timeout = 15): array
    {
        $ctxHeaders = ["Accept: application/json", "User-Agent: DUiR-PHP-MySQL/1.0"];
        foreach ($headers as $k => $v) $ctxHeaders[] = is_int($k) ? $v : "$k: $v";
        $ctx = stream_context_create(['http' => ['method'=>'GET', 'timeout'=>$timeout, 'ignore_errors'=>true, 'header'=>implode("\r\n", $ctxHeaders)]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) throw new \RuntimeException('Nie udało się pobrać URL: '.$url);
        $this->assertSuccessStatus($http_response_header ?? [], $url);
        $data = json_decode($raw, true);
        if (!is_array($data)) throw new \RuntimeException('Źródło nie zwróciło JSON: '.$url);
        return $data;
    }

    public function postJson(string $url, array $payload, array $headers = [], int $timeout = 20): array
    {
        $ctxHeaders = ["Accept: application/json", "Content-Type: application/json", "User-Agent: DUiR-PHP-MySQL/1.0"];
        foreach ($headers as $k => $v) $ctxHeaders[] = is_int($k) ? $v : "$k: $v";
        $ctx = stream_context_create(['http' => ['method'=>'POST', 'timeout'=>$timeout, 'ignore_errors'=>true, 'header'=>implode("\r\n", $ctxHeaders), 'content'=>json_encode($payload, JSON_UNESCAPED_UNICODE)]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) throw new \RuntimeException('Nie udało się wysłać URL: '.$url);
        $this->assertSuccessStatus($http_response_header ?? [], $url);
        $data = json_decode($raw, true);
        return is_array($data) ? $data : ['raw'=>$raw];
    }

    private function assertSuccessStatus(array $headers, string $url): void
    {
        $code = null;
        foreach ($headers as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) $code = (int)$m[1];
        }
        if ($code !== null && ($code < 200 || $code >= 300)) {
            throw new \RuntimeException("Źródło zwróciło błąd HTTP $code: $url");
        }
    }
}
