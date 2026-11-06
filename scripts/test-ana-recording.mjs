import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import net from 'node:net';
import { createServer } from 'vite';
import { chromium } from 'playwright';

const temp=await mkdtemp(path.join(tmpdir(),'colorfix-ana-'));
const port=await new Promise(resolve=>{const s=net.createServer();s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});});
const phpOrigin=`http://127.0.0.1:${port}`;
const php=spawn('php',['-S',`127.0.0.1:${port}`,'api/tests/ANARecordingHttpFixture.php'],{
 env:{...process.env,ANA_RECORDING_TEST_DB:path.join(temp,'ana.sqlite'),COLORFIX_ADMIN_SESSION_PATH:path.join(temp,'sessions')},stdio:['ignore','pipe','pipe'],
});
let phpOutput='';php.stderr.on('data',v=>phpOutput+=v);php.stdout.on('data',v=>phpOutput+=v);
const configPath='/api/v2/admin/ana/ignore-config.php';
const paths=['/api/v2/admin/ana/record-event.php','/api/v2/admin/analytics/record-event.php'];
let checks=0,server,browser;
const event={event_key:'visit',resource_type:'page',resource_id:999,path:'/picker',src:null,referrer:'',user_agent:'Client supplied Googlebot'};
async function post(url,body,ua='Mozilla/5.0 Human Browser'){
 const response=await fetch(phpOrigin+url,{method:'POST',headers:{'Content-Type':'application/json','User-Agent':ua},body:JSON.stringify(body)});
 return {response,data:await response.json()};
}
const rows=async()=>await(await fetch(phpOrigin+'/__rows')).json();
try{
 for(let i=0;i<100;i++){try{await rows();break;}catch{if(php.exitCode!==null)throw new Error(phpOutput);await new Promise(r=>setTimeout(r,30));}}
 for(const endpoint of paths) for(const name of ['ElevenlabsCrawler','Googlebot','Google-InspectionTool']) for(const ua of [name,name.toUpperCase(),name.toLowerCase()]){
  const before=(await rows()).length;
  const {response,data}=await post(endpoint,event,`Mozilla/5.0 (${ua}/1.0)`);
  assert.equal(response.status,200);assert.deepEqual(data,{ok:true,event_id:0});assert.equal((await rows()).length,before);checks++;
 }
 for(const endpoint of paths)for(const ua of ['Mozilla/5.0 Chrome Human Browser','']){
  const {response,data}=await post(endpoint,event,ua);assert.equal(response.status,200);assert(data.event_id>0);
  const row=(await rows()).find(row=>Number(row.id)===data.event_id);
  assert.equal(row.user_agent,ua||null);assert.equal(row.source_key,null);assert.equal(row.referrer,null);assert.equal(row.resource_id,1);checks++;
 }
 const test=await post(paths[0],{...event,is_test:1},'Human test browser');
 assert.equal((await rows()).find(row=>Number(row.id)===test.data.event_id).is_test,1);checks++;
 const denied=await fetch(phpOrigin+configPath);assert.equal(denied.status,401);checks++;
 browser=await chromium.launch();
 const context=await browser.newContext({userAgent:'Human browser fixture'});
 const login=await context.request.post(phpOrigin+'/__login',{headers:{Origin:phpOrigin},data:{email:'admin@example.test',password:'fixture-admin-password',remember:true}});
 assert.equal(login.status(),200);
 const save=async patterns=>context.request.post(phpOrigin+configPath,{headers:{Origin:phpOrigin},data:{patterns}});
 assert.equal((await save(['Mozilla'])).status(),400);checks++;
 assert.equal((await save(['CustomCrawler'])).status(),200);
 assert.equal((await post(paths[0],event,'customcrawler')).data.event_id,0);
 assert((await post(paths[0],event,'Googlebot')).data.event_id>0);checks++;
 assert.equal((await save([])).status(),200);assert((await post(paths[0],event,'CustomCrawler')).data.event_id>0);checks++;
 assert.equal((await context.request.post(phpOrigin+configPath,{headers:{Origin:'https://evil.example'},data:{patterns:[]}})).status(),403);checks++;
 await save(['ElevenlabsCrawler','Googlebot','Google-InspectionTool']);
 // Initial-event race: a valid ColorFix cookie is sufficient even before the
 // browser has set its local exclusion flag or verified in-memory admin state.
 const beforeInitial=(await rows()).length;
 const initial=await context.request.post(phpOrigin+paths[0],{data:event});
 assert.deepEqual(await initial.json(),{ok:true,event_id:0});
 assert.equal((await rows()).length,beforeInitial);checks++;
 const cookieOnly=await fetch(phpOrigin+paths[1],{method:'POST',headers:{'Content-Type':'application/json',Cookie:'cf_internal_viewer=1','User-Agent':'Human browser'},body:JSON.stringify(event)});
 assert.deepEqual(await cookieOnly.json(),{ok:true,event_id:0});checks++;
 assert.equal((await fetch(phpOrigin+configPath,{headers:{Cookie:'cf_internal_viewer=1'}})).status,401,'Analytics preference cannot authorize admin');checks++;

 server=await createServer({server:{host:'127.0.0.1',port:0,strictPort:false,proxy:{'/api':{target:phpOrigin,changeOrigin:false,secure:false}}},plugins:[{
  name:'ana-recording-browser-fixture',
  resolveId(id){if(id==='/__ana.js')return '\0ana-recording';if(id==='/__ana-details.js')return '\0ana-details';},
  load(id){if(id==='\0ana-details')return `import React from 'react';import{createRoot}from'react-dom/client';import{AdminDialogProvider}from'/src/components/AdminLayout/index.jsx';import Page from '/src/pages/AdminAnalyticsPage/index.jsx';createRoot(document.getElementById('root')).render(React.createElement(AdminDialogProvider,null,React.createElement(Page)));`;if(id!=='\0ana-recording')return;return `
    import React from 'react';import{createRoot}from'react-dom/client';
    import ANAProvider from '/src/ANA/ANAProvider.jsx';import{useANA}from'/src/ANA/useANA.js';
    import{setAdmin}from'/src/helpers/authHelper.js';import{recordANAEvent}from'/src/ANA/ANAClient.js';import{recordAnalyticsEvent}from'/src/Analytics/analyticsClient.js';import ANAIgnoreSettings from '/src/pages/AdminAnalyticsPage/ANAIgnoreSettings.jsx';
    function App(){const{track}=useANA();return React.createElement(React.Fragment,null,
     React.createElement(ANAIgnoreSettings),
     React.createElement('button',{onClick:()=>recordANAEvent({event_key:'visit',resource_type:'playlist',resource_id:3})},'Direct ANA'),
     React.createElement('button',{onClick:()=>recordAnalyticsEvent({event_key:'visit',resource_type:'playlist',resource_id:3})},'Legacy ANA'),
     React.createElement('button',{onClick:()=>{setAdmin(false);localStorage.removeItem('analyticsTestMode');localStorage.removeItem('cf_internal_viewer');document.cookie='cf_internal_viewer=; Max-Age=0; path=/';}},'Human mode'),
     React.createElement('button',{onClick:()=>{setAdmin(true);localStorage.removeItem('analyticsTestMode');}},'Own device'),
     React.createElement('button',{onClick:()=>{setAdmin(true);localStorage.setItem('analyticsTestMode','1');}},'Own device test'),
     React.createElement('button',{onClick:()=>track('visit',{}, {resource_type:'playlist',resource_id:3})},'Record ANA'));}
    createRoot(document.getElementById('root')).render(React.createElement(ANAProvider,null,React.createElement(App)));
  `;}
 }]});
 await server.listen();const origin=`http://127.0.0.1:${server.httpServer.address().port}`;
 await context.route('**/__ana',async route=>route.fulfill({contentType:'text/html',body:await server.transformIndexHtml('/__ana','<!doctype html><div id="root"></div><script type="module" src="/__ana.js"></script>')}));
 const page=await context.newPage();await page.goto(origin+'/__ana');
 await page.getByRole('button',{name:'Crawler filtering',exact:true}).click();
 const dialog=page.getByRole('dialog');await dialog.getByRole('textbox').waitFor({state:'visible'});
 await page.waitForFunction(()=>!document.querySelector('textarea')?.disabled);
 assert((await dialog.getByRole('textbox').inputValue()).includes('Googlebot'));
 await dialog.getByRole('textbox').fill('ExtraCrawler\nGooglebot');await dialog.getByRole('button',{name:'Save',exact:true}).click();await dialog.waitFor({state:'hidden'});
 assert.equal((await post(paths[0],event,'EXTRACRAWLER')).data.event_id,0);checks++;
 await page.getByRole('button',{name:'Crawler filtering',exact:true}).click();
 await page.waitForFunction(()=>!document.querySelector('textarea')?.disabled);
 assert.equal(await dialog.getByRole('textbox').inputValue(),'ExtraCrawler\nGooglebot');
 await dialog.getByRole('button',{name:'Cancel',exact:true}).click();checks++;
 let browserEvents=0;page.on('request',r=>{if(r.url().includes('/record-event.php'))browserEvents++;});
 await page.getByRole('button',{name:'Own device',exact:true}).click();
 await page.getByRole('button',{name:'Record ANA',exact:true}).click();
 await page.waitForTimeout(150);assert.equal(browserEvents,0);checks++;
 await page.getByRole('button',{name:'Own device test',exact:true}).click();
 const recorded=page.waitForResponse(r=>r.url().includes('/ana/record-event.php'));
 await page.getByRole('button',{name:'Record ANA',exact:true}).click();const testResponse=await recorded;assert.equal(testResponse.status(),200);
 const testData=await testResponse.json();const browserRow=(await rows()).find(row=>Number(row.id)===testData.event_id);
 assert.equal(browserRow.is_test,1);assert.equal(browserRow.user_agent,'Human browser fixture');checks++;
 await context.clearCookies();
 await page.getByRole('button',{name:'Human mode',exact:true}).click();
 const humanRecorded=page.waitForResponse(r=>r.url().includes('/ana/record-event.php'));
 await page.getByRole('button',{name:'Record ANA',exact:true}).click();assert((await(await humanRecorded).json()).event_id>0);checks++;
 // Stored own-device flag works with no admin state or auth cookie, across
 // reloads and direct client calls that do not use the tracking hook.
 await page.evaluate(()=>localStorage.setItem('cf_internal_viewer','1'));
 await page.reload();
 const beforePersistent=browserEvents;
 await page.getByRole('button',{name:'Record ANA',exact:true}).click();
 await page.getByRole('button',{name:'Direct ANA',exact:true}).click();
 await page.getByRole('button',{name:'Legacy ANA',exact:true}).click();
 await page.waitForTimeout(100);assert.equal(browserEvents,beforePersistent);checks++;
 // A cookie-only mark repairs local storage and survives loss of login cookies.
 await page.evaluate(()=>localStorage.removeItem('cf_internal_viewer'));
 await page.getByRole('button',{name:'Direct ANA',exact:true}).click();
 assert.equal(await page.evaluate(()=>localStorage.getItem('cf_internal_viewer')),'1');checks++;
 await context.clearCookies();
 await page.getByRole('button',{name:'Legacy ANA',exact:true}).click();
 await page.waitForTimeout(100);assert.equal(browserEvents,beforePersistent);checks++;
 // Render the actual existing event-detail grid with an untrusted header.
 const unsafeUA='Browser <img src=x onerror="window.__uaExecuted=true">';
 await context.route('**/api/v2/admin/analytics/counts.php?**',route=>route.fulfill({json:{ok:true,resource_types:['playlist'],items:[{resource_id:3,title:'Fixture playlist',source_label:null,event_count:1,last_visit:'2026-10-29 12:00:00',visit_path:'/t/fixture'}]}}));
 await context.route('**/api/v2/admin/analytics/events.php?**',route=>route.fulfill({json:{ok:true,items:[{id:99,event_key:'visit',user_agent:unsafeUA,created_at:'2026-10-29 12:00:00',path:'/t/fixture',source_key:null}]}}));
 await context.route('**/__ana-details',async route=>route.fulfill({contentType:'text/html',body:await server.transformIndexHtml('/__ana-details','<!doctype html><div id="root"></div><script type="module" src="/__ana-details.js"></script>')}));
 await page.goto(origin+'/__ana-details');
 await page.getByText('Fixture playlist',{exact:true}).dblclick();
 await page.getByText(unsafeUA,{exact:true}).waitFor();
 assert.equal(await page.evaluate(()=>Boolean(window.__uaExecuted)),false);
 assert.equal(await page.locator('img[onerror]').count(),0);checks++;
 await context.close();
 console.log(`PASS: ${checks} HTTP/browser checks across both ANA receivers, server UA capture, config editing/refresh/authentication, and own-device/test behavior`);
}catch(error){console.error(phpOutput.slice(-4000));throw error;}
finally{await browser?.close();await server?.close();php.kill();await rm(temp,{recursive:true,force:true});}
