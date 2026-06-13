import { pinterestChannel } from "./channels/pinterest/channel.mjs";
import { youtubeChannel } from "./channels/youtube/channel.mjs";

export const publishingRegistry = [
  pinterestChannel,
  youtubeChannel,
];

export function listChannels() {
  return publishingRegistry.map((channel) => ({
    channelKey: channel.channelKey,
    label: channel.label,
    assetCreatorCount: channel.assetCreators.length,
  }));
}

export function listAssetCreators(channelKey = "") {
  const wanted = String(channelKey || "").trim();
  return publishingRegistry
    .filter((channel) => !wanted || channel.channelKey === wanted)
    .flatMap((channel) =>
      channel.assetCreators.map((creator) => ({
        channelKey: channel.channelKey,
        channelLabel: channel.label,
        ...creator,
      }))
    );
}

export function findAssetCreator(channelKey, assetType) {
  const channel = publishingRegistry.find((entry) => entry.channelKey === channelKey);
  if (!channel) return null;
  return channel.assetCreators.find((creator) => creator.assetType === assetType) || null;
}
