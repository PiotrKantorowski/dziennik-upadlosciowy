<?php
namespace Duir\Services;

use Duir\Support\Normalize;

final class PdfReport
{
    public function subjectPdf(array $subject, array $events, string $risk, string $summary = ''): string
    {
        $lines = [];
        $lines[] = 'Raport DUiR';
        $lines[] = 'Podmiot: '.($subject['name'] ?? '');
        foreach (['krs'=>'KRS','nip'=>'NIP','regon'=>'REGON','pesel'=>'PESEL'] as $k=>$label) if (!empty($subject[$k])) $lines[] = "$label: {$subject[$k]}";
        $lines[] = 'Ocena ryzyka: '.$risk;
        if ($summary) { $lines[] = ''; $lines[] = 'Podsumowanie:'; foreach ($this->wrap($this->ascii($this->plainMarkdown($summary)), 100) as $l) $lines[] = $l; }
        $lines[] = 'Wygenerowano: '.date('Y-m-d H:i');
        if (!$events) {
            $lines[] = '';
            $lines[] = 'Brak zdarzeń. Upewnij się, że źródła odpowiedziały poprawnie.';
            return $this->minimalPdf($lines);
        }
        // Raport ma odpowiadać na pytanie "jaka jest sytuacja TERAZ", a nie streszczać
        // całą historię podmiotu: sekcja SYTUACJA AKTUALNA zawiera najnowszy wpis
        // z każdego źródła (pełna, oczyszczona treść), a wszystkie starsze wpisy lądują
        // w HISTORII jako pojedyncze linie (data | tytuł | sygnatura | ryzyko).
        $current = []; $history = [];
        foreach ($events as $e) {
            $src = (string)($e['source'] ?? '');
            if (!isset($current[$src])) $current[$src] = $e; else $history[] = $e;
        }
        $lines[] = '';
        $lines[] = '=== SYTUACJA AKTUALNA ===';
        foreach ($current as $src => $e) {
            $lines[] = '';
            $lines[] = '['.$src.'] '.($e['title'] ?? 'Informacja');
            $meta = ['Ryzyko: '.($e['risk'] ?? '')];
            if (!empty($e['publication_date'])) $meta[] = 'Data wpisu: '.$e['publication_date'];
            if (!empty($e['created_at'])) $meta[] = 'Sprawdzono: '.mb_substr((string)$e['created_at'],0,16);
            if (!empty($e['signature'])) $meta[] = 'Sygnatura: '.$e['signature'];
            $lines[] = implode(' | ', $meta);
            if (!empty($e['risk_reason'])) $lines[] = 'Dlaczego istotne: '.$e['risk_reason'];
            $desc = RiskAnalyzer::tidyPortalText((string)($e['description'] ?? ''));
            if (($e['source'] ?? '') === 'MSIG') $desc = self::msigEssence($desc, (string)($subject['name'] ?? ''));
            $desc = mb_substr($desc, 0, 1200, 'UTF-8');
            if ($desc !== '') foreach ($this->wrap($this->ascii($desc), 100) as $l) $lines[] = $l;
        }
        if ($history) {
            $lines[] = '';
            $lines[] = '=== HISTORIA (starsze wpisy: '.count($history).') ===';
            foreach ($history as $e) {
                $row = (($e['publication_date'] ?? '') ?: '----------').' | ['.($e['source'] ?? '').'] '.($e['title'] ?? 'Informacja');
                if (!empty($e['signature'])) $row .= ' | '.$e['signature'];
                $row .= ' | ryzyko: '.($e['risk'] ?? '');
                $lines[] = $row;
            }
            $lines[] = '';
            $lines[] = 'Pelne tresci starszych wpisow sa dostepne w karcie podmiotu w aplikacji.';
        }
        return $this->minimalPdf($lines);
    }

