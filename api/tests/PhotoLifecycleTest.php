<?php
declare(strict_types=1);
use App\PHOTOS\Services\PhotoLifecycleService;
use App\PHOTOS\Services\PhotoDuplicateService;

function lifecycle_fixture(): array
{
    [$pdo, $root, $repo] = photo_library_usage_fixture();
    $pdo->exec('ALTER TABLE photo_library ADD COLUMN palette_id INTEGER');
    $pdo->exec('ALTER TABLE photo_library ADD COLUMN created_at TEXT');
    $pdo->exec('ALTER TABLE asset_library ADD COLUMN legacy_photo_library_id INTEGER');
    $pdo->exec('ALTER TABLE asset_library ADD COLUMN metadata_json TEXT');
    $pdo->exec('CREATE UNIQUE INDEX uq_asset_legacy ON asset_library(legacy_photo_library_id)');
    $pdo->exec('CREATE TABLE photos_artwork (artwork_id INTEGER PRIMARY KEY, photo_library_id INTEGER UNIQUE REFERENCES photo_library(photo_library_id) ON DELETE CASCADE, artist TEXT)');
    $pdo->exec('CREATE TABLE photo_alt_text_jobs (job_id INTEGER PRIMARY KEY, photo_library_id INTEGER UNIQUE REFERENCES photo_library(photo_library_id) ON DELETE CASCADE, status TEXT)');
    $pdo->exec('CREATE TABLE saved_palette_photos (id INTEGER PRIMARY KEY, photo_library_id INTEGER, rel_path TEXT, photo_type TEXT)');
    $pdo->exec('CREATE TABLE items (id INTEGER PRIMARY KEY, image_url TEXT)');
    $pdo->exec('CREATE TABLE sql_queries (id INTEGER PRIMARY KEY, image_url TEXT)');
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec('DELETE FROM asset_library');
    $pdo->exec('UPDATE photo_library SET asset_library_id=NULL');
    $pdo->exec("UPDATE photo_library SET rel_path='/photos/a.png' WHERE photo_library_id=736");
    $pdo->exec("UPDATE photo_library SET rel_path='/photos/b.png' WHERE photo_library_id=900");
    copy($root . '/old.png', $root . '/photos/a.png');
    copy($root . '/source.png', $root . '/photos/b.png');
    return [$pdo, $root, new PhotoLifecycleService($pdo, $root)];
}

function with_lifecycle(callable $run): void
{
    [$pdo, $root, $service] = lifecycle_fixture();
    try { $run($pdo, $root, $service); } finally { cleanup_photo_replacement_fixture($root); }
}

test('unused deletion commits before deleting bytes', function () {
    with_lifecycle(function ($pdo, $root, $s) {
        $r = $s->delete(736); assert_true($r['ok'] && $r['file_deleted']);
        assert_equals(0, $pdo->query('SELECT COUNT(*) FROM photo_library WHERE photo_library_id=736')->fetchColumn());
        assert_true(!is_file($root . '/photos/a.png')); assert_true(is_file($root . '/photos/b.png'));
    });
});

test('each supported dependency blocks deletion including disabled references and artwork cascade', function () {
    $inserts = [
        "INSERT INTO project_photos (project_id,photo_library_id,`use`) VALUES(7,736,0)",
        "INSERT INTO palette_viewer_photos VALUES(85,736,NULL,'zoom')",
        "INSERT INTO playlist_items(photo_library_id,image_url,playlist_id) VALUES(736,NULL,56)",
        "INSERT INTO articles VALUES(1,'Article',736,NULL)",
        "INSERT INTO asset_library(asset_library_id,legacy_photo_library_id) VALUES(1,736)",
        "INSERT INTO photos_artwork VALUES(1,736,'Artist')",
        "INSERT INTO photo_alt_text_jobs VALUES(1,736,'pending')",
        "INSERT INTO items VALUES(1,'https://colorfix.terrymarr.com/photos/a.png?v=1')",
        "INSERT INTO sql_queries VALUES(1,'photo:736|/photos/a.png')",
        "INSERT INTO asset_library(asset_library_id,metadata_json) VALUES(1,'{\"before\":{\"photo_library_id\":736}}')",
        'UPDATE photo_library SET palette_id=162 WHERE photo_library_id=736',
    ];
    foreach ($inserts as $sql) {
        with_lifecycle(function ($pdo, $root, $s) use ($sql) {
            $pdo->exec($sql); $r = $s->delete(736);
            assert_true(!$r['ok'] && $r['status'] === 409, $sql);
            assert_true(count($r['usages']) > 0 && is_file($root . '/photos/a.png'), $sql);
            assert_equals(1, $pdo->query('SELECT COUNT(*) FROM photo_library WHERE photo_library_id=736')->fetchColumn());
        });
    }
});

