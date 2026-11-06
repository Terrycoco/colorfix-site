import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createServer } from 'vite';
import { chromium } from 'playwright';

const smsModule = (await readFile('src/SAS/services/podShare.js','utf8')).replace('export function openSmsComposer(', 'function originalOpenSmsComposer(') + '\nexport function openSmsComposer(url){window.smsLink=url;}';
const experiences = ['public','concept','showcase','client','custom'].map(experience_key=>({experience_key,label:experience_key}));
const items = [
  {playlist_id:1,title:'Existing with REX',experiences:experiences.map(e=>({...e,token:e.experience_key==='public'?'stable_token':null}))},
  {playlist_id:2,title:'Draft without REX',experiences},
];
const server = await createServer({configFile:'vite.sas.config.js',server:{host:'127.0.0.1',port:0}});
let browser;
try {
  await server.listen();
  const origin=`http://127.0.0.1:${server.httpServer.address().port}`;
  browser=await chromium.launch();
  for (const width of [1280,390,320]) {
    const context=await browser.newContext({viewport:{width,height:844},isMobile:width<500});
    await context.addInitScript(()=>{
      Object.defineProperty(navigator,'clipboard',{value:{writeText:async value=>{window.copiedLink=value;}}});
      Object.defineProperty(navigator,'share',{value:async value=>{window.sharedLink=value;}});
    });
    const api=[];
    await context.route('**/api/**',async route=>{
      const url=new URL(route.request().url()); api.push(url);
      if(url.pathname.endsWith('/list-rex-playlists.php')) return route.fulfill({json:{ok:true,items}});
      if(url.pathname.endsWith('/rex/playlist-url.php')) {
        const id=url.searchParams.get('playlist_id'), key=url.searchParams.get('experience_key');
        const token=id==='1'&&key==='public'?'stable_token':`created_${id}_${key}`;
        return route.fulfill({json:{ok:true,item:{token,public_url:`/t/${token}?mode=selected&extra=keep%20this`}}});
      }
      throw new Error(`Unexpected API request ${url}`);
    });
    // Stub only the native SMS launcher to avoid opening an OS messaging app.
    await context.route('**/src/SAS/services/podShare.js',route=>route.fulfill({contentType:'text/javascript',body:smsModule}));
    await context.route('https://api.qrserver.com/**',route=>route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="320" height="320"/>'}));
    await context.route('https://colorfix.terrymarr.com/t/**',route=>route.fulfill({contentType:'text/html',body:'<title>Fixture playback</title>Playlist'}));
    const page=await context.newPage(); page.on('pageerror',error=>console.error('Browser error:',error)); page.on('console',message=>{if(message.type()==='error') console.error(message.text());}); await page.goto(origin+'/sas/playlists');
    await page.getByText('Draft without REX',{exact:true}).waitFor({timeout:10000}).catch(async error=>{console.error(await page.locator('body').innerText());throw error;});
    assert(api.length>=1 && api.every(url=>url.pathname.endsWith('/list-rex-playlists.php')),'Listing must not prepare/create a REX');
    const search=page.getByRole('searchbox',{name:'Find a playlist'});
    await search.fill('exi');
    assert.equal(await page.locator('.sas-playlist-item').count(),1);
    await page.getByRole('button',{name:'public',exact:true}).click();
    await page.getByRole('button',{name:'Quick Play',exact:false}).waitFor();
    assert.equal(new URL(await page.locator('#sas-playlist-url').inputValue()).pathname,'/t/stable_token');
    await page.getByRole('button',{name:'Playlists',exact:true}).click();
    await search.fill('draft');
    assert.equal(await page.locator('.sas-playlist-item').count(),1);
    await page.getByRole('button',{name:'custom',exact:true}).click();
    await page.getByRole('button',{name:'Quick Play',exact:false}).waitFor();
    const url=await page.locator('#sas-playlist-url').inputValue();
    assert.equal(url,'https://colorfix.terrymarr.com/t/created_2_custom?mode=selected&extra=keep+this&src=sas');
    const prepared=api.length;
    const popupPromise=page.waitForEvent('popup');
    await page.getByRole('button',{name:'Quick Play',exact:false}).click();
    const popup=await popupPromise; await popup.waitForLoadState();
    assert.equal(popup.url(),url,'Quick Play must open the exact displayed URL');
    assert.equal(await popup.evaluate(()=>window.opener),null);
    await popup.close();
    assert.equal(api.length,prepared,'Quick Play must make no creation, dispatch, recipient or send request');
    await page.getByRole('button',{name:'Copy Link',exact:false}).click();
    await page.getByRole('status').filter({hasText:'Link copied to clipboard.'}).waitFor();
    assert.equal(await page.evaluate(()=>window.copiedLink),url,'Copy must retain the whole URL with all parameters');
    await page.getByRole('button',{name:'QR Code',exact:false}).click();
    const qr=await page.getByAltText('Playlist QR code').getAttribute('src');
    assert.equal(new URL(qr).searchParams.get('data'),url);
    await page.getByRole('button',{name:'Share',exact:false}).click();
    assert.equal((await page.evaluate(()=>window.sharedLink)).url,url);
    await page.getByRole('button',{name:'Text',exact:false}).click();
    assert.equal(await page.evaluate(()=>window.smsLink),url);
    assert.equal(api.length,prepared,'All sharing actions must reuse the prepared URL');
    assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'Sharing must fit viewport width');
    const input=page.locator('#sas-playlist-url');
    assert.equal(await input.evaluate(el=>getComputedStyle(el).textOverflow),'ellipsis');
    if(width<500) assert(await input.evaluate(el=>el.scrollWidth>el.clientWidth),'Long URL is visually truncated');
    const copy=page.locator('.sas-playlist-url-control button');
    const box=await copy.boundingBox(); assert(box.x>=0 && box.x+box.width<=width && box.height>=44,'Copy button remains accessible');
    await page.getByRole('button',{name:'Playlists',exact:true}).click();
    await page.getByRole('button',{name:'custom',exact:true}).click();
    await page.getByRole('button',{name:'Quick Play',exact:false}).waitFor();
    assert.equal(await page.locator('#sas-playlist-url').inputValue(),url,'Return/selection reuses the same URL');
    // A failed lookup must never expose sharing actions with a missing URL.
    await page.getByRole('button',{name:'Playlists',exact:true}).click();
    await page.route('**/api/v2/admin/rex/playlist-url.php?**',route=>route.fulfill({status:503,json:{ok:false,error:'Temporary link failure'}}));
    await page.getByRole('button',{name:'public',exact:true}).click();
    await page.getByRole('alert').filter({hasText:'Temporary link failure'}).waitFor();
    assert.equal(await page.getByRole('button',{name:'Quick Play',exact:false}).count(),0);
    await context.close();
    console.log(`PASS: ${width}px catalogue/search, existing/new/custom selection, Quick Play, no dispatch, full copy, QR, Share, Text, retry and mobile URL layout.`);
  }
} finally {await browser?.close(); await server.close();}
