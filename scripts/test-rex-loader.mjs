import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { createServer } from 'vite';
const server = await createServer({server:{host:'127.0.0.1',port:0,strictPort:false},plugins:[{
 name:'rex-loader-fixture',
 resolveId(id) {
  if(id==='/__rex-loader.js') return '\0rex-loader';
  if(id==='@RX/Player') return '\0rex-test-player';
 },
 load(id) {
  if(id==='\0rex-test-player') return `import React from 'react';export default ({playbackPlan})=>React.createElement('div',{className:'test-player'},playbackPlan.title);`;
  if(id==='\0rex-loader') return `import React from 'react';import{createRoot}from'react-dom/client';import{BrowserRouter,Routes,Route}from'react-router-dom';import RexPublicPage from '/src/pages/REX/RexPublicPage/index.jsx';createRoot(document.getElementById('root')).render(React.createElement(BrowserRouter,null,React.createElement(Routes,null,React.createElement(Route,{path:'/t/:token',element:React.createElement(RexPublicPage)}))));`;
 }
}]});
let browser;
try {
 await server.listen();
 const origin=`http://127.0.0.1:${server.httpServer.address().port}`;
 browser=await chromium.launch();
 const context=await browser.newContext();
 let mode='challenge', count=0;
 await context.route('**/api/**',async route=>{
  if(!route.request().url().includes('/rex/resolve.php'))return route.fulfill({json:{ok:true}});
  count++;
  if(mode==='network')return route.abort('failed');
  if(mode==='missing')return route.fulfill({status:404,json:{ok:false,error:'Link not found.'}});
  return route.fulfill({status:mode==='server'?500:409,contentType:'text/html',body:'<script>document.cookie="challenge"</script>'});
 });
 await context.route('**/t/**',async route=>route.fulfill({contentType:'text/html',body:await server.transformIndexHtml('/__rex-loader','<!doctype html><div id="root"></div><script type="module" src="/__rex-loader.js"></script>')}));
 const page=await context.newPage();
 for(const value of ['challenge','server','network','missing']) {
  mode=value;
  await page.goto(origin+'/t/test');
  await page.getByText(value==='missing'?'Link not found.':'Unable to load this ColorFix link right now. Please try again.',{exact:true}).waitFor();
  assert.equal(await page.getByRole('button',{name:'Try again'}).count(),value==='missing'?0:1);
  if(value!=='missing') {const before=count;await page.getByRole('button',{name:'Try again'}).click();await page.getByRole('button',{name:'Try again'}).waitFor();assert(count>before);}
 }
 console.log('PASS: actual REX page distinguishes HTML 409, 500, network failure and confirmed 404; Retry makes a new request.');
 await context.close();
}finally{await browser?.close();await server.close();}
