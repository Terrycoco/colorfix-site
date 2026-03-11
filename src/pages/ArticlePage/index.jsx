import { useEffect, useMemo, useState } from "react";
import { Link, useNavigate, useParams, useSearchParams } from "react-router-dom";
import { API_FOLDER } from "@helpers/config";
import "./article-page.css";

const GET_URL = `${API_FOLDER}/v2/articles/get.php`;

const emdashify = (value) => String(value ?? "").replace(/--/g, "—");

function formatBody(text = "") {
  if (!text) return null;
  return String(text)
    .split("\n")
    .map((line, idx) => (
      <p key={`${line}-${idx}`}>{emdashify(line)}</p>
    ));
}

export default function ArticlePage() {
  const { id } = useParams();
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const [payload, setPayload] = useState(null);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);
  const playlistInstanceId = searchParams.get("playlist_instance_id") ?? "";
  const playlistTitle = searchParams.get("playlist_title") ?? "";
  const returnToParam = searchParams.get("return_to") ?? "";
  const playlistUrl = returnToParam || (playlistInstanceId ? `/playlist/${playlistInstanceId}` : "");

  useEffect(() => {
    let active = true;
    async function loadArticle() {
      setLoading(true);
      setError("");
      try {
        const adminFlag = searchParams.get("admin") === "1" ? "&admin=1" : "";
        const res = await fetch(`${GET_URL}?id=${id}${adminFlag}`, { credentials: "include" });
        const text = await res.text();
        if (!res.ok) {
          throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
        }
        const data = JSON.parse(text);
        if (!data?.ok) throw new Error(data?.error || "Failed to load article");
        if (!active) return;
        setPayload(data.item || null);
      } catch (err) {
        if (!active) return;
        setError(err?.message || "Failed to load article");
      } finally {
        if (active) setLoading(false);
      }
    }
    if (id) loadArticle();
    return () => {
      active = false;
    };
  }, [id, searchParams]);

  const article = payload?.article;
  const hero = payload?.hero;
  const sections = useMemo(() => payload?.sections || [], [payload]);
  const ctaList = useMemo(() => {
    const raw = payload?.ctas || [];
    if (!Array.isArray(raw)) return [];
    return raw.map((cta) => {
      const parsed = (() => {
        if (!cta?.params) return {};
        if (typeof cta.params === "object") return cta.params;
        try {
          const out = JSON.parse(cta.params);
          return out && typeof out === "object" ? out : {};
        } catch {
          return {};
        }
      })();
      const key =
        (cta?.action_key || cta?.type_action_key || cta?.key || cta?.action || "").toString();
      return { ...cta, params: parsed, key };
    });
  }, [payload]);
  const playlistCtas = useMemo(
    () => ctaList.filter((cta) => String(cta.key || "").toLowerCase() === "playlist_link"),
    [ctaList]
  );
  const otherCtas = useMemo(
    () => ctaList.filter((cta) => String(cta.key || "").toLowerCase() !== "playlist_link"),
    [ctaList]
  );
  const hasLibraryCtas = ctaList.length > 0;

  if (loading) {
    return <div className="article-page article-page__state">Loading…</div>;
  }
  if (error) {
    return <div className="article-page article-page__state">{error}</div>;
  }
  if (!article) {
    return <div className="article-page article-page__state">Article not found.</div>;
  }

  const withCacheBuster = (src, updatedAt) => {
    if (!src) return src;
    if (!updatedAt) return src;
    const sep = src.includes("?") ? "&" : "?";
    const stamp = Date.parse(updatedAt);
    if (!Number.isFinite(stamp)) return src;
    return `${src}${sep}v=${stamp}`;
  };

  function handleCtaClick(cta) {
    if (!cta) return;
    const key = (cta?.key || "").toLowerCase();
    const params = cta?.params || {};
    if (key === "playlist_link") {
      const pid = params.playlist_instance_id || params.playlistInstanceId;
      const url = params.url || (pid ? `/playlist/${pid}` : "");
      if (url) {
        if (url.startsWith("/")) navigate(url);
        else window.location.href = url;
      }
      return;
    }
    if (key === "article_link") {
      const aid = params.article_id || params.articleId;
      const url = params.url || (aid ? `/articles/${aid}` : "");
      if (url) {
        if (url.startsWith("/")) navigate(url);
        else window.location.href = url;
      }
      return;
    }
    if (key === "navigate") {
      const url = params.url || "";
      if (!url) return;
      if (url.startsWith("/")) {
        navigate(url);
      } else {
        window.open(url, params.target || "_self", "noopener");
      }
      return;
    }
    if (params.url) {
      if (params.url.startsWith("/")) navigate(params.url);
      else window.location.href = params.url;
    }
  }

  function resolvePlaylistLink(cta) {
    const params = cta?.params || {};
    const pid = params.playlist_instance_id || params.playlistInstanceId;
    return params.url || (pid ? `/playlist/${pid}` : "");
  }

  function resolvePlaylistLabel(cta) {
    const params = cta?.params || {};
    return params.label || cta?.label || "";
  }

  function resolvePlaylistSubtitle(cta) {
    const params = cta?.params || {};
    return params.subtitle || params.dek || "";
  }

  return (
    <div className="article-page">
      <div className="article-page__header">
        {hero?.rel_path && (
          <img
            className="article-page__hero"
            src={withCacheBuster(hero.rel_path, hero.updated_at)}
            alt={hero.alt_text || article.title || ""}
          />
        )}
        <div className="article-page__headline">
          <h1>{emdashify(article.title)}</h1>
          {article.dek && <p className="article-page__dek">{emdashify(article.dek)}</p>}
        </div>
      </div>

      <div className="article-page__content">
        {sections.map((section) => (
          <div key={section.id} className="article-page__section">
            {(() => {
              if (!section.heading) return null;
              const level = String(section.heading_level || "h2").toLowerCase();
              const tag = ["h1", "h2", "h3", "h4", "h5", "h6"].includes(level) ? level : "h2";
              const Tag = tag;
              const className =
                section.kind === "header"
                  ? `article-page__header article-page__header-${tag}`
                  : `article-page__section-heading article-page__header-${tag}`;
              return <Tag className={className}>{emdashify(section.heading)}</Tag>;
            })()}

            {section.kind === "text" && (
              <div className="article-page__text">{formatBody(section.body || "")}</div>
            )}

            {section.kind === "list" && (
              <ul className="article-page__list">
                {emdashify(section.body || "")
                  .split("\n")
                  .map((line) => line.trim())
                  .filter(Boolean)
                  .map((line, idx) => (
                    <li key={`${line}-${idx}`}>{line}</li>
                  ))}
              </ul>
            )}

            {section.kind === "image" && section.asset?.rel_path && (
              <figure className="article-page__figure">
                <img
                  src={withCacheBuster(section.asset.rel_path, section.asset.updated_at)}
                  alt={section.asset.alt_text || section.heading || ""}
                />
                {section.caption ? (
                  <figcaption>{emdashify(section.caption)}</figcaption>
                ) : (
                  section.asset.title && <figcaption>{emdashify(section.asset.title)}</figcaption>
                )}
              </figure>
            )}
            {section.kind === "image" && section.body && (
              <div className="article-page__text">{formatBody(section.body)}</div>
            )}

            {section.kind === "embed" && section.body && (
              <div
                className="article-page__embed"
                dangerouslySetInnerHTML={{ __html: emdashify(section.body) }}
              />
            )}

            {section.kind === "cta" && section.body && (
              <div className="article-page__cta">
                {formatBody(section.body)}
              </div>
            )}

            {section.kind === "palette_link" && section.palette_id && (
              <div className="article-page__cta">
                <span>Palette #{section.palette_id}</span>
              </div>
            )}
          </div>
        ))}
      </div>

      {playlistCtas.length > 0 && (
        <div className="article-cta-group">
          {playlistCtas.map((cta) => {
            const href = resolvePlaylistLink(cta);
            if (!href) return null;
            return (
              <a key={cta.cta_id} className="article-media-cta" href={href} onClick={(e) => {
                if (!href.startsWith("/")) return;
                e.preventDefault();
                handleCtaClick(cta);
              }}>
                <div className="article-media-cta__media" aria-hidden="true">
                  <span className="article-media-cta__play" />
                </div>
                <div className="article-media-cta__content">
                  <div className="article-media-cta__label">{emdashify(resolvePlaylistLabel(cta))}</div>
                  {resolvePlaylistSubtitle(cta) && (
                    <p className="article-media-cta__subtitle">
                      {emdashify(resolvePlaylistSubtitle(cta))}
                    </p>
                  )}
                </div>
              </a>
            );
          })}
        </div>
      )}

      {otherCtas.length > 0 && (
        <div className="article-page__cta-list">
          {otherCtas.map((cta) => (
            <button
              key={cta.cta_id}
              type="button"
              className="article-page__cta-button"
              onClick={() => handleCtaClick(cta)}
            >
              {cta.label}
            </button>
          ))}
        </div>
      )}

      {!hasLibraryCtas && playlistUrl && (
        <div className="article-page__end-cta">
          {playlistUrl.startsWith("/") ? (
            <Link className="article-page__end-cta-link" to={playlistUrl}>
              <div className="article-page__end-cta-title">
                Back to playlist{playlistTitle ? `: ${emdashify(playlistTitle)}` : ""}
              </div>
              <div className="article-page__end-cta-subtitle">
                Continue exploring the colors from this story.
              </div>
            </Link>
          ) : (
            <a className="article-page__end-cta-link" href={playlistUrl}>
              <div className="article-page__end-cta-title">
                Back to playlist{playlistTitle ? `: ${emdashify(playlistTitle)}` : ""}
              </div>
              <div className="article-page__end-cta-subtitle">
                Continue exploring the colors from this story.
              </div>
            </a>
          )}
        </div>
      )}
    </div>
  );
}
