import { fallbackToOriginal } from "@helpers/imageThumb";
import { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { photoThumbUrl } from "@helpers/imageThumb";
import "./FeaturedArticleItem.css";

function parseJsonMaybe(text) {
  if (!text || typeof text !== "string") return null;
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}

const FeaturedArticleItem = ({ item = {} }) => {
  const [payload, setPayload] = useState(null);
  const [error, setError] = useState("");

  const metadata = useMemo(() => {
    const bodyMeta = parseJsonMaybe(item.body);
    const descMeta = parseJsonMaybe(item.description);
    return {
      ...(typeof bodyMeta === "object" ? bodyMeta : {}),
      ...(typeof descMeta === "object" ? descMeta : {}),
    };
  }, [item.body, item.description]);

  const type = metadata.article_type || metadata.type || "";
  const kicker = item.display || item.title || metadata.kicker || "Featured Article";

  useEffect(() => {
    let cancelled = false;
    const controller = new AbortController();

    const load = async () => {
      try {
        setError("");
        const params = new URLSearchParams();
        if (type) params.set("type", type);
        const query = params.toString();
        const url = `/api/v2/articles/featured.php${query ? `?${query}` : ""}`;
        const res = await fetch(url, { signal: controller.signal, cache: "no-store" });
        const data = await res.json().catch(() => ({}));
        if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load featured article");
        if (!cancelled) setPayload(data.item || null);
      } catch (err) {
        if (!cancelled && err?.name !== "AbortError") {
          setError(err?.message || "Failed to load featured article");
        }
      }
    };

    load();
    return () => {
      cancelled = true;
      controller.abort();
    };
  }, [type, item?.featured_payload]);

  const article = payload?.article || null;
  const hero = payload?.hero || null;
  const heroMobile = payload?.hero_mobile || null;
  const primaryHero = hero || heroMobile;
  const primaryHeroId = Number(primaryHero?.photo_library_id || article?.hero_asset_id || article?.hero_mobile_asset_id || 0);
  const mobileHeroId = Number(heroMobile?.photo_library_id || article?.hero_mobile_asset_id || 0);
  const primaryHeroSrc = photoThumbUrl(primaryHeroId, 520, 72) || primaryHero?.rel_path;
  const mobileHeroSrc = photoThumbUrl(mobileHeroId, 520, 72) || heroMobile?.rel_path;

  const withCacheBuster = (src, updatedAt) => {
    if (!src || !updatedAt) return src;
    const sep = src.includes("?") ? "&" : "?";
    const stamp = Date.parse(updatedAt);
    if (!Number.isFinite(stamp)) return src;
    return `${src}${sep}v=${stamp}`;
  };

  const href = article?.id ? `/articles/${article.id}` : "#";

  if (!article) return null;

  return (
    <Link
      className="featured-article"
      to={href}
      onClick={(e) => {
        if (!article?.id) e.preventDefault();
      }}
    >
      {/* Full-bleed editorial image */}
      <div className="featured-media">
        {primaryHeroSrc ? (
          <picture>
            {mobileHeroSrc ? (
              <source
                media="(max-width: 640px)"
                srcSet={mobileHeroSrc}
              />
            ) : null}
            <img
              src={photoThumbUrl(primaryHeroId, 720, 72) || withCacheBuster(primaryHero?.rel_path, primaryHero?.updated_at)}
              onError={event => fallbackToOriginal(event, primaryHero?.rel_path)}
              srcSet={`${primaryHeroSrc} 520w, ${photoThumbUrl(primaryHeroId, 720, 72) || primaryHeroSrc} 720w`}
              sizes="(max-width: 640px) 90vw, 320px"
              alt={heroMobile?.alt_text || primaryHero?.alt_text || article?.title || ""}
              loading="eager"
              fetchPriority="high"
              decoding="async"
            />
          </picture>
        ) : (
          <div className="featured-article__image-placeholder">No image</div>
        )}
      </div>

      {/* A single readable text overlay over the photograph. */}
      <div className="featured-content">
        <span className="kicker">{kicker}</span>
        <h3>{article?.title || "Featured article"}</h3>
        <p className="dek">{article?.dek || (error ? error : "Tap to read")}</p>
        <div className="cta">Read Article →</div>
      </div>
    </Link>
  );
};

export default FeaturedArticleItem;
