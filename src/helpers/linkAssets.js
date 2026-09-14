export const LINK_ASSET_TYPE_OPTIONS = [
  { value: "playlist_instance", label: "Playlist Instance" },
  { value: "saved_palette", label: "Saved Palette" },
  { value: "playlist_instance_set", label: "Playlist Set" },
  { value: "watch_page", label: "Watch Page" },
];

export function normalizeLinkAssetItems(assetType, rows) {
  const list = Array.isArray(rows) ? rows : [];

  if (assetType === "playlist_instance") {
    return list
      .map((row) => ({
        ...row,
        playlist_instance_id: row?.playlist_instance_id ?? row?.id ?? null,
        display_title: row?.display_title ?? row?.title ?? "",
        instance_name: row?.instance_name ?? row?.name ?? "",
        audience: row?.audience ?? "any",
        instance_notes: row?.instance_notes ?? row?.notes ?? "",
        player_url: row?.player_url ?? "",
        playlist_slug: row?.playlist_slug ?? row?.slug ?? "",
      }))
      .filter((row) => row.playlist_instance_id);
  }

  if (assetType === "saved_palette") {
    return list
      .map((row) => ({
        ...row,
        id: row?.id ?? row?.palette_id ?? row?.saved_palette_id ?? null,
        nickname: row?.nickname ?? row?.title ?? row?.display_title ?? "",
        brand: row?.brand ?? row?.palette_brand ?? "",
        palette_type: row?.palette_type ?? row?.type ?? "",
        palette_hash: row?.palette_hash ?? row?.hash ?? "",
      }))
      .filter((row) => row.id || row.palette_hash);
  }

  if (assetType === "playlist_instance_set") {
    return list
      .map((row) => ({
        ...row,
        id: row?.id ?? row?.set_id ?? row?.playlist_instance_set_id ?? null,
        title: row?.title ?? row?.name ?? "",
        handle: row?.handle ?? row?.slug ?? "",
        context: row?.context ?? row?.subtitle ?? "",
        version: row?.version ?? "",
        updated_at: row?.updated_at ?? "",
      }))
      .filter((row) => row.id);
  }

  if (assetType === "watch_page") {
    return list
      .map((row) => ({
        ...row,
        id: row?.id ?? "watch-page",
        title: row?.title ?? "Watch Page",
        playlist_instance_id: row?.playlist_instance_id ?? null,
        playlist_slug: row?.playlist_slug ?? row?.slug ?? "",
        player_url: row?.player_url ?? row?.url ?? "",
      }))
      .filter((row) => row.player_url || row.playlist_instance_id);
  }

  return list;
}

export function buildLinkAssetLabel(assetType, item) {
  if (!item) return "";
  if (assetType === "playlist_instance") {
    return item.display_title || item.instance_name || `Playlist Instance #${item.playlist_instance_id}`;
  }
  if (assetType === "saved_palette") {
    if (item.nickname) return item.nickname;
    if (item.brand) return String(item.brand).toUpperCase();
    if (item.id) return `Saved Palette #${item.id}`;
    if (item.palette_hash) return "Saved Palette";
    return "Saved Palette";
  }
  if (assetType === "playlist_instance_set") {
    return item.title || item.handle || `Set #${item.id}`;
  }
  return item.title || "Watch Page";
}

export function buildLinkAssetMeta(assetType, item) {
  if (!item) return "";
  if (assetType === "playlist_instance") {
    const parts = [`#${item.playlist_instance_id}`];
    if (item.audience && item.audience !== "any") parts.push(item.audience);
    if (item.instance_notes) parts.push(item.instance_notes);
    return parts.join(" · ");
  }
  if (assetType === "saved_palette") {
    const parts = [];
    if (item.id) parts.push(`#${item.id}`);
    if (item.brand) parts.push(String(item.brand).toUpperCase());
    if (item.palette_type) parts.push(item.palette_type);
    return parts.join(" · ");
  }
  if (assetType === "playlist_instance_set") {
    const parts = [`#${item.id}`];
    if (item.handle) parts.push(item.handle);
    if (item.context) parts.push(item.context);
    return parts.join(" · ");
  }
  const parts = [];
  if (item.playlist_instance_id) parts.push(`Instance #${item.playlist_instance_id}`);
  if (item.playlist_slug) parts.push(item.playlist_slug);
  return parts.join(" · ");
}

export function getLinkAssetId(assetType, item) {
  if (!item) return "";
  if (assetType === "playlist_instance") return item.playlist_instance_id;
  return item.id;
}

export function getLinkAssetKey(assetType, item) {
  if (!item) return "";
  return `${assetType}:${getLinkAssetId(assetType, item)}`;
}

export function buildLinkAssetUrl(assetType, item) {
  if (!item || typeof window === "undefined") return "";

  if (assetType === "playlist_instance") {
    const slug = String(item.playlist_slug || item.slug || "").trim();
    const pathId = slug || item.playlist_instance_id;
    if (!pathId) return "";
    const params = new URLSearchParams();
    if (item.audience && item.audience !== "any") {
      params.set("aud", item.audience);
    }
    const qs = params.toString();
    return `${window.location.origin}/p/${encodeURIComponent(String(pathId))}${qs ? `?${qs}` : ""}`;
  }

  if (assetType === "saved_palette") {
    if (!item.palette_hash) return "";
    return `${window.location.origin}/palette/${encodeURIComponent(item.palette_hash)}/share`;
  }

  if (assetType === "playlist_instance_set") {
    if (!item.id) return "";
    const params = new URLSearchParams({ set: String(item.id) });
    if (item.version) params.set("set_v", String(item.version));
    return `${window.location.origin}/picker?${params.toString()}`;
  }

  const relativeUrl = item.player_url || "/watch";
  return new URL(relativeUrl, window.location.origin).toString();
}