test('bulk uses single deletion safety with mixed artwork and eligible records', function () {
    with_lifecycle(function ($pdo, $root, $s) {
        $pdo->exec('UPDATE photo_library SET is_inactive=1');
        $pdo->exec("INSERT INTO photos_artwork VALUES(1,736,'Artist')");
        $r = $s->deleteInactive(); assert_equals(2,$r['checked']); assert_equals(1,$r['deleted']); assert_equals(1,$r['skipped']); assert_equals('in_use',array_column($r['items'],'status','photo_library_id')[736]);
        assert_true(is_file($root . '/photos/a.png')); assert_true(!is_file($root . '/photos/b.png'));
        assert_equals(1,$pdo->query('SELECT COUNT(*) FROM photos_artwork')->fetchColumn());
    });
});

test('shared physical files survive row deletion for path variants, asset records and symlink aliases', function () {
    foreach (['relative','absolute','symlink','asset'] as $kind) {
        with_lifecycle(function ($pdo,$root,$s) use ($kind) {
            if ($kind === 'asset') { $pdo->exec("INSERT INTO asset_library(asset_library_id,rel_path) VALUES(1,'photos/a.png?cache=1')"); }
            else {
                $path = match($kind) { 'relative'=>'photos/a.png?cache=1', 'absolute'=>'https://colorfix.terrymarr.com/photos/a.png', default=>'/photos/alias.png' };
                if ($kind === 'symlink') { symlink($root . '/photos/a.png', $root . '/photos/alias.png'); }
                $pdo->prepare('UPDATE photo_library SET rel_path=? WHERE photo_library_id=900')->execute([$path]);
            }
            $r=$s->delete(736);
            // Assets with a matching path block removal of the row too; other photo records protect bytes.
            assert_true(is_file($root.'/photos/a.png'));
            assert_true(empty($r['file_deleted']));
        });
    }
});

