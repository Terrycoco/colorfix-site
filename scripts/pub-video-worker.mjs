import fs from "node:fs";
import path from "node:path";
import os from "node:os";
import { spawn } from "node:child_process";

const ROOT = process.cwd();

const BASE_URL = String(
  process.env.COLORFIX_BASE_URL || "https://colorfix.terrymarr.com"
).replace(/\/+$/, "");

const POLL_MS = 5000;
const HEARTBEAT_MS = 5000;
const REQUEST_TIMEOUT_MS = 15000;
const VIDEO_UPLOAD_TIMEOUT_MS = 120000;

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

async function postJson(
  url,
  payload = {},
  timeoutMs = REQUEST_TIMEOUT_MS
) {
  const controller =
    new AbortController();

  const timer =
    setTimeout(
      () => {
        controller.abort();
      },
      timeoutMs
    );

  try {
    const response =
      await fetch(url, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          ...payload,
          worker_secret: WORKER_SECRET,
        }),
        signal:
          controller.signal,
      });

    const text =
      await response.text();

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

  } catch (error) {
    if (
      error?.name === "AbortError"
    ) {
      throw new Error(
        `Request timed out after ${timeoutMs}ms: ${url}`
      );
    }

    throw error;

  } finally {
    clearTimeout(
      timer
    );
  }
}

async function uploadVideo(
  jobId,
  outputPath,
  timeoutMs = VIDEO_UPLOAD_TIMEOUT_MS
) {
  const form = new FormData();

  form.append(
    "pub_video_job_id",
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

  const controller =
    new AbortController();

  const timer =
    setTimeout(
      () => {
        controller.abort();
      },
      timeoutMs
    );

  try {
    const response =
      await fetch(
        `${BASE_URL}/api/v2/admin/pub/video-jobs/upload.php`,
        {
          method: "POST",
          body: form,
          signal:
            controller.signal,
        }
      );

    const text =
      await response.text();

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
        `Video upload failed: ${response.status}`
      );
    }

    return data.file;

  } catch (error) {
    if (
      error?.name === "AbortError"
    ) {
      throw new Error(
        `Video upload timed out after ${timeoutMs}ms.`
      );
    }

    throw error;

  } finally {
    clearTimeout(
      timer
    );
  }
}

/*
 * GENERIC REMOTION EXECUTOR
 *
 * The worker does not select a product-specific renderer.
 * The Creator has already selected the composition and built the
 * complete render plan. The worker only transports that plan to
 * the generic Remotion executor.
 */
function remotionExecutorPath() {
  const executor = path.join(
    ROOT,
    "scripts",
    "render-remotion-video.mjs"
  );

  if (!fs.existsSync(executor)) {
    throw new Error(
      `Generic Remotion executor not found: ${executor}`
    );
  }

  return executor;
}

function outputPathFor(job) {
  const jobId = Number(
    job?.pub_video_job_id || 0
  );

  if (!jobId) {
    throw new Error("Video job ID missing.");
  }

  const dir = path.join(
    ROOT,
    "exports",
    "pub-video-jobs",
    String(jobId)
  );

  ensureDir(dir);

  return path.join(
    dir,
    "output.mp4"
  );
}

function recipePathFor(job) {
  const jobId = Number(
    job?.pub_video_job_id || 0
  );

  if (!jobId) {
    throw new Error("Video job ID missing.");
  }

  const dir = path.join(
    ROOT,
    "exports",
    "pub-video-jobs",
    String(jobId)
  );

  ensureDir(dir);

  return path.join(
    dir,
    "recipe.json"
  );
}

/*
 * One claimed asset must always end in one of two states:
 *
 *   complete
 *   failed
 *
 * Once an asset has been claimed, every remaining operation belongs
 * inside this failure boundary. That prevents an exception during
 * validation/setup from leaving the asset stranded in rendering.
 */
