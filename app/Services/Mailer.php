<?php
namespace Duir\Services;

use Duir\Config;

final class Mailer
{
    public function send(string $to, string $subject, string $body, ?string $attachmentPath = null, ?string $htmlBody = null): void
    {
        $toList = $this->recipients($to);
        if (!$toList) throw new \RuntimeException('Brak odbiorcy e-mail.');
        $host = (string)Config::get('SMTP_HOST','');
        if ($host) { $this->sendSmtp($toList, $subject, $body, $attachmentPath, $htmlBody); return; }
        $headers = "From: ".self::sanitizeHeader((string)Config::get('SMTP_FROM','duir@localhost'))."\r\n";
        $safeSubject = self::sanitizeHeader($subject);
        $msg = $this->buildMimeMessage($body, $htmlBody, $attachmentPath, $headers);
        foreach ($toList as $rcpt) if (!@mail($rcpt, $safeSubject, $msg['body'], $msg['headers'])) throw new \RuntimeException('Funkcja mail() nie wysłała wiadomości.');
    }

    public static function sanitizeHeader(string $s): string { return str_replace(["\r", "\n"], '', $s); }

    // Treść e-maila prowadzi PODSUMOWANIE LLM (czytelna narracja: wniosek,
    // ustalenia, zalecenia) — mechaniczne pola są zredukowane do krótkiego
    // nagłówka. Markdown z LLM jest zdejmowany (e-mail to czysty tekst).
    public function buildSubjectBody(array $subject, string $risk, array $events, string $summary): string
    {
        $lines = [];
        $lines[] = 'Podmiot: '.($subject['name'] ?? '');
        $lines[] = 'Stopień ryzyka: '.$risk.' | Zdarzenia: '.count($events);
        $lines[] = str_repeat('-', 46);
        $summary = trim($summary);
        if ($summary !== '') {
            $lines[] = ReportService::llmTextToPlain($summary);
        } else {
            $main = $this->mainReason($events);
            $lines[] = 'Najważniejszy powód: '.($main ?: 'Brak zdarzeń wysokiego lub średniego ryzyka; sprawdź status źródeł w aplikacji DUiR.');
        }
        $lines[] = '';
        $lines[] = 'Pełny raport (KRZ, MSiG, KRS) znajduje się w treści tej wiadomości (wersja HTML).';
        return implode("\n", $lines);
    }

    // Tekstowa wersja raportu dziennego — PER PODMIOT (spójnie z wersją HTML):
    // sekcja na każdy podmiot z nowymi wpisami, a zbiorczo wyłącznie stan
    // monitoringu (kogo sprawdzono / kogo nie udało się sprawdzić).
    public function buildDailyBody(array $report): string
    {
        $events = $report['events'] ?? [];
        $lines = ['Raport dzienny DUiR — '.date('Y-m-d')];
        if (!$events) {
            $lines[] = 'Brak nowych wpisów w ostatnich 24 godzinach.';
        } else {
            $lines[] = 'Nowe wpisy: '.count($events).' | Najwyższy stopień ryzyka: '.($report['risk'] ?? 'niski');
            $bySubject = [];
            foreach ($events as $e) $bySubject[(string)($e['subject_name'] ?? '—')][] = $e;
            foreach ($bySubject as $name => $list) {
                $lines[] = '';
                $lines[] = mb_strtoupper(mb_substr($name, 0, 80, 'UTF-8'), 'UTF-8');
                $lines[] = str_repeat('-', 46);
                foreach ($list as $e) {
                    $when = ($e['publication_date'] ?? '') ?: mb_substr((string)($e['created_at'] ?? ''), 0, 10);
                    $lines[] = '- ['.($e['source'] ?? '').'] '.($e['title'] ?? '').' (ryzyko: '.($e['risk'] ?? '').($when !== '' ? ', wpis: '.$when : '').')';
                }
            }
        }
        $failures = ReportService::monitoringFailures($report['monitoring'] ?? []);
        if ($failures) {
            $lines[] = '';
            $lines[] = 'NIE UDAŁO SIĘ W PEŁNI SPRAWDZIĆ:';
            foreach ($failures as $f) $lines[] = '- '.$f['name'].': '.implode('; ', $f['problems']);
        } elseif ($report['monitoring'] ?? []) {
            $lines[] = '';
            $lines[] = 'Monitoring wykonany dla wszystkich '.count($report['monitoring']).' podmiotów.';
        }
        $lines[] = '';
        $lines[] = 'Pełny raport znajduje się w treści tej wiadomości (wersja HTML).';
        return implode("\n", $lines);
    }

    public function recipients(string $value): array
    {
        $parts = preg_split('/[;,\s]+/', $value) ?: [];
        return array_values(array_filter(array_map('trim', $parts), fn($x)=>filter_var($x, FILTER_VALIDATE_EMAIL)));
    }

    private function mainReason(array $events): string
    {
        $rank = ['krytyczny'=>4,'wysoki'=>3,'średni'=>2,'niski'=>1];
        usort($events, fn($a,$b)=>(($rank[$b['risk']??'niski']??1)<=>($rank[$a['risk']??'niski']??1)));
        $e = $events[0] ?? null;
        return $e ? (($e['source'] ?? '').': '.($e['risk_reason'] ?? $e['title'] ?? '')) : '';
    }

