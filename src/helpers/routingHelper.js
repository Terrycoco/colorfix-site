export function buildResultsUrl(queryId, paramObj = {}) {
  const search = new URLSearchParams(paramObj).toString(); // converts to key=value&key2=value2
  return `/results/${queryId}${search ? `?${search}` : ''}`;
}

export function isAdminPath(pathname) {
  return pathname === "/admin" || pathname.startsWith("/admin/");
}

// Root-relative destinations belong to the site root, regardless of the
// current app. Admin navigation must explicitly target /admin/....
export function resolveAppPath(target) {
  return String(target || '').trim();
}

// The existing homepage marketing tile now has a permanent public address.
// Other picker contexts (versions, returns, audiences, other sets) stay intact.
export function resolveBrowsePlaylistsMarketingUrl(url, handle) {
  if (handle !== "BrowsePlaylistsTile") return url;
  try {
    const parsed = new URL(url, "https://colorfix.terrymarr.com");
    if (!String(url).startsWith("/") || String(url).startsWith("//")
      || !["/picker", "/picker/"].includes(parsed.pathname)
      || parsed.searchParams.get("set") !== "11"
      || [...parsed.searchParams.keys()].some(key => !["set", "src"].includes(key))) return url;
    parsed.pathname = "/browse-playlists";
    parsed.searchParams.delete("set");
    return `${parsed.pathname}${parsed.search}${parsed.hash}`;
  } catch { return url; }
}
