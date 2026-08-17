import { Composition } from "remotion";
import { ColorFixYoutubeVideo } from "./video.jsx";
import { YOUTUBE_VIDEO_TIMING } from "./youtubeVideoTiming.js";
import { PinterestBeforeAfterVideo } from "./pinterestBeforeAfterVideo.jsx";
import { PINTEREST_BEFORE_AFTER_TIMING } from "./pinterestBeforeAfterTiming.js";

const { fps: FPS, width: WIDTH, height: HEIGHT } = YOUTUBE_VIDEO_TIMING;

export function RemotionRoot() {
  return (
    <>
      <Composition
        id="colorfix-youtube-video"
        component={ColorFixYoutubeVideo}
        fps={FPS}
        width={WIDTH}
        height={HEIGHT}
        durationInFrames={FPS}
        defaultProps={{
          plan: {
            title: "ColorFix",
            items: [],
            video: {
              fps: FPS,
              width: WIDTH,
              height: HEIGHT,
              timeline: [],
            },
          },
        }}
        calculateMetadata={({ props }) => {
          const fps = Number(props?.plan?.video?.fps || FPS);
          const timeline = Array.isArray(props?.plan?.video?.timeline)
            ? props.plan.video.timeline
            : [];

          const totalMs = timeline.length
            ? Math.max(...timeline.map((item) => Number(item?.end_ms || 0)))
            : 1000;

          return {
            fps,
            width: Number(props?.plan?.video?.width || WIDTH),
            height: Number(props?.plan?.video?.height || HEIGHT),
            durationInFrames: Math.max(
              1,
              Math.ceil((totalMs / 1000) * fps)
            ),
          };
        }}
      />

      <Composition
        id="colorfix-pinterest-before-after-video"
        component={PinterestBeforeAfterVideo}
        fps={PINTEREST_BEFORE_AFTER_TIMING.fps}
        width={PINTEREST_BEFORE_AFTER_TIMING.width}
        height={PINTEREST_BEFORE_AFTER_TIMING.height}
        durationInFrames={
          PINTEREST_BEFORE_AFTER_TIMING.durationInFrames
        }
        defaultProps={{
          plan: {
            before: {
              image_url: "",
            },
            after: {
              image_url: "",
            },
          },
        }}
      />
    </>
  );
}