test('merge reassigns project, PV, playlist, artwork, articles, compatibility and asset metadata references atomically', function () {
    with_lifecycle(function ($pdo,$root,$s) {
        $pdo->exec("INSERT INTO project_photos(project_id,photo_library_id,`use`,zoom,main,`before`,sort_order) VALUES(7,736,1,1,0,0,9)");
        $pdo->exec("INSERT INTO palette_viewer_photos VALUES(85,736,'/photos/a.png','zoom')");
        $pdo->exec("INSERT INTO playlist_items(photo_library_id,image_url,playlist_id) VALUES(736,'photo:736|/photos/a.png?src=test',56)");
        $pdo->exec("UPDATE playlists SET hero_image_id=736,hero_image_url='/photos/a.png?v=1'");
        $pdo->exec("INSERT INTO playlist_sets VALUES(1,'Cover',736)");
        $pdo->exec("INSERT INTO playlist_instances VALUES(1,'Instance','photo:736','https://colorfix.terrymarr.com/photos/a.png?src=client')");
        $pdo->exec("INSERT INTO photos_artwork VALUES(1,736,'Artist')");
        $pdo->exec("INSERT INTO articles VALUES(1,'Article',736,736)");
        $pdo->exec('INSERT INTO article_sections VALUES(1,1,736)');
        $pdo->exec("INSERT INTO asset_library(asset_library_id,legacy_photo_library_id,metadata_json) VALUES(1,736,'{\"after\":{\"photo_library_id\":736,\"rel_path\":\"/photos/a.png\"}}')");
        $pdo->exec("INSERT INTO playlist_instance_sets VALUES(1,'Set')");
        $pdo->exec('INSERT INTO playlist_instance_set_items VALUES(1,1,736)');
        $pdo->exec("INSERT INTO photo_groups VALUES(1,'Group')");
        $pdo->exec('INSERT INTO photo_group_items VALUES(1,736)');
        $pdo->exec("INSERT INTO photo_alt_text_jobs VALUES(1,736,'pending')");
        $pdo->exec("INSERT INTO items VALUES(1,'photo:736|/photos/a.png')");
        $pdo->exec("INSERT INTO sql_queries VALUES(1,'/photos/a.png?display=1')");
        $before=$pdo->query('SELECT * FROM project_photos')->fetch(PDO::FETCH_ASSOC);
        $r=$s->merge(736,900,false); assert_true(!$r['applied']);
        assert_equals(736,$pdo->query('SELECT photo_library_id FROM photos_artwork')->fetchColumn());
        $r=$s->merge(736,900,true); assert_true($r['applied'] && $r['retired_source_row']);
        foreach(['project_photos','palette_viewer_photos','playlist_items','photos_artwork','playlist_instance_set_items','photo_group_items','photo_alt_text_jobs'] as $table) { assert_equals(900,$pdo->query("SELECT photo_library_id FROM $table")->fetchColumn(),$table); }
        $after=$pdo->query('SELECT * FROM project_photos')->fetch(PDO::FETCH_ASSOC); $before['photo_library_id']=900; assert_equals($before,$after);
        assert_equals('photo:900|/photos/b.png?src=test',$pdo->query('SELECT image_url FROM playlist_items')->fetchColumn());
        assert_equals('/photos/b.png',$pdo->query('SELECT rel_path FROM palette_viewer_photos')->fetchColumn());
        assert_equals('photo:900|/photos/b.png',$pdo->query('SELECT image_url FROM items')->fetchColumn());
        assert_equals('/photos/b.png?display=1',$pdo->query('SELECT image_url FROM sql_queries')->fetchColumn());
        assert_equals(900,$pdo->query('SELECT hero_image_id FROM playlists')->fetchColumn());
        assert_equals(900,$pdo->query('SELECT cover_photo_library_id FROM playlist_sets')->fetchColumn());
        assert_equals(900,$pdo->query('SELECT legacy_photo_library_id FROM asset_library')->fetchColumn());
        $meta=json_decode($pdo->query('SELECT metadata_json FROM asset_library')->fetchColumn(),true); assert_equals(900,$meta['after']['photo_library_id']);
        assert_true(is_file($root.'/photos/a.png') && is_file($root.'/photos/b.png'));
        assert_equals(0,$pdo->query('SELECT show_in_gallery FROM photo_library WHERE photo_library_id=900')->fetchColumn());
    });
});

test('conflicting palettes, artwork, projects, assets and client contexts reject merge without overwriting', function () {
    foreach ([
        'UPDATE photo_library SET palette_id=photo_library_id',
        "INSERT INTO photos_artwork VALUES(1,736,'A'),(2,900,'B')",
        "INSERT INTO project_photos(project_id,photo_library_id) VALUES(7,736),(7,900)",
        'UPDATE photo_library SET client_id=1 WHERE photo_library_id=736',
        'UPDATE photo_library SET asset_library_id=photo_library_id',
        "UPDATE photo_library SET photo_permission_status='declined' WHERE photo_library_id=736",
        'UPDATE photo_library SET is_inactive=1 WHERE photo_library_id=900',
    ] as $sql) {
        with_lifecycle(function($pdo,$root,$s)use($sql){
            $pdo->exec($sql);
            try { $s->merge(736,900,true); throw new RuntimeException('Expected conflict'); } catch (DomainException $e) {}
            assert_equals(0,$pdo->query('SELECT is_inactive FROM photo_library WHERE photo_library_id=736')->fetchColumn());
            assert_true(is_file($root.'/photos/a.png'));
        });
    }
});

test('merge transfers a sole direct palette association without changing gallery or PV roles', function () {
    with_lifecycle(function($pdo,$root,$s){
        $pdo->exec('UPDATE photo_library SET palette_id=162 WHERE photo_library_id=736');
        $s->merge(736,900,true);
        assert_equals(162,$pdo->query('SELECT palette_id FROM photo_library WHERE photo_library_id=900')->fetchColumn());
        assert_equals(0,$pdo->query('SELECT show_in_gallery FROM photo_library WHERE photo_library_id=900')->fetchColumn());
    });
});

