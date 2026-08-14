// Centralized CTA action handlers shared by player screens.
import { getPaletteTargets, isPaletteEligibleItem } from "@helpers/playerPaletteItems";
import { copyShareText, openTextShare, shareOrText } from "@helpers/shareUrls";
import { getCurrentSourceParam, withSourceParam } from "@helpers/sourceParam";

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
  shareOrText({ title, text, url }).catch(() => {});
}

async function runCopyLink({ data, shareFolder, shareSource }) {
  if (!data?.playlist_instance_id) return;
  const url = buildShareUrl(shareFolder, data.playlist_instance_id, shareSource);
  if (await copyShareText(url)) {
    return;
  }
  await openTextShare({ url });
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
    url = withSourceParam(url, getCurrentSource());
  }
  url = withSourceParam(url);

  const target = cta?.params?.target || "_blank";

  if (navigate && shouldNavigateInPlayerShell(url)) {
    navigate(url);
    return;
  }

  window.open(url, target, "noopener");
}

function shouldPreserveSource(cta, url) {
  if (cta?.params?.preserve_src !== undefined) {
    return (
      cta.params.preserve_src === true ||
      cta.params.preserve_src === "true" ||
      cta.params.preserve_src === 1 ||
      cta.params.preserve_src === "1"
    );
  }

  return typeof url === "string" && (url === "/playlists" || url.startsWith("/playlists?"));
}

function getCurrentSource() {
  return getCurrentSourceParam();
}

function runArticleLink({ navigate, cta }) {
  const params = cta?.params || {};
  const articleId = params.article_id || params.articleId;
  const baseUrl = params.url || (articleId ? `/articles/${articleId}` : "");

  if (!baseUrl) return;

  const playlistInstanceId = cta?.data?.playlist_instance_id;
  const playlistTitle = cta?.data?.display_title || cta?.data?.title || "";
  const defaultReturnTo = buildPlayerPath(cta?.data);

  const url = withSourceParam(appendParams(baseUrl, {
    playlist_instance_id: playlistInstanceId || undefined,
    playlist_title: playlistTitle || undefined,
    return_to: withSourceParam(params.return_to || defaultReturnTo || "") || undefined,
  }));

  const target = params.target || "_self";

  if (navigate && shouldNavigateInPlayerShell(url)) {
    navigate(url);
    return;
  }

  window.open(url, target, "noopener");
}

function runPlaylistLink({ navigate, cta, source }) {
  const params = cta?.params || {};
  const playlistInstanceId =
    params.playlist_instance_id ||
    params.playlistInstanceId ||
    cta?.data?.playlist_instance_id;

  const rawUrl =
    params.url ||
    cta?.data?.reserved_playlist_url ||
    buildPlayerPath({
      ...cta?.data,
      playlist_instance_id: playlistInstanceId,
    });

  if (!rawUrl) return;

  const audience = params.aud || params.audience || cta?.data?.audience;

  const url = withSourceParam(appendParams(rawUrl, {
    src: source,
    aud: audience,
  }));

  const target = params.target || "_self";

  if (navigate && shouldNavigateInPlayerShell(url)) {
    navigate(url);
    return;
  }

  window.open(url, target, "noopener");
}

function appendParams(url, params) {
  const entries = Object.entries(params || {}).filter(
    ([, value]) => value !== undefined && value !== null && value !== ""
  );

  if (!entries.length) return url;

  try {
    const isAbsolute = /^https?:\/\//i.test(url);
    const base = isAbsolute
      ? url
      : `${window.location.origin}${url.startsWith("/") ? "" : "/"}${url}`;

    const next = new URL(base);

    entries.forEach(([key, value]) => {
      if (key === "src") {
        next.searchParams.set(key, String(value));
      } else if (!next.searchParams.has(key)) {
        next.searchParams.set(key, String(value));
      }
    });

    return isAbsolute
      ? next.toString()
      : `${next.pathname}${next.search}${next.hash}`;
  } catch {
    const sep = url.includes("?") ? "&" : "?";
    const qs = new URLSearchParams(entries).toString();

    return qs ? `${url}${sep}${qs}` : url;
  }
}

