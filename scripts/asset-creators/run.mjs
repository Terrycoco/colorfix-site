import fs from "node:fs";
import { assertValidRegistry, findAssetCreator, listAssetCreators } from "./registry.mjs";

function readArg(name) {
  const prefix = `--${name}=`;
  const match = process.argv.find((arg) => arg.startsWith(prefix));
  return match ? match.slice(prefix.length) : "";
}

function readContext() {
  const contextPath = readArg("context");
  if (!contextPath) {
    return {};
  }
  const raw = fs.readFileSync(contextPath, "utf8");
  return JSON.parse(raw);
}

function usage() {
  console.log("Usage:");
  console.log("  node scripts/asset-creators/run.mjs --list");
  console.log("  node scripts/asset-creators/run.mjs --creator=pinterest.before_after_composite --phase=propose --context=job.json");
  console.log("  node scripts/asset-creators/run.mjs --creator=pinterest.before_after_composite --phase=run --context=job.json");
}

async function main() {
  assertValidRegistry();

  if (process.argv.includes("--help")) {
    usage();
    return;
  }

  if (process.argv.includes("--list")) {
    console.log(JSON.stringify({ ok: true, items: listAssetCreators() }, null, 2));
    return;
  }

  const creatorKey = readArg("creator");
  const phase = readArg("phase") || "propose";
  const creator = findAssetCreator(creatorKey);
  if (!creator) {
    throw new Error(`Unknown asset creator: ${creatorKey || "(missing)"}`);
  }

  const context = readContext();
  const result = phase === "run"
    ? await creator.run(context)
    : await creator.propose(context);

  console.log(JSON.stringify(result, null, 2));
}

main().catch((err) => {
  console.error(err?.message || err);
  process.exit(1);
});