async function processVideoJob(job) {
  const jobId = Number(
    job?.pub_video_job_id || 0
  );

  const assetId = Number(
    job?.pub_asset_id || 0
  );

  if (!jobId) {
    throw new Error(
      "Claimed video work is missing its internal job ID."
    );
  }

  if (!assetId) {
    throw new Error(
      "Claimed video work is missing its PUB asset ID."
    );
  }

  console.log(
    `\nClaimed PUB asset #${assetId}: ${job?.creator_key || "video"}`
  );

  try {
    await postJson(
      `${BASE_URL}/api/v2/admin/pub/video-jobs/rendering.php`,
      {
        pub_video_job_id: jobId,
      }
    );

    const recipePath =
      recipePathFor(job);

    const outputPath =
      outputPathFor(job);

    const compositionId = String(
      job?.video_recipe_key || ""
    ).trim();

    if (!compositionId) {
      throw new Error(
        "Video is missing its renderer composition."
      );
    }

    if (
      !job?.props ||
      typeof job.props !== "object" ||
      Array.isArray(job.props)
    ) {
      throw new Error(
        "Video is missing its Creator-owned render plan."
      );
    }

    if (
      !job.props.render ||
      typeof job.props.render !== "object" ||
      Array.isArray(job.props.render)
    ) {
      throw new Error(
        "Video is missing Creator-owned render instructions."
      );
    }

    const codec = String(
      job.props.render.codec || ""
    ).trim();

    if (!codec) {
      throw new Error(
        "Video is missing Creator-owned render.codec."
      );
    }

    fs.writeFileSync(
      recipePath,
      JSON.stringify(
        {
          composition_id:
            compositionId,

          plan:
            job.props,

          render:
            job.props.render,
        },
        null,
        2
      )
    );

    const rendererScript =
      remotionExecutorPath();

    console.log(
      `Rendering PUB asset #${assetId}...`
    );

    await run(
      "node",
      [
        rendererScript,
        `--recipe=${recipePath}`,
        `--output=${outputPath}`,
      ]
    );

    const stat =
      fs.statSync(
        outputPath
      );

    if (!stat.isFile() || stat.size <= 0) {
      throw new Error(
        "Video renderer did not produce a valid output file."
      );
    }

    console.log(
      `Uploading PUB asset #${assetId}...`
    );

    const uploaded =
      await uploadVideo(
        jobId,
        outputPath
      );

    if (!uploaded?.rel_path) {
      throw new Error(
        "Upload did not return a stored video path."
      );
    }

await postJson(
  `${BASE_URL}/api/v2/admin/pub/video-jobs/complete.php`,
  {
    pub_video_job_id:
      jobId,

    status:
      "complete",

    output_rel_path:
      uploaded.rel_path,

    output_file_size_bytes:
      uploaded.file_size_bytes,
  }
);

cleanupJobFiles(jobId);

console.log(
  `Completed PUB asset #${assetId}`
);

  } catch (error) {
    const message =
      error?.message ||
      "Video rendering failed.";

    console.error(
      `PUB asset #${assetId} FAILED: ${message}`
    );

    /*
     * Best effort: once a claimed asset fails, tell PUB immediately
     * so the job/asset does not remain stuck in rendering.
     *
     * postJson() is timeout-bounded, so a stalled failure-report cannot
     * trap the worker inside this recovery path forever.
     */
    try {
      await postJson(
        `${BASE_URL}/api/v2/admin/pub/video-jobs/complete.php`,
        {
          pub_video_job_id:
            jobId,

          status:
            "failed",

          error_message:
            message,
        }
      );

      console.error(
        `PUB asset #${assetId} marked failed.`
      );

    } catch (reportError) {
      console.error(
        `PUB asset #${assetId} failure could not be reported to PUB: ${
          reportError?.message ||
          reportError
        }`
      );
    }

    console.log(
      `Worker recovered from PUB asset #${assetId}; returning to polling.`
    );
  }
}

async function claimNextVideoJob() {
  const data =
    await postJson(
      `${BASE_URL}/api/v2/admin/pub/video-jobs/claim.php`
    );

  return data?.job || null;
}

let heartbeatInFlight = false;

async function sendHeartbeat() {
  if (heartbeatInFlight) {
    return;
  }

  heartbeatInFlight = true;

  try {
    await postJson(
      `${BASE_URL}/api/v2/admin/pub/video-jobs/heartbeat.php`,
      {
        worker_name:
          os.hostname(),

        pid:
          process.pid,

        implementation:
          "local-remotion",
      }
    );

  } finally {
    heartbeatInFlight = false;
  }
}

async function main() {
  await sendHeartbeat();

  const heartbeatTimer =
    setInterval(
      () => {
        sendHeartbeat()
          .catch((error) => {
            console.error(
              "PUB video worker heartbeat failed:",
              error?.message ||
              error
            );
          });
      },
      HEARTBEAT_MS
    );

  heartbeatTimer.unref();

  console.log(
    "PUB local video worker started."
  );

  console.log(
    `Server: ${BASE_URL}`
  );

  console.log(
    `Heartbeat every ${HEARTBEAT_MS / 1000} seconds.`
  );

  console.log(
    `Polling every ${POLL_MS / 1000} seconds.`
  );

  console.log(
    `HTTP request timeout: ${REQUEST_TIMEOUT_MS / 1000} seconds.`
  );

  console.log(
    `Video upload timeout: ${VIDEO_UPLOAD_TIMEOUT_MS / 1000} seconds.`
  );

  while (true) {
    try {
      const job =
        await claimNextVideoJob();

      if (job) {
        await processVideoJob(
          job
        );
      }

    } catch (error) {
      /*
       * Claim/poll failures are isolated here.
       * Per-asset failures are absorbed and reported inside
       * processVideoJob(), allowing the worker to continue.
       */
      console.error(
        "PUB video worker polling error:",
        error?.message ||
        error
      );
    }

    await sleep(
      POLL_MS
    );
  }
}


function cleanupJobFiles(jobId) {
  const dir = path.join(
    ROOT,
    "exports",
    "pub-video-jobs",
    String(jobId)
  );

  try {
    fs.rmSync(dir, {
      recursive: true,
      force: true,
    });

    console.log(
      `Cleaned local files for video job #${jobId}`
    );
  } catch (error) {
    console.error(
      `Could not clean local files for video job #${jobId}:`,
      error?.message || error
    );
  }
}





main().catch((error) => {
  console.error(
    "PUB video worker stopped:",
    error?.message ||
    error
  );

  process.exit(1);
});