<?php
namespace Duir\Controllers;

use Duir\Bootstrap;
use Duir\Config;
use Duir\Repository;
use Duir\Services\CheckService;
use Duir\Services\ReportService;
use Duir\Support\Http;

final class MsigApiController
{
    private const MIN_PLUGIN_VERSION = '1.8.6';

    public function __construct(private Repository $repo, private CheckService $check, private ?ReportService $reports = null) {}

    private function guard(): void
    {
        $token = $_SERVER['HTTP_X_MSIG_TOKEN'] ?? ($_SERVER['HTTP_X_KRZ_TOKEN'] ?? '');
        $expected = (string)Config::get('KRZ_BRIDGE_TOKEN','');
        if ($expected === '') Http::json(['ok'=>false,'error'=>'missing bridge token'], 503);
        if (Bootstrap::isWeakBridgeToken() && Config::get('APP_ENV','production') === 'production') {
            Http::json(['ok'=>false,'error'=>'KRZ bridge token not securely configured'], 503);
        }
        if (!hash_equals($expected, (string)$token)) Http::json(['ok'=>false,'error'=>'bad token'], 403);
    }

    private function guardPluginVersion(): void
    {
        $version = trim((string)($_SERVER['HTTP_X_DUIR_PLUGIN_VERSION'] ?? ''));
        if ($version === '' || version_compare($version, self::MIN_PLUGIN_VERSION, '<')) {
            Http::json([
                'ok'=>false,
                'error'=>'Wtyczka jest za stara. Zaktualizuj ją na tym komputerze do wersji '.self::MIN_PLUGIN_VERSION.'.',
                'min_plugin_version'=>self::MIN_PLUGIN_VERSION,
            ], 426);
        }
    }

    public function ping(): void { $this->guard(); Http::json(['ok'=>true,'app'=>'DUiR PHP/MySQL FULL','time'=>date(DATE_ATOM),'sweep_requested_at'=>$this->repo->setting('msig_sweep_requested_at'),'min_plugin_version'=>self::MIN_PLUGIN_VERSION]); }

    public function worklist(): void
    {
        $this->guard();
        $this->guardPluginVersion();
        $items = [];
        // pendingMsigWorklist() ATOMOWO rezerwuje paczkę dla tej wtyczki (claimed_by)
        // i oznacza zadania jako running — bez dodatkowego markMsigRunning per wiersz.
        foreach($this->repo->pendingMsigWorklist() as $t) {
            $items[] = ['task_id'=>(int)$t['id'],'subject_id'=>(int)$t['subject_id'],'claim_token'=>(string)$t['claimed_by'],'name'=>$t['name'],'krs'=>$t['krs'],'nip'=>$t['nip'],'regon'=>$t['regon'],'pesel'=>$t['pesel'],'query'=>$t['query'],'query_key'=>$t['query_key'],'search_kind'=>$t['search_kind'],
                'seen'=>$this->repo->seenMsigDownloadIds((int)$t['subject_id'])];
        }
        Http::json(['ok'=>true,'msig_url'=>Config::get('MSIG_URL','https://wyszukiwarka-msig.ms.gov.pl/'),'items'=>$items]);
    }

    public function ingest(): void
    {
        $this->guard(); $this->guardPluginVersion(); $data=Http::bodyJson();
        $result=$this->check->ingestMsig((int)($data['subject_id']??0),(string)($data['text']??''),$data['source_url']??null,$data);
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
            if (!$taskId || !$this->repo->taskClaimIsValid('MSIG',$taskId,$sid,$claimToken)) continue;
            // Deduplikacja: jeden podmiot+zadanie oznaczamy raz na przebieg.
            $key = $sid.'|'.$taskId;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            if(!empty($item['captured'])) continue;
            if(!empty($item['noResults'])) {
                if ($this->check->textConfirmsNoResultsForSubject((string)($item['pageText'] ?? ''), $sid)) {
                    $this->repo->markMsigNoResults($sid,$item,$taskId);
                } else {
                    $this->repo->markMsigError($sid,'MSiG: odrzucono „brak wyników” bez potwierdzenia kryterium monitorowanego podmiotu.',$item,$taskId);
                }
                continue;
            }
            if(!empty($item['error'])||!empty($item['timedOut'])) {
                // Siatka bezpieczeństwa: próbka strony z potwierdzonym komunikatem
                // braku wyników zamienia błąd na czysty wynik.
                if ($this->check->textConfirmsNoResultsForSubject((string)($item['pageText'] ?? ''), $sid)) { $this->repo->markMsigNoResults($sid,$item,$taskId); continue; }
                $this->repo->markMsigError($sid,(string)($item['error']??'Timeout MSiG'),$item,$taskId); continue;
            }
            $this->repo->markMsigError($sid,'MSiG: przebieg zakończony bez potwierdzenia wyniku i bez potwierdzonego braku wyników.',$item,$taskId);
        }
        // Po domknięciu przebiegu MSiG raport dzienny może być gotowy do wysyłki.
        try { $this->reports?->autoSendDailyReportIfDue(); } catch (\Throwable) {}
        Http::json(['ok'=>true]);
    }
}
