import { useEffect, useMemo, useState } from "react";
import ModalDialog from "@components/ModalDialog";
import { API_FOLDER } from "@helpers/config";
import {
  LINK_ASSET_TYPE_OPTIONS,
  buildLinkAssetLabel,
  buildLinkAssetMeta,
  buildLinkAssetUrl,
  getLinkAssetId,
  getLinkAssetKey,
  normalizeLinkAssetItems,
} from "@helpers/linkAssets";
import "./insert-link-modal.css";

const PLAYLIST_INSTANCES_URL = `${API_FOLDER}/v2/admin/playlist-instances/list.php`;
const PLAYLIST_INSTANCE_SETS_URL = `${API_FOLDER}/v2/admin/playlist-instance-sets/list.php`;
const SAVED_PALETTES_URL = `${API_FOLDER}/v2/admin/saved-palettes.php`;
const WATCH_CONFIG_URL = `${API_FOLDER}/v2/admin/watch-config/get.php`;

const DEFAULT_ASSET_TYPE = "playlist_instance";

function normalizeSourceTag(value) {
  return String(value || "")
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9_-]+/g, "")
    .slice(0, 100);
}

function splitTrackedUrl(rawUrl) {
  const value = String(rawUrl || "").trim();
  if (!value || typeof window === "undefined") {
    return { cleanUrl: value, sourceTag: "" };
  }

  try {
    const parsed = new URL(value, window.location.origin);
    const sourceTag = normalizeSourceTag(parsed.searchParams.get("src") || "");
    if (sourceTag) {
      parsed.searchParams.delete("src");
    }
    return {
      cleanUrl: parsed.toString(),
      sourceTag,
    };
  } catch {
    return { cleanUrl: value, sourceTag: "" };
  }
}

function buildTrackedUrl(rawUrl, sourceTag) {
  const cleanUrl = String(rawUrl || "").trim();
  const normalizedSource = normalizeSourceTag(sourceTag);
  if (!cleanUrl || typeof window === "undefined") return cleanUrl;

  try {
    const parsed = new URL(cleanUrl, window.location.origin);
    if (normalizedSource) {
      parsed.searchParams.set("src", normalizedSource);
    } else {
      parsed.searchParams.delete("src");
    }
    return parsed.toString();
  } catch {
    return cleanUrl;
  }
}

function getAssetListUrl(assetType) {
  if (assetType === "saved_palette") return SAVED_PALETTES_URL;
  if (assetType === "playlist_instance_set") return PLAYLIST_INSTANCE_SETS_URL;
  if (assetType === "watch_page") return WATCH_CONFIG_URL;
  return PLAYLIST_INSTANCES_URL;
}

function buildPickerAssetTitle(assetType, item) {
  if (!item) return "";
  if (assetType === "playlist_instance") {
    return item.instance_name || item.display_title || `Playlist Instance #${item.playlist_instance_id}`;
  }
  return buildLinkAssetLabel(assetType, item);
}

function buildPickerAssetMeta(assetType, item) {
  if (!item) return "";
  if (assetType === "playlist_instance") {
    const parts = [];
    if (item.display_title && item.display_title !== item.instance_name) parts.push(item.display_title);
    if (item.playlist_slug) parts.push(item.playlist_slug);
    if (item.playlist_instance_id) parts.push(`#${item.playlist_instance_id}`);
    if (item.audience && item.audience !== "any") parts.push(item.audience);
    if (item.instance_notes) parts.push(item.instance_notes);
    return parts.join(" · ");
  }
  return buildLinkAssetMeta(assetType, item);
}

