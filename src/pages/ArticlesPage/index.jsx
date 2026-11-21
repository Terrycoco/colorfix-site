import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { API_FOLDER } from "@helpers/config";
import DissolvingImages from "@components/DissolvingImages";
import "./ArticlesPage.css";

const PAGE_SIZE = 24;
const LIST_URL = `${API_FOLDER}/v2/articles/list.php`;

function publicationDate(value) {
  if (!value) return "";
  const date = new Date(String(value).replace(" ", "T") + (/[zZ]|[+-]\d{2}:?\d{2}$/.test(value) ? "" : "Z"));
  return Number.isNaN(date.getTime()) ? "" : new Intl.DateTimeFormat("en-US", { month: "long", year: "numeric", timeZone: "UTC" }).format(date);
}

export default function ArticlesPage() {
  const [items, setItems] = useState([]);
  const [featured, setFeatured] = useState(null);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [more, setMore] = useState(false);

  useEffect(() => {
    const controller = new AbortController();
    async function load() {
      try {
        const response = await fetch(`${LIST_URL}?limit=${PAGE_SIZE}&offset=0`, { signal: controller.signal, cache: "no-store" });
        if (!response.ok) throw new Error("Could not load articles.");
        const json = await response.json();
        if (!json?.ok || !Array.isArray(json.items)) throw new Error("Could not load articles.");
        setItems(json.items);
        setFeatured(json.featured || null);
        setMore(json.items.length === PAGE_SIZE);
      } catch (err) {
        if (err.name !== "AbortError") setError(err.message || "Could not load articles.");
      } finally {
        if (!controller.signal.aborted) setLoading(false);
      }
    }
    load();
    return () => controller.abort();
  }, []);

  async function loadMore() {
    if (loadingMore) return;
    setLoadingMore(true);
    setError("");
    try {
      const response = await fetch(`${LIST_URL}?limit=${PAGE_SIZE}&offset=${items.length}`, { cache: "no-store" });
      if (!response.ok) throw new Error("Could not load more articles.");
      const json = await response.json();
      if (!json?.ok || !Array.isArray(json.items)) throw new Error("Could not load more articles.");
      setItems(current => [...current, ...json.items.filter(item => !current.some(existing => existing.id === item.id))]);
      setMore(json.items.length === PAGE_SIZE);
    } catch (err) {
      setError(err.message || "Could not load more articles.");
    } finally {
      setLoadingMore(false);
    }
  }

  const regularArticles = items.filter(article => article.id !== featured?.id);

  return (
    <main className="cf-articles-index">
      <div className="cf-articles-index__inner">
        <header className="cf-articles-index__intro">
          <p className="cf-articles-index__eyebrow">COLORFIX BY TERRY / ARTICLES</p>
          <p>Color studies, design observations, and the reasoning behind the choices.</p>
        </header>
        {loading ? <p className="cf-articles-index__status" role="status">Loading articles…</p> : null}
        {error ? <p className="cf-articles-index__status" role="alert">{error}</p> : null}
        {!loading && items.length === 0 && !error ? <p className="cf-articles-index__status">Articles are coming soon.</p> : null}

        {featured ? (
          <article className="cf-articles-index__lead">
            <Link className="cf-articles-index__lead-image" to={featured.url} aria-label={`Read ${featured.title}`}>
              {featured.images?.length ? (
                <DissolvingImages images={featured.images} intervalMs={6000} fadeMs={1100} />
              ) : null}
            </Link>
            <div className="cf-articles-index__lead-copy">
              <p className="cf-articles-index__eyebrow">FEATURED ARTICLE</p>
              <h1><Link to={featured.url}>{featured.title}</Link></h1>
              {featured.dek ? <p className="cf-articles-index__lead-dek">{featured.dek}</p> : null}
              <Link className="cf-articles-index__read" to={featured.url}>Read Article <span aria-hidden="true">→</span></Link>
            </div>
          </article>
        ) : null}

        {regularArticles.length > 0 ? (
          <section className="cf-articles-index__rest" aria-label="More articles">
            <div className="cf-articles-index__section-heading"><h2>More to explore</h2><span /></div>
            <div className="cf-articles-index__grid">
              {regularArticles.map(article => (
                <Link className="cf-articles-index__card" to={article.url} key={article.id}>
                  <div className="cf-articles-index__image">
                    {article.thumbnail_url ? <img src={article.thumbnail_url} alt="" loading="lazy" decoding="async" /> : null}
                  </div>
                  <div className="cf-articles-index__meta">
                    {article.kicker ? <p className="cf-articles-index__card-kicker">{article.kicker}</p> : null}
                    <h3>{article.title}</h3>
                    {article.dek ? <p className="cf-articles-index__card-dek">{article.dek}</p> : null}
                    {publicationDate(article.published_at) ? <time dateTime={String(article.published_at).slice(0, 10)}>{publicationDate(article.published_at)}</time> : null}
                  </div>
                </Link>
              ))}
            </div>
          </section>
        ) : null}
        {!loading && more ? <div className="cf-articles-index__more"><button type="button" disabled={loadingMore} onClick={loadMore}>{loadingMore ? "Loading…" : "More articles"}</button></div> : null}
      </div>
    </main>
  );
}
