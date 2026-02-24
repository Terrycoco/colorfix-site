import { useEffect, useMemo, useState } from "react";
import { useParams, useSearchParams } from "react-router-dom";
import { API_FOLDER } from "@helpers/config";
import "./article-page.css";

const GET_URL = `${API_FOLDER}/v2/articles/get.php`;

function formatBody(text = "") {
  if (!text) return null;
  return text.split("\n").map((line, idx) => (
    <p key={`${line}-${idx}`}>{line}</p>
  ));
}

export default function ArticlePage() {
  const { id } = useParams();
  const [searchParams] = useSearchParams();
  const [payload, setPayload] = useState(null);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);

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

  if (loading) {
    return <div className="article-page article-page__state">Loading…</div>;
  }
  if (error) {
    return <div className="article-page article-page__state">{error}</div>;
  }
  if (!article) {
    return <div className="article-page article-page__state">Article not found.</div>;
  }

  return (
    <div className="article-page">
      <div className="article-page__header">
        {hero?.rel_path && (
          <img
            className="article-page__hero"
            src={hero.rel_path}
            alt={hero.alt_text || article.title || ""}
          />
        )}
        <div className="article-page__headline">
          <h1>{article.title}</h1>
          {article.dek && <p className="article-page__dek">{article.dek}</p>}
        </div>
      </div>

      <div className="article-page__content">
        {sections.map((section) => (
          <div key={section.id} className="article-page__section">
            {section.heading && <h2>{section.heading}</h2>}

            {section.kind === "text" && (
              <div className="article-page__text">{formatBody(section.body || "")}</div>
            )}

            {section.kind === "list" && (
              <ul className="article-page__list">
                {(section.body || "")
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
                  src={section.asset.rel_path}
                  alt={section.asset.alt_text || section.heading || ""}
                />
                {section.asset.title && <figcaption>{section.asset.title}</figcaption>}
              </figure>
            )}

            {section.kind === "embed" && section.body && (
              <div
                className="article-page__embed"
                dangerouslySetInnerHTML={{ __html: section.body }}
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
    </div>
  );
}