test('failed row deletion and failed merge roll back and leave files untouched', function () {
    with_lifecycle(function($pdo,$root,$s){
        $pdo->exec("CREATE TRIGGER fail_delete BEFORE DELETE ON photo_library BEGIN SELECT RAISE(ABORT,'fixture failure'); END");
        try { $s->delete(736); throw new RuntimeException('Expected failure'); } catch(PDOException $e) {}
        assert_true(is_file($root.'/photos/a.png'));
        $pdo->exec('DROP TRIGGER fail_delete');
        $pdo->exec("INSERT INTO playlist_items(photo_library_id) VALUES(736)");
        $pdo->exec("INSERT INTO photos_artwork VALUES(1,736,'Artist')");
        $pdo->exec("CREATE TRIGGER fail_art BEFORE UPDATE ON photos_artwork BEGIN SELECT RAISE(ABORT,'fixture failure'); END");
        try { $s->merge(736,900,true); throw new RuntimeException('Expected failure'); } catch(PDOException $e) {}
        assert_equals(736,$pdo->query('SELECT photo_library_id FROM playlist_items')->fetchColumn());
        assert_equals(736,$pdo->query('SELECT photo_library_id FROM photos_artwork')->fetchColumn());
        assert_true(!$pdo->inTransaction() && is_file($root.'/photos/a.png'));
    });
});

test('nested transactions cannot unlink and merge savepoints preserve caller work on failure', function () {
    with_lifecycle(function($pdo,$root,$s){
        $pdo->beginTransaction();
        try { $s->delete(736); throw new RuntimeException('Expected guard'); } catch(LogicException $e) {}
        $pdo->exec("UPDATE photo_library SET title='Caller work' WHERE photo_library_id=736");
        $pdo->exec("CREATE TRIGGER fail_merge BEFORE UPDATE ON photo_library WHEN NEW.is_inactive=1 BEGIN SELECT RAISE(ABORT,'fixture failure'); END");
        try { $s->merge(736,900,true); throw new RuntimeException('Expected failure'); } catch(PDOException $e) {}
        assert_true($pdo->inTransaction()); assert_equals('Caller work',$pdo->query('SELECT title FROM photo_library WHERE photo_library_id=736')->fetchColumn());
        $pdo->rollBack(); assert_true(is_file($root.'/photos/a.png'));
    });
});

test('filesystem failure retains bytes and reports committed deletion rather than pretending rollback', function () {
    with_lifecycle(function($pdo,$root,$s){
        $s=new PhotoLifecycleService($pdo,$root,static fn(string $path):bool=>false);
        $r=$s->delete(736); assert_true($r['ok'] && $r['file_retained'] && !$r['file_deleted']);
        assert_true(is_file($root.'/photos/a.png'));
        assert_equals(0,$pdo->query('SELECT COUNT(*) FROM photo_library WHERE photo_library_id=736')->fetchColumn());
    });
});

test('missing core dependency tables fail closed and retain photograph', function () {
    with_lifecycle(function($pdo,$root,$s){
        $pdo->exec('DROP TABLE project_photos');
        try { $s->delete(736); throw new RuntimeException('Expected failure'); } catch(PDOException $e) {}
        assert_true(is_file($root.'/photos/a.png'));
        assert_equals(1,$pdo->query('SELECT COUNT(*) FROM photo_library WHERE photo_library_id=736')->fetchColumn());
    });
});

test('candidate detection is read only and dedupe retains retired rows and physical files', function () {
    with_lifecycle(function($pdo,$root,$s){
        $pdo->exec("UPDATE photo_library SET rel_path='/photos/a.png' WHERE photo_library_id=900");
        $pdo->exec("INSERT INTO photos_artwork VALUES(1,736,'Artist')");
        $duplicates=new PhotoDuplicateService($pdo);
        $r=$duplicates->candidates(); assert_equals(1,$r['group_count']); assert_equals(2,$r['items'][0]['duplicate_count']);
        assert_equals(2,$pdo->query('SELECT COUNT(*) FROM photo_library')->fetchColumn());
        assert_equals(0,$duplicates->dedupeAllRelPathRows(false)['deduped']);
        $r=$duplicates->dedupeAllRelPathRows(true); assert_equals(1,$r['deduped']); assert_equals(0,$r['deleted']); assert_equals(1,$r['retired']);
        assert_equals(900,$pdo->query('SELECT photo_library_id FROM photos_artwork')->fetchColumn());
        assert_equals(2,$pdo->query('SELECT COUNT(*) FROM photo_library')->fetchColumn());
        assert_equals(0,$duplicates->dedupeAllRelPathRows(true)['deduped']);
        assert_true(is_file($root.'/photos/a.png'));
    });
});

