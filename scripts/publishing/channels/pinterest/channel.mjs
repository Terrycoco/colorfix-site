export const pinterestChannel = {
  channelKey: "pinterest",
  label: "Pinterest",
  defaultOutputStatus: "draft",
  defaultBoard: {
    board_name: "ColorFix Makeovers",
    board_url: "https://www.pinterest.com/terrymarr/colorfix-makeovers/",
    board_slug: "terrymarr/colorfix-makeovers",
    board_id: null,
  },
  apiPublishGate: {
    requiresBoardId: true,
    syncStatus: "pending_pinterest_api_access",
    syncPlan: [
      "Call Pinterest list boards after API access is approved.",
      "Find the board named ColorFix Makeovers.",
      "Store the returned board_id.",
      "Allow queued pins to publish once board_id is available.",
    ],
  },
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
