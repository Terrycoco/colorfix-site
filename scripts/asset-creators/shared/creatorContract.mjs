export const CREATOR_STATUS = Object.freeze({
  planned: "planned",
  proposalReady: "proposal_ready",
  ready: "ready",
  running: "running",
  generated: "generated",
  failed: "failed",
});

export const CREATOR_PHASES = Object.freeze({
  propose: "propose",
  run: "run",
});

export function normalizeCreatorKey(value) {
  return String(value || "")
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9.]+/g, "_")
    .replace(/^_+|_+$/g, "");
}

export function createProposalResult({
  creatorKey,
  source = {},
  instructions = {},
  pairs = [],
  ignored = [],
  warnings = [],
  metadata = {},
}) {
  const key = normalizeCreatorKey(creatorKey);
  if (!key) {
    throw new Error("Proposal result requires creatorKey.");
  }

  return {
    ok: true,
    phase: CREATOR_PHASES.propose,
    creatorKey: key,
    source: normalizeSource(source),
    instructions: normalizeObject(instructions),
    pairs: Array.isArray(pairs) ? pairs.map(normalizePair) : [],
    ignored: Array.isArray(ignored) ? ignored.map(normalizeIgnored) : [],
    warnings: Array.isArray(warnings) ? warnings.map(String) : [],
    metadata: normalizeObject(metadata),
  };
}

export function createRunResult({
  creatorKey,
  jobId = null,
  outputs = [],
  warnings = [],
  metadata = {},
}) {
  const key = normalizeCreatorKey(creatorKey);
  if (!key) {
    throw new Error("Run result requires creatorKey.");
  }

  return {
    ok: true,
    phase: CREATOR_PHASES.run,
    creatorKey: key,
    jobId: jobId == null ? null : Number(jobId),
    outputs: Array.isArray(outputs) ? outputs.map(normalizeOutput) : [],
    warnings: Array.isArray(warnings) ? warnings.map(String) : [],
    metadata: normalizeObject(metadata),
  };
}

export function validateCreatorModule(module) {
  const missing = [];
  if (!module || typeof module !== "object") missing.push("module");
  if (!module?.creatorKey) missing.push("creatorKey");
  if (!module?.label) missing.push("label");
  if (typeof module?.propose !== "function") missing.push("propose");
  if (typeof module?.run !== "function") missing.push("run");

  return {
    ok: missing.length === 0,
    missing,
  };
}

function normalizeSource(source) {
  const value = normalizeObject(source);
  return {
    sourceType: String(value.sourceType || value.source_type || "").trim(),
    sourceId: value.sourceId ?? value.source_id ?? null,
    playlistInstanceId: value.playlistInstanceId ?? value.playlist_instance_id ?? null,
  };
}

function normalizePair(pair, index) {
  const value = normalizeObject(pair);
  return {
    pairKey: String(value.pairKey || value.pair_key || `pair-${index + 1}`),
    beforeAssetId: normalizeOptionalNumber(value.beforeAssetId ?? value.before_asset_id),
    afterAssetId: normalizeOptionalNumber(value.afterAssetId ?? value.after_asset_id),
    title: String(value.title || ""),
    caption: String(value.caption || ""),
    include: value.include !== false,
    confidence: String(value.confidence || "manual"),
    source: String(value.source || ""),
    sortOrder: Number(value.sortOrder ?? value.sort_order ?? index + 1),
    metadata: normalizeObject(value.metadata),
  };
}

function normalizeIgnored(item) {
  const value = normalizeObject(item);
  return {
    assetId: normalizeOptionalNumber(value.assetId ?? value.asset_id),
    reason: String(value.reason || ""),
    metadata: normalizeObject(value.metadata),
  };
}

function normalizeOutput(output) {
  const value = normalizeObject(output);
  return {
    assetLibraryId: normalizeOptionalNumber(value.assetLibraryId ?? value.asset_library_id),
    relPath: String(value.relPath || value.rel_path || ""),
    role: String(value.role || "output"),
    metadata: normalizeObject(value.metadata),
  };
}

function normalizeObject(value) {
  return value && typeof value === "object" && !Array.isArray(value) ? value : {};
}

function normalizeOptionalNumber(value) {
  if (value == null || value === "") return null;
  const number = Number(value);
  return Number.isFinite(number) && number > 0 ? number : null;
}
