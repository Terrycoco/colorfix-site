import assert from 'node:assert/strict';
import {createServer} from 'vite';
import {chromium} from 'playwright';

// Mirrors the existing raw SlidePresetListEndpoint contract, with no DB connection.
const defaults = {
  default_title:null,default_subtitle:null,default_subtitle_2:null,default_layout:'default',
  default_title_mode:null,default_star:false,default_transition:null,default_duration_ms:null,
  default_is_active:true,default_exclude_from_thumbs:false,default_is_share_image:false,
  default_site:true,default_yt:true,default_pin:false,default_concept:false,default_client:false,
  default_analyzer_role:'ignore',default_finder_start:'auto',default_version_number:1,default_is_final:false,
  is_enabled:true,
};
const presets = [
  {preset_key:'normal',label:'Slide',item_type:'non-palette',insert_position:'after_selected',default_transition:'animation',default_pin:true,default_concept:true,default_client:true},
  {preset_key:'intro',label:'Intro',item_type:'intro',insert_position:'top'},
  {preset_key:'text',label:'Text',item_type:'text',insert_position:'after_selected'},
  {preset_key:'hue-wheel',label:'Hue Wheel',item_type:'hue-wheel',insert_position:'after_selected'},
  {preset_key:'cover-image',label:'Cover Image',item_type:'cover-image',insert_position:'top',default_site:false},
  {preset_key:'teaser',label:'Teaser',item_type:'non-palette',insert_position:'bottom',default_site:false,default_yt:false,default_pin:true,default_analyzer_role:'teaser'},
  {preset_key:'brand-bumper',label:'Brand Bumper',item_type:'brand-bumper',insert_position:'bottom',default_title:'ColorFix',default_subtitle:'by Terry',default_duration_ms:4200,default_concept:true,default_client:true,default_analyzer_role:'single'},
].map(row=>({...defaults,...row}));
const originalPresets=structuredClone(presets);
const entry=`import React from 'react';import {createRoot} from 'react-dom/client';
import {MemoryRouter} from 'react-router-dom';import {AppStateProvider} from '/src/context/AppStateContext.jsx';
import {AdminDialogProvider} from '/src/components/AdminLayout/index.jsx';
import Editor from '/src/pages/AdminPlaylistsPage/PlaylistEditor.jsx';
createRoot(document.getElementById('root')).render(React.createElement(AppStateProvider,null,React.createElement(MemoryRouter,null,React.createElement(AdminDialogProvider,null,React.createElement(Editor,{playlistId:43})))));`;
const server=await createServer({logLevel:'error',server:{host:'127.0.0.1',port:0,strictPort:false,open:false},plugins:[{
  name:'slide-types-fixture',resolveId(id){if(id==='/__slide-types.jsx')return id;},load(id){if(id==='/__slide-types.jsx')return entry;},
}]});
let browser;
try {
  await server.listen();const origin=`http://127.0.0.1:${server.httpServer.address().port}`;
  const html=await server.transformIndexHtml('/__slide-types','<!doctype html><meta name="viewport" content="width=device-width, initial-scale=1"><style>:root{--board-scroller-height:0px;--z-drawer:1300}</style><div id="root"></div><script type="module" src="/__slide-types.jsx"></script>');
  browser=await chromium.launch();
  for(const width of [1280,390]) {
    const context=await browser.newContext({viewport:{width,height:844}});
    let savedItems=[{playlist_item_id:1,item_type:'normal',title:'Legacy Normal',is_active:1},{playlist_item_id:2,item_type:'palette',title:'Existing Palette',is_active:1}];
    let saves=0;
    let nextItemId=100;
    const playlist={playlist_id:43,title:'Slide Type Fixture',type:'normal',is_active:false,is_public:false,indexable:true};
    await context.route('**/api/**',route=>{
      const path=new URL(route.request().url()).pathname;
      if(path.endsWith('/playlist-slide-presets/list.php'))return route.fulfill({json:{ok:true,presets}});
      if(path.endsWith('/playlists/get.php'))return route.fulfill({json:{ok:true,playlist,items:savedItems}});
      if(path.endsWith('/playlists/list.php'))return route.fulfill({json:{ok:true,items:[playlist]}});
      if(path.endsWith('/playlists/save.php')){
        const body=route.request().postDataJSON();assert.equal(body.is_active,false);assert.equal(body.is_public,false);
        assert.equal(body.type,'normal','Playlist type is separate from slide TYPE');
        return route.fulfill({json:{ok:true,playlist_id:43}});
      }
      if(path.endsWith('/playlist-items/save.php')){
        savedItems=route.request().postDataJSON().items.map((item,i)=>({...item,playlist_item_id:item.playlist_item_id||nextItemId++,order_index:i}));
        saves++;return route.fulfill({json:{ok:true}});
      }
      assert.equal(route.request().method(),'GET','Do not write presets or call send/playback workflows');
      return route.fulfill({json:{ok:true,items:[],projects:[],user:{id:1,is_admin:true}}});
    });
    await context.route('**/__slide-types',route=>route.fulfill({contentType:'text/html',body:html}));
    const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
    await page.goto(`${origin}/__slide-types`);
    await page.getByText('Legacy Normal',{exact:true}).waitFor();
    const fieldSelect=(name)=>page.locator('label.admin-field').filter({has:page.locator('.admin-field__label').filter({hasText:new RegExp('^'+name+'$')})}).locator('select');
    const insert=fieldSelect('Insert');
    await insert.locator('option[value="normal:palette"]').waitFor({state:'attached'});
    const labels=await insert.locator('option').allTextContents();
    for(const label of ['Palette','Non Palette','Intro','Text','Hue Wheel','Cover Image','Brand Bumper','Teaser'])assert(labels.some(text=>text.trim()===label),label);
    assert(!labels.some(text=>text.trim()==='Slide'));
    const legacyRow=page.locator('tbody tr').filter({has:page.getByText('Legacy Normal',{exact:true})});
    assert.match(await legacyRow.innerText(),/Palette/);assert(!/\bNormal\b/.test((await legacyRow.locator('td').nth(3).innerText())));
    await legacyRow.dblclick();
    await fieldSelect('Type').waitFor();
    assert.equal(await fieldSelect('Type').inputValue(),'normal','Legacy value preserved while label is Palette');
    assert.equal(await fieldSelect('Type').locator('option:checked').innerText(),'Palette');
    await page.getByRole('button',{name:'Save & Close',exact:true}).first().click();
    await fieldSelect('Type').waitFor({state:'hidden'});
    assert.equal(savedItems.find(row=>row.playlist_item_id===1).item_type,'normal');

    const cases=[['Palette','palette'],['Non Palette','non-palette'],['Intro','intro'],['Text','text'],['Hue Wheel','hue-wheel'],['Cover Image','cover-image'],['Brand Bumper','brand-bumper'],['Teaser','non-palette']];
    for(const [label,value] of cases) {
      await insert.selectOption({label});
      const row=page.locator('tbody tr.is-selected');await row.waitFor();
      assert.match(await row.innerText(),new RegExp(value==='non-palette'?'Non Palette':label));
      await row.dblclick();const type=fieldSelect('Type');await type.waitFor();
      assert.equal(await type.inputValue(),value,`${label} must choose TYPE automatically`);
      await page.getByLabel('Title',{exact:true}).fill(`Inserted ${label}`);
      await page.getByRole('button',{name:'Save & Close',exact:true}).first().click();
      await type.waitFor({state:'hidden'});
      const saved=savedItems.find(item=>item.title===`Inserted ${label}`);assert.equal(saved.item_type,value);
      if(label==='Palette'||label==='Non Palette')assert.equal(saved.transition,'animation','Reuse the existing Slide defaults');
      if(label==='Teaser') {
        assert.equal(savedItems.at(-1).title,'Inserted Teaser');
        assert.equal(saved.analyzer_role,'teaser');assert.equal(saved.site,false);assert.equal(saved.yt,false);assert.equal(saved.pin,true);assert.equal(saved.concept,false);assert.equal(saved.client,false);
        assert.equal(saved.showcase,true,'Preserve the existing Showcase fallback; do not change publishing rules');
      }
      if(label==='Intro'||label==='Cover Image')assert.equal(savedItems[0].title,`Inserted ${label}`);
      if(label==='Hue Wheel')assert.equal(JSON.parse(saved.body).items.length,2);
      if(label==='Brand Bumper')assert.equal(JSON.parse(saved.body).variant,'colorfix-signature');
      await page.locator('tbody tr').filter({has:page.getByText(`Inserted ${label}`,{exact:true})}).dblclick();
      await fieldSelect('Type').waitFor();
      assert.equal(await fieldSelect('Type').inputValue(),value,`${label} retains its TYPE after save and reopen`);
      await page.getByRole('button',{name:'Save & Close',exact:true}).first().click();
      await fieldSelect('Type').waitFor({state:'hidden'});
    }
    // Deliberately change TYPE, save, reload, and reopen using the existing API contract.
    await page.locator('tbody tr').filter({has:page.getByText('Inserted Palette',{exact:true})}).dblclick();
    await fieldSelect('Type').selectOption('non-palette');
    await page.getByRole('button',{name:'Save & Close',exact:true}).first().click();
    await fieldSelect('Type').waitFor({state:'hidden'});
    await page.reload();await page.getByText('Inserted Palette',{exact:true}).waitFor();
    await page.locator('tbody tr').filter({has:page.getByText('Inserted Palette',{exact:true})}).dblclick();
    assert.equal(await fieldSelect('Type').inputValue(),'non-palette');
    await fieldSelect('Type').selectOption('palette');
    await page.getByRole('button',{name:'Save & Close',exact:true}).first().click();
    await fieldSelect('Type').waitFor({state:'hidden'});
    assert.equal(savedItems.find(item=>item.title==='Inserted Palette').item_type,'palette');
    assert.equal(savedItems.find(item=>item.playlist_item_id===1).item_type,'normal');
    assert.equal(savedItems.some(item=>item.item_type==='teaser'),false);
    assert.deepEqual(presets,originalPresets);assert.deepEqual(errors,[]);assert(saves>=11);
    console.log('PASS',width,'insert all types, automatic TYPE, shared labels, legacy normal unchanged, switch/save/reopen, Teaser flags/position, original defaults and publication status');
    await context.close();
  }
} finally {await browser?.close();await server.close();}
