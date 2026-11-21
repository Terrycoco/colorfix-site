import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createServer } from 'vite';
import { chromium } from 'playwright';
const server = await createServer({server:{host:'127.0.0.1',port:5177,strictPort:true,proxy:{}},logLevel:'error'});
let browser;
try {
  await server.listen(); browser = await chromium.launch({headless:true});
  for (const width of [1280,768,390]) {
    const context = await browser.newContext({viewport:{width,height:900}});
    let status=200, visits=[];
    await context.route('**/*',async route=>{
      const req=route.request(), url=new URL(req.url());
      if(url.pathname.includes('/api/')) {
        if(url.pathname.endsWith('/session.php')) return route.fulfill({json:{ok:false,user:null}});
        if(url.pathname.endsWith('/articles/list.php')) return route.fulfill({json:{ok:true,items:[{id:19,title:'Publication fixture',published_at:'2026-01-02 12:00:00',url:'/articles/19'}]}});
        if(url.pathname.endsWith('/articles/get.php')) return route.fulfill({status,json: status===200 ? {ok:true,item:{article:{id:19,status:'published',title:'Publication fixture',published_at:'2026-01-02 12:00:00'},sections:[{id:1,kind:'text',heading:'A guide section',body:'Preserved editorial content'}],ctas:[],metadata:{title:'Publication fixture',description:'Editorial description',canonical:'https://colorfix.terrymarr.com/articles/19',robots:'index,follow'}}}:{ok:false,error:'Not published'}});
        if(url.pathname.includes('record-event.php')) visits.push(req.postDataJSON());
        return route.fulfill({json:{ok:true,event_id:1,items:[],categories:[]}});
      }
      if(url.origin!=='http://127.0.0.1:5177') return route.abort();
      if(req.resourceType()==='document') return route.fulfill({contentType:'text/html',body:await server.transformIndexHtml(url.pathname,await readFile('index.html','utf8'))});
      return route.continue();
    });
    const page=await context.newPage(); const errors=[]; page.on('pageerror',e=>errors.push(e.message));
    await page.goto('http://127.0.0.1:5177/articles');
    await page.getByRole('heading',{name:'Articles',exact:true}).waitFor();
    await page.getByRole('link',{name:/Publication fixture/}).click();
    await page.getByRole('heading',{name:'Publication fixture',exact:true}).waitFor();
    await page.getByText('Published January 2, 2026').waitFor();
    assert(await page.getByText('Published January 2, 2026').isVisible());
    assert(await page.getByText('Preserved editorial content').isVisible());
    await page.waitForTimeout(250);
    assert.equal(await page.title(),'Publication fixture');
    assert(visits.some(v=>v.resource_type==='article' && Number(v.resource_id)===19),JSON.stringify(visits));
    visits=[]; status=403;
    await page.goto('http://127.0.0.1:5177/articles/19');
    await page.getByRole('alert').waitFor(); await page.waitForTimeout(250);
    assert(!visits.some(v=>v.resource_type==='article'));
    assert.equal(await page.locator('meta[name="robots"]').getAttribute('content'),'noindex,nofollow');
    visits=[]; status=200;
    await page.goto('http://127.0.0.1:5177/articles/19?admin=1');
    await page.getByRole('heading',{name:'Publication fixture',exact:true}).waitFor(); await page.waitForTimeout(250);
    assert(!visits.some(v=>v.resource_type==='article'));
    assert.equal(await page.locator('meta[name="robots"]').getAttribute('content'),'noindex,nofollow');
    assert.deepEqual(errors,[]);
    await context.close();
  }
  const adminContext = await browser.newContext();
  let adminArticle = {id:19,title:"Editable fixture",slug:"editable",type:"guide",status:"draft",sections:[],tags:[]};
  let savedPayload, publicationActions=[];
  await adminContext.route('**/*', async route => {
    const req=route.request(),url=new URL(req.url());
    if(url.pathname.includes('/api/')) {
      if(url.pathname.endsWith('/session.php')) return route.fulfill({json:{ok:true,user:{id:1,email:'fixture@example.test',is_admin:true}}});
      if(url.pathname.endsWith('/articles/list.php')) return route.fulfill({json:{ok:true,items:[adminArticle]}});
      if(url.pathname.endsWith('/articles/get.php')) return route.fulfill({json:{ok:true,item:adminArticle}});
      if(url.pathname.endsWith('/articles/save.php')) { savedPayload=req.postDataJSON(); adminArticle={...adminArticle,...savedPayload}; return route.fulfill({json:{ok:true,article_id:19}}); }
      if(url.pathname.endsWith('/articles/publication.php')) {
        const action=req.postDataJSON().action; publicationActions.push(action);
        adminArticle={...adminArticle,status:action==='publish'?'published':'draft',published_at:'2026-01-02 12:00:00'};
        return route.fulfill({json:{ok:true,item:{...adminArticle,article_id:19}}});
      }
      return route.fulfill({json:{ok:true,items:[],categories:[]}});
    }
    if(url.origin!=='http://127.0.0.1:5177') return route.abort();
    if(req.resourceType()==='document') return route.fulfill({contentType:'text/html',body:await server.transformIndexHtml(url.pathname,await readFile('admin/index.html','utf8'))});
    return route.continue();
  });
  const editor=await adminContext.newPage();
  await editor.goto('http://127.0.0.1:5177/admin/articles');
  await editor.getByText('Editable fixture',{exact:true}).click();
  await editor.getByRole('button',{name:'Save',exact:true}).click();
  await editor.getByText('Article saved.',{exact:true}).waitFor();
  assert(!Object.hasOwn(savedPayload,'status') && !Object.hasOwn(savedPayload,'published_at'));
  await editor.getByRole('button',{name:'Publish saved article',exact:true}).click();
  await editor.getByRole('button',{name:'Unpublish',exact:true}).waitFor();
  await editor.getByRole('button',{name:'Unpublish',exact:true}).click();
  await editor.getByRole('button',{name:'Publish saved article',exact:true}).waitFor();
  assert.deepEqual(publicationActions,['publish','unpublish']);
  await adminContext.close();
  console.log('PASS: desktop/tablet/phone article layout, publication byline, route metadata, public ANA visit and preview/failed-load exclusions');
} finally { await browser?.close(); await server.close(); }
