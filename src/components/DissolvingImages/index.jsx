import { useEffect, useMemo, useState } from "react";
import "./DissolvingImages.css";

/**
 * Reusable, layout-neutral crossfade.
 *
 * images: ["/photo.jpg", { src: "/landscape.jpg", mobileSrc: "/portrait.jpg", alt: "Room description" }]
 * intervalMs: time each image stays on screen, including the fade
 * fadeMs: duration of each crossfade (must be shorter than intervalMs)
 * className: optional class for sizing/aspect ratio in the parent layout
 *
 * The parent controls width and height (or aspect-ratio). This component
 * does not fetch article data or make assumptions about image sources.
 */
export default function DissolvingImages({
  images = [],
  intervalMs = 6000,
  fadeMs = 1100,
  className = "",
  objectPosition = "center",
}) {
  const slides = useMemo(
    () =>
      (Array.isArray(images) ? images : [])
        .map((image) =>
          typeof image === "string"
            ? { src: image, alt: "" }
            : { src: image?.src, mobileSrc: image?.mobileSrc || "", alt: image?.alt || "" }
        )
        .filter((image) => typeof image.src === "string" && image.src.trim()),
    [images]
  );

  const [activeIndex, setActiveIndex] = useState(0);
  const [loaded, setLoaded] = useState({});
  const [reduceMotion, setReduceMotion] = useState(false);

  const sourcesKey = slides.map((slide) => `${slide.src}|${slide.mobileSrc || ""}`).join("\u0000");

  useEffect(() => {
    setActiveIndex(0);
    setLoaded({});
  }, [sourcesKey]);

  useEffect(() => {
    if (typeof window === "undefined" || !window.matchMedia) return undefined;
    const preference = window.matchMedia("(prefers-reduced-motion: reduce)");
    const sync = () => setReduceMotion(preference.matches);
    sync();
    preference.addEventListener?.("change", sync);
    return () => preference.removeEventListener?.("change", sync);
  }, []);

  // Preload the next slide before transitioning. Failed images are skipped.
  useEffect(() => {
    if (slides.length < 2 || reduceMotion || typeof Image === "undefined") return undefined;
    const next = (activeIndex + 1) % slides.length;
    const candidate = slides[next];
    const src = window.matchMedia?.("(max-width: 560px)").matches && candidate.mobileSrc
      ? candidate.mobileSrc : candidate.src;
    if (!src || loaded[src] !== undefined) return undefined;
    const img = new Image();
    let cancelled = false;
    img.onload = () => !cancelled && setLoaded((current) => ({ ...current, [src]: true }));
    img.onerror = () => !cancelled && setLoaded((current) => ({ ...current, [src]: false }));
    img.src = src;
    if (img.complete && img.naturalWidth > 0) img.onload();
    return () => { cancelled = true; img.onload = null; img.onerror = null; };
  }, [activeIndex, loaded, reduceMotion, slides]);

  useEffect(() => {
    if (slides.length < 2 || reduceMotion) return undefined;
    const delay = Math.max(1500, Number(intervalMs) || 6000);
    const timer = window.setInterval(() => {
      setActiveIndex((current) => {
        for (let step = 1; step < slides.length; step += 1) {
          const candidate = (current + step) % slides.length;
          const slide = slides[candidate];
          const src = window.matchMedia?.("(max-width: 560px)").matches && slide.mobileSrc
            ? slide.mobileSrc : slide.src;
          if (loaded[src] === true) return candidate;
        }
        return current;
      });
    }, delay);
    return () => window.clearInterval(timer);
  }, [slides, intervalMs, reduceMotion, loaded]);

  if (!slides.length) return null;

  const fadeDuration = Math.min(
    Math.max(0, Number(fadeMs) || 0),
    Math.max(0, (Number(intervalMs) || 6000) - 250)
  );

  return (
    <div
      className={`dissolving-images ${className}`.trim()}
      style={{ "--dissolve-duration": `${fadeDuration}ms` }}
    >
      {slides.map((slide, index) => (
        <picture
          key={`${slide.src}-${index}`}
          className={`dissolving-images__slide${index === activeIndex ? " is-active" : ""}`}
          aria-hidden={index !== activeIndex}
        >
          {slide.mobileSrc ? <source media="(max-width: 560px)" srcSet={slide.mobileSrc} /> : null}
          <img
            src={slide.src}
            alt={index === activeIndex ? slide.alt : ""}
            loading={index === 0 ? "eager" : "lazy"}
            decoding="async"
            style={{ objectPosition }}
          />
        </picture>
      ))}
    </div>
  );
}
