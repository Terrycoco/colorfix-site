import assert from 'node:assert/strict';
import {createServer} from 'vite';
import {chromium} from 'playwright';
import {filterAdminRecords} from '../src/components/AdminLayout/adminSearch.js';

// Snapshot of relevant live metadata, not a production connection.
const existing = [
  {playlist_id:43,title:'Adams Makeover',is_active:0,cover_title:'Adobe Makeovers'},
  {playlist_id:50,title:'Green Exterior Palettes',is_active:1,slide_title:'Adobe Garden'},
];
const fixtures = [
  {playlist_id:133,title:'Warm Adobe',is_active:1},
  {playlist_id:1330,title:'Adobe Cottage',is_active:1},
];
// The exact search predicate used before AdminSearchField integration.
function previousTextSearch(rows, query) {
  const needle=query.trim().toLowerCase();
  return rows.filter(row=>!needle||String(row.title||'').toLowerCase().includes(needle)||String(row.playlist_id||'').includes(needle));
}
for(const rows of [existing,[...existing,...fixtures]]) {
  for(const query of ['adobe','ADOBE','  DoBe  ','Adams','Green','']) {
    assert.deepEqual(
      filterAdminRecords(rows,query,{idField:'playlist_id',textFields:['title']}),
      previousTextSearch(rows,query),
      `Existing text behavior must be preserved for ${JSON.stringify(query)}`
    );
  }
}
assert.deepEqual(previousTextSearch(existing,'adobe'),[]);
assert.deepEqual(filterAdminRecords(fixtures,'133',{idField:'playlist_id'}).map(r=>r.playlist_id),[133]);
assert.deepEqual(filterAdminRecords(fixtures,'#133',{idField:'playlist_id'}).map(r=>r.playlist_id),[133]);
assert.deepEqual(filterAdminRecords(fixtures,'13',{idField:'playlist_id'}),[]);
console.log('PASS before/after comparison: partial title text is unchanged; slide text excluded; IDs now exact');

const entry=`import React from 'react';import {createRoot} from 'react-dom/client';
import {MemoryRouter} from 'react-router-dom';
import {AppStateProvider} from '/src/context/AppStateContext.jsx';
import {AdminDialogProvider} from '/src/components/AdminLayout/index.jsx';
import Page from '/src/pages/AdminPlaylistsPage/AdminPlaylistsPage.jsx';
createRoot(document.getElementById('root')).render(React.createElement(AppStateProvider,null,React.createElement(MemoryRouter,null,React.createElement(AdminDialogProvider,null,React.createElement(Page)))));`;
const server=await createServer({logLevel:'error',server:{host:'127.0.0.1',port:0,strictPort:false,open:false},plugins:[{
  name:'playlist-search-regression',
  resolveId(id){if(id==='/__playlist-search-regression.jsx')return id;},
  load(id){if(id==='/__playlist-search-regression.jsx')return entry;},
}]});
let browser;
try {
  await server.listen();const origin=`http://127.0.0.1:${server.httpServer.address().port}`;
  const html=await server.transformIndexHtml('/__playlist-search-regression','<!doctype html><div id="root"></div><script type="module" src="/__playlist-search-regression.jsx"></script>');
  browser=await chromium.launch();
  for(const width of [1280,390]) {
    const context=await browser.newContext({viewport:{width,height:844}});
    let withFixtures=false;
    const errors=[];
    await context.route('**/api/**',route=>{
      assert.equal(route.request().method(),'GET');
      const path=new URL(route.request().url()).pathname;
      if(path.endsWith('/playlists/list.php'))return route.fulfill({json:{ok:true,items:withFixtures?[...existing,...fixtures]:existing}});
      return route.fulfill({json:{ok:true,items:[],user:{id:1,is_admin:true}}});
    });
    await context.route('**/__playlist-search-regression',route=>route.fulfill({contentType:'text/html',body:html}));
    const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
    async function openSearch(){
      if(width===390)await page.locator('.admin-mobile-select__trigger').click();
      return page.getByRole('searchbox',{name:width===390?'Search Playlists':'Search playlists...'});
    }
    async function closeSearch(){if(width===390)await page.getByRole('button',{name:'Close selection'}).click();}
    const selector=width===390?'[role="option"]':'[data-admin-object-id]';
    async function expectIds(ids){
      await page.waitForFunction(({selector,ids})=>{
        const rows=[...document.querySelectorAll(selector)];
        return rows.length===ids.length&&rows.every((row,i)=>selector==='[role="option"]'?row.innerText.includes('#'+ids[i]):row.dataset.adminObjectId===String(ids[i]));
      },{selector,ids});
    }
    await page.goto(`${origin}/__playlist-search-regression`);
    let search=await openSearch();await search.fill('adobe');await expectIds([]);
    await closeSearch();await page.getByRole('button',{name:'Inactive',exact:true}).click();
    search=await openSearch();await search.fill('adobe');await expectIds([]);
    await search.fill('aDaMs');await expectIds([43]);
    await search.fill('#43');await expectIds([43]);
    console.log('PASS',width,'actual-name fixture: Adobe is slide-only; inactive Adams found by name/#43');

    withFixtures=true;
    await page.reload();
    search=await openSearch();
    for(const query of ['adobe','ADOBE','dObE','  adobe  ']) {
      await search.fill(query);await expectIds([1330,133]);
    }
    if(width===1280) {
      await page.getByRole('button',{name:'ID',exact:true}).click();
      await expectIds([1330,133]);
      await page.getByRole('button',{name:'Title',exact:true}).click();
      await expectIds([1330,133]);
      // Reversed IDs make the sort comparison independent of text matching.
      await search.fill('');await expectIds([1330,50,133]);
      await page.getByRole('button',{name:'ID',exact:true}).click();
      await expectIds([1330,133,50]);
      await search.fill('adobe');await expectIds([1330,133]);
    }
    for(const query of ['133','#133']){await search.fill(query);await expectIds([133]);}
    await search.fill('13');await expectIds([]);
    await search.fill('adobe');await expectIds([1330,133]);
    await closeSearch();await page.getByRole('button',{name:'Inactive',exact:true}).click();
    search=await openSearch();await search.fill('adobe');await expectIds([]);
    assert.deepEqual(errors,[]);
    console.log('PASS',width,'partial case-insensitive Adobe titles, exact numeric/# IDs, no partial IDs, activity scope; Title/ID are sorting only');
    await context.close();
  }
} finally {await browser?.close();await server.close();}
