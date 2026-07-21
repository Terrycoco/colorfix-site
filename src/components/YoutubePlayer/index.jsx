import { useEffect, useMemo, useState } from "react";
import AnimatedHueWheel from "../AnimatedHueWheel";
import BrandBumperLogo from "../BrandBumperLogo";
import { YOUTUBE_VIDEO_TIMING } from "../../remotion/youtubeVideoTiming.js";
import {
  extractAssetId,
  fetchAssetUrl,
  isAssetRef,
  parsePhotoRef,
} from "../../helpers/assetImage";
import "./youtube-player.css";

export default function YoutubePlayer({
  slides = [],
  activeIndex = 0,
  title = "",
  imageComponent: ImageComponent = "img",
  imageOpacity = 1,
  captionOpacity = 1,
  localMs = 0,
  slideDurationMs = 0,
  videoTiming = {},
  showCaption = true,
  transparentBackground = false,
}) {
  const items = Array.isArray(slides) ? slides : [];
  const currentItem = items[Math.min(Math.max(0, activeIndex), Math.max(0, items.length - 1))] || null;
  const itemType = String(currentItem?.type || "normal").toLowerCase().trim();
  const isTextSlide = itemType === "intro" || itemType === "text";
  const isHueWheel = itemType === "hue-wheel";
  const isBrandBumper = itemType === "brand-bumper";
  const hueWheelConfig = useMemo(() => parseHueWheelConfig(currentItem?.body), [currentItem?.body]);
  const immediateImageUrl = useMemo(
    () => resolveImmediateImageUrl(currentItem?.image_url || "", isHueWheel),
    [currentItem?.image_url, isHueWheel]
  );
  const [assetImageUrl, setAssetImageUrl] = useState("");
  const imageUrl = immediateImageUrl || assetImageUrl;

  useEffect(() => {
    let cancelled = false;
    const value = currentItem?.image_url || "";
    if (!value || isHueWheel || immediateImageUrl) {
      setAssetImageUrl("");
      return () => {};
    }

    if (!isAssetRef(value)) {
      setAssetImageUrl("");
      return () => {};
    }

    const assetId = extractAssetId(value);
    fetchAssetUrl(assetId).then((url) => {
      if (!cancelled) setAssetImageUrl(url || "");
    });
    return () => {
      cancelled = true;
    };
  }, [currentItem?.image_url, immediateImageUrl, isHueWheel]);

  const rootClassName = [
    "youtube-player-root",
    transparentBackground ? "is-transparent" : "",
  ].filter(Boolean).join(" ");

  if (!currentItem) {
    return (
      <div className={rootClassName}>
        <div className="youtube-player-text">
          <h1>{title || "ColorFix"}</h1>
        </div>
      </div>
    );
  }

  if (isHueWheel) {
    return (
      <div className={rootClassName}>
        <div className="youtube-player-hue-wheel-slide">
          <div className="youtube-player-hue-wheel-copy">
            {currentItem.title ? <h1>{currentItem.title}</h1> : null}
            {currentItem.subtitle ? <p>{currentItem.subtitle}</p> : null}
          </div>
          <AnimatedHueWheel
            key={`youtube-hue-${activeIndex}-${currentItem?.playlist_item_id || ""}`}
            items={hueWheelConfig.items}
            animated={hueWheelConfig.animated}
            showLabels={hueWheelConfig.showLabels}
            showDots={hueWheelConfig.showDots}
            pulseOnComplete={hueWheelConfig.pulseOnComplete}
            caption={hueWheelConfig.caption}
            size={hueWheelConfig.size}
            wheelFadeMs={hueWheelConfig.wheelFadeMs}
            spokeStartRadius={hueWheelConfig.spokeStartRadius}
            spokeEndRadius={hueWheelConfig.spokeEndRadius}
            spokeDelayMs={hueWheelConfig.spokeDelayMs}
            spokeStaggerMs={hueWheelConfig.spokeStaggerMs}
            spokeDurationMs={hueWheelConfig.spokeDurationMs}
            className="youtube-player-hue-wheel"
          />
        </div>
      </div>
    );
  }

  if (isBrandBumper) {
    const config = parseBrandBumperConfig(currentItem?.body);
    const revealDelayMs = Number(videoTiming.signature_reveal_delay_ms || config.signatureRevealDelayMs || YOUTUBE_VIDEO_TIMING.signatureRevealDelayMs);
    const revealDurationMs = Number(videoTiming.signature_reveal_duration_ms || config.signatureRevealDurationMs || YOUTUBE_VIDEO_TIMING.signatureRevealDurationMs);
    const durationMs = Math.max(
      YOUTUBE_VIDEO_TIMING.defaultBrandBumperDurationMs,
      Number(slideDurationMs || currentItem?.duration_ms || videoTiming.default_brand_bumper_duration_ms || YOUTUBE_VIDEO_TIMING.defaultBrandBumperDurationMs),
    );
    const revealProgress = signatureWriteProgress((Number(localMs || 0) - revealDelayMs) / Math.max(1, revealDurationMs));
    const fadeIn = clamp01(Number(localMs || 0) / 420);
    const fadeOutStart = Math.max(0, durationMs - 420);
    const fadeOut = durationMs > 0 ? 1 - clamp01((Number(localMs || 0) - fadeOutStart) / 420) : 1;
    const logoOpacity = imageOpacity * fadeIn * fadeOut;
    return (
      <div className={`${rootClassName} youtube-player-brand-bumper`}>
        <BrandBumperLogo signatureProgress={revealProgress} style={{ opacity: logoOpacity }} />
      </div>
    );
  }

  if (isTextSlide || !imageUrl) {
    return (
      <div className={rootClassName}>
        <div className="youtube-player-text">
          {currentItem.title ? <h1>{currentItem.title}</h1> : null}
          {currentItem.subtitle ? <p>{currentItem.subtitle}</p> : null}
          {currentItem.body ? <div className="youtube-player-body">{currentItem.body}</div> : null}
        </div>
      </div>
    );
  }

  return (
    <div className={rootClassName}>
      <ImageComponent
        src={imageUrl}
        alt={currentItem.alt_tag || currentItem.title || currentItem.subtitle || ""}
        className="youtube-player-image"
        style={{ opacity: imageOpacity }}
      />
      {showCaption && (currentItem.title || currentItem.subtitle) && (
        <div className="youtube-player-caption" style={{ opacity: captionOpacity }}>
          {currentItem.title ? <span className="youtube-player-caption-title">{currentItem.title}</span> : null}
          {currentItem.subtitle ? <span className="youtube-player-caption-subtitle">{currentItem.subtitle}</span> : null}
        </div>
      )}
    </div>
  );
}

