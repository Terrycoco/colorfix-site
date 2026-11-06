import assert from 'node:assert/strict';
import { createServer } from 'vite';
import { chromium } from 'playwright';
const entry="import React from 'react';\nimport {createRoot} from 'react-dom/client';\nimport {MemoryRouter} from 'react-router-dom';\nimport AdminColorEditPage from '/src/pages/AdminColorEditPage/index.jsx';\nimport RecalcCatsButton from '/src/pages/CategoryEditPage/RecalcCatsButton.jsx';\ncreateRoot(document.getElementById('test-root')).render(React.createElement(MemoryRouter,null,React.createElement(AdminColorEditPage),React.createElement(RecalcCatsButton)));";
const server=await createServer({plugins:[{name:'refresh-control-fixture',resolveId(id){if(id==='/__swatch-refresh-entry.jsx')return id;},load(id){if(id==='/__swatch-refresh-entry.jsx')return entry;}}],server:{host:'127.0.0.1',port:0,strictPort:false},logLevel:'error'});
let browser;
try {
  await server.listen(); const origin=`http://127.0.0.1:${server.httpServer.address().port}`;
  browser=await chromium.launch();
  const context=await browser.newContext();
  await context.route('**/src/context/AppStateContext.jsx*',route=>route.fulfill({contentType:'text/javascript',body:'export function useAppState(){return {colors:[],currentColorDetail:null,setCurrentColorDetail(){}}}'}));
  const html=await server.transformIndexHtml('/__swatch-refresh-test',`<!doctype html><html><head></head><body><main id="test-root"></main><script type="module" src="/__swatch-refresh-entry.jsx"></script></body></html>`);
  await context.route(origin+'/__swatch-refresh-test',route=>route.fulfill({contentType:'text/html',body:html}));
  let refreshes=0,saves=0,recalcs=0,failRefresh=false,warnRecalc=false,releaseRefresh;
  await context.route('**/api/**',async route=>{
    const url=new URL(route.request().url());
    if(url.pathname.endsWith('/refresh-swatches.php')) {
      refreshes++; assert.equal(route.request().method(),'POST');
      if(releaseRefresh) await releaseRefresh;
      return route.fulfill({status:failRefresh?500:200,json:failRefresh?{ok:false,error:'Refresh fixture failure'}:{ok:true,summary:{rows_after:20834}}});
    }
    if(url.pathname.endsWith('/color-save.php')) {saves++;return route.fulfill({json:{ok:true,id:1}});}
    if(url.pathname.endsWith('/recalculate.php')) {recalcs++;return route.fulfill({json:{status:warnRecalc?'warning':'success',message:warnRecalc?'Categories saved; swatch refresh failed. Use Refresh Swatches to retry.':null,summary:{processed:2,total_colors:2,swatches:{ok:!warnRecalc}}}});}
    if(url.pathname.endsWith('/get-color.php')) return route.fulfill({json:{ok:true,color:{id:1}}});
    return route.fulfill({json:{ok:true,results:[]}});
  });
  const page=await context.newPage();page.setDefaultTimeout(10000);page.on('pageerror',error=>console.error(error));
  await page.goto(origin+'/__swatch-refresh-test');
  const refresh=page.getByRole('button',{name:'Refresh Swatches',exact:true});await refresh.waitFor();
  assert.equal(refreshes,0,'Opening Colors must not refresh swatches');
  await page.getByLabel('Brand',{exact:true}).fill('sw');
  await page.getByPlaceholder('Victorian Pewter').fill('Fixture paint');
  await page.getByPlaceholder('EFEFEF').fill('EDEAE0');
  await page.getByRole('button',{name:'Save',exact:true}).click();
  await page.getByText('Saved ✔',{exact:false}).waitFor();
  assert.equal(saves,1);assert.equal(refreshes,0,'Saving a color must not trigger a rebuild');
  await page.getByRole('button',{name:'New',exact:true}).click();
  assert.equal(refreshes,0,'New/reset must not trigger a rebuild');
  let release;releaseRefresh=new Promise(resolve=>{release=resolve;});
  await refresh.click();
  await page.getByRole('button',{name:'Refreshing Swatches…',exact:true}).waitFor();
  assert.equal(await page.getByRole('button',{name:'Refreshing Swatches…',exact:true}).isDisabled(),true);
  release();releaseRefresh=null;
  await page.getByRole('status').filter({hasText:'Swatches refreshed: 20834 colors.'}).waitFor();
  assert.equal(refreshes,1,'Only the manual button calls refresh');
  failRefresh=true;await refresh.click();
  await page.getByRole('status').filter({hasText:'Refresh fixture failure'}).waitFor();
  assert.equal(await refresh.isEnabled(),true,'Failure permits retry');
  page.on('dialog',dialog=>dialog.accept());
  await page.getByRole('button',{name:'Recalc Categories',exact:true}).click();
  await page.getByText('Recalc complete: 2/2 colors; categories, clusters, and swatches refreshed.').waitFor();
  assert.equal(recalcs,1);assert.equal(refreshes,2,'Recalc uses its server workflow; browser must not double-trigger refresh');
  warnRecalc=true;await page.getByRole('button',{name:'Recalc Categories',exact:true}).click();
  await page.getByText('Categories saved; swatch refresh failed. Use Refresh Swatches to retry.').waitFor();
  console.log('PASS: no refresh on load/edit/save/reset; explicit manual refresh, busy/retry states; recalc completion/warning messages and no duplicate client refresh.');
} finally {await browser?.close();await server.close();}
