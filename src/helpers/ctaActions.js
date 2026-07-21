// Centralized CTA action handlers shared by player screens.
import { getPaletteTargets, isPaletteEligibleItem } from "@helpers/playerPaletteItems";

export function getCtaKey(cta) {
  return (cta?.key || cta?.action_key || cta?.action || "").toString().toLowerCase();
}

function buildShareUrl(shareFolder, playlistInstanceId, source = "share") {
  return appendParams(`${shareFolder}/playlist.php?id=${playlistInstanceId}`, {
    src: source || undefined,
  });
}

function buildPlayerPath(data) {
  const pathId = data?.slug || data?.playlist_instance_slug || data?.playlist_instance_id;
  return pathId ? `/p/${pathId}` : "";
}

function runShare({ data, shareFolder, shareSource }) {
  if (!data?.playlist_instance_id) return;
  if (data?.share_enabled === false) return;
  const url = buildShareUrl(shareFolder, data.playlist_instance_id, shareSource);
  const title = data?.share_title || data?.title || "ColorFix Playlist";
  const text = data?.share_description || "I'm sharing a playlist I found on ColorFix";
  if (navigator.share) {
    navigator.share({ title, text, url }).catch(() => {});
    return;
  }
  if (navigator.clipboard?.writeText) {
    navigator.clipboard.writeText(url).catch(() => {});
  }
  const body = encodeURIComponent(url);
  window.location.href = `sms:&body=${body}`;
}

function runCopyLink({ data, shareFolder, shareSource }) {
  if (!data?.playlist_instance_id) return;
  const url = buildShareUrl(shareFolder, data.playlist_instance_id, shareSource);
  if (navigator.clipboard?.writeText) {
    navigator.clipboard.writeText(url).catch(() => {});
    return;
  }
  const body = encodeURIComponent(url);
  window.location.href = `sms:&body=${body}`;
}

function runNavigate({ navigate, cta, psi, thumb, demo }) {
  let url = cta?.params?.url;
  if (!url) return;
  if (typeof url === "string") {
    const playlistInstanceId = cta?.data?.playlist_instance_id;
    const playlistId = cta?.data?.playlist_id;
    url = url
      .replaceAll("{playlist_instance_id}", playlistInstanceId ?? "")
      .replaceAll("{playlist_id}", playlistId ?? "")
      .replaceAll("{psi}", psi ?? "")
      .replaceAll("{thumb}", thumb ? "1" : "")
      .replaceAll("{demo}", demo ? "1" : "");
  }
  if (shouldPreserveSource(cta, url)) {
    url = appendParams(url, {
      src: getCurrentSource(),
    });
  }
  const target = cta?.params?.target || "_blank";
  if (navigate && shouldNavigateInPlayerShell(url)) {
    navigate(url);
    return;
  }
  window.open(url, target, "noopener");
}

function shouldPreserveSource(cta, url) {
  if (cta?.params?.preserve_src !== undefined) {
    return cta.params.preserve_src === true || cta.params.preserve_src === "true" || cta.params.preserve_src === 1 || cta.params.preserve_src === "1";
  }
  return typeof url === "string" && (url === "/playlists" || url.startsWith("/playlists?"));
}

function getCurrentSource() {
  if (typeof window === "undefined") return "";
  try {
    return new URLSearchParams(window.location.search).get("src") || "";
  } catch {
    return "";
  }
}

function runArticleLink({ navigate, cta }) {
  const params = cta?.params || {};
  const articleId = params.article_id || params.articleId;
  const baseUrl = params.url || (articleId ? `/articles/${articleId}` : "");
  if (!baseUrl) return;
  const playlistInstanceId = cta?.data?.playlist_instance_id;
  const playlistTitle = cta?.data?.display_title || cta?.data?.title || "";
  const defaultReturnTo = buildPlayerPath(cta?.data);
  const url = appendParams(baseUrl, {
    playlist_instance_id: playlistInstanceId || undefined,
    playlist_title: playlistTitle || undefined,
    return_to: params.return_to || defaultReturnTo || undefined,
  });
  const target = params.target || "_self";
  if (navigate && shouldNavigateInPlayerShell(url)) {
    navigate(url);
    return;
  }
  window.open(url, target, "noopener");
}