export default function InsertLinkModal({
  open,
  onClose,
  onInsert,
  initialAssetType = DEFAULT_ASSET_TYPE,
  initialText = "",
  initialUrl = "",
  initialSourceTag = "",
  title = "Insert Link",
  subtitle = "Pick an internal asset, confirm the URL, and choose the visible link text.",
}) {
  const [assetType, setAssetType] = useState(initialAssetType);
  const [query, setQuery] = useState("");
  const [items, setItems] = useState([]);
  const [selectedKey, setSelectedKey] = useState("");
  const [url, setUrl] = useState(initialUrl);
  const [sourceTag, setSourceTag] = useState("");
  const [text, setText] = useState(initialText);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    if (!open) return;
    setAssetType(initialAssetType || DEFAULT_ASSET_TYPE);
    setQuery("");
    setItems([]);
    setSelectedKey("");
    const nextUrl = splitTrackedUrl(initialUrl || "");
    setUrl(nextUrl.cleanUrl);
    setSourceTag(nextUrl.sourceTag || normalizeSourceTag(initialSourceTag));
    setText(initialText || "");
    setError("");
  }, [open, initialAssetType, initialText, initialUrl, initialSourceTag]);

  useEffect(() => {
    if (!open) return;
    let cancelled = false;
    const controller = new AbortController();

    async function loadItems() {
      setLoading(true);
      setError("");
      try {
        const params = new URLSearchParams();
        if (assetType !== "watch_page" && query.trim()) params.set("q", query.trim());
        if (assetType !== "watch_page") params.set("limit", "50");
        params.set("_", String(Date.now()));

        const res = await fetch(`${getAssetListUrl(assetType)}?${params.toString()}`, {
          credentials: "include",
          signal: controller.signal,
        });
        const data = await res.json();
        if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load assets");
        if (cancelled) return;

        const rows = assetType === "watch_page" ? [data.item].filter(Boolean) : data.items;
        setItems(normalizeLinkAssetItems(assetType, rows));
      } catch (err) {
        if (err?.name === "AbortError" || cancelled) return;
        setItems([]);
        setError(err?.message || "Failed to load assets");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }

    void loadItems();
    return () => {
      cancelled = true;
      controller.abort();
    };
  }, [assetType, open, query]);

  const sortedItems = useMemo(() => {
    return [...items].sort((a, b) =>
      buildLinkAssetLabel(assetType, a).localeCompare(buildLinkAssetLabel(assetType, b), undefined, {
        sensitivity: "base",
        numeric: true,
      })
    );
  }, [assetType, items]);

  const selectedAsset = useMemo(
    () => sortedItems.find((item) => getLinkAssetKey(assetType, item) === selectedKey) || null,
    [assetType, selectedKey, sortedItems]
  );

  useEffect(() => {
    if (!open) return;
    if (!sortedItems.length) {
      setSelectedKey("");
      if (!initialUrl) setUrl("");
      return;
    }
    const hasCurrent = sortedItems.some((item) => getLinkAssetKey(assetType, item) === selectedKey);
    const nextAsset = hasCurrent ? selectedAsset : sortedItems[0];
    if (!nextAsset) return;
    setSelectedKey(getLinkAssetKey(assetType, nextAsset));
    if (!hasCurrent) {
      const nextUrl = splitTrackedUrl(buildLinkAssetUrl(assetType, nextAsset));
      setUrl(nextUrl.cleanUrl);
      setSourceTag((prev) => (prev ? prev : nextUrl.sourceTag));
      setText((prev) => (prev.trim() ? prev : buildLinkAssetLabel(assetType, nextAsset)));
    }
  }, [assetType, initialUrl, open, selectedAsset, selectedKey, sortedItems]);

  const canInsert = Boolean(url.trim());

  function handleAssetTypeChange(nextType) {
    setAssetType(nextType);
    setSelectedKey("");
    setItems([]);
    setQuery("");
    setUrl("");
    setSourceTag("");
    setText("");
    setError("");
  }

  function handleAssetSelect(nextKey) {
    const nextAsset = sortedItems.find((item) => getLinkAssetKey(assetType, item) === nextKey) || null;
    setSelectedKey(nextKey);
    const nextUrl = splitTrackedUrl(nextAsset ? buildLinkAssetUrl(assetType, nextAsset) : "");
    setUrl(nextUrl.cleanUrl);
    setSourceTag((prev) => (prev ? prev : nextUrl.sourceTag));
    setText(nextAsset ? buildLinkAssetLabel(assetType, nextAsset) : "");
  }

  function handleInsert() {
    if (!canInsert) return;
    const finalUrl = buildTrackedUrl(url, sourceTag);
    onInsert?.({
      assetType,
      assetId: selectedAsset ? getLinkAssetId(assetType, selectedAsset) : null,
      url: finalUrl,
      source: normalizeSourceTag(sourceTag) || "",
      text: text.trim(),
      label: selectedAsset ? buildLinkAssetLabel(assetType, selectedAsset) : "",
      meta: selectedAsset ? buildLinkAssetMeta(assetType, selectedAsset) : "",
      item: selectedAsset,
    });
  }

  return (
    <ModalDialog open={open} title={title} subtitle={subtitle} onClose={onClose} width="860px">
      <div className="insert-link-modal">
        <div className="insert-link-modal__actions insert-link-modal__actions--sticky">
          <button type="button" className="insert-link-modal__btn" onClick={onClose}>
            Cancel
          </button>
          <button
            type="button"
            className="insert-link-modal__btn insert-link-modal__btn--primary"
            onClick={handleInsert}
            disabled={!canInsert}
          >
            Insert
          </button>
        </div>

        <div className="insert-link-modal__grid">
          <div className="insert-link-modal__picker">
            <div className="insert-link-modal__field">
              <label htmlFor="insert-link-asset-type">Asset Type</label>
              <select
                id="insert-link-asset-type"
                value={assetType}
                onChange={(e) => handleAssetTypeChange(e.target.value)}
              >
                {LINK_ASSET_TYPE_OPTIONS.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
            </div>

            {assetType !== "watch_page" ? (
              <div className="insert-link-modal__field">
                <label htmlFor="insert-link-search">Search</label>
                <input
                  id="insert-link-search"
                  type="search"
                  value={query}
                  placeholder="Search by title, id, or notes"
                  onChange={(e) => setQuery(e.target.value)}
                />
              </div>
            ) : null}

            <div>
              <div className="insert-link-modal__section-title">Assets</div>
              <div className="insert-link-modal__hint">
                Select one item to auto-fill the URL, then adjust the display text if needed.
              </div>
            </div>

            {loading ? <div className="insert-link-modal__status">Loading assets…</div> : null}
            {error ? <div className="insert-link-modal__status is-error">{error}</div> : null}
            {!loading && !error && !sortedItems.length ? (
              <div className="insert-link-modal__status">No assets found.</div>
            ) : null}

            <div className="insert-link-modal__asset-list">
              {sortedItems.map((item) => {
                const itemKey = getLinkAssetKey(assetType, item);
                return (
                  <button
                    key={itemKey}
                    type="button"
                    className={`insert-link-modal__asset ${itemKey === selectedKey ? "is-active" : ""}`}
                    onClick={() => handleAssetSelect(itemKey)}
                  >
                    <div className="insert-link-modal__asset-title">{buildPickerAssetTitle(assetType, item)}</div>
                    <div className="insert-link-modal__asset-meta">{buildPickerAssetMeta(assetType, item)}</div>
                  </button>
                );
              })}
            </div>
          </div>

          <div className="insert-link-modal__picker">
            <div className="insert-link-modal__field">
              <label htmlFor="insert-link-url">URL</label>
              <input
                id="insert-link-url"
                type="text"
                value={url}
                placeholder="https://..."
                onChange={(e) => {
                  const next = splitTrackedUrl(e.target.value);
                  setUrl(next.cleanUrl);
                  if (next.sourceTag) setSourceTag(next.sourceTag);
                }}
              />
            </div>

            <div className="insert-link-modal__field">
              <label htmlFor="insert-link-source">Source Tag</label>
              <input
                id="insert-link-source"
                type="text"
                value={sourceTag}
                placeholder="email or qr"
                onChange={(e) => setSourceTag(normalizeSourceTag(e.target.value))}
              />
            </div>

            <div className="insert-link-modal__field">
              <label htmlFor="insert-link-text">Link Text</label>
              <input
                id="insert-link-text"
                type="text"
                value={text}
                placeholder="Visible link text"
                onChange={(e) => setText(e.target.value)}
              />
            </div>

            <div className="insert-link-modal__preview">
              <div className="insert-link-modal__section-title">Preview</div>
              <div>{text.trim() || "Your link text will appear here."}</div>
              {url.trim() ? (
                <a
                  className="insert-link-modal__preview-link"
                  href={buildTrackedUrl(url, sourceTag)}
                  target="_blank"
                  rel="noreferrer"
                >
                  {buildTrackedUrl(url, sourceTag)}
                </a>
              ) : (
                <div className="insert-link-modal__hint">Select an asset or paste a URL.</div>
              )}
            </div>
          </div>
        </div>
      </div>
    </ModalDialog>
  );
}
