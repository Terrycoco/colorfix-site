<?php
declare(strict_types=1);
require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/fixtures/ANAFixtureDatabase.php';
use App\ANA\DTO\ANAEvent;
use App\ANA\Repos\PdoANAEventRepository;
use App\ANA\Services\ANAIgnoreService;
use App\ANA\Services\ANAService;
use App\Repos\PdoAppConfigRepository;
use App\SYSTEM\Services\AdminSession;

$pdo=anaFixtureDatabase();$verified=false;$authChecks=0;$checks=0;
$service=new ANAService(new PdoANAEventRepository($pdo),new ANAIgnoreService(new PdoAppConfigRepository($pdo)),
    static function()use(&$verified,&$authChecks):bool{$authChecks++;return $verified;});
function ownCheck(bool $condition,string $message):void{global $checks;if(!$condition)throw new RuntimeException($message);$checks++;}
$_SERVER['HTTP_USER_AGENT']='Human browser';$_COOKIE=[];
$event=new ANAEvent(eventKey:'visit',resourceType:'page',path:'/picker',src:null,referrer:null);
$historical=$pdo->query('SELECT * FROM analytics_events WHERE id=1')->fetch(PDO::FETCH_ASSOC);
ownCheck($service->record($event)>0,'Ordinary Direct visit records');
ownCheck($authChecks===0,'Anonymous visitors do not trigger an auth lookup');
foreach(['1','true','YES'] as $value){$_COOKIE=['cf_internal_viewer'=>$value];ownCheck($service->record($event)===0,'Persistent own-device cookie excludes regardless of login');}
ownCheck($authChecks===0,'Existing exclusion flag needs no auth lookup');
$test=new ANAEvent(eventKey:'visit',isTest:true,resourceType:'page',path:'/picker');
$id=$service->record($test);
ownCheck($id>0 && (int)$pdo->query('SELECT is_test FROM analytics_events WHERE id='.$id)->fetchColumn()===1,'Explicit Test Mode still records marked devices');
$_COOKIE=['cf_internal_viewer'=>'0'];ownCheck($service->record($event)>0,'False exclusion flag does not suppress visitor');
$_COOKIE=[AdminSession::COOKIE=>'fixture-cookie'];$verified=false;
ownCheck($service->record($event)>0,'Unverified session cookie does not identify an admin');
$verified=true;ownCheck($service->record($event)===0,'Verified admin excludes initial event before local storage is marked');
$prior=$authChecks;ownCheck($service->record($test)>0 && $authChecks===$prior,'Test Mode remains independent of admin check');
$_COOKIE=['cf_internal_viewer'=>'1'];$_SERVER['HTTP_USER_AGENT']='Googlebot';ownCheck($service->record($test)===0,'Crawler policy still applies to test events');
ownCheck($pdo->query('SELECT * FROM analytics_events WHERE id=1')->fetch(PDO::FETCH_ASSOC)===$historical,'Historical events unchanged');
echo "PASS: $checks own-device exclusion, initial-session race, test-mode and history checks\n";
