import {mkdtemp, mkdir, copyFile, symlink, writeFile, rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {resolve, join} from 'node:path';
import {spawn} from 'node:child_process';
import net from 'node:net';
import assert from 'node:assert/strict';

const repo=resolve(import.meta.dirname,'..');
const root=await mkdtemp(join(tmpdir(),'photos-lifecycle-http-'));
let server;
try {
  const dir=join(root,'api/v2/admin/photo-library');
  await mkdir(dir,{recursive:true});
  await symlink(join(repo,'app'),join(root,'app'));
  await copyFile(join(repo,'api/autoload.php'),join(root,'api/autoload.php'));
  for(const file of ['delete','delete-inactive','merge','duplicate-candidates','usage','dedupe-rel-path-rows','dedupe-saved-palette-rows']) {
    await copyFile(join(repo,`api/v2/admin/photo-library/${file}.php`),join(dir,`${file}.php`));
  }
  // The real API files and app classes run against disposable SQLite fixtures. No production db.php is copied.
  await writeFile(join(root,'api/v2/admin/auth.php'),`<?php
if (($_SERVER['HTTP_X_FIXTURE_AUTH'] ?? '') !== 'local-test') { http_response_code(401); echo '{"ok":false,"error":"Unauthorized fixture request"}'; exit; }
`);
  await writeFile(join(root,'api/db.php'),`<?php
if (!getenv('COLORFIX_PHOTOS_REPO')) throw new RuntimeException('Fixture environment required');
function test($name,$callback) {}
require getenv('COLORFIX_PHOTOS_REPO').'/api/tests/PhotoLibraryUsageTest.php';
require getenv('COLORFIX_PHOTOS_REPO').'/api/tests/PhotoLifecycleTest.php';
[$pdo,$fixtureRoot]=$fixture=lifecycle_fixture();
$_SERVER['DOCUMENT_ROOT']=$fixtureRoot;
register_shutdown_function(static function()use($fixtureRoot){cleanup_photo_replacement_fixture($fixtureRoot);});
if (($_SERVER['HTTP_X_FIXTURE_CASE'] ?? '')==='art') $pdo->exec("INSERT INTO photos_artwork VALUES(1,736,'Artist')");
if (($_SERVER['HTTP_X_FIXTURE_CASE'] ?? '')==='bulk') $pdo->exec("UPDATE photo_library SET is_inactive=1; INSERT INTO photos_artwork VALUES(1,736,'Artist')");
if (($_SERVER['HTTP_X_FIXTURE_CASE'] ?? '')==='conflict') $pdo->exec("UPDATE photo_library SET palette_id=photo_library_id");
`);
  const allocator=net.createServer(); await new Promise((r,reject)=>{allocator.once('error',reject);allocator.listen(0,'127.0.0.1',r);});
  const port=allocator.address().port; await new Promise(r=>allocator.close(r));
  server=spawn('php',['-S',`127.0.0.1:${port}`,'-t',root],{env:{...process.env,COLORFIX_PHOTOS_REPO:repo},stdio:['ignore','pipe','pipe']});
  let logs=''; server.stderr.on('data',c=>logs+=c);
  const base=`http://127.0.0.1:${port}/api/v2/admin/photo-library/`;
  for(let i=0;i<50;i++){try{await fetch(base+'usage.php');break;}catch{await new Promise(r=>setTimeout(r,100));}}
  async function call(name,{method='POST',payload,fixture='',auth=true}={}) {
    const r=await fetch(base+name+'.php',{method,headers:{...(auth?{'X-Fixture-Auth':'local-test'}:{}),'X-Fixture-Case':fixture,'Content-Type':'application/json'},body:payload===undefined?undefined:JSON.stringify(payload)});
    const body=await r.text(); assert.match(r.headers.get('content-type')??'',/application\/json/); return [r.status,JSON.parse(body)];
  }
  let [status,data]=await call('delete',{payload:{photo_library_id:736}}); assert.equal(status,200); assert.equal(data.ok,true); assert.equal(data.file_deleted,true);
  [status,data]=await call('delete',{payload:{photo_library_id:736},fixture:'art'}); assert.equal(status,409); assert.equal(data.ok,false); assert.ok(data.usages.some(x=>x.usage_type==='photos_artwork'));
  [status]=await call('delete',{payload:{photo_library_id:9999}}); assert.equal(status,404);
  [status]=await call('delete',{payload:{}}); assert.equal(status,400);
  [status]=await call('delete',{method:'GET'}); assert.equal(status,405);
  [status]=await call('delete',{payload:{photo_library_id:736},auth:false}); assert.equal(status,401);
  [status,data]=await call('delete-inactive',{fixture:'bulk'}); assert.equal(status,200); assert.equal(data.result.deleted,1); assert.equal(data.result.skipped,1);
  [status,data]=await call('merge',{payload:{from_photo_library_id:736,to_photo_library_id:900,apply:false}}); assert.equal(status,200); assert.equal(data.result.applied,false);
  [status,data]=await call('merge',{payload:{from_photo_library_id:736,to_photo_library_id:900,apply:true},fixture:'art'}); assert.equal(status,200); assert.equal(data.result.applied,true); assert.equal(data.result.retired_source_row,true);
  [status]=await call('merge',{payload:{from_photo_library_id:736,to_photo_library_id:900,apply:true},fixture:'conflict'}); assert.equal(status,409);
  [status]=await call('merge',{payload:{from_photo_library_id:736,to_photo_library_id:736}}); assert.equal(status,400);
  [status,data]=await call('duplicate-candidates',{method:'GET'}); assert.equal(status,200); assert.equal(data.result.group_count,0);
  const usage=await fetch(base+'usage.php?photo_library_id=736',{headers:{'X-Fixture-Auth':'local-test','X-Fixture-Case':'art'}}); data=await usage.json(); assert.equal(usage.status,200); assert.equal(data.in_use,true);
  for(const name of ['dedupe-rel-path-rows','dedupe-saved-palette-rows']) { [status,data]=await call(name,{method:'GET'}); assert.equal(status,200); assert.equal(data.apply,false); assert.equal(data.result.deleted,0); }
  assert.doesNotMatch(logs,/Fatal error|Uncaught|Warning:/);
  console.log('PASS HTTP fixture checks: existing URLs, deletion, guards, bulk, merge, dry runs, usage, JSON and error statuses.');
} finally {
  if(server && server.exitCode===null) { server.kill(); await new Promise(r=>server.once('exit',r)); }
  await rm(root,{recursive:true,force:true});
}
