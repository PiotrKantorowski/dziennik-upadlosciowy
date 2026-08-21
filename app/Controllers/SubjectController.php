<?php
namespace Duir\Controllers;

use Duir\Repository;
use Duir\Services\{CheckService,ReportService,Mailer,KrsClient,RiskAnalyzer};
use Duir\Support\Http;
use Duir\Support\Csrf;
use Duir\Support\Client;
use Duir\Support\Normalize;
use Duir\Config;

final class SubjectController extends BaseController
{
    public function __construct(private Repository $repo, private CheckService $check, private ReportService $reports) {}

    public function index(): void
    {
        $this->repo->purgeLegacyKrsJsonEvents();
        $this->header('Monitorowane podmioty');
        echo '<div class="actions"><a class="btn primary" href="/subjects/new">Dodaj podmiot</a><form method="post" action="/checks/all">'.Csrf::field().'<button class="btn">Sprawdź wszystkie</button></form></div>';
        // Ile komputerów z wtyczką jest teraz aktywnych — codzienne sprawdzenie
        // rozkłada się między nie automatycznie (atomowa kolejka). 0 = żadna wtyczka
        // nie odpytywała ostatnio (KRZ/MSiG się nie wykonają, aż któraś wystartuje).
        $active = [];
        try { $active = $this->repo->activePluginInstances(12); } catch (\Throwable $e) {}
        $n = count($active);
        $labels = array_values(array_filter(array_map(fn($a)=>trim((string)($a['label'] ?? '')), $active)));
        $cls = $n > 0 ? 'ok' : 'muted';
        $txt = $n > 0
            ? 'Aktywne wtyczki: '.$n.' '.($n === 1 ? 'komputer' : ($n < 5 ? 'komputery' : 'komputerów')).($labels ? ' ('.Http::e(implode(', ', array_slice($labels,0,6))).')' : '')
            : 'Brak aktywnych wtyczek — uruchom przeglądarkę z wtyczką, inaczej KRZ/MSiG się nie wykonają';
        echo '<div class="chips" style="margin:2px 0 6px"><span class="chip '.$cls.'">🖥 '.$txt.'</span></div>';
        echo '<section class="card"><h2>Skrót monitoringu</h2><table><tr><th>Podmiot</th><th>Identyfikatory</th><th>KRZ</th><th>MSiG</th><th>KRS</th><th>Ryzyko</th><th>Ostatnio</th></tr>';
        foreach ($this->repo->subjects() as $s) {
            $risk = $this->repo->maxRiskForSubject((int)$s['id']);
            echo '<tr><td><a href="/subjects/'.(int)$s['id'].'"><b>'.Http::e($s['name']).'</b></a><br>'.($s['monitored']?'<span class="chip ok">monitorowany</span>':'<span class="chip muted">wstrzymany</span>').'</td>';
            $ids=[]; foreach(['krs'=>'KRS','nip'=>'NIP','regon'=>'REGON','pesel'=>'PESEL'] as $k=>$l) if($s[$k]) $ids[]=$l.' '.Http::e($s[$k]);
            echo '<td>'.($ids?implode('<br>',$ids):'<span class="muted">brak twardego identyfikatora</span>').'</td>';
            foreach(['KRZ','MSIG','KRS'] as $src) echo '<td>'.$this->sourceBadge($this->repo->latestCheckBySource((int)$s['id'],$src)).'</td>';
            echo '<td>'.$this->riskChip($risk).'</td><td>'.Http::e($s['last_checked_at'] ?: 'brak').'</td></tr>';
        }
        echo '</table></section>';
        $this->footer();
    }

    public function createForm(): void { $this->header('Dodaj podmiot'); $this->form('/subjects/create'); $this->footer(); }
    public function editForm(int $id): void { $s = $this->repo->findSubject($id); if(!$s){http_response_code(404);echo 'Nie znaleziono';return;} $this->header('Edytuj podmiot'); $this->form('/subjects/'.$id.'/update', $s); $this->footer(); }

