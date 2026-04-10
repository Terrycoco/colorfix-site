import { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
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
        params.set("_", String(Date.now()));
        const url = `/api/v2/articles/featured.php?${params.toString()}`;
        const res = await fetch(url, { signal: controller.signal });
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
  }, [type]);

  const article = payload?.article || null;
  const hero = payload?.hero || null;
  const heroMobile = payload?.hero_mobile || null;
  const primaryHero = hero || heroMobile;

  const withCacheBuster = (src, updatedAt) => {
    if (!src || !updatedAt) return src;
    const sep = src.includes("?") ? "&" : "?";
    const stamp = Date.parse(updatedAt);
    if (!Number.isFinite(stamp)) return src;
    return `${src}${sep}v=${stamp}`;
  };

  const href = article?.id ? `/articles/${article.id}` : "#";

  return (
    <Link
      className="featured-article"
      to={href}
      onClick={(e) => {
        if (!article?.id) e.preventDefault();
      }}
    >
      {/* Kicker stays above image, magazine style */}
      <div className="kicker">{kicker}</div>

      {/* Full-bleed image */}
      <div className="featured-media">
        {primaryHero?.rel_path ? (
          <picture>
            {heroMobile?.rel_path ? (
              <source
                media="(max-width: 640px)"
                srcSet={withCacheBuster(heroMobile.rel_path, heroMobile.updated_at)}
              />
            ) : null}
            <img
              src={withCacheBuster(primaryHero.rel_path, primaryHero.updated_at)}
              alt={heroMobile?.alt_text || primaryHero.alt_text || article?.title || ""}
              loading="lazy"
            />
          </picture>
        ) : (
          <div className="featured-article__image-placeholder">No image</div>
        )}
      </div>

      {/* Padded text area (THIS is what you were missing) */}
      <div className="featured-content">
        <h3>{article?.title || "Featured article"}</h3>
        <p className="dek">{article?.dek || (error ? error : "Tap to read")}</p>
        <div className="cta">Read Full Article →</div>
      </div>
    </Link>
  );
};

export default FeaturedArticleItem;
