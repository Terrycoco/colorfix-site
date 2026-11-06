<?php
declare(strict_types=1);
require_once __DIR__ . '/../autoload.php';
ob_start(); // Keep HTTP endpoint fixture headers writable after CLI test output.
use App\COLORS\Repos\PdoMaterializedSwatchRepository;

// Execute real rebuild SQL against isolated tables; adapt only MySQL DDL,
// advisory locks and information_schema inspection for SQLite.
final class SwatchRefreshFixturePDO extends PDO
{
    public string $failure = '';
    public array $sql = [];
    public function __construct() {
        parent::__construct('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    }
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        $this->sql[]=$query;
        if (str_contains($query,'GET_LOCK')) $query=$this->failure==='busy' ? 'SELECT 0 WHERE :name IS NOT NULL' : 'SELECT 1 WHERE :name IS NOT NULL';
        if (str_contains($query,'RELEASE_LOCK')) $query='SELECT 1 WHERE :name IS NOT NULL';
        return parent::prepare($query,$options);
    }
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false {
        $this->sql[]=$query;
        if ($query==='SELECT DATABASE()') $query="SELECT 'fixture'";
        elseif (str_contains($query,'information_schema.TABLES')) $query="SELECT 'InnoDB'";
        elseif (str_contains($query,'information_schema.COLUMNS')) $query="SELECT COUNT(*) FROM pragma_table_info('mv_swatch_view') WHERE name='is_inactive'";
        elseif ($this->failure==='count' && str_contains($query,'SELECT COUNT(*) FROM `mv_swatch_view_refresh_')) $query='SELECT 0';
        return $fetchMode===null ? parent::query($query) : parent::query($query,$fetchMode,...$fetchModeArgs);
    }
    public function exec(string $query): int|false {
        $this->sql[]=$query;
        if (str_contains($query,'INSERT INTO mv_color_categories')) {
            $query=preg_replace("/\\s+ORDER BY cd\\.name SEPARATOR ','/", '', $query);
        }
        if (preg_match('/^CREATE TABLE `([^`]+)` LIKE mv_swatch_view$/',$query,$match)) {
            $definition=parent::query("SELECT sql FROM sqlite_master WHERE name='mv_swatch_view'")->fetchColumn();
            $query=preg_replace('/^CREATE TABLE "?mv_swatch_view"?/','CREATE TABLE `'.$match[1].'`',$definition);
        }
        if ($this->failure==='insert' && str_starts_with($query,'INSERT INTO `mv_swatch_view_refresh_')) throw new RuntimeException('Injected insert failure');
        if (preg_match('/^RENAME TABLE mv_swatch_view TO `([^`]+)`, `([^`]+)` TO mv_swatch_view$/',$query,$match)) {
            parent::beginTransaction();
            try {
                parent::exec('ALTER TABLE mv_swatch_view RENAME TO `'.$match[1].'`');
                parent::exec('ALTER TABLE `'.$match[2].'` RENAME TO mv_swatch_view');
                parent::commit(); return 0;
            } catch (Throwable $e) {parent::rollBack();throw $e;}
        }
        return parent::exec($query);
    }
}
function refreshFixture(): SwatchRefreshFixturePDO {
    $pdo=new SwatchRefreshFixturePDO();
    $pdo->exec('CREATE TABLE mv_swatch_view (id INTEGER PRIMARY KEY, name TEXT, code TEXT, brand TEXT, chip_num TEXT,
 r INTEGER,g INTEGER,b INTEGER,hex6 TEXT,hex TEXT,hcl_l REAL,hcl_c REAL,hcl_h REAL,brand_name TEXT,hue_cats TEXT,
 hue_cat_order INTEGER,neutral_cats TEXT,is_stain INTEGER,cluster_id INTEGER,light_cat_id INTEGER,
 light_cat_name TEXT,light_cat_order INTEGER,int_only INTEGER)');
    $pdo->exec("INSERT INTO mv_swatch_view(id,name) VALUES (99,'Old snapshot')");
    $pdo->exec('CREATE TABLE source_swatches AS SELECT * FROM mv_swatch_view WHERE 0');
    $pdo->exec("INSERT INTO source_swatches(id,name) VALUES (1,'New active'),(2,'New inactive')");
    $pdo->exec('CREATE TABLE colors (id INTEGER PRIMARY KEY,is_inactive INTEGER)');
    $pdo->exec('INSERT INTO colors VALUES (1,0),(2,1)');
    $pdo->exec('CREATE VIEW swatch_view AS SELECT * FROM source_swatches');
    return $pdo;
}
function expectRefresh(bool $ok,string $message): void {if (!$ok) throw new RuntimeException($message);}
$pdo=refreshFixture();
$result=(new PdoMaterializedSwatchRepository($pdo))->refresh();
expectRefresh($result===['rows_before'=>1,'rows_after'=>2,'includes_is_inactive'=>true],'Report a successful populated replacement.');
expectRefresh($pdo->query('SELECT id,is_inactive FROM mv_swatch_view ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===[['id'=>1,'is_inactive'=>0],['id'=>2,'is_inactive'=>1]],'Copy the actual stored flags, not a guessed/default value.');
$pdo->exec("UPDATE source_swatches SET name='Changed source' WHERE id=1");
(new PdoMaterializedSwatchRepository($pdo))->refresh();
expectRefresh($pdo->query('SELECT name FROM mv_swatch_view WHERE id=1')->fetchColumn()==='Changed source','Repeat refresh updates data and preserves the added field.');
expectRefresh($pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name LIKE 'mv_swatch_view_%'")->fetchColumn()===0,'Successful refresh leaves no staging/backup tables.');
foreach (['insert','count','empty','busy'] as $failure) {
    $pdo=refreshFixture();
    if ($failure==='empty') $pdo->exec('DELETE FROM source_swatches');
    else $pdo->failure=$failure;
    $before=$pdo->query('SELECT * FROM mv_swatch_view')->fetchAll(PDO::FETCH_ASSOC);
    $caught=false;
    try {(new PdoMaterializedSwatchRepository($pdo))->refresh();} catch(RuntimeException $e) {$caught=true;}
    expectRefresh($caught,"{$failure} must fail safely.");
    expectRefresh($pdo->query('SELECT * FROM mv_swatch_view')->fetchAll(PDO::FETCH_ASSOC)===$before,"{$failure} must preserve the complete old table and schema.");
    expectRefresh(!$pdo->inTransaction(),"{$failure} must close its transaction.");
    expectRefresh($pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE name LIKE 'mv_swatch_view_%'")->fetchColumn()===0,"{$failure} must clean up staging tables.");
}
echo "PASS: stored inactive flags, schema addition, refresh/repeat, atomic publication SQL, empty-source/insert/count failure preservation, concurrency lock and staging cleanup.\n";

// Integration: category recalculation must commit its existing work first,
// then build the same swatch snapshot used by the manual refresh button.
function categoryRefreshFixture(): SwatchRefreshFixturePDO {
    $pdo=refreshFixture();
    foreach (['hcl_h REAL','hcl_c REAL','hcl_l REAL','cluster_id INTEGER'] as $column) $pdo->exec('ALTER TABLE colors ADD COLUMN '.$column);
    $pdo->exec('UPDATE colors SET hcl_h=20,hcl_c=10,hcl_l=50,cluster_id=1');
    $pdo->exec('CREATE TABLE category_definitions (id INTEGER PRIMARY KEY,name TEXT,type TEXT,hue_min REAL,hue_max REAL,chroma_min REAL,chroma_max REAL,light_min REAL,light_max REAL,active INTEGER,calc_only INTEGER,sort_order INTEGER)');
    $pdo->exec("INSERT INTO category_definitions(id,name,type,hue_min,hue_max,active,calc_only,sort_order) VALUES (1,'Reds','hue',0,60,1,0,1)");
    $pdo->exec('CREATE TABLE color_category (color_id INTEGER,category_id INTEGER)');
    $pdo->exec('CREATE TABLE mv_color_categories (color_id INTEGER PRIMARY KEY,hue_cats TEXT,neutral_cats TEXT,lightness_cats TEXT,chroma_cats TEXT)');
    $pdo->exec('CREATE TABLE clusters (id INTEGER PRIMARY KEY,hue_cats TEXT,neutral_cats TEXT,lightness_cats TEXT,chroma_cats TEXT)');
    $pdo->exec('INSERT INTO clusters(id) VALUES (1)');
    $fields=$pdo->query("SELECT name FROM pragma_table_info('source_swatches')")->fetchAll(PDO::FETCH_COLUMN);
    $projection=implode(',',array_map(fn($field)=>$field==='hue_cats'?'mv.hue_cats AS hue_cats':'s.'.$field,$fields));
    $pdo->exec('DROP VIEW swatch_view');
    $pdo->exec('CREATE VIEW swatch_view AS SELECT '.$projection.' FROM source_swatches s LEFT JOIN mv_color_categories mv ON mv.color_id=s.id');
    return $pdo;
}
$pdo=categoryRefreshFixture();
$result=(new App\COLORS\Managers\CategoryRecalcManager($pdo))->rebuild(1);
expectRefresh($result['swatches']['ok']===true,'Successful recalc must finish by refreshing swatches.');
expectRefresh($pdo->query('SELECT hue_cats FROM mv_swatch_view WHERE id=1')->fetchColumn()==='Reds','New swatches must contain newly recalculated categories.');
$creates=array_filter($pdo->sql,fn($sql)=>str_starts_with($sql,'CREATE TABLE `mv_swatch_view_refresh_'));
expectRefresh(count($creates)===1,'Recalc must trigger exactly one swatch refresh.');
$pdo=categoryRefreshFixture();
try {(new App\COLORS\Managers\CategoryRecalcManager($pdo))->rebuild(1,null,true);} catch(RuntimeException $e) {expectRefresh($e->getMessage()==='COLORS_TEST_ROLLBACK_INJECTED','Expected controlled rollback.');}
expectRefresh($pdo->query('SELECT id FROM mv_swatch_view')->fetchColumn()===99,'Failed/rolled-back recalc must not refresh swatches.');
expectRefresh(!array_filter($pdo->sql,fn($sql)=>str_starts_with($sql,'CREATE TABLE `mv_swatch_view_refresh_')),'No refresh may start before category commit.');
$pdo=categoryRefreshFixture(); $pdo->failure='insert';
$result=(new App\COLORS\Managers\CategoryRecalcManager($pdo))->rebuild(1);
expectRefresh($result['swatches']['ok']===false,'Post-commit refresh failures must be reported explicitly.');
expectRefresh($pdo->query('SELECT COUNT(*) FROM color_category')->fetchColumn()===2,'Warning must preserve successful committed category changes.');
expectRefresh($pdo->query('SELECT id FROM mv_swatch_view')->fetchColumn()===99,'Post-commit refresh failure retains the old swatch table.');
echo "PASS: recalc refreshes once after category commit; category rollback skips refresh; post-commit refresh failure reports partial success accurately.\n";

$pdo=refreshFixture();
$serverBefore=$_SERVER;
foreach ([['GET','application/json','https://admin.test',405],['POST','application/json','https://other.test',403],['POST','text/plain','https://admin.test',415],['POST','application/json','https://admin.test',200]] as [$method,$contentType,$origin,$status]) {
    $_SERVER['REQUEST_METHOD']=$method; $_SERVER['CONTENT_TYPE']=$contentType;
    $_SERVER['HTTP_ORIGIN']=$origin; $_SERVER['HTTP_HOST']='admin.test';
    ob_start(); App\COLORS\Endpoints\RefreshSwatchesEndpoint::handle($pdo); $response=json_decode(ob_get_clean(),true);
    expectRefresh(http_response_code()===$status,'Manual endpoint must enforce method, origin and JSON checks.');
    if ($status===200) expectRefresh($response['ok']===true && $response['summary']['rows_after']===2,'Explicit POST refresh must succeed.');
    else expectRefresh($pdo->query('SELECT id FROM mv_swatch_view')->fetchColumn()===99,'Rejected requests must not change swatches.');
}
$_SERVER=$serverBefore;
echo "PASS: manual endpoint rejects GET/cross-origin/non-JSON requests; explicit POST uses the shared refresh.\n";
ob_end_flush();
