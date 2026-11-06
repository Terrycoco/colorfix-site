import assert from 'node:assert/strict';
import { chromium } from 'playwright';
const origin = process.env.COLORFIX_BASE_URL || 'https://colorfix.terrymarr.com';
const tokens = [
 ['m70J1Zy-mPOwamhlAMIkAOyXTQ0zy2THjuzWxHCgWZg','public',9],
 ['f9vnX2UfipaLMqCY_d7ptRLDH-7H3Pi2yS5BUMvcGsE','public'],
 ['jBEPyoGag6P6hHLAcHhl6yuP6B-yWzw2ML_sV2IZC-o','showcase'],
];
const browser=await chromium.launch({headless:true});
try {
 for(const blocked of [false,true]) for(const [token,experience,count] of tokens) {
  const context=await browser.newContext();
  let resolverRequests=0;
  const events=[];
  await context.route('**/api/**',async route=>{
   if(route.request().url().includes('/rex/resolve.php'))resolverRequests++;
   if(blocked)return route.fulfill({status:409,contentType:'text/html',body:'<script>document.cookie="challenge"</script>'});
   if(route.request().url().includes('/ana/record-event.php')){
    events.push(route.request().postDataJSON());return route.fulfill({json:{ok:true}});
   }
   return route.continue();
  });
  const page=await context.newPage();
  const expected=`${origin}/t/${token}`;
  const response=await page.goto(expected+'?src=sas',{waitUntil:'domcontentloaded'});
  assert.equal(response.status(),200);
  assert(!response.headers()['x-robots-tag']?.includes('noindex'));
  const bootstrap=await page.locator('#colorfix-rex-bootstrap').evaluate(el=>JSON.parse(el.textContent));
  const plan=bootstrap.payload.data.destination.playback_plan;
  assert.equal(plan.experience_key,experience);
  if(count)assert.equal(plan.total_items,count);
  await page.locator('.player-root').waitFor({state:'visible'});
  await page.waitForFunction(expected=>document.querySelector('link[rel="canonical"]')?.href===expected,expected);
  assert.equal(await page.getByText('Link not found.',{exact:true}).count(),0);
  assert.equal(await page.locator('meta[property="og:url"]').getAttribute('content'),expected);
  assert(!await page.locator('meta[name="robots"]').getAttribute('content').then(value=>value.includes('noindex')));
  assert.equal(resolverRequests,0,'Initial rendering must not depend on a second API request');
  if(!blocked)assert(events.some(event=>event.src==='sas'),'Analytics retains src');
  console.log(JSON.stringify({token,experience,blockedAPIs:blocked,status:response.status(),title:plan.title,items:plan.total_items,playerVisible:true,resolverRequests,canonical:expected,robots:'index, follow',tracking:blocked?'API deliberately blocked':'PASS'}));
  await context.close();
 }
}finally{await browser.close();}
