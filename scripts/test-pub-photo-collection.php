<?php
declare(strict_types=1);
require_once __DIR__ . '/../api/autoload.php';

use App\PLAYLISTS\Repos\PdoPlaylistRepository;
use App\PHOTOS\Repos\PdoPhotoLibraryRepository;
use App\PHOTOS\Services\PhotoEditorialService;
use App\PALETTES\PV\PVService;
use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Services\RexReservationRelationships;
use App\PUB\Analyze\Sources\PlaylistSourcePreparer;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE photo_library (photo_library_id INTEGER PRIMARY KEY, title TEXT, rel_path TEXT, tags TEXT, alt_text TEXT, crop_x REAL, custom_metadata TEXT)');
$pdo->exec("INSERT INTO photo_library VALUES (1,'Library title','/photos/one.jpg','art, blue','Blue room',0.25,'preserve me'),(2,'No editorial','/photos/two.jpg',NULL,NULL,NULL,NULL),(3,'No image','',NULL,NULL,NULL,NULL)");
$pdo->exec('CREATE TABLE photo_editorial (photo_library_id INTEGER PRIMARY KEY, photo_description TEXT, design_notes TEXT, refined_commentary TEXT)');
$pdo->exec("INSERT INTO photo_editorial VALUES (1,'Description','Design notes','Commentary')");
$pdo->exec('CREATE TABLE rex_reservations (id INTEGER, resolver_key TEXT, resource_type TEXT, resource_id INTEGER, status TEXT, experience_key TEXT)');
$playlists = new class($pdo) extends PdoPlaylistRepository {
    public ?string $editorialType = 'themed_makeover';
    public function getAdminRowById(int $id): ?array { return ['title'=>'Playlist title','editorial_type'=>$this->editorialType]; }
    public function getActivePublishingSlides(int $id): array {
        return [
            ['photo_library_id'=>1,'image_url'=>'/photos/one.jpg','title'=>'Slide title','pin'=>0,'yt'=>1],
            ['photo_library_id'=>1,'image_url'=>'/photos/one.jpg','title'=>'Repeated photo'],
            ['photo_library_id'=>2,'image_url'=>'','title'=>'Fallback URL'],
            ['photo_library_id'=>3,'image_url'=>''],
            ['photo_library_id'=>999,'image_url'=>'/photos/legacy.jpg','title'=>'Legacy slide'],
            ['item_type'=>'text','title'=>'No photo'],
        ];
    }
};
$photos = new PdoPhotoLibraryRepository($pdo);
$preparer = new PlaylistSourcePreparer($playlists, new PVService($pdo), new RexReservationRelationships(new PdoRexReservationRepository($pdo)), new PhotoEditorialService($pdo), $photos);
$source = $preparer->prepare(60);
$assert = static function(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$assert($source['editorial_type']==='themed_makeover', 'Playlist editorial type missing');
$assert(count($source['items'])===6, 'Collection must not filter slides');
$photo = $source['items'][0]['photo'];
$record = $photos->findManyByIds([1,1,0,-1,999])[1];
foreach ($record as $key=>$value) $assert($photo[$key]===$value, "Lost owner field {$key}");
$assert($photo['title']==='Library title' && $source['items'][0]['title']==='Slide title', 'Photo/slide titles conflated');
$assert($photo['editorial']===['photo_description'=>'Description','design_notes'=>'Design notes','refined_commentary'=>'Commentary'], 'Editorial incomplete');
$assert($source['items'][1]['photo']===$photo, 'Repeated photo inconsistent');
$assert($source['items'][2]['photo']['image_url']==='/photos/two.jpg', 'Library URL fallback missing');
$assert($source['items'][2]['photo']['editorial']===array_fill_keys(['photo_description','design_notes','refined_commentary'],null), 'Missing editorial must have nullable keys');
$assert($source['items'][3]['photo']['title']==='No image', 'Metadata-only photo lost');
$assert($source['items'][4]['photo']['title']==='Legacy slide', 'Legacy delivery fallback changed');
$assert(!isset($source['items'][5]['photo']), 'Text slide gained a fake photo');
$assert($photos->findManyByIds([])===[], 'Empty bulk lookup');
$playlists->editorialType=null;
$assert($preparer->prepare(60)['editorial_type']===null, 'Unset editorial type must be null');
$contract = json_encode(App\PUB\Contracts\PubContract::all());
$assert(str_contains($contract,'editorial_type') && str_contains($contract,'refined_commentary'), 'Contract missing input fields');
echo "PASS: full owner photo snapshots, delivery compatibility, editorial fields, playlist type/null, missing metadata, repeated photos and neutral slide collection.\n";
