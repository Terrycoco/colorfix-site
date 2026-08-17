import fs from "node:fs";
import path from "node:path";
import { spawn } from "node:child_process";

const ROOT = process.cwd();
const BASE_URL = String(
  process.env.COLORFIX_BASE_URL || "https://colorfix.terrymarr.com"
).replace(/\/+$/, "");

const POLL_MS = 5000;
const WORKER_SECRET = String(
  process.env.COLORFIX_RENDER_WORKER_SECRET || ""
).trim();

if (!WORKER_SECRET) {
  throw new Error(
    "COLORFIX_RENDER_WORKER_SECRET is required."
  );
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function ensureDir(dir) {
  fs.mkdirSync(dir, { recursive: true });
}

function run(command, args) {
  return new Promise((resolve, reject) => {
    const child = spawn(command, args, {
      cwd: ROOT,
      stdio: "inherit",
      env: process.env,
    });

    child.on("error", reject);

    child.on("exit", (code) => {
      if (code === 0) {
        resolve();
      } else {
        reject(
          new Error(`${command} exited with code ${code}`)
        );
      }
    });
  });
}

async function postJson(url, payload = {}) {
  const response = await fetch(url, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify({
      ...payload,
      worker_secret: WORKER_SECRET,
    }),
  });

  const text = await response.text();

  let data;

  try {
    data = JSON.parse(text);
  } catch {
    throw new Error(
      `Expected JSON from ${url}, got: ${text.slice(0, 200)}`
    );
  }

  if (!response.ok || !data?.ok) {
    throw new Error(
      data?.error || `Request failed: ${response.status}`
    );
  }

  return data;
}

async function uploadRender(jobId, outputPath) {
  const form = new FormData();

  form.append(
    "pub_render_job_id",
    String(jobId)
  );

  form.append(
  "worker_secret",
  WORKER_SECRET
);

  const bytes = fs.readFileSync(outputPath);

  const blob = new Blob(
    [bytes],
    {
      type: "video/mp4",
    }
  );

  form.append(
    "file",
    blob,
    "output.mp4"
  );

  const response = await fetch(
    `${BASE_URL}/api/v2/admin/pub/render-jobs/upload.php`,
    {
      method: "POST",

      headers: {
       
      },

      body: form,
    }
  );

  const text = await response.text();

  let data;

  try {
    data = JSON.parse(text);
  } catch {
    throw new Error(
      `Expected JSON from upload.php, got: ${text.slice(0, 200)}`
    );
  }

  if (!response.ok || !data?.ok) {
    throw new Error(
      data?.error ||
      `Render upload failed: ${response.status}`
    );
  }

  return data.file;
}

function rendererScriptFor(job) {
  const creatorKey = String(job?.creator_key || "").trim();

  const renderers = {
    "youtube.playlist_video":
      "scripts/render-youtube-video.mjs",

    "pinterest.before_after_video":
      "scripts/render-pinterest-before-after-video.mjs",
  };

  const relative = renderers[creatorKey];

  if (!relative) {
    throw new Error(
      `No local renderer registered for ${creatorKey}`
    );
  }

  return path.join(ROOT, relative);
}

function outputPathFor(job) {
  const jobId = Number(job?.pub_render_job_id || 0);

  if (!jobId) {
    throw new Error("Render job ID missing.");
  }

  const dir = path.join(
    ROOT,
    "exports",
    "pub-render-jobs",
    String(jobId)
  );

  ensureDir(dir);

  return path.join(dir, "output.mp4");
}

function propsPathFor(job) {
  const jobId = Number(job?.pub_render_job_id || 0);

  const dir = path.join(
    ROOT,
    "exports",
    "pub-render-jobs",
    String(jobId)
  );

  ensureDir(dir);

  return path.join(dir, "props.json");
}

async function renderJob(job) {
  const jobId = Number(job.pub_render_job_id);

  console.log(
    `\nClaimed PUB render job #${jobId}: ${job.creator_key}`
  );

  await postJson(
    `${BASE_URL}/api/v2/admin/pub/render-jobs/rendering.php`,
    {
      pub_render_job_id: jobId,
    }
  );

  const propsPath = propsPathFor(job);
  const outputPath = outputPathFor(job);

  fs.writeFileSync(
    propsPath,
    JSON.stringify(
      {
        plan: job.props,
      },
      null,
      2
    )
  );

  const rendererScript = rendererScriptFor(job);

  try {
    await run("node", [
      rendererScript,
      `--recipe=${propsPath}`,
      `--output=${outputPath}`,
    ]);

    const stat = fs.statSync(outputPath);

console.log(
  `Uploading PUB render job #${jobId}...`
);

const uploaded =
  await uploadRender(
    jobId,
    outputPath
  );

if (!uploaded?.rel_path) {
  throw new Error(
    "Upload did not return a stored render path."
  );
}

  await postJson(
    `${BASE_URL}/api/v2/admin/pub/render-jobs/complete.php`,
    {
      pub_render_job_id: jobId,
      status: "complete",

      output_rel_path:
        uploaded.rel_path,

      output_file_size_bytes:
        uploaded.file_size_bytes,
    }
  );



    console.log(
      `Completed PUB render job #${jobId}`
    );

    console.log(outputPath);

  } catch (error) {
    const message =
      error?.message || "Render failed.";

    console.error(
      `PUB render job #${jobId} failed: ${message}`
    );

    try {
      await postJson(
        `${BASE_URL}/api/v2/admin/pub/render-jobs/complete.php`,
        {
          pub_render_job_id: jobId,
          status: "failed",
          error_message: message,
        }
      );
    } catch (reportError) {
      console.error(
        `Could not report failure for job #${jobId}:`,
        reportError?.message || reportError
      );
    }
  }
}

async function claimNextJob() {
  const data = await postJson(
    `${BASE_URL}/api/v2/admin/pub/render-jobs/claim.php`
  );

  return data?.job || null;
}

async function main() {
  console.log("PUB Remotion worker started.");
  console.log(`Server: ${BASE_URL}`);
  console.log(`Polling every ${POLL_MS / 1000} seconds.`);

  while (true) {
    try {
      const job = await claimNextJob();

      if (job) {
        await renderJob(job);
      }
    } catch (error) {
      console.error(
        "PUB worker error:",
        error?.message || error
      );
    }

    await sleep(POLL_MS);
  }
}

main().catch((error) => {
  console.error(error?.message || error);
  process.exit(1);
});