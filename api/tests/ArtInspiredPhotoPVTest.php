<?php
declare(strict_types=1);
require_once __DIR__ . '/../autoload.php';

use App\PALETTES\Services\PaletteViewerAccessService;
use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Services\RexReserver;
use App\REX\Services\RexTokenGenerator;
use App\REX\DTO\RexCreateReservationRequest;
use App\REX\Services\RexResolver;
use App\REX\Resolvers\RexResolverRegistryFactory;
use App\REX\Endpoints\RexResolveEndpoint;

(function () use ($argv) {
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->sqliteCreateFunction('NOW',static fn()=> '2026-11-13 12:00:00');
$schemas=[
'photo_library'=>'photo_library_id INTEGER PRIMARY KEY,asset_library_id INTEGER,source_type TEXT,source_id INTEGER,client_id INTEGER,photo_permission_status TEXT,rel_path TEXT,title TEXT,tags TEXT,alt_text TEXT,ai_alt_text TEXT,show_in_gallery INTEGER DEFAULT 1,has_palette INTEGER,is_inactive INTEGER DEFAULT 0,is_retired INTEGER DEFAULT 0,inspiration_photo_id INTEGER,palette_id INTEGER,updated_at TEXT',
'photos_artwork'=>'artwork_id INTEGER PRIMARY KEY,photo_library_id INTEGER,artist TEXT,official_title TEXT,display_title TEXT,year INTEGER,circa INTEGER,medium TEXT,museum TEXT,source_url TEXT,historical_notes TEXT,color_analysis TEXT,created_at TEXT,updated_at TEXT',
'saved_palettes'=>'id INTEGER PRIMARY KEY,display_title TEXT,nickname TEXT,palette_type TEXT,palette_hash TEXT,brand TEXT,notes TEXT,kicker_id INTEGER',
'saved_palette_members'=>'id INTEGER PRIMARY KEY,saved_palette_id INTEGER,color_id INTEGER,role_name TEXT,sheen TEXT,note TEXT,order_index INTEGER',
'swatch_view'=>'id INTEGER PRIMARY KEY,name TEXT,brand TEXT,brand_name TEXT,code TEXT,hex6 TEXT,hcl_h REAL,hcl_c REAL,hcl_l REAL,int_only INTEGER,chip_num TEXT,cluster_id INTEGER',
'palette_viewers'=>'palette_viewer_id INTEGER PRIMARY KEY,saved_palette_id INTEGER,format TEXT,template_key TEXT,kicker_text TEXT,title TEXT,intro TEXT,notes TEXT,cta_label TEXT,is_active INTEGER,created_at TEXT,updated_at TEXT',
'rex_reservations'=>'id INTEGER PRIMARY KEY,token TEXT UNIQUE,label TEXT,admin_note TEXT,qr_key TEXT,resolver_key TEXT,resource_type TEXT,resource_id INTEGER,context_json TEXT,status TEXT,revoked_at TEXT,created_at TEXT,updated_at TEXT,experience_key TEXT,fallback_rex_id INTEGER',
'rex_aliases'=>'id INTEGER PRIMARY KEY,reservation_id INTEGER,alias TEXT',
'rex_reservation_links'=>'id INTEGER PRIMARY KEY,parent_reservation_id INTEGER,child_reservation_id INTEGER,relationship_key TEXT,sort_order INTEGER,created_at TEXT,updated_at TEXT',
];
foreach($schemas as $name=>$cols)$pdo->exec("CREATE TABLE $name ($cols)");
$pdo->exec("INSERT INTO saved_palettes VALUES (11,'Artwork colors','Art','interior','art-eight','behr','',NULL),(12,'Ordinary colors','Ordinary','interior','ordinary','behr','',NULL)");
$pdo->exec("INSERT INTO photo_library (photo_library_id,source_type,inspiration_photo_id,palette_id,rel_path,title) VALUES (1,'art',NULL,11,'/fixture/art.png','Painting'),(2,'art_inspired',1,NULL,'/fixture/room2.png','Dining room'),(3,'art_inspired',1,12,'/fixture/room3.png','Living room'),(4,'progression',NULL,12,'/fixture/ordinary.png','Ordinary room')");
$pdo->exec("INSERT INTO photos_artwork (artwork_id,photo_library_id,artist,official_title,display_title) VALUES (101,1,'Fixture artist','Painting','Painting')");
foreach(range(1,8) as $i){$pdo->exec("INSERT INTO saved_palette_members VALUES ($i,11,$i,NULL,NULL,NULL,$i)");$pdo->exec("INSERT INTO swatch_view (id,name,brand,brand_name,hex6) VALUES ($i,'Art color $i','behr','Behr','".sprintf('%06x',0x334455+$i*1000)."')");}
$pdo->exec('INSERT INTO saved_palette_members VALUES (9,12,1,NULL,NULL,NULL,1)');
$n=0;$check=function($v,$m)use(&$n){if(!$v)throw new RuntimeException($m);$n++;};
$access=new PaletteViewerAccessService($pdo);$repo=new PdoRexReservationRepository($pdo);
$reserver=new RexReserver($repo,new RexTokenGenerator());
$existing=$reserver->reserve(new RexCreateReservationRequest(label:'Already shared room',resolverKey:'viewer',resourceType:'saved_palette',resourceId:11,context:['format'=>'public','art_inspired_photo_id'=>2],experienceKey:'public'));
$first=$access->openForArtInspiredPhoto(2);$check($first['token']===$existing->token&&$first['reused'],'Reuse pre-existing exact room experience');
$other=$access->openForArtInspiredPhoto(3);$check(!$other['reused']&&$other['token']!==$first['token'],'Different rooms get separate tokens');
$again=$access->openForArtInspiredPhoto(3);$check($again['token']===$other['token']&&$again['reused'],'Repeated room opening reuses token');
$check((int)$pdo->query('SELECT COUNT(*) FROM rex_reservations')->fetchColumn()===2,'No duplicate reservations');
$check((int)$pdo->query('SELECT COUNT(*) FROM saved_palettes')->fetchColumn()===2,'No duplicate palettes');
$resolver=new RexResolver($repo,RexResolverRegistryFactory::build($pdo));
$results=[];
foreach([2=>$first,3=>$other] as $id=>$entry){
 $result=$resolver->resolveToken($entry['token']);$viewer=$result->destination['viewer'];
 $check($viewer['meta']['photo_library_id']===$id,'Restore exact clicked room');
 $check(count($viewer['swatches'])===8,'Every artwork color remains visible');
 $check(array_map('intval',array_column($viewer['swatches'],'id'))===range(1,8),'Full palette order preserved, ignoring room palette');
 $check($viewer['meta']['art_inspiration']['study_url']==='/inspired-by/101','Inset links by artwork ID, not photo ID');
 $check($result->shareMetadata->imageUrl==='/fixture/room'.$id.'.png','REX sharing uses room image');
 $results[$entry['token']]=RexResolveEndpoint::payload($result,$repo->findByToken($entry['token']));
}
foreach([11=>'art',12=>'ordinary'] as $id=>$label){
 $reservation=$reserver->reserve(new RexCreateReservationRequest(label:$label,resolverKey:'viewer',resourceType:'saved_palette',resourceId:$id,context:['format'=>'public'],experienceKey:'public'));
 $result=$resolver->resolveToken($reservation->token);$viewer=$result->destination['viewer'];
 $check(!isset($viewer['meta']['art_inspiration']),'Normal/artwork PV has no new inset');
 $check($viewer['meta']['photo_library_id']===($id===11?1:3),'Normal palette main-photo selection unchanged');
}
foreach(['client_id=7','show_in_gallery=0','is_inactive=1','is_retired=1',"photo_permission_status='declined'"] as $change){
 $pdo->exec('UPDATE photo_library SET '.$change.' WHERE photo_library_id=2');
 try{$access->openForArtInspiredPhoto(2);throw new LogicException('Private room exposed');}catch(OutOfBoundsException){$n++;}
 $pdo->exec('UPDATE photo_library SET client_id=NULL,show_in_gallery=1,is_inactive=0,is_retired=0,photo_permission_status=NULL WHERE photo_library_id=2');
}
$pdo->exec('UPDATE photo_library SET show_in_gallery=0 WHERE photo_library_id=1');
try{$resolver->resolveToken($first['token']);throw new LogicException('Unpublished source artwork exposed');}catch(OutOfBoundsException){$n++;}
$pdo->exec('UPDATE photo_library SET show_in_gallery=1 WHERE photo_library_id=1');
$rows=(new App\Repos\PdoColorTriggerPhotoRepository($pdo))->findGalleryTriggerPhotosForColor(8);
$check(array_map('intval',array_column($rows,'photo_library_id'))===[1,2,3],'Color search uses full artwork palette for both linked rooms');
$grouped=(new App\GALLERY\Repos\PdoGalleryResultsRepository($pdo))->findByColorIds([8]);
$check(array_map('intval',array_column($grouped[8],'photo_library_id'))===[3,2,1],'Gallery enrichment includes all linked room photos using artwork membership');
$check(count($results)===2,'Fixture includes two real REX resolution responses');
if(in_array('--json',$argv,true))echo json_encode(['responses'=>$results,'rooms'=>[2=>$first,3=>$other],'checks'=>$n],JSON_THROW_ON_ERROR);
else echo "PASS: $n art-inspired PV, eight-color palette, REX reuse/resolution, ordinary/artwork and privacy checks\n";
})();