    /**
     * Składa wiadomość MIME: tekst + opcjonalna wersja HTML (multipart/alternative,
     * klienci poczty pokażą ładny raport-stronę zamiast surowego tekstu) +
     * opcjonalny załącznik PDF (multipart/mixed).
     */
    private function buildMimeMessage(string $textBody, ?string $htmlBody, ?string $attachmentPath, string $headers): array
    {
        $bodyPart = "Content-Type: text/plain; charset=utf-8\r\n\r\n$textBody";
        $bodyContentType = 'text/plain; charset=utf-8';
        if ($htmlBody !== null && trim($htmlBody) !== '') {
            $alt = 'ALT_'.bin2hex(random_bytes(8));
            $bodyContentType = "multipart/alternative; boundary=\"$alt\"";
            $bodyPart = "Content-Type: $bodyContentType\r\n\r\n"
                ."--$alt\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n$textBody\r\n"
                ."--$alt\r\nContent-Type: text/html; charset=utf-8\r\n\r\n$htmlBody\r\n"
                ."--$alt--";
        }
        if ($attachmentPath && is_file($attachmentPath)) {
            $boundary = 'DUiR_'.bin2hex(random_bytes(8));
            $headers .= "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$boundary\"\r\n";
            $msg = "--$boundary\r\n$bodyPart\r\n";
            $msg .= "--$boundary\r\nContent-Type: application/pdf; name=\"raport.pdf\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"raport.pdf\"\r\n\r\n".chunk_split(base64_encode(file_get_contents($attachmentPath)))."\r\n--$boundary--";
            return ['headers'=>$headers,'body'=>$msg];
        }
        if ($htmlBody !== null && trim($htmlBody) !== '') {
            $headers .= "MIME-Version: 1.0\r\nContent-Type: $bodyContentType\r\n";
            return ['headers'=>$headers,'body'=>substr($bodyPart, strpos($bodyPart, "\r\n\r\n") + 4)];
        }
        $headers .= "Content-Type: text/plain; charset=utf-8\r\n";
        return ['headers'=>$headers,'body'=>$textBody];
    }

    private function sendSmtp(array $toList, string $subject, string $body, ?string $attachmentPath, ?string $htmlBody = null): void
    {
        $host = (string)Config::get('SMTP_HOST'); $port = (int)Config::get('SMTP_PORT',587); $tls = filter_var(Config::get('SMTP_TLS','1'), FILTER_VALIDATE_BOOL);
        $user = (string)Config::get('SMTP_USER',''); $pass = (string)Config::get('SMTP_PASSWORD','');
        if (($user && !$pass) || ($pass && !$user)) throw new \RuntimeException('Niepełna konfiguracja SMTP: login i hasło muszą być podane razem.');
        $remote = ($port === 465 ? 'ssl://' : '').$host.':'.$port;
        $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
        if (!$fp) throw new \RuntimeException("SMTP connect failed: $errstr");
        // Czyta pełną (także wieloliniową, np. EHLO "250-...") odpowiedź serwera
        // i zwraca jej ostatnią linię z kodem statusu.
        $read = function() use ($fp): string {
            do { $line = fgets($fp, 2048) ?: ''; } while ($line !== '' && strlen($line) >= 4 && $line[3] === '-');
            return $line;
        };
        // Każda odpowiedź SMTP jest sprawdzana. Wcześniej odpowiedzi były ignorowane,
        // więc odrzucona wysyłka (błędny login, odrzucony odbiorca) wyglądała jak sukces
        // i trafiała do outgoing_mail ze statusem "sent".
        $expect = function(string $resp, array $codes, string $step): void {
            $code = (int)substr($resp, 0, 3);
            if (!in_array($code, $codes, true)) throw new \RuntimeException("SMTP $step: nieoczekiwana odpowiedź serwera: ".trim($resp));
        };
        $cmd = function(string $c) use ($fp,$read) { fwrite($fp, $c."\r\n"); return $read(); };
        $expect($read(), [220], 'connect');
        $expect($cmd('EHLO localhost'), [250], 'EHLO');
        if ($tls && $port !== 465) {
            $expect($cmd('STARTTLS'), [220], 'STARTTLS');
            // Bez potwierdzonego szyfrowania NIE wolno wysłać AUTH LOGIN — dane logowania
            // poszłyby czystym tekstem.
            if (stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                fclose($fp);
                throw new \RuntimeException('SMTP STARTTLS: negocjacja szyfrowania nie powiodła się — wysyłka przerwana.');
            }
            $expect($cmd('EHLO localhost'), [250], 'EHLO po STARTTLS');
        }
        if ($user) {
            $expect($cmd('AUTH LOGIN'), [334], 'AUTH LOGIN');
            $expect($cmd(base64_encode($user)), [334], 'AUTH LOGIN (login)');
            $expect($cmd(base64_encode($pass)), [235], 'AUTH LOGIN (hasło)');
        }
        $from = (string)(Config::get('SMTP_FROM','') ?: $user);
        if (!$from) throw new \RuntimeException('Brak nadawcy SMTP_FROM.');
        $expect($cmd('MAIL FROM:<'.$from.'>'), [250], 'MAIL FROM');
        foreach ($toList as $r) $expect($cmd('RCPT TO:<'.$r.'>'), [250, 251], 'RCPT TO <'.$r.'>');
        $expect($cmd('DATA'), [354], 'DATA');
        $headers = "From: $from\r\nTo: ".implode(', ',$toList)."\r\nSubject: ".$this->encodeHeader($subject)."\r\n";
        $msg = $this->buildMimeMessage($body, $htmlBody, $attachmentPath, $headers);
        fwrite($fp, $msg['headers'].$msg['body']."\r\n.\r\n");
        $expect($read(), [250], 'zakończenie DATA');
        $cmd('QUIT'); fclose($fp);
    }

    private function encodeHeader(string $s): string { return '=?UTF-8?B?'.base64_encode($s).'?='; }
}
