import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {mkdtemp,mkdir,writeFile,readFile,copyFile,rm} from 'node:fs/promises';
import path from 'node:path';
import {tmpdir} from 'node:os';
import net from 'node:net';
import {createServer} from 'vite';
import {chromium} from 'playwright';
const root=await mkdtemp(path.join(tmpdir(),'colorfix-art-'));
const project=process.cwd();
let php,vite,browser,output='';
try {
 const endpoints=['photo-library/upload.php','photo-library/list.php','photo-library/update.php','photo-library/series.php','saved-palette-save.php','palettes/save-as-new.php','saved-palette-photos/upload.php','saved-palette-photos/add-from-library.php','saved-palette-photos/delete.php','saved-palette-photos/by-library.php'];
 for(const endpoint of endpoints){const target=path.join(root,'api/v2/admin',endpoint);await mkdir(path.dirname(target),{recursive:true});await copyFile(path.join(project,'api/v2/admin',endpoint),target);}
 await writeFile(path.join(root,'api/autoload.php'),`<?php require_once ${JSON.stringify(project+'/api/autoload.php')};`);
 await writeFile(path.join(root,'api/db.php'),`<?php require_once ${JSON.stringify(project+'/api/tests/fixtures/ArtRepertoireDatabase.php')}; $pdo=artFixtureDatabase(getenv('COLORFIX_ART_TEST_DB'));`);
 await writeFile(path.join(root,'api/v2/admin/auth.php'),`<?php if(($_SERVER['HTTP_X_ART_FIXTURE']??'')!=='isolated-art-test'){http_response_code(401);echo '{"ok":false,"error":"Unauthorized"}';exit;}`);
 // Local-only readback for verification; never copied into production.
 await writeFile(path.join(root,'inspect.php'),`<?php require __DIR__.'/api/autoload.php';require __DIR__.'/api/db.php';header('Content-Type: application/json');$tables=['photo_library','saved_palettes','saved_palette_members','palette_viewers','palette_viewer_photos'];$out=[];foreach($tables as $table)$out[$table]=$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);$out['retired_tables']=$pdo->query("SELECT name FROM sqlite_master WHERE name IN ('saved_palette_sets','saved_palette_set_photos','saved_palette_photos')")->fetchAll(PDO::FETCH_COLUMN);echo json_encode($out);`);
 const port=await new Promise(resolve=>{const s=net.createServer();s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});});
 const origin=`http://127.0.0.1:${port}`;
 php=spawn('php',['-S',`127.0.0.1:${port}`,'-t',root],{env:{...process.env,COLORFIX_ART_TEST_DB:path.join(root,'fixture.sqlite')},stdio:['ignore','pipe','pipe']});
 php.stdout.on('data',d=>{output+=d;});php.stderr.on('data',d=>{output+=d;});
 for(let i=0;i<100;i++){try{await fetch(origin+'/inspect.php');break;}catch{if(php.exitCode!==null)throw new Error(output);await new Promise(r=>setTimeout(r,30));}}
 const headers={'X-Art-Fixture':'isolated-art-test'};
 async function call(endpoint,{body,query='',method}={}){
  const response=await fetch(origin+'/api/v2/admin/'+endpoint+query,{method:method|| (body?'POST':'GET'),headers:{...headers,...(body&&!(body instanceof FormData)?{'Content-Type':'application/json'}:{})},body:body instanceof FormData?body:body?JSON.stringify(body):undefined});
  const text=await response.text();let data;try{data=JSON.parse(text);}catch{throw new Error(text+'\n'+output);}
  assert(response.ok,`${endpoint}: ${response.status} ${text}`);assert.notEqual(data.ok,false,text);return data;
 }
 const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9S8AAAAASUVORK5CYII=','base64');
 async function upload(type,visible){const form=new FormData();form.set('source_type',type);form.set('series','fixture-art');form.set('tags','fixture');form.set('alt_text','Fixture artwork');if(visible!==undefined)form.set('show_in_gallery',visible?'1':'0');form.append('photos[]',new Blob([png],{type:'image/png'}),`${type}.png`);return (await call('photo-library/upload.php',{body:form})).items[0];}
 const art=await upload('art');assert(art.rel_path.startsWith('/photos/art/fixture-art/'));
 assert((await readFile(path.join(root,art.rel_path))).equals(png),'Real multipart upload preserves image bytes');
 let state=await (await fetch(origin+'/inspect.php')).json();
 assert.equal(state.photo_library[0].source_type,'art');assert.equal(state.photo_library[0].show_in_gallery,0);
 const palette=(await call('saved-palette-save.php',{body:{brand:'sw',color_ids:[1,2],nickname:'Art study',palette_type:'art'}})).data.palette;
 assert.equal(palette.palette_type,'art');
 await call('photo-library/update.php',{body:{photo_library_id:art.photo_library_id,palette_id:palette.id}});
 const listing=await call('photo-library/list.php',{query:'?source_type=art'});
 assert.equal(listing.items.length,1);assert.equal(listing.items[0].palette_id,palette.id);assert.equal(listing.items[0].has_palette,true);assert.equal(listing.items[0].show_in_gallery,false);
 const series=await call('photo-library/series.php',{query:'?source_type=art'});assert(series.items.includes('fixture-art'));
 for(const type of ['progression','article','pin'])await upload(type);
 for(const type of ['interior','exterior','hoa']){
  const p=(await call('saved-palette-save.php',{body:{brand:'sw',color_ids:[2,1],nickname:'Existing '+type,palette_type:type}})).data.palette;assert.equal(p.palette_type,type);
 }
 assert.equal((await call('photo-library/list.php',{query:'?source_type=art'})).items.length,1,'Art filtering excludes ordinary uploads');
 const independent=(await call('palettes/save-as-new.php',{body:{source_palette_id:palette.id,new_nickname:'Independent Art copy',palette_type:'art',members:[{color_id:1},{color_id:2}],copy_photos:false}})).item;
 assert.notEqual(independent.id,palette.id);assert.notEqual(independent.palette_hash,palette.palette_hash);assert.equal(independent.palette_type,'art');assert.equal(Number(independent.is_public),0);
 // Confirm the existing content-hash reuse behavior remains unchanged.
 const reused=(await call('saved-palette-save.php',{body:{brand:'sw',color_ids:[1,2],nickname:'Reused Art study',palette_type:'art'}})).data.palette;
 assert.equal(reused.id,palette.id);
 state=await (await fetch(origin+'/inspect.php')).json();
 assert.equal(state.photo_library.find(p=>p.photo_library_id===art.photo_library_id).show_in_gallery,0,'Linking does not publish artwork');
 assert.deepEqual(state.retired_tables,[]);
 for(const table of ['palette_viewers','palette_viewer_photos'])assert.equal(state[table].length,0,`Phase 1 must not create ${table}`);
 const optedIn=await upload('art',true);
 assert.equal((await call('photo-library/list.php',{query:'?photo_library_ids='+optedIn.photo_library_id})).items[0].show_in_gallery,true,'Explicit Gallery opt-in remains available');
 await call('photo-library/update.php',{body:{photo_library_id:art.photo_library_id,palette_id:null}});
 const unlinked=(await call('photo-library/list.php',{query:'?photo_library_ids='+art.photo_library_id})).items[0];assert.equal(unlinked.palette_id,null);assert.equal(unlinked.has_palette,false);
 // Existing saved-palette multipart URL now uses the PHOTOS upload and direct association, with no retired tables.
 const savedForm=new FormData(); savedForm.set('palette_id',String(independent.id));savedForm.set('photo_type','full');savedForm.set('tags','fixture');savedForm.set('alt_text','Main photograph');
 savedForm.append('photos[]',new Blob([png],{type:'image/png'}),'main.png');
 const savedUpload=await call('saved-palette-photos/upload.php',{body:savedForm});
 const main=savedUpload.photos[0]; assert.equal(main.saved_palette_id,independent.id);assert.equal(main.id,main.photo_library_id);
 assert.equal((await call('photo-library/list.php',{query:'?photo_library_ids='+main.photo_library_id})).items[0].show_in_gallery,true,'Existing saved Full-photo Gallery default is preserved');
 const links=await call('saved-palette-photos/by-library.php',{query:'?photo_library_id='+main.photo_library_id});assert.equal(links.items[0].saved_palette_id,independent.id);
 await call('saved-palette-photos/delete.php',{body:{photo_library_id:main.photo_library_id,unlink_only:1}});
 assert((await readFile(path.join(root,main.rel_path))).equals(png),'Unlink preserves Photo Library bytes');
 const detached=(await call('photo-library/list.php',{query:'?photo_library_ids='+main.photo_library_id})).items[0];assert.equal(detached.palette_id,null);
 await call('saved-palette-photos/add-from-library.php',{body:{palette_id:independent.id,photo_library_id:main.photo_library_id,photo_type:'full'}});
 const oldMutation=await fetch(origin+'/api/v2/admin/saved-palette-photos/add-from-library.php',{method:'POST',headers:{...headers,'Content-Type':'application/json'},body:JSON.stringify({palette_id:independent.id,photo_library_id:main.photo_library_id,create_new_set:1})});assert.equal(oldMutation.status,400);
 state=await (await fetch(origin+'/inspect.php')).json();assert.deepEqual(state.retired_tables,[]);
 console.log('PASS: current saved-palette upload/attach/unlink URLs without Palette Set tables; stable Photo Library IDs; no bytes deleted; retired set mutations rejected.');
 console.log('PASS: real Art multipart upload/bytes/storage/retrieval/filter/series; Art palette save; direct link/unlink without publication; existing photo/palette types; independent Save as New; unchanged hash reuse; explicit Gallery opt-in; no set/viewer associations.');
 const entry="import React from 'react';import {createRoot} from 'react-dom/client';import {MemoryRouter} from 'react-router-dom';import PhotoLibrary from '/src/pages/AdminPhotoLibraryPage/index.jsx';createRoot(document.getElementById('root')).render(React.createElement(MemoryRouter,null,React.createElement(PhotoLibrary)));";
 vite=await createServer({plugins:[{name:'art-photo-fixture',resolveId(id){if(id==='/__art-entry.jsx')return id;},load(id){if(id==='/__art-entry.jsx')return entry;}}],server:{host:'127.0.0.1',port:0,strictPort:false},logLevel:'error'});
 await vite.listen();const uiOrigin=`http://127.0.0.1:${vite.httpServer.address().port}`;
 const html=await vite.transformIndexHtml('/__art-test','<!doctype html><html><head></head><body><div id="root"></div><script type="module" src="/__art-entry.jsx"></script></body></html>');
 browser=await chromium.launch();
 for(const width of [1280,390]){
  const context=await browser.newContext({viewport:{width,height:844}});
  await context.route(uiOrigin+'/__art-test',r=>r.fulfill({contentType:'text/html',body:html}));
  await context.route('**/api/**',async route=>{
   const url=new URL(route.request().url());
   if(url.pathname.startsWith('/api/v2/admin/photo-library/')){
    const result=await route.fetch({url:origin+url.pathname+url.search,headers:{...route.request().headers(),...headers}});return route.fulfill({response:result});
   }
   return route.fulfill({json:{ok:true,items:[],groups:[],data:{palettes:[]}}});
  });
  const page=await context.newPage();page.setDefaultTimeout(10000);const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(uiOrigin+'/__art-test');
  const uploadToggle=page.getByRole('button',{name:'Upload Photos',exact:false});
  if(await uploadToggle.getAttribute('aria-expanded')!=='true')await uploadToggle.click();
  const form=page.locator('form.admin-photo-library__upload');await form.waitFor();
  await form.locator('select').first().selectOption('progression');
  await form.getByLabel('Show in gallery',{exact:true}).check();
  await form.locator('select').first().selectOption('art');
  assert.equal(await form.getByLabel('Show in gallery',{exact:true}).isChecked(),false,'Art selection resets inherited Gallery opt-in');
  await form.getByLabel('Show in gallery',{exact:true}).check();
  assert.equal(await form.getByLabel('Show in gallery',{exact:true}).isChecked(),true,'Art permits explicit Gallery opt-in');
  await form.getByLabel('Show in gallery',{exact:true}).uncheck();
  assert.equal(await page.locator('select option[value="art"]').count(),2,'Art is available in upload and library filter');
  assert.deepEqual(errors,[],'Photo Library renders without JavaScript errors');
  await context.close();console.log(`PASS: ${width}px Photo Library Art options, default Gallery exclusion and explicit opt-in.`);
 }

} catch(error){console.error(output);throw error;}finally{await browser?.close();await vite?.close();php?.kill();await rm(root,{recursive:true,force:true});}
