import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { writeFile, rm } from 'node:fs/promises';
import net from 'node:net';
import { createServer } from 'vite';
import { chromium } from 'playwright';

// Real PHOTOS encoding/HTTP delivery; all image and database fixtures are temporary.
const fixture = JSON.parse(execFileSync('php', ['api/tests/PhotoOptimizationTest.php', '--fixture'], { encoding: 'utf8' }));
const probe = net.createServer();
await new Promise(resolve => probe.listen(0, '127.0.0.1', resolve));
const port = probe.address().port;
await new Promise(resolve => probe.close(resolve));
const phpOrigin = `http://127.0.0.1:${port}`;
const router = `${fixture.root}/router.php`;
await writeFile(router, `<?php
require ${JSON.stringify(process.cwd() + '/api/autoload.php')};
$root=__DIR__;
if (parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/photos/original.png') {
header('Content-Type: image/png');readfile($root.'/photos/original.png');return;
}
$pdo=new PDO('sqlite:'.$root.'/fixture.sqlite');
App\\PHOTOS\\Endpoints\\PhotoThumbnailEndpoint::handle($pdo,$root);
`);
const php = spawn('php', ['-S', `127.0.0.1:${port}`, router], { stdio: 'ignore' });
let server, browser;
try {
  for(let attempt=0;;attempt++) {
    try { await fetch(`${phpOrigin}/api/v2/image-thumb.php?id=2&w=160`); break; }
    catch(error) { if(attempt>100) throw error; await new Promise(resolve=>setTimeout(resolve,50)); }
  }
  const url=`${phpOrigin}/api/v2/image-thumb.php?id=1&w=520&q=88`;
  const first=await fetch(url), bytes=await first.arrayBuffer(), etag=first.headers.get('etag');
  assert.equal(bytes.byteLength,fixture.thumbnail_bytes);
  const warm=await fetch(url,{headers:{'If-None-Match':etag}});
  assert.equal(warm.status,304); assert.equal((await warm.arrayBuffer()).byteLength,0);
  execFileSync('php',['-r',`$p=new PDO('sqlite:${fixture.root}/fixture.sqlite');$p->exec('UPDATE photo_crops SET crop_y=0.466667 WHERE photo_crop_id=1');`]);
  const changed=await fetch(url,{headers:{'If-None-Match':etag}});
  assert.equal(changed.status,200); assert.notEqual(changed.headers.get('etag'),etag);
  execFileSync('php',['-r',`$p=new PDO('sqlite:${fixture.root}/fixture.sqlite');$p->exec('UPDATE photo_crops SET crop_y=0 WHERE photo_crop_id=1');`]);
  const module=`import React from 'react'; import {createRoot} from 'react-dom/client';
import {BrowserRouter} from 'react-router-dom';
import PictureSwatch from '/src/components/Swatches/PictureSwatch.jsx';
import Player from '/src/components/Player/index.jsx';
import Picker from '/src/components/Dialogs/PhotoPickerDialog.jsx';
import PaletteViewer from '/src/components/Viewers/PaletteViewer/index.jsx';
const mode=new URLSearchParams(location.search).get('mode');
const photos=Array.from({length:160},(_,i)=>({photo_library_id:i+1,image_url:'/photos/original.png',title:'Slide '+(i+1),type:'normal',transition:'cut'}));
let app;
if(mode==='player') app=React.createElement(Player,{slides:photos,playlistId:999,embedded:true});
else if(mode==='picker') app=React.createElement(Picker,{open:true,onPick:()=>{},onClose:()=>{}});
else if(mode==='palette') app=React.createElement(PaletteViewer,{meta:{photo_library_id:1,photo_url:'/photos/original.png',title:'Fixture palette'},showLogo:false});
else app=React.createElement('div',{style:{display:'grid',gridTemplateColumns:'repeat(2,1fr)',gap:20}},photos.map(p=>React.createElement('div',{key:p.photo_library_id,style:{height:260}},React.createElement(PictureSwatch,{photoLibraryId:p.photo_library_id,photoUrl:p.image_url,name:p.title,widthPercent:100,cropX:0,cropY:0,cropWidth:1,cropHeight:0.533333}))));
createRoot(document.getElementById('root')).render(React.createElement(BrowserRouter,null,app));`;
  server=await createServer({logLevel:'error',server:{host:'127.0.0.1',port:0,proxy:{}},plugins:[{
    name:'photos-fixture',configureServer(vite){vite.middlewares.use(async(req,res,next)=>{
      const url=new URL(req.url,'http://fixture');
      if(url.pathname==='/fixture') {res.setHeader('Content-Type','text/html');res.end(await vite.transformIndexHtml(req.url,'<div id="root"></div><script type="module" src="/fixture.jsx"></script>'));return;}
      if(url.pathname==='/api/v2/image-thumb.php'||url.pathname==='/photos/original.png') {
        const response=await fetch(phpOrigin+req.url,{headers:req.headers['if-none-match']?{'If-None-Match':req.headers['if-none-match']}:{}});
        res.statusCode=response.status;response.headers.forEach((v,k)=>res.setHeader(k,v));res.end(Buffer.from(await response.arrayBuffer()));return;
      }
      next();
    });},resolveId(id){if(id==='/fixture.jsx')return '\0photos-fixture';},load(id){if(id==='\0photos-fixture')return module;}
  }]});
  await server.listen();const origin=server.resolvedUrls.local[0].replace(/\/$/,'');
  browser=await chromium.launch({headless:true});
  for(const width of [1280,390]) {
    const context=await browser.newContext({viewport:{width,height:844}});
    await context.route('**/api/v2/admin/photo-library/list.php*',route=>route.fulfill({json:{ok:true,items:[{photo_library_id:1,image_url:'/photos/original.png',title:'Fixture photo'}]}}));
    const page=await context.newPage(),errors=[],requests=[];
    page.on('pageerror',e=>{errors.push(e.message);console.error(e.message);});
    page.on('request',r=>{if(r.url().includes('image-thumb.php')||r.url().includes('/photos/original.png'))requests.push(new URL(r.url()));});
    console.log('Checking gallery',width); await page.goto(`${origin}/fixture`);
    await page.locator('.pals-photo-img').first().evaluate(img=>Promise.race([img.decode(),new Promise((_,reject)=>setTimeout(()=>reject(new Error('Image decode timed out: '+img.src)),10000))]));
    await page.waitForTimeout(500);
    const initial=requests.length;assert(initial>0&&initial<160,'Gallery retains native lazy loading');
    assert(requests.every(u=>u.pathname.includes('image-thumb.php')),'No original downloads for gallery crops');
    const crop=await page.locator('.pals-photo-img').first().evaluate(img=>({src:img.currentSrc,ratio:img.naturalWidth/img.naturalHeight,fit:getComputedStyle(img).objectFit}));
    assert(Math.abs(crop.ratio-1.25)<0.01);assert.equal(crop.fit,'contain');
    const response=await fetch(crop.src);assert((await response.arrayBuffer()).byteLength<fixture.original_bytes/10);
    requests.length=0;
    console.log('Checking player'); await page.goto(`${origin}/fixture?mode=player`);
    await page.locator('.player-image:not(.is-prev)').evaluate(img=>Promise.race([img.decode(),new Promise((_,reject)=>setTimeout(()=>reject(new Error('Image decode timed out: '+img.src)),10000))]));
    await page.waitForTimeout(500);
    assert.deepEqual([...new Set(requests.map(u=>Number(u.searchParams.get('id'))))].sort((a,b)=>a-b),[1,2,3],'Only current and next two slides load');
    assert(requests.every(u=>u.searchParams.get('crop_type')==='original'&&u.searchParams.get('fit')==='inside'));
    await page.locator('.player-stage').click();await page.waitForTimeout(600);
    assert(requests.some(u=>u.searchParams.get('id')==='4'),'Advancing warms next slide');
    requests.length=0;
    console.log('Checking picker'); await page.goto(`${origin}/fixture?mode=picker`);
    await page.getByPlaceholder('e.g., door, cottage, adobe, or #621').fill('fixture');
    await page.getByRole('button',{name:'Search',exact:true}).click();
    await page.locator('img').first().evaluate(img=>Promise.race([img.decode(),new Promise((_,reject)=>setTimeout(()=>reject(new Error('Image decode timed out: '+img.src)),10000))]));
    assert(requests.some(u=>u.searchParams.get('w')==='320'),'Picker requests small derivative');
    requests.length=0;
    console.log('Checking palette'); await page.goto(`${origin}/fixture?mode=palette`);
    await page.locator('img').first().evaluate(img=>Promise.race([img.decode(),new Promise((_,reject)=>setTimeout(()=>reject(new Error('Image decode timed out: '+img.src)),10000))]));
    assert(requests.some(u=>u.searchParams.get('w')==='1200'&&u.searchParams.get('crop_type')==='original'),'Palette uses full composition display');
    assert(!requests.some(u=>u.pathname==='/photos/original.png'),'Palette avoids original download');
    assert.deepEqual(errors,[]);
    console.log(`PASS ${width}px: 160-card gallery (${initial} initial requests), real crop bytes/aspect, 160-slide player preload/advance, picker and palette delivery.`);
    await context.close();
  }
  console.log(`PASS HTTP: ${fixture.original_bytes} original bytes -> ${fixture.thumbnail_bytes} cropped thumbnail bytes; ETag 304 with zero body; crop edit invalidates same URL.`);
} finally {
  await browser?.close();await server?.close();php.kill();await rm(fixture.root,{recursive:true,force:true});
}
