<?php
declare(strict_types=1);

/** Isolated, disposable Gallery fixtures. Never connects to a configured database. */
function galleryRunQueryDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec(<<<'SQL'
CREATE TABLE sql_queries (query_id INTEGER PRIMARY KEY, name TEXT, item_type TEXT, `query` TEXT);
CREATE TABLE items (id INTEGER, query_id INTEGER, is_active INTEGER, insert_position INTEGER, item_type TEXT);
INSERT INTO items VALUES (1,5,1,0,'text'), (2,17,1,1,'text'), (3,5,0,0,'text');
CREATE TABLE category_definitions (id INTEGER, name TEXT, sort_order INTEGER, type TEXT);
INSERT INTO category_definitions VALUES (1,'Light',1,'Lightness');
CREATE TABLE filter_definitions (fieldname TEXT, tablename TEXT);
INSERT INTO filter_definitions VALUES ('brand','swatch_view'), ('brand','colors');
CREATE TABLE mv_swatch_view (id INTEGER, name TEXT, code TEXT, brand TEXT, hex6 TEXT, hcl_h REAL, hcl_c REAL, hcl_l REAL, hue_cats TEXT, hue_cat_order INTEGER, light_cat_id INTEGER, light_cat_name TEXT, light_cat_order INTEGER, neutral_cats TEXT);
INSERT INTO mv_swatch_view VALUES
 (1,'Art Blue','A1','sw','1122FF',240,40,50,'Blues',1,1,'Light',1,'Whites'),
 (2,'House Blue','A2','bm','2233FF',250,20,60,'Blues',1,1,'Light',1,'Grays'),
 (3,'Private Blue','A3','sw','3344FF',260,10,70,'Blues',1,1,'Light',1,'Whites'),
 (4,'Red','A4','sw','FF0000',20,80,50,'Reds',2,1,'Light',1,'Blacks');
CREATE TABLE photo_library (photo_library_id INTEGER PRIMARY KEY, palette_id INTEGER, rel_path TEXT, source_type TEXT, show_in_gallery INTEGER, is_inactive INTEGER, is_retired INTEGER);
INSERT INTO photo_library VALUES
 (746,163,'/photos/art.svg','art',1,0,0),
 (747,164,'/photos/house.svg','saved_palette',1,0,0),
 (748,165,'/photos/private.svg','art',0,0,0),
 (749,166,'/photos/inactive.svg','art',1,1,0),
 (750,167,'/photos/retired.svg','art',1,0,1);
ALTER TABLE photo_library ADD COLUMN inspiration_photo_id INTEGER;
CREATE TABLE saved_palettes (id INTEGER PRIMARY KEY, palette_hash TEXT, nickname TEXT, brand TEXT);
INSERT INTO saved_palettes VALUES (163,'art-hash','Art palette','sw'),(164,'house-hash','House palette','bm'),(165,'private-hash','Private','sw'),(166,'inactive','Inactive','sw'),(167,'retired','Retired','sw');
CREATE TABLE saved_palette_members (saved_palette_id INTEGER, color_id INTEGER);
INSERT INTO saved_palette_members VALUES (163,1),(164,2),(165,3),(166,3),(167,3);
CREATE TABLE photo_crops (photo_library_id INTEGER, crop_type TEXT, crop_x REAL, crop_y REAL, crop_width REAL, crop_height REAL);
INSERT INTO photo_crops VALUES (746,'swatch',0,0.083990,1,0.600000),(746,'square',0.1,0.2,0.5,0.5);
CREATE TABLE photos_artwork (artwork_id INTEGER, photo_library_id INTEGER, artist TEXT, official_title TEXT, display_title TEXT);
INSERT INTO photos_artwork VALUES (9,746,'Fixture artist','Fixture art','Blue artwork');
CREATE VIEW vw_photo_palette_colors AS SELECT m.color_id,p.photo_library_id AS photo_id,p.rel_path,p.palette_id FROM photo_library p JOIN saved_palette_members m ON m.saved_palette_id=p.palette_id;
CREATE VIEW vw_gallery AS SELECT s.*,p.photo_id,p.rel_path AS photo_url,p.palette_id AS photo_palette_id FROM mv_swatch_view s LEFT JOIN vw_photo_palette_colors p ON p.color_id=s.id;
CREATE VIEW colors AS SELECT * FROM mv_swatch_view;
CREATE TABLE palette_viewers (palette_viewer_id INTEGER, saved_palette_id INTEGER, format TEXT, template_key TEXT, title TEXT, is_active INTEGER, created_at TEXT);
INSERT INTO palette_viewers VALUES (20,164,'public','full_palette','House',1,'2026-11-08');
CREATE TABLE rex_reservations (id INTEGER, token TEXT, label TEXT, admin_note TEXT, resolver_key TEXT, resource_type TEXT, resource_id INTEGER, experience_key TEXT, context_json TEXT, status TEXT, revoked_at TEXT, created_at TEXT, updated_at TEXT);
INSERT INTO rex_reservations VALUES (30,'stable-house-token','House',NULL,'viewer','palette_viewer',20,'public','{}','active',NULL,NULL,NULL);
SQL);
    $insert = $pdo->prepare('INSERT INTO sql_queries VALUES (?,?,?,?)');
    foreach ([5=>'Blues',6=>'Reds',12=>'Blacks',15=>'Whites'] as $id=>$name) {
        $field = in_array($id,[12,15],true) ? 'neutral_cats' : 'hue_cats';
        $insert->execute([$id,$name,'search',"SELECT *, 'swatch' AS item_type FROM vw_gallery WHERE $field LIKE '%$name%' ORDER BY hue_cat_order, hcl_h"]);
    }
    $insert->execute([18,'Name search','no-render',"SELECT *, 'swatch' AS item_type FROM vw_gallery WHERE name LIKE :name OR code LIKE :code ORDER BY hue_cat_order"]);
    $insert->execute([2,'Families','search',"SELECT query_id AS id,name,'search' AS item_type FROM sql_queries ORDER BY name"]);
    $insert->execute([16,'Brand','search',"SELECT *, 'swatch' AS item_type FROM colors WHERE brand LIKE :brand ORDER BY hue_cat_order"]);
    return $pdo;
}
