<?php
namespace Duir\Controllers;

use Duir\KrzPisma\{KrzForms,DraftStore};
use Duir\Support\{Http,Csrf};

/** Zakładka „Pisma KRZ" — warstwa widoku DUiR nad samodzielnym modułem app/KrzPisma. */
final class KrzPismaController extends BaseController
{
    private const WZOR = '30048';

    public function __construct(private DraftStore $store, private string $catalogPath) {}

    public function index(): void
    {
        $this->header('Pisma KRZ');
        if (isset($_GET['deleted'])) echo '<p class="okbox">Pismo robocze usunięte.</p>';
        echo '<section class="card"><h2>Pisma w opracowaniu</h2>'
            .'<p class="muted">Prototyp: przygotowanie danych i karty wypełnienia formularza KRZ. Pismo podpisuje i wysyła pełnomocnik w Portalu Użytkowników Zarejestrowanych KRZ.</p>'
            .'<div class="actions"><a class="btn primary" href="/krz/pisma/nowe">Nowe zgłoszenie wierzytelności (30048)</a><a class="btn" href="/krz/wzory">Katalog wzorów KRZ</a></div>';
        $rows = $this->store->all();
        if (!$rows) { echo '<p class="muted">Nie ma jeszcze pism roboczych.</p></section>'; $this->footer(); return; }
        echo '<table><tr><th>Pismo</th><th>Wzór</th><th>Zmienione</th><th>Akcje</th></tr>';
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            echo '<tr><td><b>'.Http::e($r['tytul']).'</b></td><td>'.Http::e($r['wzor']).'</td><td>'.Http::e($r['updated_at']).'</td>'
                .'<td><a class="btn" href="/krz/pisma/'.$id.'">Edytuj</a> <a class="btn" href="/krz/pisma/'.$id.'/karta">Karta wypełnienia</a></td></tr>';
        }
        echo '</table></section>';
        $this->footer();
    }

    public function catalog(): void
    {
        $all = KrzForms::catalog($this->catalogPath);
        $q = trim((string)($_GET['q'] ?? ''));
        $kto = (string)($_GET['kto'] ?? '');
        $rows = array_filter($all, static function (array $r) use ($q, $kto): bool {
            if ($kto !== '' && $r['kto'] !== $kto) return false;
            return $q === '' || mb_stripos($r['kod'].' '.$r['tytul'], $q, 0, 'UTF-8') !== false;
        });
        $this->header('Katalog wzorów KRZ');
        echo '<section class="card"><form method="get" action="/krz/wzory" class="formgrid">'
            .'<div><label>Szukaj (kod albo słowo z tytułu)<input name="q" value="'.Http::e($q).'"></label></div>'
            .'<div><label>Kto składa<select name="kto">'
            .$this->options(['' => 'wszyscy', 'uczestnik' => 'uczestnik / pełnomocnik', 'organ' => 'syndyk, nadzorca, zarządca, kurator, rada wierzycieli'], $kto)
            .'</select></label></div><div class="actions"><button class="btn primary">Filtruj</button><a class="btn" href="/krz">Wróć do pism</a></div></form>'
            .'<p class="muted">Wzorów: '.count($rows).' z '.count($all).'. Źródło: zestawienie wzorów KRZ od 06.05.2025 oraz opisy zmian 1.20–1.23. Kolumna „kto składa" jest przypisana automatycznie po tytule i ma charakter orientacyjny.</p>'
            .'<table><tr><th>Kod</th><th>Wzór pisma</th><th>Od</th><th>Kto składa</th><th>Prototyp</th></tr>';
        foreach ($rows as $r) {
            $supported = isset(KrzForms::SUPPORTED[$r['kod']]);
            echo '<tr><td>'.Http::e($r['kod']).'</td><td>'.Http::e($r['tytul']).'</td><td>'.Http::e($r['od']).'</td>'
                .'<td>'.($r['kto'] === 'organ' ? 'organ postępowania' : 'uczestnik / pełnomocnik').'</td>'
                .'<td>'.($supported ? '<a class="chip ok" href="/krz/pisma/nowe">obsługiwany</a>' : '<span class="muted">—</span>').'</td></tr>';
        }
        echo '</table></section>';
        $this->footer();
    }

    public function form(?int $id): void
    {
        $row = $id ? $this->store->find($id) : null;
        if ($id && !$row) { http_response_code(404); echo '404'; return; }
        $d = $row['dane'] ?? KrzForms::fromPost([]);
        $this->header($id ? 'Zgłoszenie wierzytelności — edycja' : 'Nowe zgłoszenie wierzytelności');
        if (isset($_GET['saved'])) echo '<p class="okbox">Zapisano.</p>';
        if ($id) echo $this->validationBox(KrzForms::validate($d)).'<div class="actions"><a class="btn primary" href="/krz/pisma/'.$id.'/karta">Karta wypełnienia</a><a class="btn" href="/krz">Wróć do pism</a></div>';

        echo '<form method="post" action="/krz/pisma/zapisz">'.Csrf::field().($id ? '<input type="hidden" name="id" value="'.$id.'">' : '');
        echo '<section class="card"><h2>Postępowanie</h2><div class="formgrid">'
            .$this->input('sygnatura', 'Sygnatura postępowania (GUp)', $d['sygnatura'], 'np. WA1M/GUp/123/2026')
            .$this->input('upadly', 'Upadły (dla kontroli — KRZ uzupełnia sam)', $d['upadly'])
            .'</div><p class="muted">Organ (syndyk) i dane upadłego KRZ wypełnia automatycznie po wpisaniu sygnatury i „Dodaj upadłego".</p></section>';

        $cr = $d['wierzyciel'];
        echo '<section class="card"><h2>Wierzyciel</h2><div class="formgrid">'
            .'<div><label>Wnoszący pismo<select name="d[tryb]">'.$this->options(['pelnomocnik' => 'pełnomocnik', 'samodzielnie' => 'samodzielnie'], $d['tryb']).'</select></label></div>'
            .'<div><label>Oznaczenie wierzyciela<select name="d[wierzyciel][oznaczenie]">'.$this->options(['podmiot' => 'podmiot niebędący osobą fizyczną', 'osoba' => 'osoba fizyczna'], $cr['oznaczenie']).'</select></label></div>';
        foreach (['nazwa','imie','nazwisko','pesel','krs','nip','inne_id','forma_prawna','siedziba'] as $k) echo $this->input('wierzyciel]['.$k, KrzForms::CREDITOR[$k], $cr[$k]);
        foreach (KrzForms::ADDRESS + KrzForms::CONTACT as $k => $label) echo $this->input('wierzyciel]['.$k, $label, ($cr[$k] === '' && $k === 'kraj') ? 'Polska' : $cr[$k]);
        echo '</div><h3>Reprezentanci (dla podmiotu)</h3>';
        $reps = $d['reprezentanci']; $reps[] = [];
        foreach ($reps as $i => $r) {
            echo '<div class="formgrid">';
            foreach (KrzForms::REPRESENTATIVE as $k => $label) echo $this->input('reprezentanci]['.$i.']['.$k, $label.' (reprezentant '.($i+1).')', $r[$k] ?? '');
            echo '</div>';
        }
        echo '</section>';

        $at = $d['pelnomocnik'];
        echo '<section class="card"><h2>Pełnomocnik procesowy</h2><p class="muted">Wypełnij, gdy pismo wnosi pełnomocnik.</p><div class="formgrid">'
            .'<div><label>Oznaczenie pełnomocnika<select name="d[pelnomocnik][rodzaj]">'.$this->options(['profesjonalny' => 'profesjonalny', 'inny' => 'pełnomocnik inny'], $at['rodzaj']).'</select></label></div>'
            .'<label class="checkline"><input type="checkbox" name="d[pelnomocnik][do_doreczen]" value="1"'.($at['do_doreczen'] !== '' ? ' checked' : '').'> Pełnomocnik wyłącznie do doręczeń</label>';
        foreach (['nazwa','imie','nazwisko','id_konta'] as $k) echo $this->input('pelnomocnik]['.$k, KrzForms::ATTORNEY[$k], $at[$k]);
        foreach (KrzForms::ADDRESS + KrzForms::CONTACT as $k => $label) echo $this->input('pelnomocnik]['.$k, $label, ($at[$k] === '' && $k === 'kraj') ? 'Polska' : $at[$k]);
        echo '</div></section>';

        echo '<section class="card"><h2>Wierzytelności</h2><p class="muted">Jedna pozycja = jedna wierzytelność w sekcji IV formularza KRZ (należność główna, odsetki albo inna, w tym koszty). Puste pozycje są pomijane przy zapisie.</p>';
        $claims = $d['wierzytelnosci']; $claims[] = []; $claims[] = [];
        foreach ($claims as $i => $c) {
            echo '<h3>Pozycja '.($i+1).'</h3><div class="formgrid">'
                .'<div><label>'.KrzForms::CLAIM['typ'].'<select name="d[wierzytelnosci]['.$i.'][typ]">'.$this->options(KrzForms::CLAIM_TYPES, $c['typ'] ?? 'glowna').'</select></label></div>'
                .'<div><label>'.KrzForms::CLAIM['kategoria'].'<select name="d[wierzytelnosci]['.$i.'][kategoria]">'.$this->options(['' => '— wybierz —'] + KrzForms::CATEGORIES, $c['kategoria'] ?? '').'</select></label></div>';
            foreach (['tytul','kwota','waluta','wymagalnosc','odsetki_rodzaj','odsetki_od','odsetki_do'] as $k) {
                $type = in_array($k, ['wymagalnosc','odsetki_od','odsetki_do'], true) ? 'date' : 'text';
                echo $this->input('wierzytelnosci]['.$i.']['.$k, KrzForms::CLAIM[$k], $c[$k] ?? ($k === 'waluta' ? 'PLN' : ''), '', $type);
            }
            foreach (['zabezpieczenie','przedmiot_zabezpieczenia','stan_sprawy','dowody'] as $k) echo $this->input('wierzytelnosci]['.$i.']['.$k, KrzForms::CLAIM[$k], $c[$k] ?? '');
            echo '</div>';
        }
        echo '</section>';

        echo '<section class="card"><h2>Dodatkowe informacje i załączniki</h2><div class="formgrid">'
            .$this->input('bank', 'Nazwa banku', $d['bank']).$this->input('rachunek', 'Numer rachunku bankowego wierzyciela', $d['rachunek'])
            .'</div><label>Załączniki do dodania w KRZ (jeden plik w wierszu)<textarea name="d[zalaczniki]">'.Http::e($d['zalaczniki']).'</textarea></label>'
            .'<p class="muted">Pliki dodaje pełnomocnik w KRZ (do 5–8 MB na plik według instrukcji KRZ). Tutaj tylko lista kontrolna.</p></section>';
        echo '<div class="actions bottom"><button class="btn primary">Zapisz</button></div></form>';

        if ($id) {
            echo '<form method="post" action="/krz/pisma/'.$id.'/usun" onsubmit="return confirm(\'Usunąć to pismo robocze?\')">'.Csrf::field().'<button class="btn danger">Usuń pismo robocze</button></form>';
        }
        $this->footer();
    }

    public function save(?int $userId): void
    {
        $data = KrzForms::fromPost(is_array($_POST['d'] ?? null) ? $_POST['d'] : []);
        $id = (int)($_POST['id'] ?? 0);
        if ($id && !$this->store->find($id)) { http_response_code(404); echo '404'; return; }
        $id = $this->store->save($id ?: null, self::WZOR, $data, $userId);
        Http::redirect('/krz/pisma/'.$id.'?saved=1');
    }

    public function card(int $id): void
    {
        $row = $this->store->find($id);
        if (!$row) { http_response_code(404); echo '404'; return; }
        $d = $row['dane'];
        $this->header('Karta wypełnienia — wzór '.$row['wzor']);
        echo '<div class="actions"><a class="btn" href="/krz/pisma/'.$id.'">Wróć do edycji</a><button class="btn" onclick="window.print()">Drukuj</button></div>';
        echo $this->validationBox(KrzForms::validate($d));
        echo '<section class="card"><h2>'.Http::e($row['tytul']).'</h2><p class="muted">Pola w kolejności metryk formularza KRZ. Kliknij wartość, aby skopiować ją do schowka.</p>'
            .'<table><tr><th>Metryka</th><th>Pole</th><th>Wartość</th><th>Kto uzupełnia</th></tr>';
        foreach (KrzForms::card($d) as [$m, $f, $v, $src]) {
            $val = $v === '' ? '<span class="muted">—</span>' : '<span title="Kliknij, aby skopiować" style="cursor:copy" onclick="navigator.clipboard.writeText(this.textContent)">'.Http::e($v).'</span>';
            echo '<tr><td>'.Http::e($m).'</td><td>'.Http::e($f).'</td><td><b>'.$val.'</b></td><td class="muted">'.Http::e($src).'</td></tr>';
        }
        echo '</table></section>';
        $s = KrzForms::summary($d);
        echo '<section class="card"><h2>Podsumowanie do porównania z sekcją VI KRZ</h2><table><tr><th>Kategoria</th><th>Typ</th><th>Kwota PLN</th></tr>';
        foreach ($s['pln'] as $cat => $types) {
            foreach ($types as $type => $sum) echo '<tr><td>'.Http::e($cat).'</td><td>'.Http::e(KrzForms::CLAIM_TYPES[$type] ?? $type).'</td><td>'.number_format($sum, 2, ',', ' ').'</td></tr>';
        }
        echo '<tr><td colspan="2"><b>Razem PLN</b></td><td><b>'.number_format($s['razem_pln'], 2, ',', ' ').'</b></td></tr>';
        foreach ($s['inne'] as $cur => $sum) echo '<tr><td colspan="2">Waluta '.Http::e($cur).'</td><td>'.number_format($sum, 2, ',', ' ').'</td></tr>';
        echo '</table></section>';
        $this->footer();
    }

    public function delete(int $id): void
    {
        $this->store->delete($id);
        Http::redirect('/krz?deleted=1');
    }

    private function validationBox(array $v): string
    {
        if (!$v['errors'] && !$v['warnings']) return '<p class="okbox">Komplet danych wymaganych i zalecanych.</p>';
        $html = '<section class="card">';
        if ($v['errors']) $html .= '<h2 class="error">Do uzupełnienia przed złożeniem ('.count($v['errors']).')</h2><ul><li>'.implode('</li><li>', array_map([Http::class, 'e'], $v['errors'])).'</li></ul>';
        if ($v['warnings']) $html .= '<h2>Zalecane ('.count($v['warnings']).')</h2><ul><li>'.implode('</li><li>', array_map([Http::class, 'e'], $v['warnings'])).'</li></ul>';
        return $html.'</section>';
    }

    private function input(string $name, string $label, string $value, string $placeholder = '', string $type = 'text'): string
    {
        return '<div><label>'.Http::e($label).'<input type="'.$type.'" name="d['.$name.']" value="'.Http::e($value).'"'
            .($placeholder !== '' ? ' placeholder="'.Http::e($placeholder).'"' : '').'></label></div>';
    }

    private function options(array $opts, string $selected): string
    {
        $html = '';
        foreach ($opts as $v => $label) $html .= '<option value="'.Http::e($v).'"'.((string)$v === $selected ? ' selected' : '').'>'.Http::e($label).'</option>';
        return $html;
    }
}
