import assert from 'node:assert/strict';
import { readFile, readdir } from 'node:fs/promises';
import path from 'node:path';

const coverage = JSON.parse(await readFile('app/SYSTEM/Resources/admin-route-coverage.json', 'utf8'));
const guarded = new Set(coverage.guarded);
const exceptions = new Set([...coverage.public_receivers, ...coverage.worker_authenticated]);
async function walk(dir) {
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    const file = path.posix.join(dir, entry.name);
    if (entry.isDirectory()) await walk(file);
    else if (file.endsWith('.php') && !entry.name.startsWith('_') && entry.name !== 'auth.php') {
      assert(guarded.has(file) || exceptions.has(file), `Unclassified admin route: ${file}`);
    }
  }
}
await walk('api/v2/admin');
for (const file of guarded) {
  const source = await readFile(file, 'utf8');
  assert(/(?:require|include)(?:_once)?[^;]*['"][^'"]*auth\.php['"]/.test(source), `Missing guard: ${file}`);
}
for (const file of coverage.worker_authenticated) {
  assert((await readFile(file, 'utf8')).includes("'/_worker_auth.php'"), `Missing worker authentication: ${file}`);
}
const rules = await readFile('api/.htaccess', 'utf8');
for (const file of coverage.disabled_legacy || []) {
  const name = path.posix.basename(file).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  assert(new RegExp(`<Files "${name}">\\s*Require all denied\\s*</Files>`).test(rules), `Legacy file must be blocked: ${file}`);
}
console.log(`PASS: ${guarded.size} guarded routes; public receivers and worker authentication explicitly classified`);
