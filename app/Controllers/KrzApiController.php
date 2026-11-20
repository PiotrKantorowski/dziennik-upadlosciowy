<?php
namespace Duir\Controllers;

use Duir\Bootstrap;
use Duir\Config;
use Duir\Repository;
use Duir\Services\CheckService;
use Duir\Services\ReportService;
use Duir\Support\Http;

final class KrzApiController
{
    private const MIN_PLUGIN_VERSION = '1.8.6';

    public function __construct(private Repository $repo, private CheckService $check, private ?ReportService $reports = null) {}

    // Automatyczna wysyłka raportu dziennego: wtyczka melduje się tu cyklicznie
    // (ping co 3 min, runFinished po przebiegu), więc to naturalny "zegar" —
    // raport wychodzi raz dziennie po porannym przebiegu (bramka w ReportService).
    private function maybeSendDailyReport(): void
    {
        try { $this->reports?->autoSendDailyReportIfDue(); } catch (\Throwable) {}
    }

    private function guard(): void
    {
        $token = $_SERVER['HTTP_X_KRZ_TOKEN'] ?? '';
        $expected = (string)Config::get('KRZ_BRIDGE_TOKEN','');
        if ($expected === '') Http::json(['ok'=>false,'error'=>'missing KRZ bridge token'], 503);
        if (Bootstrap::isWeakBridgeToken() && Config::get('APP_ENV','production') === 'production') {
            Http::json(['ok'=>false,'error'=>'KRZ bridge token not securely configured'], 503);
        }
        if (!hash_equals($expected, (string)$token)) Http::json(['ok'=>false,'error'=>'bad token'], 403);
    }

    private function pluginVersionIsCompatible(): bool
    {
        $version = trim((string)($_SERVER['HTTP_X_DUIR_PLUGIN_VERSION'] ?? ''));
        return $version !== '' && version_compare($version, self::MIN_PLUGIN_VERSION, '>=');
    }

    private function guardPluginVersion(): void
    {
        if (!$this->pluginVersionIsCompatible()) {
            Http::json([
                'ok'=>false,
                'error'=>'Wtyczka jest za stara. Zaktualizuj ją na tym komputerze do wersji '.self::MIN_PLUGIN_VERSION.'.',
                'min_plugin_version'=>self::MIN_PLUGIN_VERSION,
            ], 426);
        }
    }

    public function ping(): void {
        $this->guard();
        // Heartbeat: rejestrujemy tę wtyczkę (stabilny identyfikator komputera) i
        // zwracamy, ile RÓŻNYCH komputerów jest teraz aktywnych — do dzielenia pracy.
        // Starej wtyczki nie liczymy jako aktywnego wykonawcy: jej worklista jest
        // celowo blokowana i taki komputer tylko fałszowałby zielony licznik.
        $compatible = $this->pluginVersionIsCompatible();
        if ($compatible) {
            try { $this->repo->touchPluginInstance((string)($_SERVER['HTTP_X_DUIR_INSTANCE'] ?? ''), $_SERVER['HTTP_X_DUIR_INSTANCE_LABEL'] ?? null); } catch (\Throwable $e) {}
        }
        $active = [];
        try { $active = $this->repo->activePluginInstances(12); } catch (\Throwable $e) {}
        $this->maybeSendDailyReport();
        $v=is_file(Config::root().'/VERSION')?trim((string)file_get_contents(Config::root().'/VERSION')):'1.0.0';
        Http::json(['ok'=>true,'app'=>'DUiR PHP/MySQL FULL','version'=>$v,'time'=>date(DATE_ATOM),'sweep_requested_at'=>$this->repo->setting('krz_sweep_requested_at'),'active_plugins'=>count($active),'plugin_compatible'=>$compatible,'min_plugin_version'=>self::MIN_PLUGIN_VERSION]);
    }

