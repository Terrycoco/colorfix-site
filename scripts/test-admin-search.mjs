import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {createServer} from 'vite';
import {chromium} from 'playwright';
import {parseAdminSearch, filterAdminRecords} from '../src/components/AdminLayout/adminSearch.js';

const records = [{key:'133',name:'Blue'}, {key:'1330',name:'Blue 133'}, {key:'154',name:'Salmon'}];
for (const query of ['133', '#133', '  #00133  ']) {
  assert.deepEqual(filterAdminRecords(records, query, {idField:'key'}).map(r=>r.key), ['133']);
}
assert.deepEqual(filterAdminRecords(records, '13', {idField:'key'}), []);
assert.equal(filterAdminRecords(records, 'bLuE', {textFields:['name']}).length, 2);
assert.equal(filterAdminRecords(records, '').length, 3);
assert.equal(parseAdminSearch('#9007199254740993').value, '9007199254740993');
assert.equal(filterAdminRecords([{id:'9007199254740993'}], '#9007199254740993').length, 1);
console.log('PASS shared search: exact IDs, #IDs, zero padding, large IDs, text and empty queries');

// Read-only searches on an isolated, in-memory database. Never loads api/db.php.
function phpSearch(type, query) {
  const php = spawnSync('php', [], {encoding:'utf8', input:`<?php
require getcwd().'/api/autoload.php';
$pdo = new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE clients (id INTEGER PRIMARY KEY,name TEXT,first_name TEXT,last_name TEXT,email TEXT,phone TEXT);
CREATE TABLE client_activity (client_id INTEGER,activity_type TEXT,admin_read_at TEXT);
CREATE TABLE addresses (id INTEGER,street_1 TEXT,street_2 TEXT,city TEXT,state TEXT,postal_code TEXT,country_code TEXT);
CREATE TABLE properties (id INTEGER PRIMARY KEY,name TEXT,address_id INTEGER,client_id INTEGER,notes TEXT,created_at TEXT,updated_at TEXT);
CREATE TABLE projects (id INTEGER,property_id INTEGER);
INSERT INTO clients VALUES (133,'Blue Client','Blue','Client','blue@example.test','555'),(1330,'Blue Other','Blue','Other','other@example.test','555133'),(154,'Salmon Client','Salmon','Client','salmon@example.test','154');
INSERT INTO client_activity VALUES (133,'site_note_received',NULL);
INSERT INTO properties VALUES (133,'Zulu Blue Property',NULL,133,'','', ''),(1330,'A Blue Other Property',NULL,1330,'','','');
INSERT INTO projects VALUES (1,133);");
// Put #133 outside the ordinary 500-record property inventory.
for($i=1;$i<=501;$i++) {$pdo->exec("INSERT OR IGNORE INTO properties(id,name) VALUES (".(2000+$i).",'Alpha ".$i."')");}
$query = ${JSON.stringify(query)};
if (${JSON.stringify(type)} === 'clients') {
  $before=$pdo->query('SELECT * FROM clients ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
  $repo=new App\\Repos\\PdoClientRepository($pdo);
  $rows=$query===''?$repo->listAll():$repo->search($query);
  if($before!==$pdo->query('SELECT * FROM clients ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))throw new RuntimeException('Search wrote data');
  echo json_encode(['ok'=>true,'items'=>$rows]);
} else {
  $_SERVER['REQUEST_METHOD']='GET';
  $_GET=${JSON.stringify(type)}==='property-id'?['id'=>$query]:['q'=>$query];
  App\\PROJECTS\\Endpoints\\PropertyListEndpoint::handle($pdo);
}
`});
  assert.equal(php.status,0,php.stderr);
  return JSON.parse(php.stdout);
}
for(const q of ['133','#133']) {
  const result=phpSearch('clients',q);
  assert.deepEqual(result.items.map(r=>r.id),[133]);
  assert.equal(result.items[0].unread_site_note_count,1);
}
assert.deepEqual(phpSearch('clients','13').items,[]);
assert.equal(phpSearch('clients','blue').items.length,2);
assert.equal(phpSearch('clients','').items.length,3);
assert.deepEqual(phpSearch('property-id','133').items.map(r=>r.id),[133]);
assert.equal(phpSearch('property-id','133').items[0].project_count,1);
assert.deepEqual(phpSearch('property-id','13').items,[]);
assert.equal(phpSearch('property-text','').items.some(r=>r.id===133),false);
assert.deepEqual(phpSearch('property-text','Blue Other').items.map(r=>r.id),[1330]);
console.log('PASS existing Client repository and PROJECTS endpoint: exact IDs, summaries retained, property beyond limit, text and empty behavior');

