<?php
declare(strict_types=1);
require_once __DIR__ . '/../autoload.php';
use App\Repos\PdoSwatchRepository;

function expectFuzzy(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->sqliteCreateFunction('REGEXP', fn($pattern,$value)=>preg_match('~'.str_replace('~','\\~',(string)$pattern).'~i',(string)$value)===1);
// Deliberately no colors, company, or category_definitions tables: all search
// matching, filtering, ordering, and returned fields must come from the MV.
$pdo->exec('CREATE TABLE mv_swatch_view (
 id INTEGER PRIMARY KEY, name TEXT, brand TEXT, code TEXT, chip_num TEXT, r INTEGER, g INTEGER, b INTEGER,
 hex6 TEXT, hex TEXT, hcl_l REAL, hcl_c REAL, hcl_h REAL, brand_name TEXT, hue_cats TEXT, hue_cat_order INTEGER,
 neutral_cats TEXT, is_stain INTEGER, cluster_id INTEGER, light_cat_id INTEGER, light_cat_name TEXT,
 light_cat_order INTEGER, int_only INTEGER, is_inactive INTEGER)');
$insert=$pdo->prepare('INSERT INTO mv_swatch_view (id,name,brand,code,hex6,hex,brand_name,hue_cats,light_cat_name,int_only,is_inactive) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
foreach ([
 [1,'Alabaster','sw','7008','EDEAE0','#EDEAE0','Sherwin-Williams','MV category','MV lightness',1,0],
 [2,'Swiss Coffee','behr','12','F1ECE1','#F1ECE1','Behr','MV category','MV lightness',0,0],
 [3,'Swiss Coffee','bm','OC-45','EEECE1','#EEECE1','Benjamin Moore','MV category','MV lightness',0,0],
 [4,'Swiss inactive','sw','SW7008','F1ECE1','#F1ECE1','Sherwin-Williams',null,null,0,1],
 [5,'Swiss unset','sw','7008-2','F1ECE1','#F1ECE1','Sherwin-Williams',null,null,0,null],
 [6,'Other','de','SW7008','EEEEEE','#EEEEEE','Dunn-Edwards',null,null,0,0],
 [7,'Code variant','bm','OC 45','EEEEEE','#EEEEEE','Benjamin Moore',null,null,0,0],
] as $row) $insert->execute($row);
$repo=new PdoSwatchRepository($pdo);
$swiss=$repo->fuzzySearchByNameCode('Swiss',80)['results'];
expectFuzzy(array_column($swiss,'id')===[2,3],'Name matching must retain all brands and exclude inactive/unset swatches.');
$alabaster=$repo->fuzzySearchByNameCode('Alabaster',80)['results'][0];
expectFuzzy($alabaster['hue_cats']==='MV category' && $alabaster['light_cat_name']==='MV lightness' && $alabaster['int_only']===1,'Return the materialized classification/display fields.');
expectFuzzy(array_column($repo->fuzzySearchByNameCode('7008',80)['results'],'id')===[1,6],'Keep exact code priority and numeric-after-letter code matching.');
expectFuzzy(array_column($repo->fuzzySearchByNameCode('OC45',80)['results'],'id')===[7,3],'Keep compact/delimited code matching.');
foreach (['#F1ECE1','f1ece1','F1E'] as $hex) {
    expectFuzzy(array_column($repo->fuzzySearchByNameCode($hex,80)['results'],'id')===[2],'Keep optional # and hex prefix matching.');
}
$out=$repo->fuzzySearchByNameCode('Swiss',1);
expectFuzzy(count($out['results'])===1 && $out['too_many']===true,'Keep capped results and too_many response.');
expectFuzzy($repo->fuzzySearchByNameCode('No Such Color',80)===['results'=>[],'too_many'=>false],'Keep empty response.');
echo "PASS: MV-only name/code/hex matching, all brands, ordering, materialized fields, inactive exclusion, caps and response shape.\n";