function runSeeColorsUsed({
  navigate,
  cta,
  data,
  ctaAudience,
  psi,
  thumb,
  demo,
  returnTo,
  playerRef,
  reservationToken,
}) {
  if (!data?.playlist_instance_id && !reservationToken) return;

  // Public REX playlists use explicit Playlist REX -> Viewer REX relationships.
  if (reservationToken) {
    const resolvedUrl = normalizeInternalRexPath(data?.colors_used_url);
    if (data?.colors_used_destination === "viewer" && resolvedUrl) {
      const originPlaylist = rexPlaylistOrigin(data, reservationToken);
      const url = withSourceParam(appendParams(resolvedUrl, {
        origin_playlist: withSourceParam(originPlaylist) || undefined,
      }));
      const target = cta?.params?.target || "_self";

      if (navigate && shouldNavigateInPlayerShell(url)) {
        navigate(url);
        return;
      }

      window.open(url, target, "noopener");
      return;
    }

    if (data?.colors_used_destination === "thumbs") {
      runToThumbs({
        navigate,
        data,
        cta,
        ctaAudience,
        psi,
        thumb: false,
        demo,
        returnTo,
        reservationToken,
      });
      return;
    }

    const viewerUrls = Array.isArray(data?.viewer_rex_urls)
      ? data.viewer_rex_urls.filter(Boolean)
      : [];

    if (viewerUrls.length === 1) {
      const originPlaylist = rexPlaylistOrigin(data, reservationToken);
      const url = withSourceParam(appendParams(viewerUrls[0], {
        origin_playlist: withSourceParam(originPlaylist) || undefined,
      }));
      const target = cta?.params?.target || "_self";

      if (navigate && shouldNavigateInPlayerShell(url)) {
        navigate(url);
        return;
      }

      window.open(url, target, "noopener");
      return;
    }

    if (viewerUrls.length > 1) {
      runToThumbs({
        navigate,
        data,
        cta,
        ctaAudience,
        psi,
        thumb: false,
        demo,
        returnTo,
        reservationToken,
      });
    }

    return;
  }

  // Legacy Playlist Instance path.
  if (thumb) {
    runToThumbs({
      navigate,
      data,
      cta,
      ctaAudience,
      psi,
      thumb,
      demo,
      returnTo,
      reservationToken,
    });
    return;
  }

  runToPalette({
    navigate,
    data,
    cta,
    ctaAudience,
    psi,
    thumb,
    demo,
    returnTo,
    playerRef,
  });
}