const entry = `import React from 'react';import {createRoot} from 'react-dom/client';
import {MemoryRouter} from 'react-router-dom';
import {AppStateProvider} from '/src/context/AppStateContext.jsx';
import {AdminDialogProvider} from '/src/components/AdminLayout/index.jsx';
import Playlists from '/src/pages/AdminPlaylistsPage/AdminPlaylistsPage.jsx';
import Sets from '/src/pages/AdminPlaylistSetsPage/index.jsx';
import Templates from '/src/pages/AdminTemplatesPage/index.jsx';
import Clients from '/src/pages/AdminClientsPage/index.jsx';
import Properties from '/src/pages/AdminPropertiesPage/index.jsx';
import {AdminListPane,AdminObjectList,AdminObjectListItem} from '/src/components/AdminLayout/index.jsx';
import MobileSelect from '/src/components/AdminLayout/mobile/MobileSelect.jsx';
function Picker(){return React.createElement(MobileSelect,{title:'Generic',searchable:true,options:[{value:'1330',label:'Option 1330'},{value:'133',label:'Option 133'}]});}
function RecordPicker(){return React.createElement(AdminListPane,{title:'Records'},React.createElement(AdminObjectList,null,...Array.from({length:10},(_,i)=>React.createElement(AdminObjectListItem,{key:i,id:i===0?133:i===1?1330:200+i,title:'Record '+(i===0?133:i===1?1330:200+i),onSelect:()=>window.fixtureSelected=i}))));}
const kind=new URLSearchParams(location.search).get('kind');const Page={playlists:Playlists,sets:Sets,templates:Templates,clients:Clients,properties:Properties,generic:Picker,records:RecordPicker}[kind];
createRoot(document.getElementById('root')).render(React.createElement(AppStateProvider,null,React.createElement(MemoryRouter,null,React.createElement(AdminDialogProvider,null,React.createElement(Page)))));`;
const server=await createServer({logLevel:'error',server:{host:'127.0.0.1',port:0,strictPort:false,open:false},plugins:[{name:'admin-search-fixture',resolveId(id){if(id==='/__admin-search.jsx')return id;},load(id){if(id==='/__admin-search.jsx')return entry;}}]});
let browser;
try {
  await server.listen();const origin=`http://127.0.0.1:${server.httpServer.address().port}`;
  const html=await server.transformIndexHtml('/__admin-search','<!doctype html><div id="root"></div><script type="module" src="/__admin-search.jsx"></script>');
  browser=await chromium.launch();
  for(const width of [1280,390]) {
    const context=await browser.newContext({viewport:{width,height:844}});
    const requests=[];
    await context.route('**/api/**',async route=>{
      assert.equal(route.request().method(),'GET','Searching cannot write');
      const url=new URL(route.request().url());requests.push(url);
      const base=[{id:133,title:'Blue Primary',label:'Blue Primary',name:'Blue Primary',is_active:1},{id:1330,title:'Blue Other',label:'Blue Other',name:'Blue Other',is_active:1},{id:154,title:'Salmon',label:'Salmon',name:'Salmon',is_active:0}];
      const payload={ok:true,items:[],templates:[],sets:[],user:{id:1,is_admin:true}};
      if(url.pathname.endsWith('/playlists/list.php')) payload.items=base.map(r=>({...r,playlist_id:r.id,type:'normal'}));
      if(url.pathname.endsWith('/playlist-sets/list.php')) payload.sets=base.map(r=>({...r,handle:'set-'+r.id}));
      if(url.pathname.endsWith('/documents/templates/list.php'))payload.templates=base.map(r=>({...r,template_key:'key-'+r.id,template_type:'document',description:'Fixture description'}));
      if(url.pathname.endsWith('/clients/list.php'))Object.assign(payload,phpSearch('clients',url.searchParams.get('q')||''));
      if(url.pathname.endsWith('/properties/list.php'))Object.assign(payload,phpSearch(url.searchParams.has('id')?'property-id':'property-text',url.searchParams.get('id')||url.searchParams.get('q')||''));
      if(url.pathname.endsWith('/playlists/get.php'))payload.playlist={playlist_id:133,title:'Blue Primary',is_active:1};
      if(url.pathname.endsWith('/clients/get.php'))payload.client={id:133,name:'Blue Client'};
      if(url.pathname.endsWith('/properties/get.php'))payload.property={id:133,name:'Zulu Blue Property'};
      return route.fulfill({json:payload});
    });
    await context.route('**/__admin-search?*',route=>route.fulfill({contentType:'text/html',body:html}));
    const page=await context.newPage();
    const errors=[];page.on('pageerror',e=>errors.push(e.message));
    for(const kind of ['playlists','sets','templates','clients','properties']) {
      await page.goto(`${origin}/__admin-search?kind=${kind}`);
      let search=page.getByRole('searchbox').first();
      if(width===390&&['playlists','sets','templates'].includes(kind)) {
        await page.locator('.admin-mobile-select__trigger').first().click();
        search=page.getByRole('searchbox');
      }
      await search.waitFor();
      await search.fill('blue');
      const results=width===390&&['playlists','sets','templates'].includes(kind)?page.locator('[role="option"]'):page.locator('[data-admin-object-id]');
      await page.waitForFunction(({mobile})=>document.querySelectorAll(mobile?'[role="option"]':'[data-admin-object-id]').length>=1,{mobile:width===390&&['playlists','sets','templates'].includes(kind)});
      for(const q of ['133','#133']) {
        await search.fill(q);
        await page.waitForFunction(({mobile})=>{const rows=[...document.querySelectorAll(mobile?'[role="option"]':'[data-admin-object-id]')];return rows.length===1&&(mobile?rows[0].innerText.includes('#133'):rows[0].dataset.adminObjectId==='133');},{mobile:width===390&&['playlists','sets','templates'].includes(kind)});
      }
      await search.fill('13');
      await page.waitForFunction(({mobile})=>document.querySelectorAll(mobile?'[role="option"]':'[data-admin-object-id]').length===0,{mobile:width===390&&['playlists','sets','templates'].includes(kind)});
      await search.fill('blue');
      await results.first().waitFor();
      await search.fill('');
      await results.first().waitFor();
      if(kind==='playlists') {
        await search.fill('154');
        await page.waitForFunction(({mobile})=>document.querySelectorAll(mobile?'[role="option"]':'[data-admin-object-id]').length===0,{mobile:width===390});
        if(width===390) await page.getByRole('button',{name:'Close selection'}).click();
        await page.getByRole('button',{name:'Inactive',exact:true}).click();
        if(width===390) {
          await page.locator('.admin-mobile-select__trigger').first().click();
          search=page.getByRole('searchbox');
        }
        await search.fill('#154');
        await results.first().waitFor();
        assert.equal(await results.count(),1,'Exact lookup must preserve the selected active/inactive scope');
        assert.match(await results.first().innerText(),/Salmon/);
      }
      console.log('PASS',width,kind,'text, exact numeric/# ID, no partial IDs, clear search');
    }
    if(width===390) {
      await page.goto(`${origin}/__admin-search?kind=records`);
      await page.locator('.admin-mobile-select__trigger').click();
      await page.getByRole('searchbox').fill('#133');
      assert.equal(await page.getByRole('option').count(),1);
      await page.getByRole('option').click();
      assert.equal(await page.evaluate(()=>window.fixtureSelected),0,'Record ID must not be interpreted as option index');
      await page.goto(`${origin}/__admin-search?kind=generic`);
      await page.locator('.admin-mobile-select__trigger').click();
      await page.getByRole('searchbox').fill('133');
      assert.equal(await page.getByRole('option').count(),2,'Generic pickers retain substring matching');
      console.log('PASS mobile record IDs distinct from positions; generic numeric options preserve text semantics');
    }
    assert.deepEqual(errors,[]);
    await context.close();
  }
} finally {await browser?.close();await server.close();}
