export function toFastPlayerPath(url) {
  const rawUrl = String(url || "").trim();
  if (!rawUrl) return rawUrl;
  try {
    const parsed = new URL(rawUrl, window.location.origin);
    if (parsed.pathname.startsWith("/playlist/")) {
      parsed.pathname = parsed.pathname.replace(/^\/playlist\//, "/p/");
    }
    if (/^https?:\/\//i.test(rawUrl)) return parsed.toString();
    return `${parsed.pathname}${parsed.search}${parsed.hash}`;
  } catch {
    return rawUrl.replace(/^\/playlist\//, "/p/");
  }
}