function clamp01(value) {
  const numeric = Number(value);
  if (!Number.isFinite(numeric)) return 0;
  return Math.max(0, Math.min(1, numeric));
}

function signatureWriteProgress(rawValue) {
  const value = clamp01(rawValue);
  if (value <= 0) return 0;
  if (value >= 1) return 1;
  return 1 - Math.pow(1 - value, 2.35);
}

function parseBrandBumperConfig(rawBody) {
  const fallback = {
    signatureRevealDelayMs: YOUTUBE_VIDEO_TIMING.signatureRevealDelayMs,
    signatureRevealDurationMs: YOUTUBE_VIDEO_TIMING.signatureRevealDurationMs,
    auto_advance: true,
    requires_tap: false,
    signatureSound: {
      enabled: true,
      src: "",
      cueMs: YOUTUBE_VIDEO_TIMING.signatureRevealDelayMs,
      volume: 0.075,
      note: "uses synthetic pencil scratch unless src is provided",
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

function resolveImmediateImageUrl(value, isHueWheel) {
  if (isHueWheel) return "";
  const raw = String(value || "").trim();
  if (!raw) return "";
  const parsed = parsePhotoRef(raw);
  if (parsed.url) return parsed.url;
  if (isAssetRef(raw)) return "";
  return raw;
}

function parseHueWheelConfig(rawBody) {
  const fallback = {
    items: [],
    animated: true,
    showLabels: false,
    showDots: false,
    pulseOnComplete: true,
    caption: "",
    size: 520,
    wheelFadeMs: 420,
    spokeStartRadius: 0,
    spokeEndRadius: 136,
    spokeDelayMs: 420,
    spokeStaggerMs: 260,
    spokeDurationMs: 800,
  };
  const raw = String(rawBody || "").trim();
  if (!raw) return fallback;
  try {
    const parsed = JSON.parse(raw);
    const config = Array.isArray(parsed) ? { items: parsed } : parsed;
    if (!config || typeof config !== "object") return fallback;
    return {
      ...fallback,
      ...config,
      items: Array.isArray(config.items) ? config.items : [],
      animated: config.animated !== false,
      showLabels: config.showLabels === true,
      showDots: config.showDots === true,
      pulseOnComplete: config.pulseOnComplete !== false,
      caption: typeof config.caption === "string" ? config.caption : fallback.caption,
    };
  } catch {
    return fallback;
  }
}