function runToThumbs({
  navigate,
  data,
  cta,
  ctaAudience,
  psi,
  thumb,
  demo,
  returnTo,
  reservationToken,
}) {
  if (!data?.playlist_instance_id && !reservationToken) return;

  if (reservationToken) {
    const resolvedUrl = normalizeInternalRexPath(data?.colors_used_url);
    if (data?.colors_used_destination === "viewer" && resolvedUrl) {
      const originPlaylist = rexPlaylistOrigin(data, reservationToken);
      const url = withSourceParam(appendParams(resolvedUrl, {
        origin_playlist: withSourceParam(originPlaylist) || undefined,
      }));
      const target = cta?.params?.target || "_self";

      if (navigate && shouldNavigateInPlayerShell(url)) {
        navigate(url);
        return;
      }

      window.open(url, target, "noopener");
      return;
    }

    const viewerUrls = Array.isArray(data?.viewer_rex_urls)
      ? data.viewer_rex_urls.filter((url) => normalizeInternalRexPath(url))
      : [];

    if (viewerUrls.length === 1) {
      const originPlaylist = rexPlaylistOrigin(data, reservationToken);
      const url = withSourceParam(appendParams(viewerUrls[0], {
        origin_playlist: withSourceParam(originPlaylist) || undefined,
      }));
      const target = cta?.params?.target || "_self";

      if (navigate && shouldNavigateInPlayerShell(url)) {
        navigate(url);
        return;
      }

      window.open(url, target, "noopener");
      return;
    }
  }

  const params = new URLSearchParams();

  if (cta?.params?.mode) {
    params.set("mode", cta.params.mode);
  }

  if (cta?.params?.add_cta_group !== undefined) {
    params.set("add_cta_group", String(cta.params.add_cta_group));
  }

  const audience =
    cta?.params?.aud ||
    cta?.params?.audience ||
    ctaAudience;

  if (audience) {
    params.set("aud", String(audience));
  }

  if (psi) {
    params.set("psi", String(psi));
  }

  if (thumb) {
    params.set("thumb", "1");
  }

  if (demo) {
    params.set("demo", "1");
  }

  if (returnTo) {
    params.set("return_to", withSourceParam(returnTo));
  }

  let url = "";

  if (reservationToken) {
    const originPlaylist = rexPlaylistOrigin(data, reservationToken);
    if (originPlaylist) {
      params.set("origin_playlist", withSourceParam(originPlaylist));
    }

    url = appendParams(
      `/playlist-thumbs/${encodeURIComponent(String(data?.playlist_id || "rex"))}`,
      {
        reservation_token: reservationToken,
        return_to: withSourceParam(originPlaylist || `/t/${encodeURIComponent(String(reservationToken))}`),
        origin_playlist: withSourceParam(originPlaylist) || undefined,
      }
    );
  } else {
    const query = params.toString();

    url =
      `/playlist-thumbs/${encodeURIComponent(String(data.playlist_instance_id))}` +
      `${query ? `?${query}` : ""}`;
  }

  url = withSourceParam(url);

  if (navigate && shouldNavigateInPlayerShell(url)) {
    navigate(url);
    return;
  }

  const target = cta?.params?.target || "_self";
  window.open(url, target, "noopener");
}

function getCurrentPaletteItem(data, playerRef) {
  const current = playerRef?.current?.getCurrentItem?.() || null;

  if (isPaletteEligibleItem(current)) {
    return current;
  }

  const palettes = getPaletteTargets(data);
  return palettes.length === 1 ? palettes[0] : null;
}

