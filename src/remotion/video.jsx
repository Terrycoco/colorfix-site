import { useMemo } from "react";
import { AbsoluteFill, Audio, Img, Sequence, interpolate, useCurrentFrame, useVideoConfig } from "remotion";
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
  const isCurrentBrandBumper = isBrandBumper(currentItem);
  const brandBumperConfig = useMemo(() => parseBrandBumperConfig(currentItem?.body), [currentItem?.body]);
  const syntheticScratchSrc = useMemo(() => makeSyntheticScratchAudioDataUri(), []);
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
  const signatureDelayMs = Number(plan?.video?.signature_reveal_delay_ms || brandBumperConfig.signatureRevealDelayMs || YOUTUBE_VIDEO_TIMING.signatureRevealDelayMs);
  const signatureDurationMs = Number(plan?.video?.signature_reveal_duration_ms || brandBumperConfig.signatureRevealDurationMs || YOUTUBE_VIDEO_TIMING.signatureRevealDurationMs);
  const scratchConfig = brandBumperConfig.signatureSound || {};
  const scratchActive = isCurrentBrandBumper && scratchConfig.enabled !== false;
  const scratchStartFrame = Math.round(((Number(activeTimeline?.start_ms || 0) + signatureDelayMs) / 1000) * fps);
  const scratchDurationFrames = Math.max(1, Math.round((signatureDurationMs / 1000) * fps));
  const scratchVolume = Math.max(0, Math.min(1, Number(scratchConfig.volume ?? 0.075)));

  return (
    <AbsoluteFill style={{ backgroundColor: "black" }}>
      {plan?.music?.src ? (
        <Audio
          src={plan.music.src}
          volume={Number.isFinite(Number(plan.music.volume)) ? Number(plan.music.volume) : 0.18}
        />
      ) : null}
      {scratchActive ? (
        <Sequence from={scratchStartFrame} durationInFrames={scratchDurationFrames}>
          <Audio
            src={scratchConfig.src || syntheticScratchSrc}
            volume={scratchVolume}
          />
        </Sequence>
      ) : null}
      {previousIndex !== null && (
        <AbsoluteFill>
          <YoutubePlayer
            slides={items}
            activeIndex={previousIndex}
            title={plan?.title || "ColorFix"}
            imageComponent={Img}
            showCaption={false}
            localMs={0}
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
          localMs={localMs}
          slideDurationMs={currentDurationMs}
          videoTiming={plan?.video || {}}
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

function isBrandBumper(item) {
  const type = String(item?.type || item?.item_type || "normal").toLowerCase().trim();
  return type === "brand-bumper";
}

function parseBrandBumperConfig(rawBody) {
  const fallback = {
    signatureRevealDelayMs: YOUTUBE_VIDEO_TIMING.signatureRevealDelayMs,
    signatureRevealDurationMs: YOUTUBE_VIDEO_TIMING.signatureRevealDurationMs,
    signatureSound: {
      enabled: true,
      src: "",
      volume: 0.075,
    },
  };
  const raw = String(rawBody || "").trim();
  if (!raw) return fallback;
  try {
    const parsed = JSON.parse(raw);
    if (!parsed || typeof parsed !== "object") return fallback;
    return {
      ...fallback,
      ...parsed,
      signatureSound: {
        ...fallback.signatureSound,
        ...(parsed.signatureSound && typeof parsed.signatureSound === "object" ? parsed.signatureSound : {}),
      },
    };
  } catch {
    return fallback;
  }
}

function makeSyntheticScratchAudioDataUri() {
  const sampleRate = 24000;
  const durationSeconds = 1.95;
  const sampleCount = Math.floor(sampleRate * durationSeconds);
  const dataSize = sampleCount * 2;
  const buffer = new ArrayBuffer(44 + dataSize);
  const view = new DataView(buffer);
  writeAscii(view, 0, "RIFF");
  view.setUint32(4, 36 + dataSize, true);
  writeAscii(view, 8, "WAVE");
  writeAscii(view, 12, "fmt ");
  view.setUint32(16, 16, true);
  view.setUint16(20, 1, true);
  view.setUint16(22, 1, true);
  view.setUint32(24, sampleRate, true);
  view.setUint32(28, sampleRate * 2, true);
  view.setUint16(32, 2, true);
  view.setUint16(34, 16, true);
  writeAscii(view, 36, "data");
  view.setUint32(40, dataSize, true);

  let seed = 5731;
  for (let i = 0; i < sampleCount; i += 1) {
    seed = (seed * 1664525 + 1013904223) >>> 0;
    const t = i / sampleRate;
    const phase = t / durationSeconds;
    const noise = ((seed / 4294967295) * 2) - 1;
    const tooth = ((t * 110) % 1) - 0.5;
    const scratchPulse = Math.pow(Math.max(0, Math.sin(t * Math.PI * 16)), 8);
    const envelope = Math.sin(Math.PI * Math.min(1, Math.max(0, phase)));
    const pressure = 0.42 + 0.26 * Math.sin(t * 31) + 0.16 * Math.sin(t * 67);
    const sample = (noise * 0.5 + tooth * 0.5) * scratchPulse * envelope * pressure;
    view.setInt16(44 + (i * 2), Math.max(-1, Math.min(1, sample)) * 32767, true);
  }

  let binary = "";
  const bytes = new Uint8Array(buffer);
  for (let i = 0; i < bytes.length; i += 1) {
    binary += String.fromCharCode(bytes[i]);
  }
  return `data:audio/wav;base64,${btoa(binary)}`;
}

function writeAscii(view, offset, value) {
  for (let i = 0; i < value.length; i += 1) {
    view.setUint8(offset + i, value.charCodeAt(i));
  }
}
