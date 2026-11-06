import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtemp, rm, readFile, writeFile, utimes } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import net from 'node:net';
import { createServer } from 'vite';
import { chromium } from 'playwright';

const temp=await mkdtemp(path.join(tmpdir(),'colorfix-sas-auth-'));
const port=await new Promise(resolve=>{const s=net.createServer();s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});});
const phpOrigin=`http://127.0.0.1:${port}`;
const php=spawn('php',['-S',`127.0.0.1:${port}`,'api/tests/SASAuthHttpFixture.php'],{
 env:{...process.env,SAS_AUTH_TEST_DB:path.join(temp,'auth.sqlite'),COLORFIX_ADMIN_SESSION_PATH:path.join(temp,'sessions')},stdio:['ignore','pipe','pipe'],
});
let output='',server,browser;php.stdout.on('data',v=>output+=v);php.stderr.on('data',v=>output+=v);
try{
 for(let i=0;i<100;i++){try{await fetch(phpOrigin+'/api/session.php');break;}catch{if(php.exitCode!==null)throw new Error(output);await new Promise(r=>setTimeout(r,30));}}
 server=await createServer({server:{host:'127.0.0.1',port:0,strictPort:false,proxy:{'/api':{target:phpOrigin,changeOrigin:false,secure:false}}}});
 await server.listen();const origin=`http://127.0.0.1:${server.httpServer.address().port}`;
 browser=await chromium.launch();
 const setup=async context=>{
  await context.route(url=>url.pathname.startsWith('/sas/'),async route=>route.fulfill({contentType:'text/html',body:await server.transformIndexHtml('/sas/photos','<!doctype html><div id="sas-root"></div><script type="module" src="/src/SAS/main.jsx"></script>')}));
 };
 let context=await browser.newContext({viewport:{width:320,height:740},isMobile:true});await setup(context);
 assert.equal((await context.request.get(origin+'/api/v2/admin/sas/search-photos.php?q=Mardo')).status(),401,'Private search stays protected');
 let page=await context.newPage();await page.goto(origin+'/sas/photos');
 await page.getByRole('searchbox',{name:'Search photos'}).fill('Mardo');
 const form=page.getByRole('form',{name:'Sign in to SAS'});await form.waitFor();
 assert.equal(await page.getByRole('button',{name:'Retry',exact:true}).count(),0);
 await page.getByLabel('Email',{exact:true}).fill('admin@example.test');
 await page.getByLabel('Password',{exact:true}).fill('wrong-password');
 await page.getByRole('button',{name:'Show password',exact:true}).click();
 assert.equal(await page.getByLabel('Password',{exact:true}).getAttribute('type'),'text');
 await page.getByRole('button',{name:'Hide password',exact:true}).click();
 await page.getByRole('button',{name:'Sign in',exact:true}).click();
 await page.getByText('Invalid credentials.',{exact:true}).waitFor();
 await page.getByLabel('Password',{exact:true}).fill('fixture-admin-password');
 await page.getByRole('button',{name:'Sign in',exact:true}).click();
 await page.getByRole('checkbox',{name:'Select photo 701'}).waitFor();
 assert.equal(await page.getByRole('searchbox',{name:'Search photos'}).inputValue(),'Mardo');
 assert.equal(new URL(page.url()).pathname,'/sas/photos');
 assert.equal(await page.evaluate(()=>localStorage.getItem('cf_internal_viewer')),'1','Verified SAS device is marked for analytics exclusion');
 assert(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth),'Sign-in/results fit mobile width');
 const state=await context.storageState();const cookie=state.cookies.find(c=>c.name==='cf_admin_session');
 assert(cookie?.httpOnly && cookie.expires>Date.now()/1000+390*86400,'Uses the SYSTEM persistent SAS device cookie');
 await page.getByRole('checkbox',{name:'Select photo 701'}).check();
 await page.getByRole('button',{name:'Continue',exact:true}).click();
 const share=await page.locator('.sas-share-url').inputValue();const url=new URL(share);
 assert.equal(url.pathname,'/pod');assert.equal(url.searchParams.get('photos'),'701');assert.equal(url.searchParams.get('src'),'sas');
 // Only the local fixture session is inspected; no production credentials.
 const sessionFile=path.join(temp,'sessions','sess_'+cookie.value);
 const identity=await readFile(sessionFile,'utf8');
 assert(identity.includes('persistent_device|b:1;') && identity.includes('expires_at|N;'),'SAS has no server-side expiry date');
 await writeFile(sessionFile,identity.replace('expires_at|N;','expires_at|i:1;'));
 const old=new Date(Date.now()-90*86400000);await utimes(sessionFile,old,old);
 await context.close();
 context=await browser.newContext({viewport:{width:390,height:800},storageState:{cookies:state.cookies.filter(c=>c.expires>0),origins:[]}});await setup(context);
 page=await context.newPage();await page.goto(origin+'/sas/photos');await page.getByRole('searchbox',{name:'Search photos'}).fill('Mardo');
 await page.getByRole('checkbox',{name:'Select photo 701'}).waitFor();
 assert.equal(await page.getByRole('form',{name:'Sign in to SAS'}).count(),0,'Remembered session resumes without signing in');
 // A server outage remains Retry, rather than being mistaken for lost auth.
 await page.route('**/api/v2/admin/sas/search-photos.php?**',route=>route.fulfill({status:503,json:{ok:false,error:'Temporary search outage'}}));
 await page.getByRole('searchbox',{name:'Search photos'}).fill('Mardo outage');
 await page.getByRole('button',{name:'Retry',exact:true}).waitFor();
 assert.equal(await page.getByRole('form',{name:'Sign in to SAS'}).count(),0);
 await page.unroute('**/api/v2/admin/sas/search-photos.php?**');
 await page.getByRole('searchbox',{name:'Search photos'}).fill('Mardo');
 await page.getByRole('checkbox',{name:'Select photo 701'}).check();
 await context.clearCookies();
 await page.getByRole('searchbox',{name:'Search photos'}).fill('Mardo again');
 await page.getByRole('form',{name:'Sign in to SAS'}).waitFor();
 await page.getByLabel('Email',{exact:true}).fill('admin@example.test');await page.getByLabel('Password',{exact:true}).fill('fixture-admin-password');
 await page.getByRole('button',{name:'Sign in',exact:true}).click();
 await page.getByText('No matching photos.',{exact:true}).waitFor();
 assert.equal(await page.getByRole('searchbox',{name:'Search photos'}).inputValue(),'Mardo again');
 await page.getByText('1 selected',{exact:true}).waitFor();
 // A normal ColorFix login is accepted directly and promoted by SAS search.
 const colorfix=await browser.newContext();
 let result=await colorfix.request.post(origin+'/api/login.php',{headers:{Origin:origin},form:{email:'admin@example.test',password:'fixture-admin-password',remember:'false'}});
 assert.equal(result.status(),200);
 assert((await colorfix.cookies()).find(c=>c.name==='cf_admin_session').expires<0,'Ordinary ColorFix login remains a browser session');
 result=await colorfix.request.get(origin+'/api/v2/admin/sas/search-photos.php?q=Mardo');assert.equal(result.status(),200);
 assert((await colorfix.cookies()).find(c=>c.name==='cf_admin_session').expires>Date.now()/1000+390*86400,'Existing verified ColorFix session is accepted without another password and made persistent for SAS');
 result=await colorfix.request.post(origin+'/api/admin-change-password.php',{headers:{Origin:origin},data:{current_password:'fixture-admin-password',new_password:'changed-fixture-password'}});
 assert.equal(result.status(),200);
 assert.equal((await context.request.get(origin+'/api/v2/admin/sas/search-photos.php?q=Mardo')).status(),401,'Password change revokes the other persistent SAS session');
 assert.equal((await colorfix.request.get(origin+'/api/v2/admin/sas/search-photos.php?q=Mardo')).status(),200,'Password-changing device remains verified');
 assert.equal((await colorfix.request.post(origin+'/api/logout.php',{headers:{Origin:origin},data:{}})).status(),200);
 assert.equal((await colorfix.request.get(origin+'/api/v2/admin/sas/search-photos.php?q=Mardo')).status(),401,'Explicit logout still revokes the persistent session');
 await colorfix.close();
 await context.close();
 console.log('PASS: private API, inline SAS sign-in, invalid password, eye toggle, search/selection preservation, non-expiring device restoration, shared ColorFix login, password-change/logout revocation, POD sharing/src and transient-error Retry');
}catch(error){console.error(output.slice(-3000));throw error;}
finally{await browser?.close();await server?.close();php.kill();await rm(temp,{recursive:true,force:true});}
