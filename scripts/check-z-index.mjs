import assert from 'node:assert/strict';
import { readFile, readdir } from 'node:fs/promises';

const tokens = { back: -1, base: 0, above: 1, label: 2, control: 3, active: 4, drag: 5, sticky: 100, nav: 200, dropdown: 300, shield: 400, fullscreen: 500, drawer: 600, editor: 700, dialog: 800, picker: 900, critical: 1000, toast: 1100 };
const source = await readFile('src/styles/ZIndex.css', 'utf8');
for (const [name, value] of Object.entries(tokens)) assert.match(source, new RegExp(`--z-${name}:\\s*${value};`));
for (const entry of ['main.jsx', 'admin-main.jsx', 'player-main.jsx', 'SAS/main.jsx']) {
  const text = await readFile(`src/${entry}`, 'utf8');
  assert.equal(text.match(/import\s+['"][^'"]*styles\/ZIndex\.css['"]/g)?.length, 1, `${entry}: exactly one layer import`);
}
const artwork = new Set([
  'src/components/BrandBumperLogo/brand-bumper-logo.css',
  'src/components/Logos/BlackAnimated/black-animated.css',
  'src/components/Logos/WhiteAnimated/white-animated.css',
]);
const failures = [];
async function scan(directory) {
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    const path = `${directory}/${entry.name}`;
    if (entry.isDirectory()) { if (path !== 'src/remotion') await scan(path); continue; }
    if (!/\.(css|jsx?|tsx?)$/.test(path) || artwork.has(path)) continue;
    const text = await readFile(path, 'utf8');
    if (/(?:z-index\s*:|zIndex\s*[:=])\s*["']?-?\d/.test(text) || /\bz-(?:\[\s*-?\d|\d)/.test(text)) failures.push(`${path}: hardcoded layer`);
    if (path !== 'src/styles/ZIndex.css' && /--z-[a-z-]+\s*:/.test(text)) failures.push(`${path}: competing token definition`);
    if (/--(?:admin-z-[\w-]+|z-controls|z-modal|navbar-zindex|dashboard-zindex|palette-zindex|modals-z-index|board-scroller-z-index)\b/.test(text)) failures.push(`${path}: obsolete token`);
    for (const match of text.matchAll(/var\(--z-([a-z-]+)[,)]/g)) if (!(match[1] in tokens)) failures.push(`${path}: unknown token ${match[1]}`);
  }
}
await scan('src');
assert.deepEqual(failures, []);
console.log('PASS approved tokens, four entry imports, no application literals, no competing/obsolete definitions, known token references');
