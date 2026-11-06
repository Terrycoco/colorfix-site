<?php
declare(strict_types=1);
require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/fixtures/ANAFixtureDatabase.php';
use App\ANA\DTO\ANAEvent;
use App\ANA\Repos\PdoANAEventRepository;
use App\ANA\Repos\PdoANAReportRepository;
use App\ANA\Services\ANAIgnoreService;
use App\ANA\Services\ANAService;
use App\Repos\PdoAppConfigRepository;

$pdo=anaFixtureDatabase();$config=new PdoAppConfigRepository($pdo);
$ignore=new ANAIgnoreService($config);$service=new ANAService(new PdoANAEventRepository($pdo),$ignore);
$checks=0;
function anaCheck(bool $value,string $message):void{global $checks;if(!$value)throw new RuntimeException($message);$checks++;}
$historical=$pdo->query('SELECT * FROM analytics_events WHERE id=1')->fetch(PDO::FETCH_ASSOC);
anaCheck($historical['user_agent']===null,'Migration must leave historical UA NULL');
$event=new ANAEvent(eventKey:'visit',resourceType:'page',resourceId:999,path:'/picker',src:null,referrer:null,userAgent:'Client-spoofed-UA');
foreach(['ElevenlabsCrawler','eLeVeNlAbScRaWlEr','Googlebot','gOoGlEbOt','Google-InspectionTool','gOoGlE-iNsPeCtIoNtOoL'] as $ua){
 $_SERVER['HTTP_USER_AGENT']='Mozilla/5.0 (compatible; '.$ua.'/1.0)';
 anaCheck($service->record($event)===0,'Crawler should be ignored: '.$ua);
}
anaCheck($pdo->configLoads===1,'Load config only once for multiple patterns/events in a request');
anaCheck((int)$pdo->query('SELECT COUNT(*) FROM analytics_events')->fetchColumn()===1,'Ignored requests must not insert');
$_SERVER['HTTP_USER_AGENT']='Mozilla/5.0 Human Browser';$id=$service->record($event);
$row=$pdo->query('SELECT * FROM analytics_events WHERE id='.$id)->fetch(PDO::FETCH_ASSOC);
anaCheck($row['user_agent']===$_SERVER['HTTP_USER_AGENT'],'UA must come from server, not event object');
anaCheck($row['source_key']===null && $row['referrer']===null,'Direct and blank referrer must record');
anaCheck((int)$row['resource_id']===1,'Existing registered-page resolution must remain');
unset($_SERVER['HTTP_USER_AGENT']);$id=$service->record($event);
anaCheck($pdo->query('SELECT user_agent FROM analytics_events WHERE id='.$id)->fetchColumn()===null,'Missing header must record with NULL UA');
$_SERVER['HTTP_USER_AGENT']=str_repeat('é',1200);$id=$service->record($event);
$ua=$pdo->query('SELECT user_agent FROM analytics_events WHERE id='.$id)->fetchColumn();
anaCheck(mb_strlen($ua,'UTF-8')===1000 && mb_check_encoding($ua,'UTF-8'),'Bound UA without splitting Unicode');
$_SERVER['HTTP_USER_AGENT']='Browser \'"><script>alert(1)</script>';$id=$service->record(new ANAEvent(eventKey:'visit',resourceType:'playlist',resourceId:3,isTest:true,src:'client'));
$report=(new PdoANAReportRepository($pdo))->listEventsForResource('playlist',3,'visit','Client');
anaCheck($report[0]['user_agent']===$_SERVER['HTTP_USER_AGENT'] && (int)$report[0]['is_test']===1,'Parameterized UA storage, detail projection and test flag');
// Saving through the existing repository refreshes the same config service and
// a new request/service; an explicitly empty list is not replaced with defaults.
$ignore->save(['CustomCrawler','customcrawler']);
anaCheck($ignore->patterns()===['customcrawler'],'Deduplicate case-insensitive tokens');
$_SERVER['HTTP_USER_AGENT']='Googlebot';anaCheck($service->record($event)>0,'Removed pattern takes effect after save');
$_SERVER['HTTP_USER_AGENT']='CustomCrawler';anaCheck($service->record($event)===0,'New pattern takes effect after save');
$next=ANAService::fromPdo($pdo);anaCheck($next->record($event)===0,'New request sees edited list');
$ignore->save([]);anaCheck($service->record($event)>0,'Empty list disables filtering');
foreach(['Mozilla','Mozilla/5.0','bot','Chrome'] as $broad){try{$ignore->save([$broad]);throw new RuntimeException('Broad pattern accepted');}catch(InvalidArgumentException){$checks++;}}
anaCheck($pdo->query('SELECT * FROM analytics_events WHERE id=1')->fetch(PDO::FETCH_ASSOC)===$historical,'Historical event unchanged');
$ignore->save(ANAIgnoreService::DEFAULT_PATTERNS);
$legacy=new \App\Analytics\Repos\PdoAnalyticsEventRepository($pdo);
$_SERVER['HTTP_USER_AGENT']='Googlebot';anaCheck($legacy->record(new \App\Analytics\DTO\AnalyticsEvent(eventKey:'visit',resourceType:'playlist',resourceId:3))===0,'Legacy writer cannot bypass central filter');
$_SERVER['HTTP_USER_AGENT']='Legacy human browser';$id=$legacy->record(new \App\Analytics\DTO\AnalyticsEvent(eventKey:'visit',resourceType:'playlist',resourceId:3,reservationToken:'fixture-rex'));
$row=$pdo->query('SELECT * FROM analytics_events WHERE id='.$id)->fetch(PDO::FETCH_ASSOC);
anaCheck($row['reservation_token']==='fixture-rex' && $row['user_agent']==='Legacy human browser','Legacy adapter preserves its token and captures UA');
echo "PASS: $checks ANA migration, persistence, config cache/refresh, Direct/test and legacy checks\n";
