export const pinterestChannel = {
  channelKey: "pinterest",
  label: "Pinterest",
  defaultOutputStatus: "draft",
  assetCreators: [
    {
      assetType: "before_after_pin",
      label: "Before/After Pin",
      folder: "scripts/publishing/channels/pinterest/asset-creators/before-after-pin",
      status: "planned",
      description: "Combines one before photo and one after photo into a Pinterest pin image.",
      outputFormats: ["png", "jpg"],
      recommendedSize: {
        width: 1000,
        height: 1500,
        ratio: "2:3",
      },
    },
  ],
};
