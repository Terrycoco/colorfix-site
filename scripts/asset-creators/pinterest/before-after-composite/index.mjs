import { createProposalResult, createRunResult } from "../../shared/creatorContract.mjs";

export const creatorKey = "pinterest.before_after_composite";
export const label = "Pinterest Before/After Composite";
export const description = "Creates vertical before/after composite pin images from approved photo pairs.";
export const output = {
  assetKind: "image",
  mimeType: "image/jpeg",
  width: 1000,
  height: 1500,
  ratio: "2:3",
};

export async function propose(context = {}) {
  const { source = {}, instructions = {} } = context;
  return createProposalResult({
    creatorKey,
    source,
    instructions: {
      layout: "vertical_before_after",
      beforeLabel: "BEFORE",
      afterLabel: "ColorFixed",
      output,
      ...instructions,
    },
    pairs: [],
    ignored: [],
    warnings: [
      "Pair detection is not wired yet. Next step: inspect playlist saved-palette photo links and propose before/full pairs.",
    ],
    metadata: {
      status: "starter",
    },
  });
}

export async function run(context = {}) {
  return createRunResult({
    creatorKey,
    jobId: context.jobId ?? null,
    outputs: [],
    warnings: [
      "Image generation is not wired yet. This unit currently defines the creator contract only.",
    ],
    metadata: {
      status: "starter",
    },
  });
}

export default {
  creatorKey,
  label,
  description,
  output,
  propose,
  run,
};
