import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import { mkdtemp, writeFile, rm } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
const dir = await mkdtemp(path.join(os.tmpdir(), 'article-publication-'));
await writeFile(path.join(dir, 'app-shell.html'), '<!doctype html><head><title>Generic</title><meta name="robots" content="index,follow"></head><body><div id="root"></div></body>');
const origin = 'http://127.0.0.1:8794';
const server = spawn('php', ['-S','127.0.0.1:8794','api/tests/ArticlePublicationHttpFixture.php'], { env: { ...process.env, ARTICLE_TEST_DIR: dir, COLORFIX_ADMIN_SESSION_PATH: path.join(dir,'sessions') }, stdio: 'ignore' });
try {
  for (let n=0;n<50;n++) { try { await fetch(origin); break; } catch { await new Promise(r=>setTimeout(r,100)); } }
  let res = await fetch(`${origin}/articles/1?admin=1`);
  assert.equal(res.status,404); assert.match(res.headers.get('x-robots-tag'),/noindex/);
  assert(! (await res.text()).includes('Private fixture'));
  res = await fetch(`${origin}/api/v2/articles/list.php?status=draft`);
  assert.equal(res.status,200); assert.deepEqual((await res.json()).items,[]);
  res = await fetch(`${origin}/api/v2/admin/articles/list.php`); assert.equal(res.status,401);
  const post = (url, body, cookie='') => fetch(origin+url,{ method:'POST', headers:{'Content-Type':'application/json', Cookie:cookie}, body:JSON.stringify(body) });
  res = await post('/publication',{article_id:1,action:'publish'});
  assert.equal(res.status,401);
  res = await post('/__login',{email:'fixture@example.test',password:'fixture-password'});
  assert.equal(res.status,200);
  const cookie = res.headers.getSetCookie().map(v=>v.split(';')[0]).join('; ');
  assert(cookie.includes('cf_admin_session='));
  res = await fetch(`${origin}/api/v2/admin/articles/list.php?status=draft`,{headers:{Cookie:cookie}});
  assert.equal(res.status,200); assert.equal((await res.json()).items[0].status,'draft');
  res = await fetch(`${origin}/articles/1?admin=1`,{headers:{Cookie:cookie}});
  assert.equal(res.status,200); assert.match(res.headers.get('x-robots-tag'),/noindex/);
  assert((await res.text()).includes('Private fixture'));
  res = await post('/publication',{article_id:1,action:'publish'},cookie);
  assert.equal(res.status,200); const first = (await res.json()).item.published_at;
  res = await fetch(`${origin}/articles/1?src=test`);
  assert.equal(res.status,200); const html = await res.text();
  assert(html.includes('<title>Private fixture</title>'));
  assert(html.includes('content="Fixture SEO"'));
  assert(html.includes('href="https://colorfix.terrymarr.com/articles/1"'));
  assert(html.includes('content="index,follow"'));
  res = await fetch(`${origin}/api/v2/articles/list.php`);
  assert.equal(res.status,200); assert.equal((await res.json()).items[0].title,'Private fixture');
  for (const action of ['publish','unpublish','unpublish','publish']) {
    res = await post('/publication',{article_id:1,action},cookie);
    assert.equal(res.status,200); assert.equal((await res.json()).item.published_at,first);
    const page = await fetch(`${origin}/articles/1`);
    assert.equal(page.status,action==='unpublish'?404:200);
  }
  res = await fetch(`${origin}/articles/999`); assert.equal(res.status,404);
  console.log('PASS: HTTP publication authentication, real SYSTEM preview session, anonymous privacy, initial metadata, repeat transitions and date retention');
} finally { server.kill(); await new Promise(r=>server.once('exit',r)); await rm(dir,{recursive:true,force:true}); }
