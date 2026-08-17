import fs from 'node:fs';
import path from 'node:path';
import { spawn } from 'node:child_process';

const ROOT = process.cwd();

function readArg(name) {
  const prefix = `--${name}=`;
  const match = process.argv.find((arg) =>
    arg.startsWith(prefix)
  );

  return match
    ? match.slice(prefix.length)
    : '';
}

function ensureDir(dir) {
  fs.mkdirSync(dir, {
    recursive: true,
  });
}

/**
 * Read the render plan written by the local PUB video bridge.
 *
 * Expected file shape:
 *
 * {
 *   "plan": {
 *      ...
 *   }
 * }
 *
 * This renderer deliberately does NOT know anything about
 * playlists, PUB Analyze, Pinterest metadata, or asset creation.
 *
 * Its only job is:
 *
 *   render plan
 *      ->
 *   Remotion composition
 *      ->
 *   MP4
 */
function readRenderPlan(recipePath) {
  if (!recipePath) {
    throw new Error('recipe path required');
  }

  if (!fs.existsSync(recipePath)) {
    throw new Error(
      `Render plan not found: ${recipePath}`
    );
  }

  const raw = fs.readFileSync(
    recipePath,
    'utf8'
  );

  const data = JSON.parse(raw);

  const plan =
    data?.plan &&
    typeof data.plan === 'object'
      ? data.plan
      : data;

  if (
    !plan ||
    typeof plan !== 'object'
  ) {
    throw new Error(
      `Invalid render plan: ${recipePath}`
    );
  }

  return plan;
}

/**
 * Validate only the minimum contract required by this
 * particular Remotion composition.
 *
 * We can tighten this contract after we've designed and
 * tested the first real Before/After video.
 */
function validatePlan(plan) {
  const beforeUrl = String(
    plan?.before?.image_url || ''
  ).trim();

  const afterUrl = String(
    plan?.after?.image_url || ''
  ).trim();

  if (!beforeUrl) {
    throw new Error(
      'Pinterest Before/After video requires before.image_url'
    );
  }

  if (!afterUrl) {
    throw new Error(
      'Pinterest Before/After video requires after.image_url'
    );
  }
}

function run(command, args) {
  return new Promise(
    (resolve, reject) => {
      const child = spawn(
        command,
        args,
        {
          cwd: ROOT,
          stdio: 'inherit',
          env: process.env,
        }
      );

      child.on(
        'error',
        reject
      );

      child.on(
        'exit',
        (code) => {
          if (code === 0) {
            resolve();
            return;
          }

          reject(
            new Error(
              `${command} exited with code ${code}`
            )
          );
        }
      );
    }
  );
}

async function main() {
  const recipePath =
    readArg('recipe');

  const outputPath =
    readArg('output');

  if (!recipePath) {
    throw new Error(
      '--recipe is required'
    );
  }

  if (!outputPath) {
    throw new Error(
      '--output is required'
    );
  }

  const plan =
    readRenderPlan(recipePath);

  validatePlan(plan);

  ensureDir(
    path.dirname(outputPath)
  );

  /*
   * Pass the same props file directly to Remotion.
   *
   * The registered composition will receive:
   *
   * {
   *   plan: { ... }
   * }
   */
  await run(
    'npx',
    [
      'remotion',
      'render',

      'src/remotion/index.jsx',

      'colorfix-pinterest-before-after-video',

      outputPath,

      `--props=${recipePath}`,

      '--overwrite',

      '--codec=h264',
    ]
  );

  console.log(
    'Rendered Pinterest Before/After video'
  );

  console.log(outputPath);
}

main().catch((error) => {
  console.error(
    error?.message ||
    error
  );

  process.exit(1);
});