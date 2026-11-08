import assert from 'node:assert/strict';
import {readFile,readdir,stat} from 'node:fs/promises';
import {isPaletteEligibleItem,getPaletteTargetKey} from '../src/helpers/playerPaletteItems.js';
import {parsePhotoRef,makePhotoRef,withImageVersion} from '../src/helpers/assetImage.js';

assert.equal(isPaletteEligibleItem({type:'normal',palette_hash:'current'}),true);
assert.equal(isPaletteEligibleItem({type:'before',palette_hash:'current'}),false);
assert.equal(isPaletteEligibleItem({type:'normal',ap_id:12,saved_palette_set_id:2}),false);
assert.equal(getPaletteTargetKey({type:'normal',palette_hash:'current',saved_palette_set_id:2}),'saved:current:default');
const image=makePhotoRef(12,'/photos/art/example.png?src=sas');
assert.deepEqual(parsePhotoRef(image),{photoId:'12',url:'/photos/art/example.png?src=sas'});
assert.match(withImageVersion('/photos/library/current.png','2026-11-07T12:00:00Z'),/\/photos\/library\/current\.png\?v=\d+/);

async function files(dir){let result=[];for(const entry of await readdir(dir,{withFileTypes:true})){const path=dir+'/'+entry.name;if(entry.isDirectory())result.push(...await files(path));else if(/\.(?:php|jsx|js|mjs)$/.test(path))result.push(path);}return result;}
const forbidden=/PhotoRenderingService|PhotosUploadService|PhotosController|PdoPhotoRepository|MaskOverlayService|MaskBlendService|\/v2\/photos\/|photos_variants|photos_mask_stats|mask_blend_settings|master_role_masks|render_project_masks|\bFROM\s+saved_palette_sets\b|\bJOIN\s+saved_palette_sets\b/i;
for(const path of [...await files('app'),...await files('api/v2'),...await files('src')]){
 const source=await readFile(path,'utf8');
 assert(!forbidden.test(source),'Retired runtime dependency: '+path);
}
for(const path of ['src/pages/AdminRolesMasksPage','src/pages/AdminUploadPhotoPage','src/pages/AdminPalettePhotosPage','api/v2/photos','api/v2/mask-training','app/services/PhotoRenderingService.php']){
 assert.equal(await stat(path).then(()=>true,()=>false),false,'Retired code remains: '+path);
}
for(const path of ['app/PUB/Create/Pinterest/BeforeAfterVideoRecipe.php','app/PUB/Create/YouTube/PlaylistVideoRecipe.php','scripts/render-remotion-video.mjs','api/v2/image-thumb.php']){
 assert((await stat(path)).isFile(),'Current renderer/thumbnail feature removed: '+path);
}
console.log('PASS current image references, thumbnail versioning, palette targets, retired runtime dependency scan, and preservation of CHEF recipes/current thumbnail endpoint.');

const rules=(await readFile('.htaccess','utf8')).split('\n').filter(line=>line.includes('[G,L]')).map(line=>new RegExp(line.trim().split(/\s+/)[1]));
for(const path of ['api/v2/photos/get.php','api/v2/photos/render-apply.php','api/v2/admin/photo-upload.php','api/v2/admin/photo-library/cleanup-legacy-applied.php','admin/palette-photos','api/v2/admin/mask-blend/save.php']){
 assert(rules.some(rule=>rule.test(path)),'Retired URL can fall through to React/stale code: '+path);
}
for(const path of ['api/v2/admin/photos/upload.php','api/v2/admin/photo-library/upload.php','api/v2/image-thumb.php','api/v2/pv-thumbs.php','api/v2/rex/resolve.php','api/v2/admin/pub/video-jobs/rendering.php','t/current-token','photos/art/retained.png','api/v2/admin/saved-palette-photos/upload.php']){
 assert(!rules.some(rule=>rule.test(path)),'Current URL accidentally retired: '+path);
}
console.log('PASS retired HTTP route patterns and current URL exclusions (Apache live verification awaits deployment).');