    private function form(string $action, array $s = []): void
    {
        echo '<form class="card" method="post" action="'.$action.'">'.Csrf::field().'<div class="formgrid">';
        $fields = ['name'=>'Nazwa','krs'=>'KRS','nip'=>'NIP','regon'=>'REGON','pesel'=>'PESEL','email'=>'E-mail raportów'];
        foreach($fields as $k=>$l) echo '<div><label>'.$l.'</label><input name="'.$k.'" value="'.Http::e($s[$k] ?? '').'" '.($k==='name'?'required':'').'></div>';
        echo '<div><label>Typ</label><select name="type">';
        foreach(['auto'=>'auto','company'=>'spółka/podmiot KRS','business_person'=>'osoba fizyczna z działalnością','natural_person'=>'osoba fizyczna'] as $v=>$l) echo '<option value="'.$v.'" '.(($s['type']??'')===$v?'selected':'').'>'.$l.'</option>';
        echo '</select></div><div><label>Tryb obsługi</label><select name="service_mode" required>';
        echo '<option value="" '.(empty($s['service_mode'])?'selected':'').' disabled>— wybierz —</option>';
        foreach([
            'office_monitoring'=>'Monitoring stały — na potrzeby Kancelarii',
            'client_monitoring'=>'Monitoring stały — raportowanie Klientowi',
            'one_time'=>'Weryfikacja jednorazowa',
        ] as $v=>$l) echo '<option value="'.$v.'" '.(($s['service_mode']??'')===$v?'selected':'').'>'.$l.'</option>';
        echo '</select></div>';
        echo '</div><label>Aliasy</label><textarea name="aliases">'.Http::e($s['aliases'] ?? '').'</textarea>';
        echo '<label class="checkline"><input type="checkbox" name="allow_name_only" value="1"> Świadomie dopuszczam sprawdzanie tylko po nazwie — wynik może być mniej pewny.</label>';
        echo '<p class="muted">Najbezpieczniej podać KRS, NIP, REGON albo PESEL. Sprawdzanie po samej nazwie może dawać fałszywe trafienia.</p>';
        echo '<div class="actions"><button type="button" class="btn secondary" id="duir-lookup">🔎 Pobierz dane z rejestrów</button> <button class="btn primary">Zapisz</button></div>';
        echo '<p id="duir-lookup-status" class="muted"></p><div id="duir-lookup-result" class="lookup-result"></div></form>';
        // Podgląd danych rejestrowych PRZED zapisem: wpisz NIP (albo KRS) i kliknij
        // "Pobierz dane" — puste pola uzupełnią się z Białej Listy MF / KRS, a pod
        // formularzem pojawi się pełen odczyt do porównania z danymi wpisanymi ręcznie.
        echo '<script>(function(){
var btn=document.getElementById("duir-lookup"); if(!btn) return;
var f=btn.closest("form"), st=document.getElementById("duir-lookup-status"), res=document.getElementById("duir-lookup-result");
btn.addEventListener("click", function(){
  var nip=(f.querySelector("[name=nip]").value||"").replace(/\D/g,"");
  var krs=(f.querySelector("[name=krs]").value||"").replace(/\D/g,"");
  if(!nip && !krs){ st.textContent="Wpisz najpierw NIP albo KRS."; return; }
  st.textContent="Pobieram dane z rejestrów…"; btn.disabled=true; res.innerHTML="";
  fetch("/subjects/lookup?"+(nip?("nip="+nip):("krs="+krs)),{headers:{Accept:"application/json"}})
    .then(function(r){ return r.json(); })
    .then(function(d){
      if(!d.ok){ st.textContent=d.error||"Nie udało się pobrać danych."; return; }
      var filled=[];
      [["name",d.name],["krs",d.krs],["nip",d.nip],["regon",d.regon]].forEach(function(p){
        if(!p[1]) return; var el=f.querySelector("[name="+p[0]+"]");
        if(el && !el.value){ el.value=p[1]; filled.push(p[0].toUpperCase()); }
      });
      [["Źródło",d.source],["Nazwa",d.name],["KRS",d.krs],["NIP",d.nip],["REGON",d.regon],["Adres",d.address],["Status VAT",d.status_vat],["Status KRS",d.status_label]].forEach(function(p){
        if(!p[1]) return; var div=document.createElement("div"); div.textContent=p[0]+": "+p[1]; res.appendChild(div);
      });
      st.textContent = filled.length ? "Uzupełniono puste pola: "+filled.join(", ")+". Porównaj resztę z odczytem poniżej." : "Dane pobrane — porównaj z wpisanymi.";
    })
    .catch(function(e){ st.textContent="Błąd połączenia: "+e; })
    .finally(function(){ btn.disabled=false; });
});
})();</script>';
    }

    /**
     * Buduje listę wyraźnych odznak stanu podmiotu: [klasa CSS, ikona, etykieta].
     * Status podmiotu czytamy z profilu KRS zapisanego przy ostatnim sprawdzeniu
     * (raw_json checka), zaległość sprawozdania z financial_check, a postępowania
     * KRZ/MSiG z zarejestrowanych zdarzeń. Czysta prezentacja — zero nowych zapytań
     * do rejestrów.
     */
    private function statusBadges(int $subjectId, array $events): array
    {
        $badges = [];
        $krsCheck = $this->repo->latestCheckBySource($subjectId, 'KRS');
        $profile = [];
        if ($krsCheck && !empty($krsCheck['raw_json'])) $profile = json_decode((string)$krsCheck['raw_json'], true) ?: [];
        $statusLabel = mb_strtolower((string)($profile['status_label'] ?? ''), 'UTF-8');
        if (str_contains($statusLabel, 'upadło')) $badges[] = ['crit','Upadłość'];
        elseif (str_contains($statusLabel, 'restrukturyzac')) $badges[] = ['warn','Restrukturyzacja'];
        elseif (str_contains($statusLabel, 'likwidac')) $badges[] = ['warn','Likwidacja'];
        elseif (str_contains($statusLabel, 'wykreśl')) $badges[] = ['crit','Wykreślony z KRS — podmiot nie istnieje'];
        elseif ($statusLabel === 'aktywny') $badges[] = ['okb','Aktywny w KRS'];
        $fc = $profile['financial_check'] ?? null;
        if (is_array($fc)) {
            if (($fc['status'] ?? '') === 'late') $badges[] = ['warn','Sprawozdanie finansowe po terminie'];
            if (($fc['status'] ?? '') === 'missing') $badges[] = ['warn','Brak sprawozdania finansowego'];
        }
        // Status działalności z CEIDG (osoby fizyczne z działalnością).
        $ceidgCheck = $this->repo->latestCheckBySource($subjectId, 'CEIDG');
        if ($ceidgCheck && !empty($ceidgCheck['raw_json'])) {
            $ceidg = json_decode((string)$ceidgCheck['raw_json'], true) ?: [];
            $cs = strtoupper((string)($ceidg['ceidg_status'] ?? ''));
            if (str_contains($cs, 'WYKRESLON')) $badges[] = ['grayb','Działalność wykreślona (CEIDG)'];
            elseif (str_contains($cs, 'ZAWIESZON')) $badges[] = ['warn','Działalność zawieszona (CEIDG)'];
            elseif ($cs === 'AKTYWNY') $badges[] = ['okb','Działalność aktywna (CEIDG)'];
        }
        foreach ([['KRZ','Postępowania w KRZ'],['MSIG','Ogłoszenia w MSiG']] as [$src,$label]) {
            $high = 0; $any = 0;
            foreach ($events as $e) {
                if (($e['source'] ?? '') !== $src) continue;
                $any++;
                if (in_array(($e['risk'] ?? ''), ['wysoki','krytyczny'], true)) $high++;
            }
            if ($high) $badges[] = ['crit',$label.': '.$high];
            elseif ($any) $badges[] = ['warn',$label.': '.$any];
        }
        return $badges;
    }

    // Podgląd danych rejestrowych na żądanie (przycisk "Pobierz dane" w formularzu).
    // NIP -> Biała Lista MF (nazwa, KRS, REGON, adres, status VAT — jedno zapytanie);
    // sam KRS -> odpis aktualny KRS (nazwa, NIP, REGON, status podmiotu).
    // Tylko odczyt (GET, bez zapisu), identyfikatory zawężone do cyfr, adresy URL
    // wyłącznie z konfiguracji — bez możliwości SSRF.
    public function lookup(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $json = fn(array $out) => print(json_encode($out, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $nip = Normalize::digits((string)($_GET['nip'] ?? ''));
        $krs = Normalize::digits((string)($_GET['krs'] ?? ''));
        try {
            if ($nip !== '') {
                if (strlen($nip) !== 10) { $json(['ok'=>false,'error'=>'NIP musi mieć 10 cyfr.']); return; }
                $base = rtrim((string)Config::get('MF_WHITELIST_BASE','https://wl-api.mf.gov.pl/api'), '/');
                $data = (new Client())->getJson("$base/search/nip/$nip?date=".date('Y-m-d'));
                $s = $data['result']['subject'] ?? null;
                if (!$s) { $json(['ok'=>false,'error'=>'Biała Lista MF nie zna tego NIP.']); return; }
                $json([
                    'ok'=>true, 'source'=>'Biała Lista MF (Wykaz podatników VAT)',
                    'name'=>Normalize::text((string)($s['name'] ?? '')),
                    'krs'=>Normalize::digits((string)($s['krs'] ?? '')),
                    'nip'=>$nip,
                    'regon'=>Normalize::digits((string)($s['regon'] ?? '')),
                    'address'=>Normalize::text((string)($s['workingAddress'] ?? $s['residenceAddress'] ?? '')),
                    'status_vat'=>Normalize::text((string)($s['statusVat'] ?? '')),
                ]);
                return;
            }
            if ($krs !== '') {
                $profile = (new KrsClient())->fetchProfile(['krs'=>$krs]);
                if (in_array(($profile['status'] ?? ''), ['error','no_identifier'], true)) {
                    $json(['ok'=>false,'error'=>(string)($profile['status_label'] ?? 'Nie udało się pobrać odpisu KRS.')]);
                    return;
                }
                $json([
                    'ok'=>true, 'source'=>'KRS (odpis aktualny)',
                    'name'=>Normalize::text((string)($profile['legal_name'] ?? '')),
                    'krs'=>$krs,
                    'nip'=>Normalize::digits((string)($profile['nip'] ?? '')),
                    'regon'=>Normalize::digits((string)($profile['regon'] ?? '')),
                    'address'=>Normalize::text((string)($profile['address'] ?? '')),
                    'status_label'=>Normalize::text((string)($profile['status_label'] ?? '')),
                ]);
                return;
            }
            $json(['ok'=>false,'error'=>'Podaj NIP albo KRS, żeby pobrać dane.']);
        } catch (\Throwable $e) {
            $json(['ok'=>false,'error'=>'Nie udało się pobrać danych z rejestru (spróbuj ponownie): '.$e->getMessage()]);
        }
    }

    public function create(): void {
        try { $id = $this->repo->createSubject($_POST); }
        catch(\Throwable $e){ $this->header('Błąd zapisu'); echo '<section class="card"><p class="error">'.Http::e($e->getMessage()).'</p><p><a class="btn" href="/subjects/new">Wróć</a></p></section>'; $this->footer(); return; }
        // Pierwsze sprawdzenie odpala się AUTOMATYCZNIE już przy dodaniu podmiotu:
        // KRS synchronicznie (od razu widoczny w karcie), a KRZ/MSiG trafiają do kolejki
        // i bumpują sweep_requested_at, więc wtyczka Chrome przetworzy je przy najbliższym
        // pollu (co 3 min) — bez czekania na przebieg 10:00 ani ręczne "Sprawdź teraz".
        // Błąd samego sprawdzenia NIE może wyglądać jak błąd zapisu — podmiot jest już zapisany.
        try { $s = $this->repo->findSubject($id); if ($s) $this->check->checkSubject($s); } catch(\Throwable $e) { /* sprawdzenie można ponowić przyciskiem albo CRON-em */ }
        Http::redirect('/subjects/'.$id);
    }
    public function update(int $id): void { try{$this->repo->updateSubject($id,$_POST); Http::redirect('/subjects/'.$id);} catch(\Throwable $e){$this->header('Błąd zapisu'); echo '<section class="card"><p class="error">'.Http::e($e->getMessage()).'</p><p><a class="btn" href="/subjects/'.$id.'/edit">Wróć</a></p></section>'; $this->footer();} }
    public function delete(int $id): void { $this->repo->deleteSubject($id); Http::redirect('/'); }
    public function check(int $id): void { $s=$this->repo->findSubject($id); if($s)$this->check->checkSubject($s); Http::redirect('/subjects/'.$id); }
    // Wymusza świeżą ocenę LLM z bieżących zdarzeń — bez czekania na NOWE zdarzenie
    // (bufor odświeża się tylko przy nowszym zdarzeniu, więc po zmianie logiki oceny
    // trzeba móc ją przeliczyć ręcznie, nie usuwając podmiotu).
    public function reassess(int $id): void
    {
        $s=$this->repo->findSubject($id);
        if($s){
            $events=$this->repo->latestEvents($id);
            if($events){
                try{
                    $text=$this->reports->subjectAssessment($s,$events);
                    if($text!=='') $this->repo->saveReport($id,'assessment','Ocena: '.$s['name'],$text,nl2br(Http::e($text)),null);
                }catch(\Throwable $e){/* ocena jest dodatkiem — nie wywracaj karty */}
            }
        }
        Http::redirect('/subjects/'.$id);
    }
    public function checkAll(): void { $this->check->checkAllMonitored(); Http::redirect('/'); }

    public function show(int $id): void
    {
        $s=$this->repo->findSubject($id); if(!$s){http_response_code(404);echo 'Nie znaleziono';return;}
        $this->repo->purgeLegacyKrsJsonEvents($id);
        $events=$this->repo->latestEvents($id); $risk=$this->repo->maxRiskForSubject($id); $checks=$this->repo->latestChecks($id, 300);
        $this->header($s['name']);
        // Powrót do panelu głównego (lista podmiotów) — wyraźna strzałka na górze karty,
        // niezależnie od linku „Podmioty" w górnej nawigacji.
        echo '<div style="margin-bottom:12px"><a class="btn" href="/">← Powrót do listy podmiotów</a></div>';
        // GŁÓWNA AKCJA na górze — „Sprawdź teraz" ma być pod ręką od razu, bez
        // przewijania na dół. Działa też dla podmiotów wyłączonych z monitoringu.
        echo '<div class="actions" style="margin-bottom:14px;display:flex;gap:8px;flex-wrap:wrap">'
            .'<form method="post" action="/subjects/'.$id.'/check">'.Csrf::field().'<button class="btn primary">🔄 Sprawdź teraz</button></form>'
            .'<form method="post" action="/subjects/'.$id.'/reassess">'.Csrf::field().'<button class="btn">🧠 Odśwież ocenę</button></form>'
            .'</div>';
        // Pas wyraźnych odznak stanu — kluczowe ustalenia (upadłość, restrukturyzacja,
        // likwidacja, wykreślenie, zaległe sprawozdanie) mają być widoczne od progu,
        // bez czytania opisów zdarzeń.
        $badges = $this->statusBadges($id, $events);
        if ($badges) {
            echo '<div class="status-strip">';
            foreach ($badges as [$cls,$label]) echo '<span class="sbadge '.$cls.'">'.Http::e($label).'</span>';
            echo '</div>';
        }
        // Ocena LLM "co się dzieje i co zrobić" — generowana przy pierwszym wejściu po
        // nowych zdarzeniach, potem serwowana z bufora (reports.type=assessment).
        $assessment = $this->repo->latestAssessment($id);
        $newestEventAt = '';
        foreach ($events as $e) {
            // ON DUPLICATE KEY aktualizuje zdarzenie w miejscu. Samo created_at wtedy
            // się nie zmienia, więc ocena AI musi uwzględniać także updated_at.
            $created = (string)($e['created_at'] ?? '');
            $updated = (string)($e['updated_at'] ?? '');
            $c = $updated > $created ? $updated : $created;
            if ($c > $newestEventAt) $newestEventAt = $c;
        }
        if ($events && (!$assessment || (string)($assessment['created_at'] ?? '') < $newestEventAt)) {
            try {
                $text = $this->reports->subjectAssessment($s, $events);
                if ($text !== '') {
                    $this->repo->saveReport($id, 'assessment', 'Ocena: '.$s['name'], $text, nl2br(Http::e($text)), null);
                    $assessment = ['summary'=>$text, 'created_at'=>date('Y-m-d H:i')];
                }
            } catch (\Throwable $e) { /* ocena jest dodatkiem — karta działa bez niej */ }
        }
        if ($assessment && trim((string)($assessment['summary'] ?? '')) !== '') {
            echo '<section class="card assessment"><h2>Ocena sytuacji i zalecenia</h2>'
                .'<div style="line-height:1.55">'.\Duir\Services\ReportService::llmTextToHtml(trim((string)$assessment['summary'])).'</div>'
                .'<p class="muted">Wygenerowane automatycznie (AI) na podstawie zdarzeń z KRZ/MSiG/KRS — '
                .Http::e((string)($assessment['created_at'] ?? '')).'. Zweryfikuj przed podjęciem czynności.</p></section>';
        }
        // Kody techniczne tłumaczymy na polski — "queued_krz" czy "company" nic nie
        // mówią osobie, która nie zna wnętrza aplikacji.
        $typeLabels = ['company'=>'spółka / podmiot rejestrowy (KRS)','business_person'=>'osoba fizyczna prowadząca działalność','natural_person'=>'osoba fizyczna','unknown'=>'nieokreślony','auto'=>'ustalany automatycznie'];
        $serviceModeLabels = [
            'office_monitoring'=>'Monitoring stały — na potrzeby Kancelarii',
            'client_monitoring'=>'Monitoring stały — raportowanie Klientowi',
            'one_time'=>'Weryfikacja jednorazowa',
        ];
        $statusLabels = [
            'queued_krz'=>'w kolejce wtyczki Chrome (KRZ/MSiG)',
            'krz_done'=>'KRZ: sprawdzono','krz_no_results'=>'KRZ: brak wyników','krz_error'=>'KRZ: błąd sprawdzenia',
            'msig_done'=>'MSiG: sprawdzono','msig_no_results'=>'MSiG: brak wyników','msig_error'=>'MSiG: błąd sprawdzenia',
        ];
        echo '<section class="card subject-header"><h2>Dane podmiotu</h2><div class="meta-grid">';
        foreach(['krs'=>'KRS','nip'=>'NIP','regon'=>'REGON','pesel'=>'PESEL','type'=>'Typ','service_mode'=>'Tryb obsługi','last_checked_at'=>'Ostatnie sprawdzenie','last_status'=>'Status'] as $k=>$l) {
            $v = (string)($s[$k] ?? '');
            if ($k === 'type') $v = $typeLabels[$v] ?? $v;
            if ($k === 'service_mode') $v = $serviceModeLabels[$v] ?? $v;
            if ($k === 'last_status') $v = $statusLabels[$v] ?? $v;
            echo '<div><span>'.$l.'</span><b>'.Http::e($v ?: '—').'</b></div>';
        }
        echo '</div><div class="chips">'.$this->riskChip($risk).($s['monitored']?'<span class="chip ok">monitorowany</span>':'<span class="chip muted">wstrzymany</span>').'</div></section>';
        // Osoby fizyczne nie podlegają KRS — ich rejestrem statusu jest CEIDG.
        $isPerson = in_array((string)($s['type'] ?? ''), ['business_person','natural_person'], true);
        // PASEK POSTĘPU: pokazuje etap każdego źródła i „coś się dzieje", dopóki
        // KRZ/MSiG są przetwarzane przez wtyczkę. JS odpytuje /subjects/{id}/status.
        $stepSources = $isPerson ? ['CEIDG'=>'CEIDG','KRZ'=>'KRZ','MSIG'=>'MSiG'] : ['KRS'=>'KRS','KRZ'=>'KRZ','MSIG'=>'MSiG'];
        $pending = $this->repo->subjectHasPendingBrowserTask($id);
        $stColor = function(string $st): array {
            return match($st) {
                'success','no_results' => ['#027a48','#ecfdf3','✓', $st==='no_results'?'brak wyników':'sprawdzono'],
                'error' => ['#912018','#fee4e2','✕','błąd'],
                'running','pending' => ['#93370d','#fff6dc','⏳','w trakcie'],
                default => ['#6b7280','#eef2f6','•','oczekuje'],
            };
        };
        echo '<section class="card" id="duir-progress" data-sub="'.$id.'" data-pending="'.($pending?'1':'0').'">';
        echo '<h2 style="margin-top:0">Postęp sprawdzania</h2><div id="duir-progress-steps" style="display:flex;gap:10px;flex-wrap:wrap">';
        foreach ($stepSources as $src=>$slabel) {
            $c = $this->repo->latestCheckBySource($id, $src);
            [$fg,$bg,$ic,$lab] = $stColor((string)($c['status'] ?? ''));
            echo '<span data-src="'.$src.'" style="display:inline-flex;align-items:center;gap:7px;padding:8px 14px;border-radius:999px;font-weight:650;font-size:.92rem;color:'.$fg.';background:'.$bg.'"><b>'.$ic.'</b> '.Http::e($slabel).': '.$lab.'</span>';
        }
        echo '</div><div id="duir-progress-bar-wrap" style="height:6px;background:#eef2f7;border-radius:999px;overflow:hidden;margin-top:12px;'.($pending?'':'display:none').'"><div id="duir-progress-bar" style="height:100%;width:38%;background:#2448a8;border-radius:999px"></div></div>';
        echo '<p id="duir-progress-note" class="muted" style="margin:10px 0 0">'.($pending?'Sprawdzanie w toku — KRZ i MSiG realizuje wtyczka Chrome (zwykle 1–3 min). Nie zamykaj przeglądarki z wtyczką.':('Ostatnie sprawdzenie: '.Http::e((string)($s['last_checked_at'] ?? '') ?: '—'))).'</p></section>';
        echo '<script>(function(){
var box=document.getElementById("duir-progress"); if(!box) return;
var sub=box.getAttribute("data-sub");
var steps=document.getElementById("duir-progress-steps"), barWrap=document.getElementById("duir-progress-bar-wrap"), bar=document.getElementById("duir-progress-bar"), note=document.getElementById("duir-progress-note");
var LABELS='.json_encode($stepSources).', ORDER='.json_encode(array_keys($stepSources)).';
var wasPending=(box.getAttribute("data-pending")==="1"), reloaded=false, pos=0, dir=1;
function st(s){ if(s==="success")return["#027a48","#ecfdf3","✓","sprawdzono"]; if(s==="no_results")return["#027a48","#ecfdf3","✓","brak wyników"]; if(s==="error")return["#912018","#fee4e2","✗","błąd"]; if(s==="running"||s==="pending")return["#93370d","#fff6dc","⏳","w trakcie"]; return["#6b7280","#eef2f6","•","oczekuje"]; }
function render(d){ var h=""; ORDER.forEach(function(src){ var i=(d.sources&&d.sources[src])||{status:"none"}; var v=st(i.status); h+="<span style=\\"display:inline-flex;align-items:center;gap:7px;padding:8px 14px;border-radius:999px;font-weight:650;font-size:.92rem;color:"+v[0]+";background:"+v[1]+"\\"><b>"+v[2]+"</b> "+(LABELS[src]||src)+": "+v[3]+"</span>"; }); steps.innerHTML=h;
 if(d.pending){ barWrap.style.display="block"; note.textContent="Sprawdzanie w toku — KRZ i MSiG realizuje wtyczka Chrome (zwykle 1–3 min). Nie zamykaj przeglądarki z wtyczką."; } else { barWrap.style.display="none"; note.textContent=d.last_checked?("Ostatnie sprawdzenie: "+d.last_checked):""; }
 if(wasPending && !d.pending && !reloaded){ reloaded=true; setTimeout(function(){location.reload();},600); return; } wasPending=!!d.pending; }
setInterval(function(){ if(barWrap.style.display==="none")return; pos+=dir*6; if(pos>62)dir=-1; if(pos<0)dir=1; bar.style.marginLeft=pos+"%"; },120);
function loop(){ fetch("/subjects/"+sub+"/status",{headers:{Accept:"application/json"}}).then(function(r){return r.json();}).then(function(d){ if(d&&d.ok){ render(d); if(d.pending && !reloaded) setTimeout(loop,4000); } }).catch(function(){ setTimeout(loop,6000); }); }
loop();
})();</script>';
        echo '<section class="grid3 source-summary">';
        $sourceCards = $isPerson
            ? ['MSIG'=>'Najnowszy wpis w MSiG','KRZ'=>'Najnowszy wpis w KRZ','CEIDG'=>'Status w CEIDG']
            : ['MSIG'=>'Najnowszy wpis w MSiG','KRZ'=>'Najnowszy wpis w KRZ','KRS'=>'Najnowszy wpis w KRS'];
        foreach($sourceCards as $src=>$label) {
            $e=$this->repo->latestEventBySource($id,$src); $c=$this->repo->latestCheckBySource($id,$src); echo '<div class="card"><h3>'.$label.'</h3>';
            echo '<p>'.$this->sourceBadge($c).'</p>';
            // Stare wersje parsera potrafiły zapisać cały odpis KRS jako opis
            // rzekomego postępowania. Nie pokazujemy takiego technicznego rekordu.
            if ($e && $src === 'KRS' && (string)($e['description'] ?? '') !== ''
                && RiskAnalyzer::readableKrsDescription((string)$e['description']) === '') $e = null;
            if($e) {
                echo '<p><b>'.Http::e($e['title']).'</b></p>'.$this->riskChip($e['risk']).'<p>'.Http::e($e['risk_reason']).'</p>';
                // DWIE OSOBNE, OPISANE daty: „Data wpisu" (kiedy wpis powstał w rejestrze)
                // vs „Sprawdzono" (kiedy DUiR to odczytał) — wcześniej były zlane w jedną.
                echo '<p class="muted" style="font-size:.82rem">Data wpisu w rejestrze: <b>'.Http::e(trim((string)($e['publication_date'] ?? '')) ?: '—').'</b></p>';
            }
            elseif($c) echo '<p class="muted">'.Http::e($c['message'] ?: 'Brak zdarzenia z tego źródła.').'</p>';
            else echo '<p class="muted">Brak sprawdzenia.</p>';
            $chk = trim((string)($c['checked_at'] ?? ''));
            if ($chk !== '') echo '<p class="muted" style="font-size:.82rem">Sprawdzono przez DUiR: <b>'.Http::e(mb_substr($chk,0,16)).'</b></p>';
            echo '</div>';
        }
        echo '</section>';
        // Sprawozdanie finansowe (KRS): za jaki OKRES jest ostatnie i KIEDY złożone.
        if (!$isPerson) {
            $fin = $this->repo->latestFinancialCheck($id);
            if ($fin) {
                $finStatus = ['on_time'=>'złożone w terminie','late'=>'złożone po terminie','missing'=>'brak informacji o złożeniu','unknown'=>'status nieustalony']
                    [(string)($fin['status'] ?? '')] ?? (string)($fin['status'] ?? '—');
                echo '<section class="card"><h2>Sprawozdanie finansowe (KRS)</h2><div class="meta-grid">'
                    .'<div><span>Ostatnie sprawozdanie za okres do</span><b>'.Http::e(trim((string)($fin['period_to'] ?? '')) ?: '—').'</b></div>'
                    .'<div><span>Data złożenia</span><b>'.Http::e(trim((string)($fin['submitted_at'] ?? '')) ?: 'brak informacji').'</b></div>'
                    .'<div><span>Ustawowy termin złożenia</span><b>'.Http::e(trim((string)($fin['due_date'] ?? '')) ?: '—').'</b></div>'
                    .'<div><span>Ocena terminowości</span><b>'.Http::e($finStatus).'</b></div>'
                    .'</div>'.(!empty($fin['reason'])?'<p class="muted">'.Http::e((string)$fin['reason']).'</p>':'').'</section>';
            }
        }
        echo '<section class="card"><h2>Historia i alerty</h2>';
        $renderedEvents = 0;
        foreach($events as $e) {
            $source = trim((string)($e['source'] ?? ''));
            $title = trim((string)($e['title'] ?? 'Informacja'));
            $heading = preg_match('/^'.preg_quote($source, '/').'\s*:/iu', $title) ? $title : ($source !== '' ? $source.': '.$title : $title);
            $description = (string)($e['description'] ?? '');
            if ($source === 'KRS') {
                $description = RiskAnalyzer::readableKrsDescription($description);
                // Rekord z pełnym JSON-em jest pozostałością starego błędu parsera,
                // a nie czytelnym zdarzeniem. Ukrywamy cały fałszywy alert.
                if ((string)($e['description'] ?? '') !== '' && $description === '') continue;
            }
            echo '<article class="event"><h3>'.Http::e($heading).'</h3>'.$this->riskChip((string)($e['risk'] ?? 'niski'))
                .'<p>'.Http::e((string)($e['risk_reason'] ?? '')).'</p>'
                .($description !== '' ? '<p>'.Http::e(mb_substr($description,0,800,'UTF-8')).'</p>' : '').'</article>';
            $renderedEvents++;
        }
        if(!$renderedEvents) echo '<p class="muted">Brak zdarzeń.</p>';
        echo '</section>';
        // Sprawdzenia źródeł rozdzielone: ostatnie (≤3 dni) na wierzchu, starsze
        // składane do „Archiwum wpisów", żeby świeży obraz nie tonął w historii.
        $renderCheckRow = function(array $c): string {
            $detail = '';
            // Diagnostykę (surowy podgląd strony źródła) pokazujemy TYLKO przy BŁĘDZIE
            // i chowamy pod dyskretny przycisk „Diagnoza" — bez zaśmiecania komunikatu
            // podglądem. „Brak wyników" to czysty, oczekiwany wynik: żadnego zrzutu strony.
            if (($c['status'] ?? '') === 'error' && !empty($c['raw_json'])) {
                $raw = json_decode((string)$c['raw_json'], true) ?: [];
                $sampleText = (string)($raw['pageText'] ?? $raw['sample'] ?? ($raw['meta']['sample'] ?? ''));
                if ($sampleText !== '') {
                    $detail = '<details style="margin-top:6px"><summary style="cursor:pointer;display:inline-block;font-size:.78rem;font-weight:650;color:#2448a8;padding:2px 10px;border:1px solid #c9d6f0;border-radius:999px;list-style:none">🔍 Diagnoza</summary>'
                        .'<pre style="white-space:pre-wrap;font-size:.78rem;line-height:1.45;background:#f8fafc;border:1px solid var(--line);border-radius:8px;padding:8px;max-height:360px;overflow:auto;margin:6px 0 0">'
                        .Http::e(mb_substr($sampleText, 0, 9000, 'UTF-8')).'</pre></details>';
                }
            }
            return '<tr><td>'.Http::e($c['source']).'</td><td>'.$this->sourceBadge($c).'</td><td>'.Http::e($c['message']).$detail.'</td><td>'.Http::e($c['checked_at']).'</td></tr>';
        };
        $cutoff = date('Y-m-d H:i:s', strtotime('-3 days'));
        $recentChecks = []; $archiveChecks = [];
        foreach ($checks as $c) { if ((string)($c['checked_at'] ?? '') >= $cutoff) $recentChecks[] = $c; else $archiveChecks[] = $c; }
        echo '<section class="card"><h2>Ostatnie sprawdzenia źródeł</h2><p class="muted" style="margin-top:-6px">Wpisy z ostatnich 3 dni. Starsze — w archiwum poniżej.</p>';
        if ($recentChecks) {
            echo '<table><tr><th>Źródło</th><th>Status</th><th>Komunikat</th><th>Czas</th></tr>';
            foreach ($recentChecks as $c) echo $renderCheckRow($c);
            echo '</table>';
        } else echo '<p class="muted">Brak sprawdzeń z ostatnich 3 dni — kliknij „Sprawdź teraz" na górze.</p>';
        if ($archiveChecks) {
            echo '<details style="margin-top:14px"><summary style="cursor:pointer;font-weight:650">Archiwum wpisów — sprawdzenia starsze niż 3 dni ('.count($archiveChecks).')</summary>'
                .'<table style="margin-top:10px"><tr><th>Źródło</th><th>Status</th><th>Komunikat</th><th>Czas</th></tr>';
            foreach ($archiveChecks as $c) echo $renderCheckRow($c);
            echo '</table></details>';
        }
        echo '</section>';
        // „Sprawdź teraz" przeniesione na GÓRĘ karty; tu zostają pozostałe akcje.
        echo '<div class="actions bottom"><a class="btn" href="/subjects/'.$id.'/edit">Edytuj</a><a class="btn" href="/subjects/'.$id.'/report">📄 Raport</a><form method="post" action="/subjects/'.$id.'/send">'.Csrf::field().'<button class="btn">Wyślij e-mail</button></form><form method="post" action="/subjects/'.$id.'/delete" onsubmit="return confirm(\'Usunąć podmiot?\')">'.Csrf::field().'<button class="btn danger">Usuń</button></form></div>';
        $this->footer();
    }

    // Lekki status JSON dla paska postępu: stan każdego źródła + czy coś jeszcze
    // wisi w kolejce wtyczki (KRZ/MSiG). Tylko odczyt, bez uruchamiania sprawdzeń.
    public function status(int $id): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $s = $this->repo->findSubject($id);
        if (!$s) { echo json_encode(['ok'=>false]); return; }
        $out = [];
        foreach (['KRS','CEIDG','KRZ','MSIG'] as $src) {
            $c = $this->repo->latestCheckBySource($id, $src);
            $out[$src] = ['status'=>(string)($c['status'] ?? 'none'), 'at'=>(string)($c['checked_at'] ?? '')];
        }
        echo json_encode([
            'ok'=>true,
            'pending'=>$this->repo->subjectHasPendingBrowserTask($id),
            'last_checked'=>(string)($s['last_checked_at'] ?? ''),
            'sources'=>$out,
        ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }

    public function pdf(int $id): void { $s=$this->repo->findSubject($id); if(!$s){http_response_code(404);return;} $r=$this->reports->createSubjectReport($s); header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="raport-duir.pdf"'); readfile($r['pdf_path']); }

    // Raport jako strona HTML (podsumowanie LLM + sytuacja aktualna + historia)
    // z przyciskiem "Drukuj / zapisz jako PDF" — podstawowa, czytelna forma raportu.
    public function report(int $id): void
    {
        $s=$this->repo->findSubject($id); if(!$s){http_response_code(404);echo 'Nie znaleziono';return;}
        $r=$this->reports->createSubjectReport($s);
        header('Content-Type: text/html; charset=utf-8');
        echo $this->reports->renderSubjectReportHtml($s, $r['events'], $r['risk'], $r['summary'], false);
    }

    public function send(int $id): void {
        $s=$this->repo->findSubject($id); if(!$s){http_response_code(404);return;}
        $r=$this->reports->createSubjectReport($s);
        $to = $s['email'] ?: (string)Config::get('REPORT_TO','');
        $mailer = new Mailer();
        // E-mail w dwóch wersjach: tekstowej (fallback) i HTML (ta sama strona co
        // raport w przeglądarce) + PDF w załączniku.
        $html = $this->reports->renderSubjectReportHtml($s, $r['events'], $r['risk'], $r['summary'], true);
        // BEZ załącznika PDF — treścią maila jest raport HTML (tekstowy PDF był brzydki).
        try { $mailer->send($to, 'Raport DUiR: '.$s['name'], $mailer->buildSubjectBody($s,$r['risk'],$r['events'],$r['summary']), null, $html); $this->repo->saveOutgoingMail($id,$to,'Raport DUiR: '.$s['name'],'sent'); }
        catch(\Throwable $e){ $this->repo->saveOutgoingMail($id,$to,'Raport DUiR: '.$s['name'],'error',$e->getMessage()); }
        Http::redirect('/subjects/'.$id);
    }
}
