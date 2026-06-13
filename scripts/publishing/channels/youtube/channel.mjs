export const youtubeChannel = {
  channelKey: "youtube",
  label: "YouTube",
  defaultOutputStatus: "draft",
  assetCreators: [
    {
      assetType: "playlist_video",
      label: "Playlist Video",
      folder: "scripts/publishing/channels/youtube/asset-creators/video",
      entrypoint: "scripts/render-youtube-video.mjs",
      status: "poc",
      description: "Renders a playlist to MP4 using Remotion.",
      outputFormats: ["mp4"],
      recommendedSize: {
        width: 1920,
        height: 1080,
        ratio: "16:9",
      },
    },
    {
      assetType: "pptx_draft",
      label: "PowerPoint Draft",
      folder: "scripts/publishing/channels/youtube/asset-creators/pptx-draft",
      entrypoint: "scripts/export-youtube-draft.mjs",
      status: "legacy-poc",
      description: "Creates a PPTX draft. Kept as a reference/prototype.",
      outputFormats: ["pptx"],
      recommendedSize: {
        width: 1920,
        height: 1080,
        ratio: "16:9",
      },
    },
  ],
};
