import { useEffect, useMemo, useState, useCallback } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { API_FOLDER } from "@helpers/config";
import PhotoPickerModal from "@components/PhotoPickerModal";
import FuzzySearchColorSelect from "@components/FuzzySearchColorSelect";
import { makePhotoRef, parsePhotoRef } from "@helpers/assetImage";
import fetchColorDetail from "@data/fetchColorDetail";
import "./admin-playlist-editor.css";

const GET_URL = `${API_FOLDER}/v2/admin/playlists/get.php`;
const SAVE_URL = `${API_FOLDER}/v2/admin/playlists/save.php`;
const SAVE_ITEMS_URL = `${API_FOLDER}/v2/admin/playlist-items/save.php`;
const DELETE_URL = `${API_FOLDER}/v2/admin/playlists/delete.php`;
const PLAYLISTS_LIST_URL = `${API_FOLDER}/v2/admin/playlists/list.php`;
const PLAYLIST_INSTANCES_LIST_URL = `${API_FOLDER}/v2/admin/playlist-instances/list.php`;
const SAVED_LIST_URL = `${API_FOLDER}/v2/admin/saved-palettes.php`;
const PHOTO_LIBRARY_LIST_URL = `${API_FOLDER}/v2/admin/photo-library/list.php`;

const emptyPlaylist = {
  playlist_id: null,
  title: "",
  type: "",
  is_active: true,
  is_public: false,
  slug: "",
  headline: "",
  page_title: "",
  meta_description: "",
  dek: "",
  intro_html: "",
  body_html: "",
  hero_image_id: "",
  hero_image_url: "",
  hero_alt: "",
  indexable: true,
  published_at: "",
};

const emptyItem = {
  _clientKey: "",
  playlist_item_id: null,
  ap_id: "",
  palette_hash: "",
  image_url: "",
  photo_library_id: "",
  saved_palette_set_id: "",
  title: "",
  subtitle: "",
  subtitle_2: "",
  body: "",
  item_type: "non-palette",
  layout: "default",
  title_mode: "",
  star: true,
  transition: "",
  duration_ms: "",
  exclude_from_thumbs: false,
  is_share_image: false,
  site: true,
  yt: true,
  is_active: true,
};

const DEFAULT_HUE_WHEEL_CONFIG = {
  items: [
    { hue: 145, label: "Green", color: "#6F8F72", animate: false },
    { hue: 355, label: "Red", color: "#A6403A", animate: true },
  ],
  animated: true,
  showLabels: false,
  showDots: false,
  pulseOnComplete: true,
  caption: "",
  size: 360,
  wheelFadeMs: 420,
  spokeStartRadius: 0,
  spokeEndRadius: 136,
  spokeDelayMs: 420,
  spokeStaggerMs: 260,
  spokeDurationMs: 800,
};

const HUE_WHEEL_BODY_TEMPLATE = serializeHueWheelConfig(DEFAULT_HUE_WHEEL_CONFIG);

const DEFAULT_PLAYLIST_TYPES = ["teaching"];

function slugifyPlaylistValue(value) {
  return String(value || "")
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/-+/g, "-")
    .replace(/^-|-$/g, "");
}

function toDatetimeLocal(value) {
  const text = String(value || "").trim();
  if (!text) return "";
  return text.replace(" ", "T").slice(0, 16);
}

function parseHueWheelBody(rawBody) {
  const raw = String(rawBody || "").trim();
  if (!raw) return { ...DEFAULT_HUE_WHEEL_CONFIG, items: [...DEFAULT_HUE_WHEEL_CONFIG.items] };
  try {
    const parsed = JSON.parse(raw);
    const config = Array.isArray(parsed) ? { items: parsed } : parsed;
    if (!config || typeof config !== "object") {
      return { ...DEFAULT_HUE_WHEEL_CONFIG, items: [...DEFAULT_HUE_WHEEL_CONFIG.items] };
    }
    const items = Array.isArray(config.items) ? config.items : [];
    return {
      ...DEFAULT_HUE_WHEEL_CONFIG,
      ...config,
      items: items.map(normalizeHueWheelItem),
      animated: config.animated !== false,
      showLabels: config.showLabels === true,
      showDots: config.showDots === true,
      pulseOnComplete: config.pulseOnComplete !== false,
    };
  } catch {
    return { ...DEFAULT_HUE_WHEEL_CONFIG, items: [...DEFAULT_HUE_WHEEL_CONFIG.items] };
  }
}

function normalizeHueWheelItem(item = {}) {
  return {
    hue: item.hue ?? "",
    label: item.label ?? "",
    color: item.color || "#111111",
    animate: item.animate !== false,
    delayMs: item.delayMs ?? "",
    durationMs: item.durationMs ?? "",
    startRadius: item.startRadius ?? "",
    endRadius: item.endRadius ?? "",
  };
}

function serializeHueWheelConfig(config) {
  const cleanedItems = (Array.isArray(config.items) ? config.items : [])
    .map((item) => {
      const next = {
        hue: item.hue === "" ? "" : Number(item.hue),
        label: String(item.label || ""),
        color: String(item.color || ""),
        animate: item.animate !== false,
      };
      ["delayMs", "durationMs", "startRadius", "endRadius"].forEach((key) => {
        if (item[key] !== "" && item[key] != null) next[key] = Number(item[key]);
      });
      return next;
    })
    .filter((item) => item.hue !== "" && Number.isFinite(item.hue));

  return JSON.stringify({
    items: cleanedItems,
    animated: config.animated !== false,
    showLabels: config.showLabels === true,
    showDots: config.showDots === true,
    pulseOnComplete: config.pulseOnComplete !== false,
    caption: String(config.caption || ""),
    size: Number(config.size) || DEFAULT_HUE_WHEEL_CONFIG.size,
    wheelFadeMs: Number(config.wheelFadeMs ?? DEFAULT_HUE_WHEEL_CONFIG.wheelFadeMs),
    spokeStartRadius: Number(config.spokeStartRadius ?? DEFAULT_HUE_WHEEL_CONFIG.spokeStartRadius),
    spokeEndRadius: Number(config.spokeEndRadius ?? DEFAULT_HUE_WHEEL_CONFIG.spokeEndRadius),
    spokeDelayMs: Number(config.spokeDelayMs ?? DEFAULT_HUE_WHEEL_CONFIG.spokeDelayMs),
    spokeStaggerMs: Number(config.spokeStaggerMs ?? DEFAULT_HUE_WHEEL_CONFIG.spokeStaggerMs),
    spokeDurationMs: Number(config.spokeDurationMs ?? DEFAULT_HUE_WHEEL_CONFIG.spokeDurationMs),
  }, null, 2);
}

