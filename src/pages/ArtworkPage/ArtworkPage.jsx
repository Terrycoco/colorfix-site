import { useEffect, useState } from "react";
import { useParams } from "react-router-dom";
import { API_FOLDER } from "@helpers/config";
import { photoThumbUrl } from "@helpers/imageThumb";
import "./ArtworkPage.css";

const DISCLAIMER = "These paint colors were selected by comparing physical paint samples with a photographic reproduction of the artwork. Photography, printing, lighting, and digital displays can all affect how colors appear. My selections are intended to capture the overall impression of each color family as it appears in the painting, rather than provide exact color matches.";

function validHex(value) {
  const hex = String(value || "").trim().replace(/^#/, "");
  return /^[0-9a-f]{6}$/i.test(hex) ? `#${hex}` : null;
}

function showOriginalIfThumbnailFails(event, originalUrl) {
  if (event.currentTarget.getAttribute("src") !== originalUrl) {
    event.currentTarget.src = originalUrl;
  }
}

export default function ArtworkPage({ artworkId: artworkIdProp, photoLibraryId }) {
  const route = useParams();
  const artworkId = artworkIdProp ?? route.artworkId ?? route.id;
  const [page, setPage] = useState(null);
  const [status, setStatus] = useState("loading");
  const [error, setError] = useState("");
  const [enlargedImage, setEnlargedImage] = useState(null);

  useEffect(() => {
    const controller = new AbortController();
    const params = new URLSearchParams();
    if (photoLibraryId) params.set("photo_library_id", photoLibraryId);
    else if (artworkId) params.set("artwork_id", artworkId);
    else { setStatus("error"); setError("Artwork ID is missing."); return undefined; }
    setStatus("loading"); setError("");
    fetch(`${API_FOLDER}/v2/artwork/page.php?${params}`, {
      method: "GET", cache: "no-store", signal: controller.signal,
    }).then(async (response) => {
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.ok) throw new Error(data.error || `Artwork unavailable (${response.status})`);
      return data.data;
    }).then((data) => {
      if (!controller.signal.aborted) { setPage(data); setStatus("ready"); }
    }).catch((err) => {
      if (!controller.signal.aborted) { setStatus("error"); setError(err.message); }
    });
    return () => controller.abort();
  }, [artworkId, photoLibraryId]);

  useEffect(() => {
    if (!enlargedImage) return undefined;
    const onKeyDown = (event) => {
      if (event.key === "Escape") setEnlargedImage(null);
    };
    window.addEventListener("keydown", onKeyDown);
    return () => window.removeEventListener("keydown", onKeyDown);
  }, [enlargedImage]);

  if (status === "loading") return <main className="artwork-page" aria-busy="true"><p>Loading artwork…</p></main>;
  if (status === "error") return <main className="artwork-page" role="alert"><h1>Artwork unavailable</h1><p>{error}</p></main>;

  const { artwork, photo, paint_approximations: palette, inspired_designs: designs } = page;
  const title = artwork.display_title || artwork.official_title || photo.title || "Artwork";
  return (
    <main className="artwork-page">
      <header className="artwork-page__header">
        <p className="artwork-page__eyebrow">Color Repertoire · Artwork Study</p>
        <h1>{title}</h1>
        {artwork.artist && <p className="artwork-page__artist">{artwork.artist}</p>}
      </header>

      <section className="artwork-page__hero" aria-label="Original artwork">
        <div className="artwork-page__figure">
          {photo.image_url && (
            <button type="button" className="artwork-page__image-trigger" onClick={() => setEnlargedImage({ src: photo.image_url, alt: photo.alt_text || title })} aria-label={`Enlarge ${title}`}>
              <img
                src={photoThumbUrl(photo.photo_library_id, 960, 80, photo.image_url) || photo.image_url}
                alt={photo.alt_text || title}
                decoding="async"
                fetchPriority="high"
                onError={(event) => showOriginalIfThumbnailFails(event, photo.image_url)}
              />
            </button>
          )}
        </div>
        <div className="artwork-page__details">
          <h2>About the artwork</h2>
          <dl>
            {artwork.official_title && artwork.official_title !== title && <><dt>Official title</dt><dd>{artwork.official_title}</dd></>}
            {(artwork.year || artwork.circa) && <><dt>Date</dt><dd>{artwork.circa ? "c. " : ""}{artwork.year || "Date unknown"}</dd></>}
            {artwork.medium && <><dt>Medium</dt><dd>{artwork.medium}</dd></>}
            {artwork.museum && <><dt>Collection</dt><dd>{artwork.museum}</dd></>}
          </dl>
          {artwork.source_url && /^https:\/\//i.test(artwork.source_url) && (
            <p><a className="artwork-page__source-link" href={artwork.source_url} target="_blank" rel="noopener noreferrer">View museum/source record</a></p>
          )}
          {artwork.historical_notes && <p className="artwork-page__notes">{artwork.historical_notes}</p>}
        </div>
      </section>

      {artwork.color_analysis && (
        <section className="artwork-page__section">
          <h2>Color Analysis</h2>
          <p className="artwork-page__prose">{artwork.color_analysis}</p>
        </section>
      )}

      <section className="artwork-page__section">
        <h2>Paint Approximations*</h2>
        {palette?.members?.length ? (
          <div className="artwork-page__swatches">
            {palette.members.map((color, index) => (
              <div className="artwork-page__swatch" key={`${color.color_id || 'unknown'}-${index}`}>
                <div className="artwork-page__swatch-color" style={{ backgroundColor: validHex(color.hex) || "#ddd" }} />
                <strong>{color.name || "Unnamed paint"}</strong>
                <span>{[color.brand, color.code].filter(Boolean).join(" · ")}</span>
                {color.role && <small>{color.role}</small>}
              </div>
            ))}
          </div>
        ) : <p>No paint approximations have been attached to this artwork yet.</p>}
        <p className="artwork-page__disclaimer">*{DISCLAIMER}</p>
      </section>

      <section className="artwork-page__section">
        <h2>Designs Inspired by {title}</h2>
        {designs.length ? (
          <div className="artwork-page__designs">
            {designs.map((design) => (
              <figure className="artwork-page__design" key={design.photo_library_id}>
                <button type="button" className="artwork-page__image-trigger" onClick={() => setEnlargedImage({ src: design.image_url, alt: design.alt_text || design.title || "Inspired design" })} aria-label={`Enlarge ${design.title || "inspired design"}`}>
                  <img
                    src={photoThumbUrl(design.photo_library_id, 480, 72, design.image_url) || design.image_url}
                    alt={design.alt_text || design.title || "Inspired design"}
                    loading="lazy"
                    decoding="async"
                    onError={(event) => showOriginalIfThumbnailFails(event, design.image_url)}
                  />
                </button>
                <figcaption><strong>{design.title || `Design #${design.photo_library_id}`}</strong></figcaption>
              </figure>
            ))}
          </div>
        ) : <p>Inspired designs will appear here as they're published.</p>}
      </section>
      {enlargedImage && (
        <div
          className="artwork-page__lightbox"
          role="dialog"
          aria-modal="true"
          aria-label="Enlarged photograph. Click to close."
          onClick={() => setEnlargedImage(null)}
        >
          <button
            type="button"
            className="artwork-page__lightbox-close"
            onClick={() => setEnlargedImage(null)}
            aria-label="Close enlarged photograph"
          >×</button>
          <img src={enlargedImage.src} alt={enlargedImage.alt} />
        </div>
      )}
    </main>
  );
}