    public function worklist(): void
    {
        $this->guard();
        // Kontrola PRZED claimTasks(): stara wtyczka nie może nawet zarezerwować
        // zadania ze wspólnej kolejki używanej przez wiele komputerów.
        $this->guardPluginVersion();
        $items = [];
        // pendingKrzWorklist() ATOMOWO rezerwuje paczkę dla tej wtyczki (claimed_by)
        // i oznacza zadania jako running — bez dodatkowego markKrzRunning per wiersz.
        foreach($this->repo->pendingKrzWorklist() as $t) {
            $items[] = ['task_id'=>(int)$t['id'],'subject_id'=>(int)$t['subject_id'],'claim_token'=>(string)$t['claimed_by'],'name'=>$t['name'],'krs'=>$t['krs'],'nip'=>$t['nip'],'regon'=>$t['regon'],'pesel'=>$t['pesel'],'query'=>$t['query'],'query_key'=>$t['query_key'],'search_kind'=>$t['search_kind']];
        }
        Http::json(['ok'=>true,'krz_url'=>Config::get('KRZ_URL','https://portal-pub-prod.apps.ocp.prod.ms.gov.pl/'),'items'=>$items]);
    }


    public function subjects(): void
    {
        $this->guard();
        $this->guardPluginVersion();
        $items = [];
        foreach ($this->repo->monitoredKrzSubjects() as $s) {
            $items[] = [
                'subject_id' => (int)$s['id'],
                'name' => $s['name'],
                'krs' => $s['krs'],
                'nip' => $s['nip'],
                'regon' => $s['regon'],
                'pesel' => $s['pesel'],
            ];
        }
        Http::json(['ok'=>true,'items'=>$items]);
    }

    public function ingest(): void
    {
        $this->guard(); $this->guardPluginVersion(); $data=Http::bodyJson();
        $result=$this->check->ingestKrz((int)($data['subject_id']??0),(string)($data['text']??''),$data['source_url']??null,$data);
        Http::json($result);
    }

    public function runFinished(): void
    {
        $this->guard(); $this->guardPluginVersion(); $data=Http::bodyJson();
        $seen = [];
        foreach(($data['items']??[]) as $item){
            $sid=(int)($item['subject_id']??0); if(!$sid)continue;
            $taskId=(int)($item['task_id']??0) ?: null;
            $claimToken=trim((string)($item['claim_token']??$item['claimToken']??''));
            // Spóźniony wynik z innego komputera nie może zamknąć aktualnej
            // dzierżawy, nawet gdy zna task_id i subject_id.
            if (!$taskId || !$this->repo->taskClaimIsValid('KRZ',$taskId,$sid,$claimToken)) continue;
            // Deduplikacja: jeden podmiot+zadanie oznaczamy raz na przebieg,
            // nawet jeśli wtyczka przysłała powtórzone pozycje.
            $key = $sid.'|'.$taskId;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            if(!empty($item['captured'])) continue;
            if(!empty($item['noResults'])) {
                if ($this->check->textConfirmsNoResultsForSubject((string)($item['pageText'] ?? ''), $sid)) {
                    $this->repo->markKrzNoResults($sid,$item,$taskId);
                } else {
                    $this->repo->markKrzError($sid,'KRZ: odrzucono „brak wyników” bez potwierdzenia kryterium monitorowanego podmiotu.',$item,$taskId);
                }
                continue;
            }
            if(!empty($item['error'])||!empty($item['timedOut'])) {
                // Siatka bezpieczeństwa: jeśli próbka strony w błędzie zawiera
                // potwierdzony komunikat braku wyników, to NIE jest błąd.
                if ($this->check->textConfirmsNoResultsForSubject((string)($item['pageText'] ?? ''), $sid)) { $this->repo->markKrzNoResults($sid,$item,$taskId); continue; }
                $this->repo->markKrzError($sid,(string)($item['error']??'Timeout KRZ'),$item,$taskId); continue;
            }
            $this->repo->markKrzError($sid,'KRZ: przebieg zakończony bez potwierdzenia wyniku i bez potwierdzonego braku wyników.',$item,$taskId);
        }
        $this->maybeSendDailyReport();
        Http::json(['ok'=>true]);
    }
}
