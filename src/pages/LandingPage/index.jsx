import { useEffect, useMemo, useState } from "react";
import { Link, useParams, useSearchParams } from "react-router-dom";
import { API_FOLDER } from "@helpers/config";
import colorfixLogoUrl from "../../assets/brand/colorfix_lightbg.png";
import "./landing-page.css";

const GET_URL = `${API_FOLDER}/v2/landing-pages/get.php`;

export default function LandingPage() {
  const { slug } = useParams();
  const [searchParams] = useSearchParams();
  const [page, setPage] = useState(null);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);
  const src = searchParams.get("src") || "";

  const playerPath = useMemo(() => {
    if (!page?.player_path) return "";
    return page.player_path;
  }, [page]);

  useEffect(() => {
    let cancelled = false;
    async function loadPage() {
      setLoading(true);
      setError("");
      try {
        const params = new URLSearchParams({ slug: slug || "" });
        if (src) params.set("src", src);
        const res = await fetch(`${GET_URL}?${params.toString()}`, { credentials: "include" });
        const data = await res.json();
        if (!res.ok || !data?.ok) {
          throw new Error(data?.error || "Landing page not found");
        }
        if (!cancelled) setPage(data.item || null);
      } catch (err) {
        if (!cancelled) setError(err?.message || "Landing page not found");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    loadPage();
    return () => {
      cancelled = true;
    };
  }, [slug, src]);

  useEffect(() => {
    if (!page) return;
    if (page.page_type === "redirect" && page.redirect_url) {
      window.location.replace(withSrc(page.redirect_url, src));
      return;
    }
    if (page.page_type === "playlist" && page.player_path) {
      window.location.replace(page.player_path);
    }
  }, [page, src]);

  if (loading) {
    return <main className="landing-page landing-page--quiet">Loading...</main>;
  }

  if (error || !page) {
    return (
      <main className="landing-page landing-page--unavailable">
        <section className="landing-page__unavailable-panel" aria-labelledby="landing-unavailable-title">
          <img className="landing-page__unavailable-logo" src={colorfixLogoUrl} alt="ColorFix" />
          <h1 id="landing-unavailable-title">This playlist isn&rsquo;t available.</h1>
          <p>It may have been moved or taken offline.</p>
          <div className="landing-page__unavailable-actions">
            <Link to="/picker?psi=11">Browse Playlists</Link>
            <Link to="/">Go to ColorFix Home</Link>
          </div>
        </section>
      </main>
    );
  }

  return (
    <main className="landing-page">
      <article className="landing-page__body">
        {page.featured_pin_url ? (
          <img className="landing-page__image" src={page.featured_pin_url} alt={page.featured_pin_title || page.title} />
        ) : null}
        <div className="landing-page__content">
          <p className="landing-page__type">{page.page_type}</p>
          <h1>{page.search_title || page.title}</h1>
          {page.description ? <p>{page.description}</p> : null}
          {playerPath ? (
            <Link className="landing-page__button" to={playerPath}>
              Watch Playlist
            </Link>
          ) : null}
        </div>
      </article>
    </main>
  );
}

function withSrc(url, src) {
  const cleanSrc = String(src || "").trim();
  if (!cleanSrc) return url;
  try {
    const parsed = new URL(url, window.location.origin);
    if (!parsed.searchParams.has("src")) {
      parsed.searchParams.set("src", cleanSrc);
    }
    if (url.startsWith("/")) {
      return `${parsed.pathname}${parsed.search}${parsed.hash}`;
    }
    return parsed.toString();
  } catch {
    return url;
  }
}
