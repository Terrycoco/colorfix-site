<?php
declare(strict_types=1);

use App\PHOTOS\Repos\PdoPhotoLibraryRepository;
use App\Repos\PdoSavedPaletteRepository;
use App\Services\PaletteViewerService;

function retirement_current_viewer_fixture(): PDO
{
    $pdo=painter_viewer_rex_fixture();
    $pdo->exec('DELETE FROM project_palettes WHERE saved_palette_id=11');
    $pdo->exec('ALTER TABLE photo_library ADD COLUMN palette_id INTEGER');
    $pdo->exec('ALTER TABLE photo_library ADD COLUMN title TEXT');
    $pdo->exec("ALTER TABLE palette_viewer_photos ADD COLUMN trigger_mode TEXT DEFAULT 'any'");
    $pdo->exec("ALTER TABLE palette_viewer_photos ADD COLUMN created_at TEXT DEFAULT '2026-11-07'");
    $pdo->exec('ALTER TABLE saved_palettes ADD COLUMN palette_hash TEXT');
    $pdo->exec("UPDATE saved_palettes SET palette_hash='current-hash' WHERE id=11");
    $pdo->exec("INSERT INTO photo_library(photo_library_id,palette_id,title,rel_path) VALUES(20,11,'Main','/photos/library/main.png')");
    $pdo->exec("INSERT INTO palette_viewers(palette_viewer_id,saved_palette_id,format,title,is_active,created_at) VALUES(88,11,'public','Current public header',1,'2026-11-07')");
    $pdo->exec("INSERT INTO palette_viewer_photos(palette_viewer_id,rel_path,photo_type,caption,order_index) VALUES
        (88,'/current-full.png','full','Full',0),(88,'/current-before.png','before','Before',1),(88,'/current-zoom.png','zoom','Zoom',2)");
    return $pdo;
}

function retirement_viewer_service(PDO $pdo): PaletteViewerService
{
    return new PaletteViewerService(new PdoSavedPaletteRepository($pdo),null,null,
        new \App\Repos\PdoPaletteViewerRepository($pdo),new \App\Repos\PdoPaletteViewerPhotoRepository($pdo));
}

test('saved palette reads main photograph without any retired tables and keeps current PV metadata',function(){
    $pdo=retirement_current_viewer_fixture();
    $full=(new PdoSavedPaletteRepository($pdo))->getFullPalette(11);
    assert_equals(20,$full['photos'][0]['photo_library_id']);
    assert_equals('full',$full['photos'][0]['photo_type']);
    assert_equals([],$full['sets']);
    assert_equals('Current public header',$full['viewer_content'][array_search(88,array_column($full['viewer_content'],'palette_viewer_id'))]['title']);
    $result=retirement_viewer_service($pdo)->getSaved('current-hash',99999);
    assert_equals('Current public header',$result['meta']['title']);
    assert_equals('/current-full.png',$result['meta']['photo_url']);
    assert_equals(['/current-before.png','/current-zoom.png'],array_column($result['meta']['inset_photos'],'url'));
});

test('hash viewer obeys current project selections and never restores retired/raw photographs',function(){
    $pdo=retirement_current_viewer_fixture();
    $pdo->exec("INSERT INTO project_palettes VALUES(101,7,11,'Kitchen','Current',1,1)");
    $pdo->exec('UPDATE palette_viewers SET project_id=7 WHERE palette_viewer_id=88');
    $pdo->exec('CREATE TABLE project_photos(project_id INTEGER,photo_library_id INTEGER,`use` INTEGER,palette_id INTEGER,zoom INTEGER,main INTEGER,`before` INTEGER,sort_order INTEGER)');
    $pdo->exec('INSERT INTO project_photos VALUES(7,20,0,11,1,1,0,0)');
    $result=retirement_viewer_service($pdo)->getSavedById(11);
    assert_equals('',$result['meta']['photo_url']);
    assert_equals([],$result['meta']['inset_photos']);
});

test('saved palette fallback cannot bypass an inactive current viewer',function(){
    $pdo=retirement_current_viewer_fixture();
    $pdo->exec("UPDATE palette_viewers SET is_active=0 WHERE saved_palette_id=11 AND format='public'");
    foreach (['hash','id'] as $lookup) {
        try {
            $service=retirement_viewer_service($pdo);
            if ($lookup==='hash') {$service->getSaved('current-hash');} else {$service->getSavedById(11);}
            throw new LogicException('Inactive viewer fallback must be blocked');
        } catch (RuntimeException $e) {
            assert_true(str_contains($e->getMessage(),'is inactive'));
        }
    }
    // A genuinely absent Concept viewer may still use the existing palette fallback.
    $concept=retirement_viewer_service($pdo)->getSaved('current-hash',null,'concept');
    assert_equals('concept',$concept['meta']['palette_viewer_key']);
    assert_equals('/photos/library/main.png',$concept['meta']['photo_url']);
    assert_equals('', $concept['meta']['intro']);
    assert_equals('', $concept['meta']['playlist_url']);
    $pdo->exec("INSERT INTO palette_viewers(palette_viewer_id,saved_palette_id,format,title,is_active,created_at) VALUES(89,11,'public','Second active public',1,'2026-11-07')");
    assert_equals('Second active public',retirement_viewer_service($pdo)->getSavedById(11)['meta']['title']);
});

test('current lifecycle works after retired tables are absent and retains artwork protections',function(){
    with_lifecycle(function($pdo,$root,$service){
        foreach(['saved_palette_set_photos','saved_palette_sets','saved_palette_photos'] as$table) $pdo->exec('DROP TABLE '.$table);
        $pdo->exec("INSERT INTO photos_artwork VALUES(1,736,'Artist')");
        assert_equals(409,$service->delete(736)['status']);
        $service->merge(736,900,true);
        assert_equals(900,$pdo->query('SELECT photo_library_id FROM photos_artwork')->fetchColumn());
        assert_true(is_file($root.'/photos/a.png'));
    });
});

test('retired relationship rows are protected until separately approved database cleanup',function(){
    with_lifecycle(function($pdo,$root,$service){
        $pdo->exec("INSERT INTO saved_palette_set_photos VALUES(1,736,'zoom','/photos/a.png')");
        assert_equals(409,$service->delete(736)['status']);
        try{$service->merge(736,900,true);throw new LogicException('Expected retirement interlock');}
        catch(DomainException $e){assert_true(str_contains($e->getMessage(),'retired'));}
        assert_equals(736,$pdo->query('SELECT photo_library_id FROM saved_palette_set_photos')->fetchColumn());
        assert_true(is_file($root.'/photos/a.png'));
    });
});

test('replacement ignores old source IDs and preserves raw PV path references',function(){
    [$pdo,$root,$repo]=photo_library_usage_fixture();
    try {
        $pdo->exec("UPDATE photo_library SET source_type='saved_palette_photo',source_id=900 WHERE photo_library_id=736");
        $pdo->exec("INSERT INTO palette_viewer_photos VALUES(85,NULL,'/photos/old.png','zoom')");
        $original=$pdo->query('SELECT rel_path FROM photo_library WHERE photo_library_id=900')->fetchColumn();
        $result=(new \App\PHOTOS\Services\PhotoReplacementService($pdo,$root))->replace(736,$root.'/source.png');
        assert_equals($original,$pdo->query('SELECT rel_path FROM photo_library WHERE photo_library_id=900')->fetchColumn());
        assert_true($result['old_file_cleanup']['file_retained']);
        assert_true(is_file($root.'/photos/old.png'));
    } finally {cleanup_photo_replacement_fixture($root);}
});

test('explicit main replacement changes only direct associations and preserves all photographs and Gallery flags',function(){
    with_lifecycle(function($pdo,$root,$lifecycle){
        $pdo->exec("INSERT INTO saved_palettes VALUES(162,'Current palette','hash')");
        $pdo->exec('UPDATE photo_library SET palette_id=162,has_palette=1,show_in_gallery=1 WHERE photo_library_id=736');
        $service=new \App\PHOTOS\Services\PhotoPaletteAssociationService($pdo);
        try{$service->attach(900,162);throw new LogicException('Expected explicit replacement conflict');}catch(DomainException $e){}
        assert_equals(162,$pdo->query('SELECT palette_id FROM photo_library WHERE photo_library_id=736')->fetchColumn());
        $service->attach(900,162,true);
        assert_equals(null,$pdo->query('SELECT palette_id FROM photo_library WHERE photo_library_id=736')->fetchColumn());
        assert_equals(162,$pdo->query('SELECT palette_id FROM photo_library WHERE photo_library_id=900')->fetchColumn());
        assert_equals(1,$pdo->query('SELECT show_in_gallery FROM photo_library WHERE photo_library_id=736')->fetchColumn());
        assert_equals(0,$pdo->query('SELECT show_in_gallery FROM photo_library WHERE photo_library_id=900')->fetchColumn());
        assert_equals(2,$pdo->query('SELECT COUNT(*) FROM photo_library')->fetchColumn());
        assert_true(is_file($root.'/photos/a.png')&&is_file($root.'/photos/b.png'));
    });
});

test('main replacement rolls back both associations if unlinking the previous main fails',function(){
    with_lifecycle(function($pdo,$root,$lifecycle){
        $pdo->exec("INSERT INTO saved_palettes VALUES(162,'Current palette','hash')");
        $pdo->exec('UPDATE photo_library SET palette_id=162 WHERE photo_library_id=736');
        $pdo->exec("CREATE TRIGGER fail_detach BEFORE UPDATE ON photo_library WHEN OLD.photo_library_id=736 AND NEW.palette_id IS NULL BEGIN SELECT RAISE(ABORT,'fixture failure'); END");
        try{(new \App\PHOTOS\Services\PhotoPaletteAssociationService($pdo))->attach(900,162,true);throw new LogicException('Expected rollback');}catch(PDOException $e){}
        assert_equals(162,$pdo->query('SELECT palette_id FROM photo_library WHERE photo_library_id=736')->fetchColumn());
        assert_equals(null,$pdo->query('SELECT palette_id FROM photo_library WHERE photo_library_id=900')->fetchColumn());
        assert_true(!$pdo->inTransaction()&&is_file($root.'/photos/a.png')&&is_file($root.'/photos/b.png'));
    });
});

test('current Playlist Instance Set raw URLs and retained PUB render inputs protect their images',function(){
    with_lifecycle(function($pdo,$root,$service){
        $pdo->exec('ALTER TABLE playlist_instance_set_items ADD COLUMN photo_url TEXT');
        $pdo->exec("INSERT INTO playlist_instance_sets VALUES(1,'Current instance set')");
        $pdo->exec("INSERT INTO playlist_instance_set_items VALUES(1,1,NULL,'/photos/a.png?src=sas')");
        assert_equals(409,$service->delete(736)['status']);
        $service->merge(736,900,true);
        assert_equals('/photos/b.png?src=sas',$pdo->query('SELECT photo_url FROM playlist_instance_set_items')->fetchColumn());
    });
    with_lifecycle(function($pdo,$root,$service){
        $pdo->exec('CREATE TABLE pub_render_jobs(id INTEGER PRIMARY KEY,props_json TEXT)');
        $pdo->exec("INSERT INTO pub_render_jobs VALUES(1,'{\"image\":\"/photos/a.png\"}')");
        assert_equals(409,$service->delete(736)['status']);
        assert_true(is_file($root.'/photos/a.png'));
    });
});