function runPlaylistLink({ navigate, cta, source }) {
  const params = cta?.params || {};
  const playlistInstanceId = params.playlist_instance_id || params.playlistInstanceId || cta?.data?.playlist_instance_id;
  const rawUrl = params.url || buildPlayerPath({
    ...cta?.data,
    playlist_instance_id: playlistInstanceId,
  });
  if (!rawUrl) return;
  const audience = params.aud || params.audience || cta?.data?.audience;
  const url = appendParams(rawUrl, {
    src: source,
    aud: audience,
  });
  const target = params.target || "_self";
  if (navigate && shouldNavigateInPlayerShell(url)) {
    navigate(url);
    return;
  }
  window.open(url, target, "noopener");
}

function appendParams(url, params) {
  const entries = Object.entries(params || {}).filter(([, value]) => value !== undefined && value !== null && value !== "");
  if (!entries.length) return url;
  try {
    const isAbsolute = /^https?:\/\//i.test(url);
    const base = isAbsolute ? url : `${window.location.origin}${url.startsWith("/") ? "" : "/"}${url}`;
    const next = new URL(base);
    entries.forEach(([key, value]) => {
      if (!next.searchParams.has(key)) next.searchParams.set(key, String(value));
    });
    return isAbsolute ? next.toString() : `${next.pathname}${next.search}${next.hash}`;
  } catch {
    const sep = url.includes("?") ? "&" : "?";
    const qs = new URLSearchParams(entries).toString();
    return qs ? `${url}${sep}${qs}` : url;
  }
}

function runSeeColorsUsed({ navigate, cta, data, ctaAudience, psi, thumb, demo, returnTo, playerRef }) {
  if (!data?.playlist_instance_id) return;
  if (thumb) {
    runToThumbs({ navigate, data, cta, ctaAudience, psi, thumb, demo, returnTo });
    return;
  }
  runToPalette({ navigate, data, cta, ctaAudience, psi, thumb, demo, returnTo, playerRef });
}

function runToThumbs({ navigate, data, cta, ctaAudience, psi, thumb, demo, returnTo }) {
  if (!data?.playlist_instance_id) return;
  const params = new URLSearchParams();
  if (cta?.params?.mode) params.set("mode", cta.params.mode);
  if (cta?.params?.add_cta_group !== undefined) {
    params.set("add_cta_group", String(cta.params.add_cta_group));
  }
  const audience = cta?.params?.aud || cta?.params?.audience || ctaAudience;
  if (audience) params.set("aud", String(audience));
  if (psi) params.set("psi", String(psi));
  if (thumb) params.set("thumb", "1");
  if (demo) params.set("demo", "1");
  if (returnTo) params.set("return_to", returnTo);
  const query = params.toString();
  const url = `/playlist-thumbs/${data.playlist_instance_id}${query ? `?${query}` : ""}`;
  if (navigate && shouldNavigateInPlayerShell(url)) {
    navigate(url);
    return;
  }
  const target = cta?.params?.target || "_self";
  window.open(url, target, "noopener");
}

function getCurrentPaletteItem(data, playerRef) {
  const current = playerRef?.current?.getCurrentItem?.() || null;
  if (isPaletteEligibleItem(current)) return current;
  const palettes = getPaletteTargets(data);
  return palettes.length === 1 ? palettes[0] : null;
}

