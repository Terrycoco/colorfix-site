import assert from 'node:assert/strict';
import {mkdtemp,mkdir,writeFile,readFile,rm,access} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import path from 'node:path';
import {pathToFileURL} from 'node:url';
import {spawnSync} from 'node:child_process';
const root=await mkdtemp(path.join(tmpdir(),'colorfix-prebuilt-routes-'));
try {
 await mkdir(path.join(root,'dist/assets'),{recursive:true});
 await mkdir(path.join(root,'api/cache'),{recursive:true});
 await writeFile(path.join(root,'dist/assets/current.js'),'// fixture current asset');
 await writeFile(path.join(root,'dist/index.html'),'<!doctype html><html><head><title>Fixture</title><script type="module" src="/assets/current.js"></script></head><body><div id="root"></div></body></html>');
 await writeFile(path.join(root,'api/cache/front-page-public.json'),JSON.stringify({items:[]}));
 const pages=[{path:'/',title:'Home'},{path:'/picker/?set=11',title:'Browse Playlists'},{path:'/about',title:'About'},{path:'/search#colors',title:'Search'}];
 const script=pathToFileURL(path.resolve('scripts/prebuild-homepage-html.mjs')).href;
 const result=spawnSync(process.execPath,['--input-type=module','-e',`globalThis.fetch=async()=>({ok:true,json:async()=>(${JSON.stringify({ok:true,pages})})});await import(${JSON.stringify(script)});`],{cwd:root,encoding:'utf8'});
 assert.equal(result.status,0,result.stdout+result.stderr);
 for (const relative of ['picker/index.html','about/index.html','search/index.html']) {
  const html=await readFile(path.join(root,'dist',relative),'utf8');
  assert(html.includes('src="/assets/current.js"'),'Generated routes must reference the current build');
  assert(html.includes('<div id="root"></div>'),'Subpages keep a clean React root');
 }
 assert((await readFile(path.join(root,'dist/picker/index.html'),'utf8')).includes('Browse Playlists'));
 for(const invalid of ['picker/?set=11/index.html','search#colors/index.html']) {
  await assert.rejects(access(path.join(root,'dist',invalid)),{code:'ENOENT'});
 }
 console.log('PASS: actual prebuild script maps URL paths to folders, excludes query/fragment folder names, and retains current asset references and clean React roots.');
} finally {await rm(root,{recursive:true,force:true});}
