import { Composition } from "remotion";

import { ColorFixYoutubeVideo } from "./video.jsx";
import { YOUTUBE_VIDEO_TIMING } from "./youtubeVideoTiming.js";

import { GenericVideo } from "./GenericVideo.jsx";


const {
  fps: YT_FPS,
  width: YT_WIDTH,
  height: YT_HEIGHT,
} = YOUTUBE_VIDEO_TIMING;


export function RemotionRoot() {
  return (
    <>
      {/*
       * Existing YouTube composition.
       *
       * Leave this untouched until the YouTube Creator is migrated
       * to the shared generic video oven.
       */}
      <Composition
        id="colorfix-youtube-video"
        component={ColorFixYoutubeVideo}
        fps={YT_FPS}
        width={YT_WIDTH}
        height={YT_HEIGHT}
        durationInFrames={YT_FPS}
        defaultProps={{
          plan: {
            title: "ColorFix",
            items: [],
            video: {
              fps: YT_FPS,
              width: YT_WIDTH,
              height: YT_HEIGHT,
              timeline: [],
            },
          },
        }}
        calculateMetadata={({ props }) => {
          const fps = Number(
            props?.plan?.video?.fps || YT_FPS
          );

          const timeline = Array.isArray(
            props?.plan?.video?.timeline
          )
            ? props.plan.video.timeline
            : [];

          const totalMs = timeline.length
            ? Math.max(
                ...timeline.map(
                  (item) =>
                    Number(item?.end_ms || 0)
                )
              )
            : 1000;

          return {
            fps,
            width: Number(
              props?.plan?.video?.width || YT_WIDTH
            ),
            height: Number(
              props?.plan?.video?.height || YT_HEIGHT
            ),
            durationInFrames: Math.max(
              1,
              Math.ceil(
                (totalMs / 1000) * fps
              )
            ),
          };
        }}
      />


      {/*
       * Shared generic video oven.
       *
       * These registration values are neutral placeholders only.
       * The actual width, height, fps, and duration come from the
       * Creator-owned plan for each render job.
       */}
      <Composition
        id="colorfix-generic-video"
        component={GenericVideo}
        fps={1}
        width={1}
        height={1}
        durationInFrames={1}
        defaultProps={{
          plan: {
            video: {
              fps: 1,
              width: 1,
              height: 1,
              duration_in_frames: 1,
            },
            render: {
              codec: "h264",
            },
            layers: [],
            audio: [],
          },
        }}
        calculateMetadata={({ props }) => {
          const video =
            props?.plan?.video;

          if (
            !video ||
            typeof video !== "object" ||
            Array.isArray(video)
          ) {
            throw new Error(
              "Generic video requires plan.video."
            );
          }

          const fps =
            requirePositiveNumber(
              video.fps,
              "plan.video.fps"
            );

          const width =
            requirePositiveNumber(
              video.width,
              "plan.video.width"
            );

          const height =
            requirePositiveNumber(
              video.height,
              "plan.video.height"
            );

          const durationInFrames =
            requirePositiveNumber(
              video.duration_in_frames,
              "plan.video.duration_in_frames"
            );

          return {
            fps,
            width,
            height,
            durationInFrames: Math.max(
              1,
              Math.round(durationInFrames)
            ),
          };
        }}
      />
    </>
  );
}


function requirePositiveNumber(
  value,
  path
) {
  const number =
    Number(value);

  if (
    !Number.isFinite(number) ||
    number <= 0
  ) {
    throw new Error(
      `Generic video requires ${path}.`
    );
  }

  return number;
}