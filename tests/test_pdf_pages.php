<?php
use Duir\Services\PdfReport;
function test_pdf_can_generate_multiple_pages(): void {
    $events=[];
    for($i=0;$i<80;$i++) $events[]=['source'=>'KRZ','title'=>'Zdarzenie '.$i,'risk'=>'średni','risk_reason'=>'Powód','description'=>str_repeat('opis ',60)];
    $pdf=(new PdfReport())->subjectPdf(['name'=>'Test'], $events, 'średni', 'Podsumowanie');
    assert_true(str_starts_with($pdf, '%PDF-1.4'));
    assert_true(substr_count($pdf, '/Type /Page') >= 2, 'PDF should contain multiple pages for long reports');
}
function test_pdf_transliterates_non_polish_diacritics(): void {
    $events=[['source'=>'KRS','title'=>'Café François €99','risk'=>'niski','risk_reason'=>'Powód','description'=>'Müller Bäckerei GmbH — Škoda, Nový Řád, cœur "cytat" i wielobajtowe „krzaki”.']];
    $pdf=(new PdfReport())->subjectPdf(['name'=>'Müller Bäckerei GmbH'], $events, 'niski', 'Podsumowanie: Café François, cena 10 € — Škoda.');
    assert_true(str_starts_with($pdf, '%PDF-1.4'));
    // minimalPdf() nie wstawia bajtów >0x7F poza tekstem, a ascii() gwarantuje czyste ASCII w tekście
    assert_true(preg_match('/[\x80-\xFF]/', $pdf) === 0, 'PDF nie może zawierać surowych bajtów UTF-8 (>0x7F)');
}
function test_pdf_preserves_polish_transliteration(): void {
    // regresja: polskie znaki nadal poprawnie transliterowane
    $pdf=(new PdfReport())->subjectPdf(['name'=>'Żółć'], [], 'niski', 'Zażółć gęślą jaźń');
    assert_true(str_contains($pdf, 'Zazolc geslja jazn') || str_contains($pdf, 'Zazolc gesla jazn'), 'polskie znaki powinny być transliterowane do ASCII');
    assert_true(preg_match('/[\x80-\xFF]/', $pdf) === 0);
}