function runToPalette({
  navigate,
  data,
  cta,
  ctaAudience,
  psi,
  thumb,
  demo,
  returnTo,
  playerRef,
  reservationToken,
}) {
  if (reservationToken) {
    const resolvedUrl = normalizeInternalRexPath(data?.colors_used_url);
    if (data?.colors_used_destination === "viewer" && resolvedUrl) {
      const originPlaylist = rexPlaylistOrigin(data, reservationToken);
      const url = withSourceParam(appendParams(resolvedUrl, {
        origin_playlist: withSourceParam(originPlaylist) || undefined,
      }));
      const target = cta?.params?.target || "_self";

      if (navigate && shouldNavigateInPlayerShell(url)) {
        navigate(url);
        return;
      }

      window.open(url, target, "noopener");
      return;
    }

    const viewerUrls = Array.isArray(data?.viewer_rex_urls)
      ? data.viewer_rex_urls.filter((url) => normalizeInternalRexPath(url))
      : [];

    if (viewerUrls.length !== 1 || data?.colors_used_destination === "thumbs") {
      runToThumbs({
        navigate,
        data,
        cta,
        ctaAudience,
        psi,
        thumb: false,
        demo,
        returnTo,
        reservationToken,
      });
      return;
    }

    const originPlaylist = rexPlaylistOrigin(data, reservationToken);
    const url = withSourceParam(appendParams(viewerUrls[0], {
      origin_playlist: withSourceParam(originPlaylist) || undefined,
    }));
    const target = cta?.params?.target || "_self";

    if (navigate && shouldNavigateInPlayerShell(url)) {
      navigate(url);
      return;
    }

    window.open(url, target, "noopener");
    return;
  }

  const targetItem = getCurrentPaletteItem(data, playerRef);

  if (!targetItem) return;

  const apId = targetItem.ap_id;
  const paletteHash = targetItem.palette_hash;
  const savedPaletteSetId = Number(targetItem.saved_palette_set_id || 0);

  const requestedViewer = String(
    cta?.params?.viewer ||
    cta?.params?.viewer_key ||
    cta?.params?.palette_viewer_key ||
    ""
  ).toLowerCase();

  const paletteViewerUrl =
    requestedViewer === "painter"
      ? targetItem.painter_palette_viewer_url || targetItem.palette_viewer_url || ""
      : targetItem.palette_viewer_url || "";

  if (!paletteHash && !savedPaletteSetId) return;

  const params = new URLSearchParams();

  if (cta?.params?.add_cta_group !== undefined) {
    params.set("add_cta_group", String(cta.params.add_cta_group));
  } else if (data?.palette_viewer_cta_group_id) {
    params.set(
      "add_cta_group",
      String(data.palette_viewer_cta_group_id)
    );
  }

  const audience =
    cta?.params?.aud ||
    cta?.params?.audience ||
    ctaAudience;

  if (audience) {
    params.set("aud", String(audience));
  }

  if (psi) {
    params.set("psi", String(psi));
  }

  if (thumb) {
    params.set("thumb", "1");
  }

  if (demo) {
    params.set("demo", "1");
  }

  const resolvedReturnTo =
    cta?.params?.return_to ||
    cta?.params?.returnTo ||
    returnTo;

  if (resolvedReturnTo) {
    params.set("return_to", withSourceParam(resolvedReturnTo));
  }

  const originPlaylist = rexPlaylistOrigin(data, reservationToken);
  if (originPlaylist) {
    params.set("origin_playlist", withSourceParam(originPlaylist));
  }

  if (savedPaletteSetId > 0) {
    params.set("set_id", String(savedPaletteSetId));
  }

  const qs = params.toString();

  const url = withSourceParam(paletteViewerUrl
    ? appendParams(
        paletteViewerUrl,
        Object.fromEntries(params.entries())
      )
    : !reservationToken && paletteHash
      ? `/palette/${paletteHash}/share${qs ? `?${qs}` : ""}`
      : "");

  if (!url) return;

  if (navigate && shouldNavigateInPlayerShell(url)) {
    navigate(url);
    return;
  }

  const target = cta?.params?.target || "_self";
  window.open(url, target, "noopener");
}

function runToReservedViewer({ navigate, cta, returnTo }) {
  const url = withSourceParam(normalizeInternalReturnPath(
    cta?.params?.return_to ||
    cta?.params?.returnTo ||
    cta?.data?.reserved_viewer_url ||
    cta?.data?.originating_reserved_viewer_url ||
    returnTo
  ));

  if (!url) {
    if (typeof window !== "undefined" && window.history.length > 1) {
      window.history.back();
    }
    return;
  }

  if (navigate && shouldNavigateInPlayerShell(url)) {
    navigate(url);
    return;
  }

  const target = cta?.params?.target || "_self";
  window.open(url, target, "noopener");
}

function normalizeInternalReturnPath(value) {
  const trimmed = String(value || "").trim();

  if (!trimmed) return "";
  if (!trimmed.startsWith("/") || trimmed.startsWith("//")) return "";

  return trimmed;
}

function rexPlaylistOrigin(data, reservationToken) {
  const explicit = normalizeInternalRexPath(data?.reserved_playlist_url);
  if (explicit) return explicit;

  const token = String(reservationToken || data?.reservation_token || data?.rex_token || "").trim();
  if (!token) return "";

  return withSourceParam(`/t/${encodeURIComponent(token)}`);
}

