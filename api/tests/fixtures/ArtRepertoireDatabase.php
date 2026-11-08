<?php
declare(strict_types=1);

namespace {
    if (!getenv('COLORFIX_ART_TEST_DB')) throw new \RuntimeException('Isolated Art fixture configuration required.');
    final class ArtFixturePDO extends \PDO {
        public function __construct(string $file) {
            parent::__construct('sqlite:'.$file,null,null,[self::ATTR_ERRMODE=>self::ERRMODE_EXCEPTION]);
            $this->sqliteCreateFunction('NOW',fn()=>gmdate('Y-m-d H:i:s'));
            $this->sqliteCreateFunction('CONCAT',fn(...$parts)=>implode('',$parts));
            $this->sqliteCreateFunction('SUBSTRING_INDEX',function($text,$delimiter,$count){$parts=explode($delimiter,$text);return implode($delimiter,$count>0?array_slice($parts,0,$count):array_slice($parts,$count));});
        }
        public function prepare(string $query,array $options=[]): \PDOStatement|false {
            if(str_contains($query,'INFORMATION_SCHEMA.TABLES'))$query='SELECT 0 WHERE :table_name IS NOT NULL';
            if(str_contains($query,'INFORMATION_SCHEMA.COLUMNS'))$query='SELECT 0 WHERE :table_name IS NOT NULL AND :column_name IS NOT NULL';
            return parent::prepare(str_replace('<=>','IS',$query),$options);
        }
        public function query(string $query,?int $mode=null,mixed ...$args): \PDOStatement|false {
            if(str_starts_with($query,'SHOW TABLES LIKE'))$query='SELECT NULL';
            return $mode===null?parent::query($query):parent::query($query,$mode,...$args);
        }
    }
    function artFixtureDatabase(string $file): ArtFixturePDO {
        $pdo=new ArtFixturePDO($file);
        if($pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name='photo_library'")->fetchColumn())return $pdo;
        $tables=[
          'photo_library'=>"photo_library_id INTEGER PRIMARY KEY AUTOINCREMENT, asset_library_id INTEGER, source_type TEXT, source_id INTEGER,client_id INTEGER,photo_permission_status TEXT,rel_path TEXT,title TEXT,tags TEXT,alt_text TEXT,ai_alt_text TEXT,ai_filename_slug TEXT,ai_alt_model TEXT,ai_alt_generated_at TEXT,note TEXT,show_in_gallery INTEGER DEFAULT 0,has_palette INTEGER DEFAULT 0,is_inactive INTEGER DEFAULT 0,is_retired INTEGER DEFAULT 0,palette_id INTEGER,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT",
          'saved_palettes'=>"id INTEGER PRIMARY KEY AUTOINCREMENT,palette_hash TEXT UNIQUE,brand TEXT,palette_type TEXT DEFAULT 'exterior',nickname TEXT,display_title TEXT,notes TEXT,private_notes TEXT,terry_fav INTEGER DEFAULT 0,is_public INTEGER DEFAULT 1,kicker_id INTEGER,created_at TEXT,updated_at TEXT",
          'saved_palette_members'=>'id INTEGER PRIMARY KEY AUTOINCREMENT,saved_palette_id INTEGER,color_id INTEGER,role_name TEXT,sheen TEXT,note TEXT,order_index INTEGER,created_at TEXT',
          'photo_alt_text_jobs'=>'photo_library_id INTEGER,status TEXT,attempts INTEGER,next_attempt_at TEXT,last_error TEXT',
          'clients'=>'id INTEGER PRIMARY KEY,name TEXT,email TEXT,photo_permission_status TEXT',
          'project_photos'=>'project_id INTEGER,photo_library_id INTEGER,`before` INTEGER',
          'palette_viewers'=>'palette_viewer_id INTEGER PRIMARY KEY,saved_palette_id INTEGER,format TEXT,template_key TEXT,kicker_text TEXT,title TEXT,intro TEXT,notes TEXT,cta_label TEXT,is_active INTEGER,created_at TEXT,updated_at TEXT',
          'palette_viewer_photos'=>'palette_viewer_photo_id INTEGER PRIMARY KEY,palette_viewer_id INTEGER,photo_library_id INTEGER,rel_path TEXT,photo_type TEXT,trigger_mode TEXT,trigger_color_id INTEGER,caption TEXT,alt_text TEXT,order_index INTEGER,created_at TEXT,updated_at TEXT',
          'colors'=>'id INTEGER PRIMARY KEY,name TEXT,brand TEXT,brand_name TEXT,code TEXT,hex6 TEXT,hcl_h REAL,hcl_c REAL,hcl_l REAL,int_only INTEGER,chip_num TEXT,cluster_id INTEGER,hue_cats TEXT,neutral_cats TEXT',
        ];
        foreach($tables as$table=>$columns)$pdo->exec("CREATE TABLE {$table} ({$columns})");
        $pdo->exec("INSERT INTO colors(id,name,brand,brand_name,hex6) VALUES (1,'Fixture White','sw','Sherwin-Williams','FFFFFF'),(2,'Fixture Blue','sw','Sherwin-Williams','0000FF')");
        $pdo->exec('CREATE VIEW swatch_view AS SELECT * FROM colors');
        return $pdo;
    }
}

namespace App\Services {
    // Only the unrelated asset registry is stubbed. File upload, Photo Library
    // persistence, palette services, normalization and direct linking are real.
    class AssetLibraryService {
        public function __construct(mixed ...$args) {}
        public function upsertAssetForPhoto(int $id,string $path,array $payload=[]): array { return []; }
    }
}