test('dedupe reports association conflicts and compatibility methods use PHOTOS safety', function () {
    with_lifecycle(function($pdo,$root,$s){
        $pdo->exec("UPDATE photo_library SET rel_path='/photos/a.png', palette_id=photo_library_id");
        $r=(new \App\Services\PhotoLibraryService(new \App\PHOTOS\Repos\PdoPhotoLibraryRepository($pdo)))->dedupeAllRelPathRows(true);
        assert_equals(0,$r['deduped']); assert_true(!empty($r['items'][0]['blocked_reason']));
        try { (new \App\PHOTOS\Repos\PdoPhotoLibraryRepository($pdo))->deleteById(736); throw new RuntimeException('Expected dependency guard'); }
        catch(DomainException $e) {}
        assert_equals(2,$pdo->query('SELECT COUNT(*) FROM photo_library')->fetchColumn());
    });
});

test('palette cleanup preserves independent Art photos and in-use legacy photos with an explanation', function () {
    foreach(['art','saved_palette_photo','unused'] as $kind) {
        with_lifecycle(function($pdo,$root,$s)use($kind){
            $type=$kind==='art'?'art':'saved_palette_photo';
            $pdo->prepare('UPDATE photo_library SET source_type=?,source_id=1 WHERE photo_library_id=736')->execute([$type]);
            if($kind==='saved_palette_photo') { $pdo->exec("INSERT INTO photos_artwork VALUES(1,736,'Artist')"); }
            $r=$s->cleanupDetachedPalettePhotos([['id'=>1,'photo_library_id'=>736,'rel_path'=>'/photos/a.png']]);
            $remaining=$pdo->query('SELECT COUNT(*) FROM photo_library WHERE photo_library_id=736')->fetchColumn();
            assert_equals(1,$remaining);
            assert_true(is_file($root.'/photos/a.png'));
            assert_true(!empty($r[0]['retained']));
        });
    }
});

test('root asset metadata and source IDs are protected and transferred during merge', function () {
    with_lifecycle(function($pdo,$root,$s){
        $pdo->exec('ALTER TABLE asset_library ADD COLUMN source_type TEXT');
        $pdo->exec('ALTER TABLE asset_library ADD COLUMN source_id INTEGER');
        $pdo->exec("INSERT INTO asset_library(asset_library_id,source_type,source_id,metadata_json) VALUES(1,'photo_library',736,'{\"photo_library_id\":736}')");
        assert_equals(409,$s->delete(736)['status']);
        $s->merge(736,900,true);
        $asset=$pdo->query('SELECT * FROM asset_library')->fetch(PDO::FETCH_ASSOC);
        assert_equals(900,$asset['source_id']); assert_equals(900,json_decode($asset['metadata_json'],true)['photo_library_id']);
    });
});

test('PUB production inputs protect deletion and reject unsafe reauthoring through merge', function () {
    with_lifecycle(function($pdo,$root,$s){
        $pdo->exec('CREATE TABLE pub_asset_orders(pub_asset_order_id INTEGER PRIMARY KEY,ingredients TEXT)');
        $pdo->exec("INSERT INTO pub_asset_orders VALUES(1,'{\"source\":{\"photo_library_id\":736,\"file_path\":\"/photos/a.png\"}}')");
        $pdo->exec('CREATE TABLE pub_video_jobs(pub_video_job_id INTEGER PRIMARY KEY,props_json TEXT)');
        $pdo->exec("INSERT INTO pub_video_jobs VALUES(1,'{\"layers\":[{\"src\":\"/photos/a.png\"}]}')");
        assert_equals(409,$s->delete(736)['status']);
        $pdo->exec('DELETE FROM pub_asset_orders');
        assert_equals(409,$s->delete(736)['status']);
        try { $s->merge(736,900,true); throw new RuntimeException('Expected production dependency guard'); }
        catch(DomainException $e) { assert_true(str_contains($e->getMessage(),'PUB')); }
        assert_equals(0,$pdo->query('SELECT is_inactive FROM photo_library WHERE photo_library_id=736')->fetchColumn());
    });
});