function normalizeInternalRexPath(value) {
  const path = normalizeInternalReturnPath(value);
  if (!path || !path.startsWith("/t/")) return "";

  return path;
}

function shouldNavigateInPlayerShell(url) {
  if (!url || !url.startsWith("/")) return false;

  if (isFastPlayerShell()) {
    return (
      url.startsWith("/p/") ||
      url.startsWith("/playlist/") ||
      url.startsWith("/t/")
    );
  }

  return (
    url.startsWith("/p/") ||
    url.startsWith("/playlist/") ||
    url.startsWith("/t/") ||
    url.startsWith("/playlist-thumbs/") ||
    url.startsWith("/picker") ||
    url.startsWith("/palette/")
  );
}

function isFastPlayerShell() {
  return (
    typeof window !== "undefined" &&
    (
      window.location.pathname === "/p" ||
      window.location.pathname.startsWith("/p/")
    )
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
  reservationToken,
  shareSource = "share",
} = {}) {
  const replayStartIndex =
    data?.skip_intro_on_replay
      ? firstNonIntroIndex
      : 0;

  return {
    replay: () => {
      setPlaybackEnded?.(false);
      playerRef?.current?.replay({
        likedOnly: false,
        startIndex: replayStartIndex,
      });
    },

    replay_liked: () => {
      setPlaybackEnded?.(false);
      playerRef?.current?.replay({
        likedOnly: true,
      });
    },

    replay_filtered: (cta) => {
      const filter = cta?.params?.filter || "";
      const likedOnly = filter === "liked";

      setPlaybackEnded?.(false);
      playerRef?.current?.replay({
        likedOnly,
      });
    },

    jump_to_item: (cta) => {
      const index = Number(cta?.params?.item_index);

      if (Number.isNaN(index)) return;

      setPlaybackEnded?.(false);
      playerRef?.current?.replay({
        likedOnly: false,
        startIndex: index,
      });
    },

    share: () =>
      runShare({
        data,
        shareFolder,
        shareSource,
      }),

    copy_link: () =>
      runCopyLink({
        data,
        shareFolder,
        shareSource,
      }),

    share_playlist: () =>
      runShare({
        data,
        shareFolder,
        shareSource,
      }),

    navigate: (cta) =>
      runNavigate({
        navigate,
        cta: {
          ...cta,
          data: {
            ...(data || {}),
            ...(cta?.data || {}),
          },
        },
        psi,
        thumb,
        demo,
      }),

    article_link: (cta) =>
      runArticleLink({
        navigate,
        cta,
      }),

    playlist_link: (cta) =>
      runPlaylistLink({
        navigate,
        cta: {
          ...cta,
          data: {
            ...(data || {}),
            ...(cta?.data || {}),
          },
        },
      }),

    watch_next: (cta) =>
      runPlaylistLink({
        navigate,
        cta: {
          ...cta,
          data: {
            ...(data || {}),
            ...(cta?.data || {}),
          },
        },
        source: "watch_next",
      }),

    see_colors_used: (cta) =>
      runSeeColorsUsed({
        navigate,
        cta,
        data,
        ctaAudience,
        psi,
        thumb,
        demo,
        returnTo,
        playerRef,
        reservationToken,
      }),

    to_thumbs: (cta) =>
      runToThumbs({
        navigate,
        data,
        cta,
        ctaAudience,
        psi,
        thumb,
        demo,
        returnTo,
        reservationToken,
      }),

    to_palette: (cta) =>
      runToPalette({
        navigate,
        data,
        cta,
        ctaAudience,
        psi,
        thumb,
        demo,
        returnTo,
        playerRef,
        reservationToken,
      }),

    to_reserved_viewer: (cta) =>
      runToReservedViewer({
        navigate,
        cta: {
          ...cta,
          data: {
            ...(data || {}),
            ...(cta?.data || {}),
          },
        },
        returnTo,
      }),

    exit: () => handleExit?.(),
  };
}
