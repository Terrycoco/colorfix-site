import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createServer } from 'vite';
import { chromium } from 'playwright';

const directory=mkdtempSync(join(tmpdir(),'colorfix-rex-meta-'));
const shell=join(directory,'shell.html');
writeFileSync(shell,'<!doctype html><html><head><title>Shell title</title><meta content="Old description" name="description"><meta name="description" content="Duplicate description"></head><body><div id="root"></div><script type="module" src="/src/main.jsx"></script></body></html>');
const render=input=>execFileSync('php',['api/tests/RexMetadataFixture.php'],{input:JSON.stringify({shell,...input}),encoding:'utf8'});
const cases=[
 {page_title:'Custom SEO $1 <Cottage>',meta_description:'Paint "ideas" & palettes $1 <safe>',title:'Custom SEO $1 &lt;Cottage&gt;',description:'Paint &quot;ideas&quot; &amp; palettes $1 &lt;safe&gt;'},
 {page_title:'',meta_description:'',title:'Existing share title',description:'Existing summary'},
 {page_title:'   ',meta_description:' \t ',title:'Existing share title',description:'Existing summary'},
 {page_title:null,meta_description:null,title:'Existing share title',description:'Existing summary'},
 {page_title:'0',meta_description:'0',title:'0',description:'0'},
];
let server,browser;
try {
 for(const row of cases) {
  const html=render(row);
  assert(html.includes(`<title>${row.title}</title>`));
  assert(html.includes(`<meta name="description" content="${row.description}">`));
  assert.equal((html.match(/name="description"/g)||[]).length,1);
  assert(html.includes('<meta property="og:title" content="Existing share title">'),'Preserve existing social fallbacks');
 }
 console.log('PASS: stored SEO fields, initial HTML, blank/null/whitespace fallbacks, duplicate removal and escaping.');
 // Use a real, anonymous public plan; every browser API is intercepted below.
 const payload=JSON.parse(execFileSync('curl',['-fsS','https://colorfix.terrymarr.com/api/v2/rex/resolve.php?token=m70J1Zy-mPOwamhlAMIkAOyXTQ0zy2THjuzWxHCgWZg'],{encoding:'utf8'}));
 const originalPlan=payload.data.destination.playback_plan;
 server=await createServer({server:{host:'127.0.0.1',port:0,strictPort:false}});
 await server.listen();
 const origin=`http://127.0.0.1:${server.httpServer.address().port}`;
 browser=await chromium.launch();
 const context=await browser.newContext();
 await context.route('**/api/**',route=>route.fulfill({json:{ok:true,categories:[],items:[],user:null}}));
 let activeRow=cases[0];
 await context.route('**/t/fixture*',async route=>{
  const html=render({...activeRow,bootstrap:{token:'fixture',payload}});
  await route.fulfill({contentType:'text/html',body:await server.transformIndexHtml('/t/fixture',html)});
 });
 const page=await context.newPage();
 for(const row of [cases[0],cases[1]]) {
  activeRow=row;
  const response=await page.goto(origin+'/t/fixture?src=sas');
  const initial=await response.text();
  assert(initial.includes(`<title>${row.title}</title>`));
  await page.locator('.player-root').waitFor({state:'visible'});
  const expectedTitle=row.page_title || originalPlan.page_h1 || originalPlan.display_title || originalPlan.title;
  const expectedDescription=row.meta_description || originalPlan.project_summary || originalPlan.share_description || '';
  await page.waitForFunction(expected=>document.title===expected,expectedTitle);
  assert.equal(await page.evaluate(() => document.querySelector('meta[name="description"]')?.content || ''),expectedDescription);
  const plan=await page.locator('#colorfix-rex-bootstrap').evaluate(el=>JSON.parse(el.textContent).payload.data.destination.playback_plan);
  assert.deepEqual(plan.items,originalPlan.items,'Slide data remains identical');
  assert.equal(plan.total_items,originalPlan.total_items);
 }
 console.log('PASS: real REX route/player preserves explicit SEO after hydration, retains existing rendered fallbacks, and leaves all nine slides unchanged.');
 await context.close();
}finally{await browser?.close();await server?.close();rmSync(directory,{recursive:true,force:true});}