    /**
     * Zostawia z przechwyconych metadanych MSiG tylko treść merytoryczną: nagłówek
     * pozycji (sąd, sygn. akt, data wpisu) i właściwą treść ogłoszenia. Pola techniczne
     * portalu (numer ogłoszenia/strony/monitora, rozdział — jest już w tytule zdarzenia)
     * oraz powtórzoną nazwę podmiotu (jest w nagłówku raportu) pomijamy.
     */
    public static function msigEssence(string $desc, string $subjectName): string
    {
        $foldName = Normalize::fold($subjectName);
        $keep = [];
        foreach (preg_split('/\R/u', $desc) ?: [] as $line) {
            // Sieroty interfejsu ("x") doklejone na końcu linii oraz białe znaki — precz,
            // ZANIM porównamy linię z nazwą podmiotu (inaczej "NAZWA x" nie zostanie
            // rozpoznana jako powtórzona nazwa).
            $t = trim(preg_replace('/\s+[x×]\s*$/iu', '', trim($line)) ?? trim($line));
            if ($t === '' || preg_match('/^[x×]$/iu', $t)) continue;
            if (preg_match('/^(Numer og[łl]oszenia|Numer strony|Nr monitora|Data publikacji|Rozdzia[łl]\/nazwa|KRS:|NIP:?\s*$|Sygnatura sprawy:?\s*$)/iu', $t)) continue;
            // Powtórzona nazwa podmiotu — w obu kierunkach ("NAZWA" zawiera linię
            // albo linia to "NAZWA w Rzeszowie." zawierająca nazwę).
            if ($foldName !== '') {
                $foldLine = Normalize::fold($t);
                if (str_contains($foldName, $foldLine) || str_contains($foldLine, $foldName)) {
                    // ...chyba że to linia "Poz. ..." — ona niesie sąd i sygn. akt.
                    if (!preg_match('/^Poz\.\s*\d+/u', $t)) continue;
                }
            }
            if (preg_match('/^Tre[śs][ćc] nag[łl][óo]wka:\s*(.+)$/iu', $t, $m)) { $keep[] = $m[1]; continue; }
            $keep[] = $t;
        }
        // Linia "Poz. NNN. ..." z nagłówka pozycji MSiG zawiera KOMPLET sedna
        // (pozycja, sąd, wydział, sygn. akt) — jeśli jest, wystarcza za cały opis.
        foreach ($keep as $line) {
            if (preg_match('/^Poz\.\s*\d+/u', $line)) return $line;
        }
        return trim(implode("\n", array_values(array_unique($keep))));
    }

    // Zamienia lekki markdown z odpowiedzi LLM na czysty tekst do PDF.
    private function plainMarkdown(string $s): string
    {
        return ReportService::llmTextToPlain($s);
    }

    public function saveSubjectPdf(string $dir, array $subject, array $events, string $risk, string $summary = ''): string
    {
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $name = 'raport_'.Normalize::safeFilename($subject['name'] ?? 'podmiot').'_'.date('Ymd_His').'.pdf';
        $path = rtrim($dir,'/').'/'.$name;
        file_put_contents($path, $this->subjectPdf($subject,$events,$risk,$summary));
        return $path;
    }

    private function minimalPdf(array $lines): string
    {
        $pages = array_chunk($this->prepareLines($lines), 58);
        if (!$pages) $pages = [[]];
        $objects = [];
        $objects[] = null; // catalog
        $objects[] = null; // pages
        $fontObjNo = 3;
        $objects[] = "3 0 obj<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>endobj\n";
        $pageObjectNos = [];
        $contentObjectNos = [];
        $next = 4;
        foreach ($pages as $pageLines) {
            $pageObjectNos[] = $next++;
            $contentObjectNos[] = $next++;
        }
        foreach ($pages as $idx => $pageLines) {
            $content = "BT\n/F1 10 Tf\n50 800 Td\n";
            foreach ($pageLines as $line) $content .= '(' . $this->escape($line) . ") Tj\n0 -13 Td\n";
            $content .= "ET";
            $pageNo = $pageObjectNos[$idx];
            $contNo = $contentObjectNos[$idx];
            $objects[$pageNo-1] = "$pageNo 0 obj<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 $fontObjNo 0 R >> >> /Contents $contNo 0 R >>endobj\n";
            $objects[$contNo-1] = "$contNo 0 obj<< /Length ".strlen($content)." >>stream\n$content\nendstream endobj\n";
        }
        $kids = implode(' ', array_map(fn($n)=>$n.' 0 R', $pageObjectNos));
        $objects[0] = "1 0 obj<< /Type /Catalog /Pages 2 0 R >>endobj\n";
        $objects[1] = "2 0 obj<< /Type /Pages /Kids [$kids] /Count ".count($pageObjectNos)." >>endobj\n";
        ksort($objects);
        $pdf = "%PDF-1.4\n"; $offsets = [0];
        foreach ($objects as $obj) { $offsets[] = strlen($pdf); $pdf .= $obj; }
        $xref = strlen($pdf); $pdf .= "xref\n0 ".(count($objects)+1)."\n0000000000 65535 f \n";
        for ($i=1;$i<=count($objects);$i++) $pdf .= str_pad((string)$offsets[$i],10,'0',STR_PAD_LEFT)." 00000 n \n";
        $pdf .= "trailer<< /Size ".(count($objects)+1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
        return $pdf;
    }

    private function prepareLines(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) foreach ($this->wrap($this->ascii((string)$line), 105) as $chunk) $out[] = $chunk;
        return $out;
    }

