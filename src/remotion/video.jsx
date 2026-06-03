import { AbsoluteFill, Img, interpolate, useCurrentFrame, useVideoConfig } from "remotion";
import YoutubePlayer from "../components/YoutubePlayer";
import { YOUTUBE_VIDEO_TIMING } from "./youtubeVideoTiming.js";

export function ColorFixYoutubeVideo({ plan }) {
  const frame = useCurrentFrame();
  const { fps } = useVideoConfig();
  const items = Array.isArray(plan?.items) ? plan.items : [];
  const timeline = Array.isArray(plan?.video?.timeline) ? plan.video.timeline : [];
  const elapsedMs = (frame / fps) * 1000;
  const activeIndex = findActiveIndex(timeline, elapsedMs);
  const activeTimeline = timeline[activeIndex] || null;
  const transitionMs = Number(activeTimeline?.transition_ms || plan?.video?.dissolve_ms || YOUTUBE_VIDEO_TIMING.dissolveMs);
  const localMs = Math.max(0, elapsedMs - Number(activeTimeline?.start_ms || 0));
  const imageOpacity = transitionMs > 0
    ? interpolate(localMs, [0, transitionMs], [0, 1], {
        extrapolateLeft: "clamp",
        extrapolateRight: "clamp",
      })
    : 1;
  const captionDelayMs = transitionMs + Number(plan?.video?.caption_delay_after_photo_ms || YOUTUBE_VIDEO_TIMING.captionDelayAfterPhotoMs);
  const captionFadeMs = Number(plan?.video?.caption_fade_ms || YOUTUBE_VIDEO_TIMING.captionFadeMs);
  const captionOpacity = interpolate(localMs, [captionDelayMs, captionDelayMs + captionFadeMs], [0, 1], {
    extrapolateLeft: "clamp",
    extrapolateRight: "clamp",
  });
  const previousIndex = activeIndex > 0 && localMs < transitionMs ? activeIndex - 1 : null;
  const currentItem = items[activeIndex] || null;
  const isCurrentTextSlide = isTextSlide(currentItem);
  const currentDurationMs = Number(activeTimeline?.duration_ms || 0);
  const finalFadeMs = Number(plan?.video?.final_fade_ms || YOUTUBE_VIDEO_TIMING.finalFadeMs);
  const isFinalSlide = activeIndex === items.length - 1;
  const textEnterOpacity = isCurrentTextSlide && activeIndex > 0 && transitionMs > 0
    ? interpolate(localMs, [transitionMs, transitionMs + captionFadeMs], [0, 1], {
        extrapolateLeft: "clamp",
        extrapolateRight: "clamp",
      })
    : 1;
  const finalExitOpacity = isFinalSlide && finalFadeMs > 0 && currentDurationMs > finalFadeMs
    ? interpolate(localMs, [currentDurationMs - finalFadeMs, currentDurationMs], [1, 0], {
        extrapolateLeft: "clamp",
        extrapolateRight: "clamp",
      })
    : 1;
  const currentLayerOpacity = textEnterOpacity * finalExitOpacity;

  return (
    <AbsoluteFill style={{ backgroundColor: "black" }}>
      {previousIndex !== null && (
        <AbsoluteFill>
          <YoutubePlayer
            slides={items}
            activeIndex={previousIndex}
            title={plan?.title || "ColorFix"}
            imageComponent={Img}
            showCaption={false}
            transparentBackground
          />
        </AbsoluteFill>
      )}
      <AbsoluteFill style={{ opacity: currentLayerOpacity }}>
        <YoutubePlayer
          slides={items}
          activeIndex={activeIndex}
          title={plan?.title || "ColorFix"}
          imageComponent={Img}
          imageOpacity={imageOpacity}
          captionOpacity={captionOpacity}
          transparentBackground
        />
      </AbsoluteFill>
    </AbsoluteFill>
  );
}

function findActiveIndex(timeline, elapsedMs) {
  if (!timeline.length) return 0;
  const index = timeline.findIndex((item) => {
    const start = Number(item?.start_ms || 0);
    const end = Number(item?.end_ms || 0);
    return elapsedMs >= start && elapsedMs < end;
  });
  if (index >= 0) return index;
  return timeline.length - 1;
}

function isTextSlide(item) {
  const type = String(item?.type || "normal").toLowerCase().trim();
  return type === "intro" || type === "text";
}
