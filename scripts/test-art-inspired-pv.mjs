import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {readFile} from 'node:fs/promises';
import {createServer} from 'vite';
import {chromium} from 'playwright';

// Actual gallery, REX route and PV; payloads come from the real PHP resolver fixture.
const fixture=JSON.parse(execFileSync('php',['api/tests/ArtInspiredPhotoPVTest.php','--json'],{encoding:'utf8'}));
const server=await createServer({server:{host:'127.0.0.1',port:5188,strictPort:true,proxy:{}},logLevel:'error'});
let browser;
try {
 await server.listen();browser=await chromium.launch({headless:true});
 const origin='http://127.0.0.1:5188';
 for(const width of [1280,390]){
  const context=await browser.newContext({viewport:{width,height:900}}),page=await context.newPage(),errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  const photos=[2,3].map(id=>({id:'photo-'+id,item_type:'picture-swatch',photo_library_id:id,source_type:'art_inspired',inspiration_photo_id:1,photo_url:`/fixture/room${id}.png`,palette_id:11,palette_hash:'art-eight',palette_name:`Room ${id}`,rex_url:'/t/wrong-generic-palette'}));
  await context.route('**/*',async route=>{
   const url=new URL(route.request().url());
   if(url.origin!==origin)return route.abort();
   if(url.pathname==='/api/v2/palette-viewer-photo.php')return route.fulfill({status:302,headers:{location:fixture.rooms[url.searchParams.get('photo_library_id')].public_url},body:''});
   if(url.pathname.endsWith('/rex/resolve.php'))return route.fulfill({json:fixture.responses[url.searchParams.get('token')]});
   if(url.pathname.includes('image-thumb.php')||url.pathname.startsWith('/fixture/'))return route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="800" height="600"><rect width="800" height="600" fill="#557788"/></svg>'});
   if(url.pathname.includes('/gallery/run-query.php'))return route.fulfill({json:{meta:{meta_id:99,item_type:'picture-swatch'},results:photos,inserts:[]}});
   if(url.pathname.includes('/api/'))return route.fulfill({json:{ok:true,items:[],data:[],categories:[],event_id:1}});
   if(route.request().resourceType()==='document')return route.fulfill({contentType:'text/html',body:await server.transformIndexHtml(url.pathname,await readFile('index.html','utf8'))});
   return route.continue();
  });
  await page.goto(origin+'/results/99');
  const tile=page.locator('#photo-2');await tile.waitFor();
  assert((await tile.getAttribute('href')).includes('palette-viewer-photo.php?photo_library_id=2'));
  await tile.click();await page.waitForURL('**/t/'+fixture.rooms[2].token);
  await page.locator('.apv-photo').waitFor();
  assert.equal(new URL(await page.locator('.apv-photo').getAttribute('src')).searchParams.get('id'),'2');
  assert.equal(await page.locator('.apv-swatch').count(),8);
  const inset=page.getByRole('link',{name:'Painting Art Inspiration'});
  await inset.waitFor();assert.equal(await inset.getAttribute('href'),'/inspired-by/101');
  assert.equal(await inset.locator('span').textContent(),'Art Inspiration');
  const layout=await page.locator('.apv-column--photo').evaluate(el=>{const main=el.querySelector('.apv-photo').getBoundingClientRect(),inset=el.querySelector('.apv-art-inspiration img').getBoundingClientRect();return {below:inset.top>=main.bottom,small:inset.width<main.width,fit:getComputedStyle(el.querySelector('.apv-art-inspiration img')).objectFit};});
  assert(layout.below&&layout.small);assert.equal(layout.fit,'contain');
  const cropUrl=new URL(await inset.locator('img').getAttribute('src'));
  assert.equal(cropUrl.searchParams.get('id'),'1');
  assert.equal(cropUrl.searchParams.get('crop_type') || 'swatch','swatch');
  await inset.locator('img').evaluate(img => img.decode());
  const cropRatio=await inset.locator('img').evaluate(img => ({display:img.clientWidth/img.clientHeight,source:img.naturalWidth/img.naturalHeight}));
  assert(Math.abs(cropRatio.display-cropRatio.source)<0.02, 'Saved crop must retain its aspect ratio without a second crop');
  await page.reload();await page.locator('.apv-photo').waitFor();assert.equal(await page.locator('.apv-swatch').count(),8);
  await page.locator('.apv-photo').click();await page.locator('.apv-photo-fullscreen').waitFor();
  await page.locator('.apv-photo-fullscreen').click();
  // A second visitor opening the stable URL gets the same full experience.
  const shared=await context.newPage();await shared.goto(origin+fixture.rooms[3].public_url);
  await shared.locator('.apv-photo').waitFor();assert.equal(new URL(await shared.locator('.apv-photo').getAttribute('src')).searchParams.get('id'),'3');
  assert.equal(await shared.locator('.apv-swatch').count(),8);
  await shared.locator('.apv-art-inspiration span').click();await shared.waitForURL('**/inspired-by/101');
  assert.deepEqual(errors,[]);
  console.log(`PASS ${width}px: gallery click -> stable room REX, full eight-color PV, inset/caption navigation, room enlargement, reload/shared URL and distinct rooms.`);
  await context.close();
 }
} finally {await browser?.close();await server.close();}
