<?php
require_once dirname(__DIR__).'/app/Bootstrap.php';
\Duir\Bootstrap::init(dirname(__DIR__));
use Duir\{Database,Repository};
use Duir\Services\{CheckService,KrsClient,RiskAnalyzer,ReportService,Mailer};

$cmd = $argv[1] ?? 'help';
$repo = new Repository((new Database())->pdo());
\Duir\Config::apply($repo->allSettings());
$check = new CheckService($repo, new KrsClient(), new RiskAnalyzer());
if ($cmd === 'check:all') { $check->checkAllMonitored(); echo "OK check:all\n"; exit; }
if ($cmd === 'check:subject') { $id=(int)($argv[2]??0); $s=$repo->findSubject($id); if(!$s){fwrite(STDERR,"Nie znaleziono\n");exit(1);} $check->checkSubject($s); echo "OK check:subject $id\n"; exit; }
if ($cmd === 'report:daily') { $r=(new ReportService($repo))->createDailyReport(); echo $r['pdf_path']."\n"; exit; }
if ($cmd === 'mail:daily') { $r=(new ReportService($repo))->createDailyReport(); (new Mailer())->send((string)\Duir\Config::get('REPORT_TO',''),'Raport dzienny DUiR',$r['summary'],$r['pdf_path']); echo "OK mail:daily\n"; exit; }
echo "DUiR PHP/MySQL console\nCommands: check:all, check:subject ID, report:daily, mail:daily\n";