test('PUB and ASSETS disposal guards preserve Photo Library bytes', function () {
    with_lifecycle(function($pdo,$root,$s){
        try { $s->assertNotLibraryFile($root.'/photos/a.png'); throw new RuntimeException('Expected library guard'); }
        catch(DomainException $e) { assert_true(str_contains($e->getMessage(),'Photo Library')); }
        $r=(new \App\Repos\PdoAssetLibraryRepository($pdo))->disposePhotoFile('/photos/a.png',$root);
        assert_true(!$r['file_deleted'] && $r['file_retained']); assert_true(is_file($root.'/photos/a.png'));
    });
});

test('file disposal catches PV symlink aliases and refuses traversal and external URLs', function () {
    with_lifecycle(function($pdo,$root,$s){
        symlink($root.'/photos/a.png',$root.'/photos/pv-alias.png');
        $pdo->exec("INSERT INTO palette_viewer_photos VALUES(85,NULL,'/photos/pv-alias.png','zoom')");
        $r=$s->delete(736); assert_true($r['ok'] && $r['file_retained']); assert_true(is_file($root.'/photos/a.png'));
        assert_true(!$s->disposeFile('/photos/../source.png')['file_deleted']);
        assert_true(!$s->disposeFile('https://another.example/photos/b.png')['file_deleted']);
        assert_true(is_file($root.'/source.png') && is_file($root.'/photos/b.png'));
    });
});

/** Domain fixture only: the real service and PHOTOS operations still use SQLite transactions. */
final class LifecyclePaletteFixtureRepository extends \App\Repos\PdoSavedPaletteRepository
{
    public function __construct(private PDO $fixturePdo) { parent::__construct($fixturePdo); }
    public function countPlaylistUsageForPalette(int $savedPaletteId): int { return 0; }
    public function deleteMembersForPalette(int $savedPaletteId): void {}
    public function deleteViewsForPalette(int $savedPaletteId): void {}
}

test('saved palette deletion delegates cleanup after commit and rollback never removes photos', function () {
    foreach([false,true] as $fail) {
        with_lifecycle(function($pdo,$root,$s)use($fail){
            $pdo->exec("INSERT INTO saved_palettes VALUES(162,'Palette','hash')");
            $pdo->exec("UPDATE photo_library SET source_type='saved_palette_photo',palette_id=162 WHERE photo_library_id=736");
            if($fail) $pdo->exec("CREATE TRIGGER fail_palette BEFORE DELETE ON saved_palettes BEGIN SELECT RAISE(ABORT,'fixture failure'); END");
            $service=new \App\Services\SavedPaletteService(new LifecyclePaletteFixtureRepository($pdo));
            $prior=$_SERVER['DOCUMENT_ROOT']??null; $_SERVER['DOCUMENT_ROOT']=$root;
            try {
                if($fail) {
                    try { $service->deleteSavedPalette(162); throw new RuntimeException('Expected rollback'); } catch(PDOException $e) {}
                    assert_equals(162,$pdo->query('SELECT palette_id FROM photo_library WHERE photo_library_id=736')->fetchColumn());
                    assert_true(is_file($root.'/photos/a.png'));
                } else {
                    $cleanup=$service->deleteSavedPalette(162);
                    assert_equals(null,$pdo->query('SELECT palette_id FROM photo_library WHERE photo_library_id=736')->fetchColumn());
                    assert_equals(0,$pdo->query('SELECT COUNT(*) FROM saved_palettes')->fetchColumn());
                    assert_true(is_file($root.'/photos/a.png')); assert_true(!empty($cleanup[0]['retained']));
                }
            } finally { if($prior===null) unset($_SERVER['DOCUMENT_ROOT']); else $_SERVER['DOCUMENT_ROOT']=$prior; }
        });
    }
});
