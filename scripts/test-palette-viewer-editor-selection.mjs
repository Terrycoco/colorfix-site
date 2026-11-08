import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {createServer} from 'vite';
import {chromium} from 'playwright';
import path from 'node:path';

const before=process.argv.includes('--before');
const pagePath=path.resolve('src/pages/AdminPaletteViewersPage/index.jsx');
const previous=before?execFileSync('git',['show','HEAD:src/pages/AdminPaletteViewersPage/index.jsx'],{encoding:'utf8'}):null;
const entry=`import React from 'react';import {createRoot} from 'react-dom/client';
import {MemoryRouter} from 'react-router-dom';import Page from '/src/pages/AdminPaletteViewersPage/index.jsx';
createRoot(document.getElementById('root')).render(React.createElement(MemoryRouter,null,React.createElement(Page)));`;
const server=await createServer({logLevel:'error',server:{host:'127.0.0.1',port:0,strictPort:false,open:false},plugins:[{
  name:'pv-selection-fixture',enforce:'pre',
  resolveId(id){if(id==='/__pv-selection.jsx')return id;},
  load(id){if(id==='/__pv-selection.jsx')return entry;if(before&&id===pagePath)return previous;},
}]});
let browser;
try {
  await server.listen();const origin=`http://127.0.0.1:${server.httpServer.address().port}`;
  const html=await server.transformIndexHtml('/__pv-selection','<!doctype html><meta name="viewport" content="width=device-width, initial-scale=1"><div id="root"></div><script type="module" src="/__pv-selection.jsx"></script>');
  browser=await chromium.launch();
  for(const width of [1280,390]) {
    const context=await browser.newContext({viewport:{width,height:844}});
    await context.addInitScript(()=>{window.open=(...args)=>{window.previewArgs=args;};});
    const detailRequests=[];let failFive=false;
    const rows=[
      {viewer:{palette_viewer_id:84,saved_palette_id:null,project_id:1,format:'painter',title:'Project Painter',is_active:1},palette:null,photo_count:0,rex:[]},
      {viewer:{palette_viewer_id:5,saved_palette_id:8,format:'public',template_key:'full_palette',title:'Adobe Garden',is_active:1},palette:{id:8,nickname:'Adobe Garden'},photo_count:3,rex:[1]},
      {viewer:{palette_viewer_id:86,saved_palette_id:156,format:'client',template_key:'client',title:'Wine Room',is_active:1},palette:{id:156,nickname:'Wine Room'},photo_count:1,rex:[2]},
    ];
    await context.route('**/api/**',route=>{
      assert.equal(route.request().method(),'GET','Selection and Preview must not modify records');
      const url=new URL(route.request().url());
      if(url.pathname.endsWith('/palette-viewers.php')) {
        if(url.searchParams.get('mode')==='palettes')return route.fulfill({json:{ok:true,items:rows.map(r=>r.palette).filter(Boolean)}});
        const id=Number(url.searchParams.get('id')||0);
        if(!id)return route.fulfill({json:{ok:true,items:rows}});
        detailRequests.push(id);
        if(id===84||id===5&&failFive)return route.fulfill({status:500,json:{ok:false,error:'Saved Palette not found.'}});
        const row=rows.find(r=>r.viewer.palette_viewer_id===id);
        return route.fulfill({json:{ok:true,item:{...row,photos:[],members:[{color_id:1,color_name:'Fixture color',color_hex6:'ABCDEF'}],rex:{public_url:`/t/viewer-${id}`,warning:null}}}});
      }
      return route.fulfill({json:{ok:true,items:[],kickers:[]}});
    });
    await context.route(/\/__pv-selection(?:\?.*)?$/,route=>route.fulfill({contentType:'text/html',body:html}));
    const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
    await page.goto(`${origin}/__pv-selection`);
    const banner=page.getByText('Saved Palette not found.',{exact:true});
    if(before) {
      await banner.waitFor();
      await page.locator('[data-admin-object-id="5"]').click();
      await page.getByLabel('Title',{exact:true}).waitFor();
      await banner.waitFor();
      assert.equal(await page.getByLabel('Title',{exact:true}).inputValue(),'Adobe Garden');
      assert(detailRequests.includes(84));
      await page.getByRole('button',{name:'Preview Viewer',exact:true}).click();
      assert.equal(await page.evaluate(()=>window.previewArgs[0]),'/t/viewer-5');
      console.log('REPRODUCED',width,'false palette-missing banner from Viewer 84 over valid Adobe Garden editor/preview');
    } else {
      await page.getByLabel('Title',{exact:true}).waitFor();
      assert.equal(await page.getByLabel('Title',{exact:true}).inputValue(),'Adobe Garden');
      assert.equal(detailRequests.includes(84),false,'No URL palette ID must not match a null project-Painter palette');
      assert.equal(await banner.count(),0);
      assert.equal(await page.locator('[data-admin-object-id="84"]').count(),1,'Project Painter record remains listed');
      for(const id of [86,5]) {
        await page.locator(`[data-admin-object-id="${id}"]`).click();
        await page.waitForFunction(title=>document.querySelector('input[value="'+title+'"]'),id===5?'Adobe Garden':'Wine Room');
        assert.equal(await banner.count(),0);
      }
      await page.getByRole('button',{name:'Preview Viewer',exact:true}).click();
      assert.equal(await page.evaluate(()=>window.previewArgs[0]),'/t/viewer-5');
      for(const query of ['?palette_id=8','?pv_id=5']) {
        await page.goto(`${origin}/__pv-selection${query}`);
        await page.getByLabel('Title',{exact:true}).waitFor();
        assert.equal(await page.getByLabel('Title',{exact:true}).inputValue(),'Adobe Garden');
        assert.equal(await banner.count(),0);
      }
      // Real failures still surface; this is not a blanket suppression of errors.
      await page.goto(`${origin}/__pv-selection`);
      await page.getByLabel('Title',{exact:true}).waitFor();
      await page.locator('[data-admin-object-id="86"]').click();
      await page.waitForFunction(()=>document.querySelector('input[value="Wine Room"]'));
      failFive=true;
      await page.locator('[data-admin-object-id="5"]').click();await banner.waitFor();
      assert.equal(detailRequests.includes(84),false);
      console.log('PASS',width,'correct default/manual selection, deep links, preview, no phantom Painter requests; real failures preserved');
    }
    assert.deepEqual(errors,[]);await context.close();
  }
} finally {await browser?.close();await server.close();}
