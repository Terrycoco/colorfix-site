import pinterestBeforeAfterComposite from "./pinterest/before-after-composite/index.mjs";
import { validateCreatorModule } from "./shared/creatorContract.mjs";

export const assetCreatorRegistry = [
  pinterestBeforeAfterComposite,
];

export function listAssetCreators() {
  return assetCreatorRegistry.map((creator) => ({
    creatorKey: creator.creatorKey,
    label: creator.label,
    description: creator.description || "",
    output: creator.output || {},
  }));
}

export function findAssetCreator(creatorKey) {
  const wanted = String(creatorKey || "").trim();
  if (!wanted) return null;
  return assetCreatorRegistry.find((creator) => creator.creatorKey === wanted) || null;
}

export function assertValidRegistry() {
  const errors = [];
  for (const creator of assetCreatorRegistry) {
    const result = validateCreatorModule(creator);
    if (!result.ok) {
      errors.push(`${creator?.creatorKey || "(unknown)"} missing ${result.missing.join(", ")}`);
    }
  }
  if (errors.length) {
    throw new Error(`Invalid asset creator registry:\n${errors.join("\n")}`);
  }
}
