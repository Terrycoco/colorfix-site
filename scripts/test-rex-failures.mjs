import assert from 'node:assert/strict';
import { readRexResponse, RexNotFoundError, REX_UNAVAILABLE, readRexBootstrap } from '../src/helpers/rexResolution.js';

for (const [status, body, missing] of [
  [409, '<script>document.cookie="challenge"</script>', false],
  [200, '<script>document.cookie="challenge"</script>', false],
  [404, '<html>Proxy error</html>', false],
  [500, JSON.stringify({ok:false,error:'Private database error'}), false],
  [404, JSON.stringify({ok:false,error:'Link not found.'}), true],
]) {
  await assert.rejects(() => readRexResponse(new Response(body, {status})), error =>
    missing ? error instanceof RexNotFoundError : error.message === REX_UNAVAILABLE);
}
const payload = {ok:true,data:{destination:{playback_plan:{playlist_id:3,src:null}}}};
assert.deepEqual(await readRexResponse(Response.json(payload)), payload);
global.document = {getElementById: () => ({textContent:JSON.stringify({token:'target',payload})})};
assert.equal(readRexBootstrap('different','sas'), null);
assert.equal(readRexBootstrap('target','google').data.destination.playback_plan.src, 'google');
console.log('PASS: challenge, network response, missing resource, safe bootstrap and attribution checks');
