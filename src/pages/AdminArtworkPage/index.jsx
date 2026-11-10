import { useCallback, useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import {
  AdminDetailPane,
  AdminEmptyState,
  AdminListPane,
  AdminMasterDetail,
  AdminObjectList,
} from "@components/AdminLayout";
import { ArtworkInlineEditor } from "@components/ArtworkDialog/ArtworkDialog";
import "./admin-artwork.css";

const ARTWORK_URL = `${API_FOLDER}/v2/admin/artwork/index.php`;
const PHOTOS_URL = `${API_FOLDER}/v2/admin/photo-library/list.php`;

async function getJson(url, signal) {
  const response = await fetch(url, { credentials: "include", signal });
  const data = await response.json().catch(() => ({}));
  if (!response.ok || !data.ok) throw new Error(data.error || `Request failed (${response.status})`);
  return data;
}

function toImageUrl(photo) {
  const path = photo?.image_url || photo?.raw_rel_path || photo?.rel_path || "";
  if (!path) return "";
  if (/^https?:\/\//i.test(path)) return path;
  return path.startsWith("/") ? path : `/${path}`;
}

/** Existing ARTWORK records only; do not duplicate metadata in PHOTOS. */
export default function AdminArtworkPage() {
  const [artworks, setArtworks] = useState([]);
  const [photos, setPhotos] = useState({});
  const [selectedId, setSelectedId] = useState(null);
  const [search, setSearch] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [revision, setRevision] = useState(0);

  useEffect(() => {
    const controller = new AbortController();
    async function load() {
      setLoading(true);
      setError("");
      try {
        // Artwork's existing read interface supplies its records. PHOTOS owns image paths.
        const { items = [] } = await getJson(`${ARTWORK_URL}?action=list&limit=500`, controller.signal);
        if (controller.signal.aborted) return;
        const rows = Array.isArray(items) ? items : [];
        setArtworks(rows);
        setSelectedId((current) => rows.some((a) => String(a.artwork_id) === String(current))
          ? current : (rows[0]?.artwork_id ?? null));
        const ids = rows.map((item) => Number(item.photo_library_id)).filter((id) => Number.isInteger(id) && id > 0);
        if (!ids.length) { setPhotos({}); return; }
        // Match existing Photo Library 'photo_library_ids' lookup. Chunk to bound URL length.
        const batches = [];
        for (let i = 0; i < ids.length; i += 60) batches.push(ids.slice(i, i + 60));
        const responses = await Promise.all(batches.map(async (batch) => {
          const params = new URLSearchParams({ photo_library_ids: batch.join(","), include_inactive: "1", limit: "200" });
          const data = await getJson(`${PHOTOS_URL}?${params}`, controller.signal);
          return Array.isArray(data.items) ? data.items : [];
        }));
        if (controller.signal.aborted) return;
        setPhotos(Object.fromEntries(responses.flat().map((photo) => [String(photo.photo_library_id), photo])));
      } catch (failure) {
        if (!controller.signal.aborted) setError(failure.message || "Failed to load artwork library");
      } finally {
        if (!controller.signal.aborted) setLoading(false);
      }
    }
    load();
    return () => controller.abort();
  }, [revision]);

  const filtered = useMemo(() => {
    const query = search.trim().toLowerCase();
    return query ? artworks.filter((art) =>
      [art.display_title, art.official_title, art.artist].some((value) => String(value || "").toLowerCase().includes(query))) : artworks;
  }, [artworks, search]);

  const selected = artworks.find((art) => String(art.artwork_id) === String(selectedId)) || null;
  const selectedPhoto = selected ? photos[String(selected.photo_library_id)] : null;
  const onSaved = useCallback(() => setRevision((value) => value + 1), []);
  const onDeleted = useCallback(() => setRevision((value) => value + 1), []);

  return (
    <div className="admin-artwork-page">
      <AdminMasterDetail
        selectedId={selectedId}
        storageKey="admin-artwork-list-width"
        defaultListWidth={320}
        list={
          <AdminListPane title="Artworks" searchValue={search} onSearchChange={setSearch} searchPlaceholder="Search artworks…">
            {loading && <p role="status" className="admin-artwork-page__notice">Loading artworks…</p>}
            {error && <p role="alert" className="admin-artwork-page__error">{error}</p>}
            {!loading && !error && filtered.length === 0 && <AdminEmptyState title="No artworks found" />}
            <AdminObjectList ariaLabel="Artworks">
              {filtered.map((art) => {
                const image = toImageUrl(photos[String(art.photo_library_id)]);
                const title = art.display_title || art.official_title || `Artwork #${art.artwork_id}`;
                return (
                  <div role="listitem" key={art.artwork_id}>
                    <button type="button" className={`admin-artwork-page__item${String(selectedId) === String(art.artwork_id) ? " is-selected" : ""}`}
                      data-admin-object-id={art.artwork_id}
                      aria-current={String(selectedId) === String(art.artwork_id) ? "true" : undefined}
                      onClick={() => setSelectedId(art.artwork_id)}>
                      {image ? <img src={image} alt="" loading="lazy" /> : <span className="admin-artwork-page__placeholder" aria-hidden="true" />}
                      <span>{title}</span>
                    </button>
                  </div>
                );
              })}
            </AdminObjectList>
          </AdminListPane>
        }
        detail={
          <AdminDetailPane title={selected ? (selected.display_title || selected.official_title || "Artwork") : "Artwork"}
            ariaLabel="Artwork editor"
            actions={selected ? <a href={`/inspired-by/${selected.artwork_id}`} target="_blank" rel="noopener noreferrer">View public page</a> : null}>
            {!selected ? <AdminEmptyState title="Select an artwork" message="Choose an artwork from the list to edit its metadata." /> : (
              <ArtworkInlineEditor
                key={selected.photo_library_id}
                photoLibraryId={selected.photo_library_id}
                imageUrl={toImageUrl(selectedPhoto)}
                onSaved={onSaved}
                onDeleted={onDeleted}
              />
            )}
          </AdminDetailPane>
        }
      />
    </div>
  );
}