export default function AdminPlaylistEditorPage() {
  const { playlistId } = useParams();
  const navigate = useNavigate();
  const [playlist, setPlaylist] = useState(emptyPlaylist);
  const [items, setItems] = useState([]);
  const [expandedItems, setExpandedItems] = useState({});
  const [savedOptions, setSavedOptions] = useState([]);
  const [playlistTypes, setPlaylistTypes] = useState([]);
  const [customType, setCustomType] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [saveStatus, setSaveStatus] = useState("");
  const [saveError, setSaveError] = useState("");
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [photoPickerIndex, setPhotoPickerIndex] = useState(null);
  const [heroPickerOpen, setHeroPickerOpen] = useState(false);
  const [seoModalOpen, setSeoModalOpen] = useState(false);
  const [photoThumbs, setPhotoThumbs] = useState({});
  const [photoInfo, setPhotoInfo] = useState({});
  const [previewPhoto, setPreviewPhoto] = useState(null);
  const [linkedInstances, setLinkedInstances] = useState([]);
  const [playing, setPlaying] = useState(false);
  const [hueWheelEditorIndex, setHueWheelEditorIndex] = useState(null);

  const makeClientItemKey = useCallback(
    () => `pli-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`,
    []
  );

  useEffect(() => {
    fetchPlaylistTypes();
  }, []);

  useEffect(() => {
    fetchSavedPalettes();
  }, []);

  useEffect(() => {
    const missingIds = Array.from(new Set(
      items
        .map((item) => {
          const photoId = String(getPhotoLibraryId(item) || "").trim();
          if (!photoId) return "";
          if (photoThumbs[photoId]) return "";
          return photoId;
        })
        .filter(Boolean)
    ));
    if (missingIds.length === 0) return;

    let cancelled = false;

    (async () => {
      try {
        const params = new URLSearchParams();
        params.set("photo_library_ids", missingIds.join(","));
        params.set("limit", String(Math.max(missingIds.length, 1)));
        params.set("_", Date.now().toString());
        const res = await fetch(`${PHOTO_LIBRARY_LIST_URL}?${params.toString()}`, {
          credentials: "include",
        });
        const data = await res.json();
        if (!res.ok || !data?.ok || cancelled) return;
        setPhotoThumbs((prev) => {
          const next = { ...prev };
          for (const row of data.items || []) {
            const id = String(row?.photo_library_id || "").trim();
            if (!id) continue;
            const imageUrl = String(row?.image_url || row?.rel_path || "").trim();
            if (!imageUrl) continue;
            next[id] = imageUrl;
          }
          return next;
        });
        setPhotoInfo((prev) => {
          const next = { ...prev };
          for (const row of data.items || []) {
            const id = String(row?.photo_library_id || "").trim();
            if (!id) continue;
            next[id] = {
              attachedSavedPaletteId: row?.attached_saved_palette_id ?? null,
              attachedSavedPaletteLabel: row?.attached_saved_palette_label || "",
              attachedSavedPaletteSetId: row?.attached_saved_palette_set_id ?? null,
              attachedSavedPaletteSetLabel: row?.attached_saved_palette_set_label || "",
              attachedSavedPalettePhotoType: row?.attached_saved_palette_photo_type || "",
            };
          }
          return next;
        });
      } catch {
        // ignore thumb lookups; editor should remain usable
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [items, photoThumbs]);

  const fetchPlaylist = useCallback(async (id) => {
    setLoading(true);
    setError("");
    try {
      const res = await fetch(`${GET_URL}?playlist_id=${id}&_=${Date.now()}`, {
        credentials: "include",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load playlist");
      setPlaylist({
        playlist_id: data.playlist.playlist_id,
        title: data.playlist.title,
        type: data.playlist.type,
        is_active: Number(data.playlist.is_active) !== 0,
        is_public: Number(data.playlist.is_public) === 1,
        slug: data.playlist.slug ?? "",
        headline: data.playlist.headline ?? "",
        page_title: data.playlist.page_title ?? "",
        meta_description: data.playlist.meta_description ?? "",
        dek: data.playlist.dek ?? "",
        intro_html: data.playlist.intro_html ?? "",
        body_html: data.playlist.body_html ?? "",
        hero_image_id: data.playlist.hero_image_id ?? "",
        hero_image_url: data.playlist.hero_image_url ?? "",
        hero_alt: data.playlist.hero_alt ?? "",
        indexable: data.playlist.indexable == null ? true : Number(data.playlist.indexable) !== 0,
        published_at: toDatetimeLocal(data.playlist.published_at ?? ""),
      });
      const typeValue = String(data.playlist.type || "").trim();
      if (typeValue && !playlistTypes.includes(typeValue)) {
        setCustomType(typeValue);
      }
      setItems(
        (data.items || []).map((item) => ({
          ...emptyItem,
          ...item,
          _clientKey: item.playlist_item_id ? `existing-${item.playlist_item_id}` : makeClientItemKey(),
          playlist_item_id: item.playlist_item_id ?? null,
          ap_id: item.ap_id ?? "",
          palette_hash: item.palette_hash ?? "",
          image_url: item.image_url ?? "",
          photo_library_id:
            item.photo_library_id ?? parsePhotoRef(item.image_url || "").photoId ?? "",
          saved_palette_set_id: item.saved_palette_set_id ?? "",
          title: item.title ?? "",
          subtitle: item.subtitle ?? "",
          subtitle_2: item.subtitle_2 ?? "",
          body: item.body ?? "",
          item_type: item.item_type ?? "non-palette",
          layout: item.layout ?? "default",
          title_mode: item.title_mode ?? "",
          star: item.star === null ? true : Boolean(item.star),
          transition: item.transition ?? "",
          duration_ms: item.duration_ms ?? "",
          exclude_from_thumbs: Boolean(item.exclude_from_thumbs),
          is_share_image: Boolean(item.is_share_image),
          site: item.site === null || item.site == null ? true : Boolean(Number(item.site)),
          yt: item.yt === null || item.yt == null ? true : Boolean(Number(item.yt)),
          is_active: item.is_active === null ? true : Boolean(item.is_active),
        }))
      );
    } catch (err) {
      setError(err?.message || "Failed to load playlist");
    } finally {
      setLoading(false);
    }
  }, [playlistTypes, makeClientItemKey]);

  const fetchLinkedInstances = useCallback(async (id) => {
    if (!id) {
      setLinkedInstances([]);
      return [];
    }
    try {
      const params = new URLSearchParams({
        playlist_id: String(id),
        _: String(Date.now()),
      });
      const res = await fetch(`${PLAYLIST_INSTANCES_LIST_URL}?${params.toString()}`, {
        credentials: "include",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load playlist instances");
      const rows = Array.isArray(data.items) ? data.items : [];
      setLinkedInstances(rows);
      return rows;
    } catch {
      setLinkedInstances([]);
      return [];
    }
  }, []);

  useEffect(() => {
    if (!playlistId) {
      setPlaylist(emptyPlaylist);
      setItems([]);
      setExpandedItems({});
      setLinkedInstances([]);
      return;
    }
    fetchPlaylist(playlistId);
    fetchLinkedInstances(playlistId);
  }, [playlistId, fetchPlaylist, fetchLinkedInstances]);

  async function fetchSavedPalettes() {
    try {
      const qs = new URLSearchParams();
      qs.set("limit", "500");
      qs.set("with_photos", "1");
      qs.set("_", Date.now().toString());
      const res = await fetch(`${SAVED_LIST_URL}?${qs.toString()}`, {
        credentials: "include",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) return;
      const options = (data.items || [])
        .filter((row) => row.palette_hash)
        .reduce((acc, row) => {
          const key = String(row.palette_hash || "").trim();
          if (!key || acc.some((item) => item.palette_hash === key)) return acc;
          const label = (row.nickname || "").trim() || `Saved #${row.id}`;
          const photos = Array.isArray(row.photos) ? row.photos : [];
          const fullPhoto = photos.find((photo) => photo.photo_type === "full") || photos[0];
          acc.push({
            key: `saved:${row.palette_hash}`,
            kind: "saved",
            id: row.id,
            palette_hash: row.palette_hash,
            title: label,
            renderUrl: fullPhoto?.rel_path || "",
            label,
          });
          return acc;
        }, []);
      setSavedOptions(options);
    } catch {
      // optional convenience list; ignore errors
    }
  }

  async function fetchPlaylistTypes() {
    try {
      const qs = new URLSearchParams();
      qs.set("limit", "500");
      qs.set("_", Date.now().toString());
      const res = await fetch(`${PLAYLISTS_LIST_URL}?${qs.toString()}`, {
        credentials: "include",
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) return;
      const types = Array.from(
        new Set(
          [...DEFAULT_PLAYLIST_TYPES, ...(data.items || [])
            .map((row) => String(row?.type || "").trim())
            .filter(Boolean)]
        )
      ).sort((a, b) => a.localeCompare(b));
      setPlaylistTypes(types);
    } catch {
      // optional convenience list; ignore errors
    }
  }

  function updatePlaylist(field, value) {
    setPlaylist((prev) => ({ ...prev, [field]: value }));
    setSaveStatus("");
    setSaveError("");
  }

  const isCustomType = useMemo(() => {
    if (!playlist.type) return false;
    return !playlistTypes.includes(playlist.type);
  }, [playlist.type, playlistTypes]);

  const handleTypeSelect = (value) => {
    if (value === "") {
      setCustomType("");
      updatePlaylist("type", "");
      return;
    }
    setCustomType("");
    updatePlaylist("type", value);
  };

  function toggleExpanded(key) {
    setExpandedItems((prev) => ({ ...prev, [key]: !prev[key] }));
  }

  function updateItem(index, field, value) {
    let nextValue = value;
    if (typeof nextValue === "string") {
      nextValue = nextValue
        .replace(/&mdash;/gi, "—")
        .replace(/--/g, "—");
    }
    setItems((prev) =>
      prev.map((item, idx) => {
        if (idx !== index) return item;
        const nextItem = { ...item, [field]: nextValue };
        if (field === "item_type" && nextValue === "hue-wheel") {
          if (!String(item.body || "").trim()) {
            nextItem.body = HUE_WHEEL_BODY_TEMPLATE;
          }
          nextItem.ap_id = "";
          nextItem.palette_hash = "";
          nextItem.image_url = "";
          nextItem.photo_library_id = "";
          nextItem.saved_palette_set_id = "";
          nextItem.is_share_image = false;
          nextItem.star = false;
        }
        return nextItem;
      })
    );
    setSaveStatus("");
    setSaveError("");
  }

  function updateHueWheelConfig(index, updater) {
    setItems((prev) =>
      prev.map((item, idx) => {
        if (idx !== index) return item;
        const current = parseHueWheelBody(item.body);
        const nextConfig = typeof updater === "function" ? updater(current) : updater;
        return {
          ...item,
          item_type: "hue-wheel",
          body: serializeHueWheelConfig(nextConfig),
          star: false,
        };
      })
    );
    setSaveStatus("");
    setSaveError("");
  }

  function updateHueWheelField(index, field, value) {
    updateHueWheelConfig(index, (config) => ({ ...config, [field]: value }));
  }

  function updateHueWheelItem(index, markerIndex, field, value) {
    updateHueWheelConfig(index, (config) => ({
      ...config,
      items: config.items.map((marker, idx) => (
        idx === markerIndex ? { ...marker, [field]: value } : marker
      )),
    }));
  }

  function addHueWheelItem(index) {
    updateHueWheelConfig(index, (config) => ({
      ...config,
      items: [
        ...config.items,
        normalizeHueWheelItem({ hue: 0, label: "", color: "#111111", animate: true }),
      ],
    }));
  }

  function removeHueWheelItem(index, markerIndex) {
    updateHueWheelConfig(index, (config) => ({
      ...config,
      items: config.items.filter((_, idx) => idx !== markerIndex),
    }));
  }

  async function applyHueWheelColor(index, markerIndex, pickedColor) {
    const colorId = pickedColor?.id ?? pickedColor?.color_id;
    let color = pickedColor || {};
    if (colorId) {
      try {
        await fetchColorDetail(colorId, (detail) => {
          color = detail || color;
        });
      } catch {
        // Fuzzy search rows usually include enough data; fall back to the picked row.
      }
    }
    const hexRaw = color.hex6 || color.hex || pickedColor?.hex6 || pickedColor?.hex || "";
    const hex = String(hexRaw || "").trim().replace(/^#/, "");
    const hue = Number(color.hcl_h ?? color.h ?? pickedColor?.hcl_h ?? pickedColor?.h);
    updateHueWheelConfig(index, (config) => ({
      ...config,
      items: config.items.map((marker, idx) => (
        idx === markerIndex
          ? {
              ...marker,
              hue: Number.isFinite(hue) ? Number(hue.toFixed(2)) : marker.hue,
              label: color.name || color.color_name || pickedColor?.name || marker.label,
              color: hex ? `#${hex.toUpperCase()}` : marker.color,
            }
          : marker
      )),
    }));
  }

  function applyAttachedPaletteFromPhoto(index, photoLibraryId, attachedInfo = null) {
    const info = attachedInfo || photoInfo[String(photoLibraryId || "").trim()];
    const attachedPaletteId = Number(info?.attachedSavedPaletteId || 0);
    const match = attachedPaletteId
      ? savedOptions.find((option) => Number(option.id || 0) === attachedPaletteId)
      : null;
    setItems((prev) =>
      prev.map((item, idx) => (
        idx === index
          ? {
              ...item,
              ap_id: "",
              palette_hash: match?.palette_hash || "",
              saved_palette_set_id: match && info?.attachedSavedPaletteSetId ? String(info.attachedSavedPaletteSetId) : "",
            }
          : item
      ))
    );
  }

  function getAttachedPaletteInfo(item) {
    const photoId = String(getPhotoLibraryId(item) || "").trim();
    return photoInfo[photoId] || null;
  }

  function getDisplayPaletteInfo(item) {
    const info = getAttachedPaletteInfo(item);
    if (!info) return null;
    if (String(info.attachedSavedPalettePhotoType || "").toLowerCase() === "before") {
      return null;
    }
    return info;
  }

  function addItem(type = "non-palette") {
    setItems((prev) => {
      const nextItem = {
        ...emptyItem,
        _clientKey: makeClientItemKey(),
        item_type: type,
        body: type === "hue-wheel" ? HUE_WHEEL_BODY_TEMPLATE : emptyItem.body,
        star: type === "hue-wheel" ? false : emptyItem.star,
      };
      if (type === "intro") {
        return [nextItem, ...prev];
      }
      return [...prev, nextItem];
    });
    setSaveStatus("");
    setSaveError("");
  }

  function removeItem(index) {
    setItems((prev) => prev.filter((_, idx) => idx !== index));
  }

  function moveItem(index, direction) {
    setItems((prev) => {
      const next = [...prev];
      const target = index + direction;
      if (target < 0 || target >= next.length) return next;
      [next[index], next[target]] = [next[target], next[index]];
      return next;
    });
  }

  const getPhotoLibraryId = (item) => {
    if (item?.photo_library_id) return item.photo_library_id;
    return parsePhotoRef(item?.image_url || "").photoId || "";
  };

  const hasItemPhoto = (item) => {
    return Boolean(String(getPhotoLibraryId(item) || "").trim() || String(item?.image_url || "").trim());
  };

  const resolvedShareItemIndex = useMemo(() => {
    const explicitIndex = items.findIndex((item) => Boolean(item?.is_share_image) && hasItemPhoto(item));
    if (explicitIndex >= 0) return explicitIndex;
    return items.findIndex((item) => hasItemPhoto(item));
  }, [items]);

  const getItemPhotoThumb = (item) => {
    const photoId = String(getPhotoLibraryId(item) || "").trim();
    if (photoId && photoThumbs[photoId]) return photoThumbs[photoId];
    const parsed = parsePhotoRef(item?.image_url || "");
    if (parsed.url) return parsed.url;
    if (item?.image_url && !String(item.image_url).startsWith("photo:")) return item.image_url;
    return "";
  };

  function clearItemPhoto(index) {
    setItems((prev) =>
      prev.map((item, idx) => (
        idx === index
          ? {
              ...item,
              photo_library_id: "",
              image_url: "",
              is_share_image: false,
            }
          : item
      ))
    );
    setSaveStatus("");
    setSaveError("");
  }

  function setShareImageIndex(index) {
    setItems((prev) =>
      prev.map((item, idx) => ({
        ...item,
        is_share_image: idx === index && hasItemPhoto(item),
      }))
    );
    setSaveStatus("");
    setSaveError("");
  }

  async function savePlaylist() {
    setSaving(true);
    setSaveStatus("");
    setSaveError("");
    try {
      const playlistPayload = {
        playlist_id: playlist.playlist_id,
        title: playlist.title,
        type: playlist.type,
        is_active: playlist.is_active,
        is_public: playlist.is_public,
        slug: playlist.slug,
        headline: playlist.headline,
        page_title: playlist.page_title,
        meta_description: playlist.meta_description,
        dek: playlist.dek,
        intro_html: playlist.intro_html,
        body_html: playlist.body_html,
        hero_image_id: playlist.hero_image_id || null,
        hero_image_url: playlist.hero_image_url,
        hero_alt: playlist.hero_alt,
        indexable: playlist.indexable,
        published_at: playlist.published_at || null,
      };
      const res = await fetch(SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(playlistPayload),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to save playlist");

      const playlistIdToSave = data.playlist_id;
      setPlaylist((prev) => ({ ...prev, playlist_id: playlistIdToSave }));

      const itemsPayload = {
        playlist_id: playlistIdToSave,
        items: items.map((item) => ({
          ...item,
          ap_id: item.ap_id === "" ? null : item.ap_id,
          palette_hash: item.palette_hash === "" ? null : item.palette_hash,
          saved_palette_set_id: item.saved_palette_set_id === "" ? null : item.saved_palette_set_id,
          duration_ms: item.duration_ms === "" ? null : item.duration_ms,
          is_share_image: Boolean(item.is_share_image),
          site: Boolean(item.site),
          yt: Boolean(item.yt),
        })),
      };
      const itemsRes = await fetch(SAVE_ITEMS_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(itemsPayload),
      });
      const itemsData = await itemsRes.json();
      if (!itemsRes.ok || !itemsData?.ok) {
        throw new Error(itemsData?.error || "Failed to save items");
      }

      setSaveStatus("Saved");
      await fetchPlaylist(playlistIdToSave);
      await fetchLinkedInstances(playlistIdToSave);
      if (!playlistId) {
        navigate(`/admin/playlists/${playlistIdToSave}`, { replace: true });
      }
      return playlistIdToSave;
    } catch (err) {
      setSaveError(err?.message || "Save failed");
      return null;
    } finally {
      setSaving(false);
    }
  }

  async function handleSave() {
    await savePlaylist();
  }

  async function handleSaveAndPlay() {
    setPlaying(true);
    setSaveError("");
    try {
      const savedPlaylistId = await savePlaylist();
      if (!savedPlaylistId) return;
      const instances = await fetchLinkedInstances(savedPlaylistId);
      const instance = instances.find((item) => Number(item.is_active) !== 0) || instances[0];
      if (!instance?.playlist_instance_id) {
        setSaveError("Saved, but this playlist has no playlist instance to run yet.");
        return;
      }
      const slug = String(instance.playlist_slug || instance.slug || "").trim();
      const pathId = slug || instance.playlist_instance_id;
      const params = new URLSearchParams({
        fresh: "1",
        close: "1",
        _: String(Date.now()),
        return_to: `/admin/playlists/${savedPlaylistId}`,
      });
      if (instance.audience && instance.audience !== "any") {
        params.set("aud", instance.audience);
      }
      window.open(`${window.location.origin}/p/${encodeURIComponent(String(pathId))}?${params.toString()}`, "_blank", "noopener");
    } finally {
      setPlaying(false);
    }
  }

  async function handleMakeYoutubeVideo() {
    setSaving(true);
    setSaveError("");
    setSaveStatus("");
    try {
      const savedPlaylistId = await savePlaylist();
      if (!savedPlaylistId) return;
      const command = `npm run render-youtube-video -- ${savedPlaylistId}`;
      try {
        await navigator.clipboard.writeText(command);
        setSaveStatus(`YT video command copied: ${command}`);
      } catch {
        setSaveStatus(`Run this to make the YT video: ${command}`);
      }
    } finally {
      setSaving(false);
    }
  }

  async function handleDeletePlaylist() {
    if (!playlist.playlist_id) return;
    if (!window.confirm(`Delete playlist #${playlist.playlist_id}?`)) return;
    setSaveStatus("");
    setSaveError("");
    setDeleting(true);
    try {
      const res = await fetch(DELETE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ playlist_id: playlist.playlist_id }),
      });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      const data = JSON.parse(text);
      if (!data?.ok) throw new Error(data?.error || "Delete failed");
      navigate("/admin/playlists");
    } catch (err) {
      setSaveError(err?.message || "Delete failed");
    } finally {
      setDeleting(false);
    }
  }

  const hasIntro = useMemo(() => {
    return items.some((item) => (item.item_type || "normal") === "intro");
  }, [items]);
  const hueWheelEditorItem = hueWheelEditorIndex != null ? items[hueWheelEditorIndex] : null;
  const hueWheelEditorConfig = hueWheelEditorItem ? parseHueWheelBody(hueWheelEditorItem.body) : null;

  return (
    <div className="admin-playlist-editor">
      <div className="editor-header">
        <div>
          <div className="editor-title">
            {playlist.playlist_id ? `Playlist #${playlist.playlist_id}` : "New Playlist"}
          </div>
          <div className="editor-subtitle">
            Build slides and intro items here, then assign to instances.
          </div>
        </div>
        <div className="editor-actions">
          <button type="button" onClick={() => navigate("/admin/playlists")}>
            Back to Playlists
          </button>
          <button type="button" onClick={() => navigate("/admin/playlist-instances")}>
            Back to Instances
          </button>
          <button
            type="button"
            onClick={handleSaveAndPlay}
            disabled={saving || playing}
          >
            {playing ? "Opening..." : "Save & Play"}
          </button>
          <button
            type="button"
            onClick={handleMakeYoutubeVideo}
            disabled={saving || playing || !playlist.playlist_id}
          >
            Make YT Video
          </button>
          {playlist.playlist_id && (
            <button
              type="button"
              className="danger-btn"
              onClick={handleDeletePlaylist}
              disabled={deleting}
            >
              {deleting ? "Deleting..." : "Delete"}
            </button>
          )}
          <button type="button" className="primary-btn" onClick={handleSave} disabled={saving}>
            {saving ? "Saving..." : "Save"}
          </button>
        </div>
      </div>

      {loading && <div className="panel-status">Loading…</div>}
      {error && <div className="panel-status error">{error}</div>}

      <div className="playlist-form">
        <label>
          Title
          <input
            type="text"
            value={playlist.title}
            onChange={(e) => updatePlaylist("title", e.target.value)}
          />
        </label>
        <label>
          Type
          <select
            value={isCustomType || !playlist.type ? "" : playlist.type}
            onChange={(e) => handleTypeSelect(e.target.value)}
          >
            <option value="">Select a type</option>
            {playlistTypes.map((type) => (
              <option key={type} value={type}>
                {type}
              </option>
            ))}
          </select>
          {(isCustomType || !playlist.type) && (
            <input
              type="text"
              placeholder="Enter new type"
              value={customType}
              onChange={(e) => {
                const next = e.target.value;
                setCustomType(next);
                updatePlaylist("type", next);
              }}
            />
          )}
        </label>
        <label className="checkbox-row">
          <input
            type="checkbox"
            checked={playlist.is_active}
            onChange={(e) => updatePlaylist("is_active", e.target.checked)}
          />
          Active
        </label>
        <label className="checkbox-row">
          <input
            type="checkbox"
            checked={playlist.is_public}
            onChange={(e) => updatePlaylist("is_public", e.target.checked)}
          />
          Public discovery / watch next
        </label>
        <div className="playlist-form__tools">
          <button type="button" onClick={() => setSeoModalOpen(true)}>
            SEO
          </button>
          {playlist.slug ? (
            <div className="muted">
              <code>/playlists/{playlist.slug}</code>
            </div>
          ) : (
            <div className="muted">No landing-page slug yet.</div>
          )}
        </div>
      </div>

      <div className="items-header">
        <div className="items-title">Playlist Items</div>
        <div className="items-actions">
          <button type="button" onClick={() => addItem("intro")}>Add Intro</button>
          <button type="button" onClick={() => addItem("normal")}>Add Slide</button>
          <button type="button" onClick={() => addItem("hue-wheel")}>Add Hue Wheel</button>
        </div>
      </div>

      {!hasIntro && (
        <div className="panel-status">
          No intro item yet. Add one if you want a headline or landing screen.
        </div>
      )}

      <div className="items-list">
        {items.map((item, index) => (
          <div className="item-card" key={item._clientKey || `fallback-${item.playlist_item_id || index}`}>
            <div className="item-row">
              <div className="item-cell item-order">#{index + 1}</div>
              <label className="item-cell">
                Type
                <select
                  value={item.item_type}
                  onChange={(e) => updateItem(index, "item_type", e.target.value)}
                >
                  <option value="palette">palette</option>
                  <option value="intro">intro</option>
                  <option value="text">text</option>
                  <option value="hue-wheel">hue wheel</option>
                  <option value="non-palette">no palette</option>
                </select>
              </label>
              <label className="item-cell item-title">
                Title
                <input
                  type="text"
                  value={item.title}
                  onChange={(e) => updateItem(index, "title", e.target.value)}
                />
              </label>
              <label className="item-cell item-subtitle">
                Subtitle
                <input
                  type="text"
                  value={item.subtitle}
                  onChange={(e) => updateItem(index, "subtitle", e.target.value)}
                />
              </label>
              <label className="item-cell item-ap">
                Palette
                {getPhotoLibraryId(item) ? (
                  <div>
                    <input
                      type="text"
                      value={getDisplayPaletteInfo(item)?.attachedSavedPaletteLabel || ""}
                      placeholder={
                        String(getAttachedPaletteInfo(item)?.attachedSavedPalettePhotoType || "").toLowerCase() === "before"
                          ? "Before photo does not carry a palette"
                          : "No palette attached to this photo"
                      }
                      readOnly
                    />
                    {getDisplayPaletteInfo(item) ? (
                      <div className="muted">From photo. Use `no palette` in Type if you do not want it shown.</div>
                    ) : null}
                  </div>
                ) : (
                  <div className="muted">Pick a photo first.</div>
                )}
              </label>
              <div className="item-cell item-photo">
                Photo
                <div className="item-inline">
                  {getItemPhotoThumb(item) ? (
                    <button
                      type="button"
                      className="item-photo-thumb-btn"
                      onClick={() =>
                        setPreviewPhoto({
                          src: getItemPhotoThumb(item),
                          title: item?.title || `Photo #${getPhotoLibraryId(item) || ""}`,
                          photoLibraryId: getPhotoLibraryId(item) || "",
                        })
                      }
                      aria-label={`Preview photo ${getPhotoLibraryId(item) || ""}`}
                    >
                      <img
                        className="item-photo-thumb"
                        src={getItemPhotoThumb(item)}
                        alt=""
                        loading="lazy"
                      />
                    </button>
                  ) : (
                    <div className="item-photo-thumb item-photo-thumb--empty" aria-hidden="true" />
                  )}
                  <input
                    type="text"
                    value={getPhotoLibraryId(item)}
                    placeholder="Pick a photo"
                    onChange={(e) => updateItem(index, "photo_library_id", e.target.value)}
                  />
                  <button
                    type="button"
                    className="item-inline-btn"
                    onClick={() => setPhotoPickerIndex(index)}
                  >
                    Pick Photo
                  </button>
                  <button
                    type="button"
                    className="item-inline-btn"
                    onClick={() => clearItemPhoto(index)}
                    disabled={!getPhotoLibraryId(item) && !item.image_url}
                  >
                    Remove Photo
                  </button>
                </div>
                <div className={`item-share-toggle ${resolvedShareItemIndex === index ? "is-active" : ""}${!hasItemPhoto(item) ? " is-disabled" : ""}`}>
                  <label>
                    <input
                      type="radio"
                      name="playlist-share-image"
                      checked={resolvedShareItemIndex === index}
                      disabled={!hasItemPhoto(item)}
                      onChange={() => setShareImageIndex(index)}
                    />
                    <span className="item-share-toggle__icon" aria-hidden="true">👓</span>
                    <span className="item-share-toggle__label">
                      {item.is_share_image ? "Manual peek photo" : resolvedShareItemIndex === index ? "Default peek photo" : "Use as peek photo"}
                    </span>
                  </label>
                </div>
              </div>
              <label className="item-cell item-transition">
                Transition Into
                <select
                  value={item.transition || ""}
                  onChange={(e) => updateItem(index, "transition", e.target.value)}
                >
                  <option value="">(default animation)</option>
                  <option value="animation">animation</option>
                  <option value="cut">cut</option>
                </select>
              </label>
              <div className="item-cell item-venues">
                Venue
                <div className="item-venue-toggles">
                  <label>
                    <input
                      type="checkbox"
                      checked={item.site !== false}
                      onChange={(e) => updateItem(index, "site", e.target.checked)}
                    />
                    Site
                  </label>
                  <label>
                    <input
                      type="checkbox"
                      checked={item.yt !== false}
                      onChange={(e) => updateItem(index, "yt", e.target.checked)}
                    />
                    YT
                  </label>
                </div>
              </div>
              <div className="item-actions">
                <div className="item-move">
                  <button type="button" onClick={() => moveItem(index, -1)}>↑</button>
                  <button type="button" onClick={() => moveItem(index, 1)}>↓</button>
                </div>
                {item.item_type === "hue-wheel" && (
                  <button type="button" className="item-more" onClick={() => setHueWheelEditorIndex(index)}>
                    Edit Hue Wheel
                  </button>
                )}
                <button type="button" className="item-more" onClick={() => toggleExpanded(index)}>
                  {expandedItems[index] ? "Less" : "More"}
                </button>
                <button type="button" onClick={() => removeItem(index)}>Remove</button>
              </div>
            </div>

            {expandedItems[index] && (
              <div className="item-row item-row--details">
                <label className="item-cell">
                  AP ID
                  <input
                    type="number"
                    value={item.ap_id}
                    onChange={(e) => updateItem(index, "ap_id", e.target.value)}
                  />
                </label>
                <label className="item-cell item-wide">
                  Subtitle 2
                  <input
                    type="text"
                    value={item.subtitle_2}
                    onChange={(e) => updateItem(index, "subtitle_2", e.target.value)}
                  />
                </label>
                {item.item_type === "hue-wheel" ? (
                  <div className="item-cell item-wide">
                    Hue Wheel
                    <button
                      type="button"
                      className="item-inline-btn"
                      onClick={() => setHueWheelEditorIndex(index)}
                    >
                      Edit Hue Wheel
                    </button>
                    <div className="muted">
                      {parseHueWheelBody(item.body).items.length} marker{parseHueWheelBody(item.body).items.length === 1 ? "" : "s"}
                    </div>
                  </div>
                ) : (
                  <label className="item-cell item-wide">
                    Body
                    <textarea
                      rows={2}
                      value={item.body}
                      onChange={(e) => updateItem(index, "body", e.target.value)}
                    />
                  </label>
                )}
                <label className="item-cell">
                  Layout
                  <input
                    type="text"
                    value={item.layout}
                    onChange={(e) => updateItem(index, "layout", e.target.value)}
                  />
                </label>
                <label className="item-cell">
                  Title Mode
                  <select
                    value={item.title_mode}
                    onChange={(e) => updateItem(index, "title_mode", e.target.value)}
                  >
                    <option value="">Select</option>
                    <option value="static">static</option>
                    <option value="animate">animate</option>
                  </select>
                </label>
                <label className="item-cell">
                  Star
                  <select
                    value={item.star ? "1" : "0"}
                    onChange={(e) => updateItem(index, "star", e.target.value === "1")}
                  >
                    <option value="1">Yes</option>
                    <option value="0">No</option>
                  </select>
                </label>
                <label className="item-cell">
                  Duration
                  <input
                    type="number"
                    value={item.duration_ms}
                    onChange={(e) => updateItem(index, "duration_ms", e.target.value)}
                  />
                </label>
                <label className="item-cell checkbox-row">
                  <input
                    type="checkbox"
                    checked={Boolean(item.exclude_from_thumbs)}
                    onChange={(e) => updateItem(index, "exclude_from_thumbs", e.target.checked)}
                  />
                  Hide from Thumbs
                </label>
                <label className="item-cell checkbox-row">
                  <input
                    type="checkbox"
                    checked={item.is_active}
                    onChange={(e) => updateItem(index, "is_active", e.target.checked)}
                  />
                  Active
                </label>
              </div>
            )}
          </div>
        ))}
      </div>

      <div className="items-actions items-actions--bottom">
        <button type="button" onClick={() => addItem("intro")}>Add Intro</button>
        <button type="button" onClick={() => addItem("normal")}>Add Slide</button>
        <button type="button" onClick={() => addItem("hue-wheel")}>Add Hue Wheel</button>
        <button type="button" className="primary-btn" onClick={handleSave} disabled={saving}>
          {saving ? "Saving..." : "Save"}
        </button>
      </div>

      {saveStatus && <div className="panel-status success">{saveStatus}</div>}
      {saveError && <div className="panel-status error">{saveError}</div>}

      <PhotoPickerModal
        open={photoPickerIndex != null}
        onClose={() => setPhotoPickerIndex(null)}
        onPick={(picked) => {
          if (!picked?.photo_library_id) return;
          const pid = String(picked.photo_library_id);
          updateItem(photoPickerIndex, "photo_library_id", pid);
          updateItem(photoPickerIndex, "image_url", makePhotoRef(pid, picked.image_url || ""));
          const attachedInfo = {
            attachedSavedPaletteId: picked.attached_saved_palette_id ?? null,
            attachedSavedPaletteLabel: picked.attached_saved_palette_label || "",
            attachedSavedPaletteSetId: picked.attached_saved_palette_set_id ?? null,
            attachedSavedPaletteSetLabel: picked.attached_saved_palette_set_label || "",
            attachedSavedPalettePhotoType: picked.attached_saved_palette_photo_type || "",
          };
          applyAttachedPaletteFromPhoto(photoPickerIndex, pid, attachedInfo);
          setPhotoPickerIndex(null);
        }}
      />

      <PhotoPickerModal
        open={heroPickerOpen}
        title="Pick Hero Photo"
        onClose={() => setHeroPickerOpen(false)}
        onPick={(picked) => {
          if (!picked?.photo_library_id) return;
          updatePlaylist("hero_image_id", String(picked.photo_library_id));
          updatePlaylist("hero_image_url", picked.image_url || "");
          if (!String(playlist.hero_alt || "").trim()) {
            updatePlaylist("hero_alt", playlist.headline || playlist.title);
          }
          setHeroPickerOpen(false);
        }}
      />

      {seoModalOpen && (
        <div
          className="playlist-seo-modal-backdrop"
          onClick={() => setSeoModalOpen(false)}
        >
          <div
            className="playlist-seo-modal"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="playlist-seo-form__header">
              <div>
                <div className="items-title">SEO Landing Page</div>
                <div className="editor-subtitle">
                  Edit the Google-facing landing page at /playlists/{'{slug}'}.
                </div>
              </div>
              <button type="button" onClick={() => setSeoModalOpen(false)}>
                Close
              </button>
            </div>

            <div className="playlist-seo-form">
              <div className="playlist-seo-form__grid">
                <label>
                  Slug
                  <div className="playlist-seo-form__inline">
                    <input
                      type="text"
                      value={playlist.slug}
                      onChange={(e) => updatePlaylist("slug", e.target.value)}
                      placeholder="where-does-the-eye-go-garage-dominant-house"
                    />
                    <button
                      type="button"
                      onClick={() =>
                        updatePlaylist(
                          "slug",
                          slugifyPlaylistValue(playlist.headline || playlist.title)
                        )
                      }
                    >
                      Generate
                    </button>
                  </div>
                </label>

                <label>
                  Headline
                  <input
                    type="text"
                    value={playlist.headline}
                    onChange={(e) => updatePlaylist("headline", e.target.value)}
                    placeholder="Public H1 for the landing page"
                  />
                </label>

                <label>
                  Page Title
                  <input
                    type="text"
                    value={playlist.page_title}
                    onChange={(e) => updatePlaylist("page_title", e.target.value)}
                    placeholder="HTML title tag"
                  />
                </label>

                <label>
                  Meta Description
                  <textarea
                    rows={2}
                    value={playlist.meta_description}
                    onChange={(e) => updatePlaylist("meta_description", e.target.value)}
                    placeholder="Short search snippet"
                  />
                </label>

                <label>
                  Dek
                  <textarea
                    rows={2}
                    value={playlist.dek}
                    onChange={(e) => updatePlaylist("dek", e.target.value)}
                    placeholder="Brief intro under the headline"
                  />
                </label>

                <label>
                  Published At
                  <input
                    type="datetime-local"
                    value={playlist.published_at}
                    onChange={(e) => updatePlaylist("published_at", e.target.value)}
                  />
                </label>

                <label className="checkbox-row">
                  <input
                    type="checkbox"
                    checked={Boolean(playlist.indexable)}
                    onChange={(e) => updatePlaylist("indexable", e.target.checked)}
                  />
                  Indexable
                </label>

                <label>
                  Hero Alt
                  <input
                    type="text"
                    value={playlist.hero_alt}
                    onChange={(e) => updatePlaylist("hero_alt", e.target.value)}
                    placeholder="Hero image alt text"
                  />
                </label>

                <label className="playlist-seo-form__span-2">
                  Hero Image URL
                  <div className="playlist-seo-form__inline">
                    <input
                      type="text"
                      value={playlist.hero_image_url}
                      onChange={(e) => updatePlaylist("hero_image_url", e.target.value)}
                      placeholder="/photos/... or https://..."
                    />
                    <button type="button" onClick={() => setHeroPickerOpen(true)}>
                      Pick Hero Photo
                    </button>
                    <button
                      type="button"
                      onClick={() => {
                        updatePlaylist("hero_image_id", "");
                        updatePlaylist("hero_image_url", "");
                      }}
                      disabled={!playlist.hero_image_id && !playlist.hero_image_url}
                    >
                      Clear
                    </button>
                  </div>
                  {playlist.hero_image_id ? (
                    <div className="muted">Photo Library #{playlist.hero_image_id}</div>
                  ) : null}
                  {playlist.hero_image_url ? (
                    <div className="playlist-seo-form__hero-preview">
                      <img src={playlist.hero_image_url} alt="" />
                    </div>
                  ) : null}
                </label>

                <label className="playlist-seo-form__span-2">
                  Intro HTML
                  <textarea
                    rows={6}
                    value={playlist.intro_html}
                    onChange={(e) => updatePlaylist("intro_html", e.target.value)}
                    placeholder="<p>Shorter explanatory section above the fold.</p>"
                  />
                </label>

                <label className="playlist-seo-form__span-2">
                  Body HTML
                  <textarea
                    rows={10}
                    value={playlist.body_html}
                    onChange={(e) => updatePlaylist("body_html", e.target.value)}
                    placeholder="<p>Longer Google-friendly explanation.</p>"
                  />
                </label>
              </div>

              {playlist.slug ? (
                <div className="muted">
                  Landing page: <code>/playlists/{playlist.slug}</code>
                </div>
              ) : null}
            </div>
          </div>
        </div>
      )}

      {hueWheelEditorItem && hueWheelEditorConfig && (
        <div
          className="playlist-seo-modal-backdrop"
          onClick={() => setHueWheelEditorIndex(null)}
        >
          <div
            className="playlist-seo-modal hue-wheel-editor-modal"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="playlist-seo-form__header">
              <div>
                <div className="items-title">Hue Wheel Slide</div>
                <div className="editor-subtitle">
                  Set the exact spokes for slide #{hueWheelEditorIndex + 1}.
                </div>
              </div>
              <button type="button" onClick={() => setHueWheelEditorIndex(null)}>
                Close
              </button>
            </div>

            <div className="hue-wheel-editor">
              <div className="hue-wheel-editor__settings">
                <label className="checkbox-row">
                  <input
                    type="checkbox"
                    checked={hueWheelEditorConfig.animated !== false}
                    onChange={(e) => updateHueWheelField(hueWheelEditorIndex, "animated", e.target.checked)}
                  />
                  Animated
                </label>
                <label className="checkbox-row">
                  <input
                    type="checkbox"
                    checked={hueWheelEditorConfig.showLabels === true}
                    onChange={(e) => updateHueWheelField(hueWheelEditorIndex, "showLabels", e.target.checked)}
                  />
                  Show labels
                </label>
                <label className="checkbox-row">
                  <input
                    type="checkbox"
                    checked={hueWheelEditorConfig.showDots === true}
                    onChange={(e) => updateHueWheelField(hueWheelEditorIndex, "showDots", e.target.checked)}
                  />
                  Show dots
                </label>
                <label className="checkbox-row">
                  <input
                    type="checkbox"
                    checked={hueWheelEditorConfig.pulseOnComplete !== false}
                    onChange={(e) => updateHueWheelField(hueWheelEditorIndex, "pulseOnComplete", e.target.checked)}
                  />
                  Finish pulse
                </label>
                <label>
                  Caption
                  <input
                    type="text"
                    value={hueWheelEditorConfig.caption || ""}
                    onChange={(e) => updateHueWheelField(hueWheelEditorIndex, "caption", e.target.value)}
                  />
                </label>
                <label>
                  Size
                  <input
                    type="number"
                    value={hueWheelEditorConfig.size ?? 360}
                    onChange={(e) => updateHueWheelField(hueWheelEditorIndex, "size", e.target.value)}
                  />
                </label>
                <label>
                  Start radius
                  <input
                    type="number"
                    value={hueWheelEditorConfig.spokeStartRadius ?? 0}
                    onChange={(e) => updateHueWheelField(hueWheelEditorIndex, "spokeStartRadius", e.target.value)}
                  />
                </label>
                <label>
                  End radius
                  <input
                    type="number"
                    value={hueWheelEditorConfig.spokeEndRadius ?? 136}
                    onChange={(e) => updateHueWheelField(hueWheelEditorIndex, "spokeEndRadius", e.target.value)}
                  />
                </label>
                <label>
                  Wheel fade
                  <input
                    type="number"
                    value={hueWheelEditorConfig.wheelFadeMs ?? 420}
                    onChange={(e) => updateHueWheelField(hueWheelEditorIndex, "wheelFadeMs", e.target.value)}
                  />
                </label>
                <label>
                  First spoke delay
                  <input
                    type="number"
                    value={hueWheelEditorConfig.spokeDelayMs ?? 420}
                    onChange={(e) => updateHueWheelField(hueWheelEditorIndex, "spokeDelayMs", e.target.value)}
                  />
                </label>
                <label>
                  Stagger
                  <input
                    type="number"
                    value={hueWheelEditorConfig.spokeStaggerMs ?? 260}
                    onChange={(e) => updateHueWheelField(hueWheelEditorIndex, "spokeStaggerMs", e.target.value)}
                  />
                </label>
                <label>
                  Duration
                  <input
                    type="number"
                    value={hueWheelEditorConfig.spokeDurationMs ?? 800}
                    onChange={(e) => updateHueWheelField(hueWheelEditorIndex, "spokeDurationMs", e.target.value)}
                  />
                </label>
              </div>

              <div className="hue-wheel-editor__markers-head">
                <div className="items-title">Markers</div>
                <button type="button" onClick={() => addHueWheelItem(hueWheelEditorIndex)}>
                  Add Marker
                </button>
              </div>

              <div className="hue-wheel-editor__markers">
                {hueWheelEditorConfig.items.map((marker, markerIndex) => (
                  <div className="hue-wheel-marker-row" key={`marker-${markerIndex}`}>
                    <div className="hue-wheel-marker-row__pick">
                      <label>Pick color</label>
                      <FuzzySearchColorSelect
                        onSelect={(color) => applyHueWheelColor(hueWheelEditorIndex, markerIndex, color)}
                        autoFocus={false}
                        preventAutoFocus
                        compact
                        showLabel={false}
                        mobileBreakpoint={0}
                      />
                    </div>
                    <label>
                      Hue
                      <input
                        type="number"
                        min="0"
                        max="360"
                        step="0.1"
                        value={marker.hue}
                        onChange={(e) => updateHueWheelItem(hueWheelEditorIndex, markerIndex, "hue", e.target.value)}
                      />
                    </label>
                    <label>
                      Label
                      <input
                        type="text"
                        value={marker.label}
                        onChange={(e) => updateHueWheelItem(hueWheelEditorIndex, markerIndex, "label", e.target.value)}
                      />
                    </label>
                    <label>
                      Color
                      <input
                        type="color"
                        value={/^#[0-9a-f]{6}$/i.test(marker.color || "") ? marker.color : "#111111"}
                        onChange={(e) => updateHueWheelItem(hueWheelEditorIndex, markerIndex, "color", e.target.value)}
                      />
                    </label>
                    <label className="checkbox-row">
                      <input
                        type="checkbox"
                        checked={marker.animate !== false}
                        onChange={(e) => updateHueWheelItem(hueWheelEditorIndex, markerIndex, "animate", e.target.checked)}
                      />
                      Animate
                    </label>
                    <label>
                      Delay
                      <input
                        type="number"
                        value={marker.delayMs ?? ""}
                        placeholder="default"
                        onChange={(e) => updateHueWheelItem(hueWheelEditorIndex, markerIndex, "delayMs", e.target.value)}
                      />
                    </label>
                    <label>
                      Duration
                      <input
                        type="number"
                        value={marker.durationMs ?? ""}
                        placeholder="default"
                        onChange={(e) => updateHueWheelItem(hueWheelEditorIndex, markerIndex, "durationMs", e.target.value)}
                      />
                    </label>
                    <label>
                      Start radius
                      <input
                        type="number"
                        value={marker.startRadius ?? ""}
                        placeholder="default"
                        onChange={(e) => updateHueWheelItem(hueWheelEditorIndex, markerIndex, "startRadius", e.target.value)}
                      />
                    </label>
                    <label>
                      End radius
                      <input
                        type="number"
                        value={marker.endRadius ?? ""}
                        placeholder="default"
                        onChange={(e) => updateHueWheelItem(hueWheelEditorIndex, markerIndex, "endRadius", e.target.value)}
                      />
                    </label>
                    <button type="button" onClick={() => removeHueWheelItem(hueWheelEditorIndex, markerIndex)}>
                      Remove
                    </button>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </div>
      )}

      {previewPhoto?.src && (
        <div
          className="playlist-photo-preview-backdrop"
          onClick={() => setPreviewPhoto(null)}
        >
          <div
            className="playlist-photo-preview-modal"
            onClick={(event) => event.stopPropagation()}
          >
            <button
              type="button"
              className="playlist-photo-preview-close"
              onClick={() => setPreviewPhoto(null)}
              aria-label="Close photo preview"
            >
              ×
            </button>
            <div className="playlist-photo-preview-meta">
              <div className="playlist-photo-preview-title">{previewPhoto.title || "Photo Preview"}</div>
              {previewPhoto.photoLibraryId ? (
                <div className="playlist-photo-preview-subtitle">Photo Library #{previewPhoto.photoLibraryId}</div>
              ) : null}
            </div>
            <img
              className="playlist-photo-preview-image"
              src={previewPhoto.src}
              alt=""
            />
          </div>
        </div>
      )}
    </div>
  );
}