    private function ascii(string $s): string
    {
        $map = [
            // polskie
            'ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ż'=>'z','ź'=>'z',
            'Ą'=>'A','Ć'=>'C','Ę'=>'E','Ł'=>'L','Ń'=>'N','Ó'=>'O','Ś'=>'S','Ż'=>'Z','Ź'=>'Z',
            // niemieckie
            'ä'=>'a','ö'=>'o','ü'=>'u','Ä'=>'A','Ö'=>'O','Ü'=>'U','ß'=>'ss',
            // francuskie / hiszpańskie / włoskie / portugalskie
            'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','å'=>'a','æ'=>'ae',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
            'ò'=>'o','ô'=>'o','õ'=>'o','ø'=>'o','œ'=>'oe',
            'ú'=>'u','ù'=>'u','û'=>'u',
            'ç'=>'c','ñ'=>'n','ý'=>'y','ÿ'=>'y',
            'Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','Å'=>'A','Æ'=>'AE',
            'É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E',
            'Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I',
            'Ò'=>'O','Ô'=>'O','Õ'=>'O','Ø'=>'O','Œ'=>'OE',
            'Ú'=>'U','Ù'=>'U','Û'=>'U',
            'Ç'=>'C','Ñ'=>'N','Ý'=>'Y',
            // czeskie / słowackie
            'č'=>'c','ř'=>'r','š'=>'s','ž'=>'z','ď'=>'d','ť'=>'t','ň'=>'n','ě'=>'e','ů'=>'u',
            'Č'=>'C','Ř'=>'R','Š'=>'S','Ž'=>'Z','Ď'=>'D','Ť'=>'T','Ň'=>'N','Ě'=>'E','Ů'=>'U',
            // litewskie / łotewskie / estońskie (najczęstsze)
            'ā'=>'a','ē'=>'e','ī'=>'i','ū'=>'u','ų'=>'u','į'=>'i','ė'=>'e','ģ'=>'g','ķ'=>'k','ļ'=>'l',
            'Ā'=>'A','Ē'=>'E','Ī'=>'I','Ū'=>'U','Ų'=>'U','Į'=>'I','Ė'=>'E',
            // typografia
            '€'=>'EUR','…'=>'...',
            '—'=>'-','–'=>'-','−'=>'-',
            '„'=>'"','”'=>'"','“'=>'"','‟'=>'"','«'=>'"','»'=>'"',
            '‚'=>"'",'‛'=>"'",'‘'=>"'",'’'=>"'",'‹'=>"'",'›'=>"'",
            "\u{00A0}"=>' ',
        ];
        $out = strtr($s, $map);
        // Ostatnia linia obrony: przetransliteruj/odrzuć wszystko, co nadal nie jest ASCII,
        // aby wynik był czystym ASCII i wordwrap() (liczący bajty) nie przeciął znaku wielobajtowego.
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $out);
            if ($t !== false) $out = $t;
        }
        return $out;
    }
    private function escape(string $s): string { return str_replace(['\\','(',')'], ['\\\\','\\(','\\)'], $s); }
    private function wrap(string $s, int $w): array { $r = wordwrap($s, $w, "\n", true); return explode("\n", $r); }
}