function runToPalette({ navigate, data, cta, ctaAudience, psi, thumb, demo, returnTo, playerRef }) {
  const targetItem = getCurrentPaletteItem(data, playerRef);
  if (!targetItem) return;
  const apId = targetItem.ap_id;
  const paletteHash = targetItem.palette_hash;
  const savedPaletteSetId = Number(targetItem.saved_palette_set_id || 0);
  if (!apId && !paletteHash && !savedPaletteSetId) return;
  const params = new URLSearchParams();
  if (cta?.params?.add_cta_group !== undefined) {
    params.set("add_cta_group", String(cta.params.add_cta_group));
  } else if (data?.palette_viewer_cta_group_id) {
    params.set("add_cta_group", String(data.palette_viewer_cta_group_id));
  }
  const audience = cta?.params?.aud || cta?.params?.audience || ctaAudience;
  if (audience) params.set("aud", String(audience));
  if (psi) params.set("psi", String(psi));
  if (thumb) params.set("thumb", "1");
  if (demo) params.set("demo", "1");
  const resolvedReturnTo = cta?.params?.return_to || cta?.params?.returnTo || returnTo;
  if (resolvedReturnTo) params.set("return_to", resolvedReturnTo);
  if (savedPaletteSetId > 0) params.set("set_id", String(savedPaletteSetId));
  const qs = params.toString();
  if (!apId && !paletteHash) return;
  const url = paletteHash
    ? `/palette/${paletteHash}/share${qs ? `?${qs}` : ""}`
    : `/view/${apId}${qs ? `?${qs}` : ""}`;
  if (navigate && shouldNavigateInPlayerShell(url)) {
    navigate(url);
    return;
  }
  const target = cta?.params?.target || "_self";
  window.open(url, target, "noopener");
}

function shouldNavigateInPlayerShell(url) {
  if (!url || !url.startsWith("/")) return false;
  if (isFastPlayerShell()) {
    return url.startsWith("/p/") || url.startsWith("/playlist/");
  }
  return url.startsWith("/p/")
    || url.startsWith("/playlist/")
    || url.startsWith("/playlist-thumbs/")
    || url.startsWith("/picker")
    || url.startsWith("/palette/")
    || url.startsWith("/view/");
}

function isFastPlayerShell() {
  return typeof window !== "undefined" && (
    window.location.pathname === "/p" || window.location.pathname.startsWith("/p/")
  );
}

export function buildCtaHandlers({
  data,
  shareFolder,
  playerRef,
  setPlaybackEnded,
  firstNonIntroIndex = 0,
  handleExit,
  navigate,
  ctaAudience,
  psi,
  thumb,
  demo,
  returnTo,
  shareSource = "share",
} = {}) {
  const replayStartIndex = data?.skip_intro_on_replay ? firstNonIntroIndex : 0;

  return {
    replay: () => {
      setPlaybackEnded?.(false);
      playerRef?.current?.replay({ likedOnly: false, startIndex: replayStartIndex });
    },
    replay_liked: () => {
      setPlaybackEnded?.(false);
      playerRef?.current?.replay({ likedOnly: true });
    },
    replay_filtered: (cta) => {
      const filter = cta?.params?.filter || "";
      const likedOnly = filter === "liked";
      setPlaybackEnded?.(false);
      playerRef?.current?.replay({ likedOnly });
    },
    jump_to_item: (cta) => {
      const index = Number(cta?.params?.item_index);
      if (Number.isNaN(index)) return;
      setPlaybackEnded?.(false);
      playerRef?.current?.replay({ likedOnly: false, startIndex: index });
    },
    share: () => runShare({ data, shareFolder, shareSource }),
    copy_link: () => runCopyLink({ data, shareFolder, shareSource }),
    share_playlist: () => runShare({ data, shareFolder, shareSource }),
    navigate: (cta) => runNavigate({ navigate, cta: { ...cta, data: { ...(data || {}), ...(cta?.data || {}) } }, psi, thumb, demo }),
    article_link: (cta) => runArticleLink({ navigate, cta }),
    playlist_link: (cta) => runPlaylistLink({ navigate, cta: { ...cta, data: { ...(data || {}), ...(cta?.data || {}) } } }),
    watch_next: (cta) => runPlaylistLink({ navigate, cta: { ...cta, data: { ...(data || {}), ...(cta?.data || {}) } }, source: "watch_next" }),
    see_colors_used: (cta) => runSeeColorsUsed({ navigate, cta, data, ctaAudience, psi, thumb, demo, returnTo, playerRef }),
    to_thumbs: (cta) => runToThumbs({ navigate, data, cta, ctaAudience, psi, thumb, demo, returnTo }),
    to_palette: (cta) => runToPalette({ navigate, data, cta, ctaAudience, psi, thumb, demo, returnTo, playerRef }),
    exit: () => handleExit?.(),
  };
}
