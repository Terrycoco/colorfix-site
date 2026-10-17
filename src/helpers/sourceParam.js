export const SOURCE_QUERY_PARAM = "src";
const ANA_SOURCE_KEY = "ana_src";
let initialized = false;

export function sourceFromReferrer(referrer, origin) {
  try {
    const url = new URL(referrer);
    if (url.origin === origin) return "";
    const host = url.hostname.toLowerCase();
    if (/^(www\.)?google\.(com|[a-z]{2}|co\.[a-z]{2}|com\.[a-z]{2})$/.test(host)) return "google";
    if (host === "bing.com" || host.endsWith(".bing.com")) return "bing";
    if (host === "duckduckgo.com" || host.endsWith(".duckduckgo.com")) return "duckduckgo";
    if (host === "search.yahoo.com" || host.endsWith(".search.yahoo.com")) return "yahoo";
    if (host === "facebook.com" || host.endsWith(".facebook.com") || host === "fb.com") return "facebook";
    if (host === "youtube.com" || host.endsWith(".youtube.com") || host === "youtu.be") return "yt";
    if (/^(www\.|[a-z]{2}\.)?pinterest\.(com|[a-z]{2}|co\.[a-z]{2}|com\.[a-z]{2})$/.test(host)
      || host === "pin.it") return "pin";
  } catch { /* No usable external referrer. */ }
  return "";
}

function rememberSource(source) {
  try { window.sessionStorage.setItem(ANA_SOURCE_KEY, source); } catch { /* Storage is optional. */ }
}

export function getCurrentSourceParam(search) {
  const rawSearch =
    search !== undefined
      ? String(search || "")
      : typeof window !== "undefined"
        ? window.location.search
        : "";

  try {
    const source = new URLSearchParams(rawSearch).get(SOURCE_QUERY_PARAM)?.trim() || "";
    if (source || search !== undefined || !initialized) return source;
    return window.sessionStorage.getItem(ANA_SOURCE_KEY) || "";
  } catch {
    return "";
  }
}

export function applySourceToParams(params, source = getCurrentSourceParam()) {
  const value = String(source || "").trim();
  if (!value) return params;
  params.set(SOURCE_QUERY_PARAM, value);
  return params;
}

export function withSourceParam(url, source = getCurrentSourceParam()) {
  const value = String(source || "").trim();
  const target = String(url || "").trim();

  if (!target || (!value && !initialized)) return url;
  if (target.startsWith("#")) return url;

  try {
    const currentOrigin =
      typeof window !== "undefined" && window.location?.origin
        ? window.location.origin
        : "http://colorfix.local";
    const parsed = new URL(target, typeof window !== "undefined" ? window.location.href : currentOrigin);
    const isAbsolute = /^[a-z][a-z0-9+.-]*:/i.test(target);

    if (!/^https?:$/.test(parsed.protocol) || parsed.origin !== currentOrigin) {
      return url;
    }

    if (value) parsed.searchParams.set(SOURCE_QUERY_PARAM, value);
    else parsed.searchParams.delete(SOURCE_QUERY_PARAM);

    if (isAbsolute) return parsed.toString();
    return `${parsed.pathname}${parsed.search}${parsed.hash}`;
  } catch {
    return url;
  }
}

function isNavigationUrl(value) {
  if (!value || String(value).startsWith("#")) return false;
  try {
    const url = new URL(value, window.location.href);
    return /^https?:$/.test(url.protocol) && url.origin === window.location.origin
      && !/^\/(api|admin|sas|assets|images|uploads)(\/|$)/.test(url.pathname)
      && !/\.[a-z0-9]+$/i.test(url.pathname);
  } catch { return false; }
}

// Establish attribution once per document entrance; URLs carry it between documents.
export function initializeSourcePropagation() {
  if (initialized || typeof window === "undefined") return;
  initialized = true;
  let internalReferrer = false;
  try { internalReferrer = new URL(document.referrer).origin === window.location.origin; } catch { /* Direct entrance. */ }
  let inherited = "";
  try { if (internalReferrer) inherited = window.sessionStorage.getItem(ANA_SOURCE_KEY) || ""; } catch { /* Storage is optional. */ }
  const source = getCurrentSourceParam(window.location.search)
    || sourceFromReferrer(document.referrer, window.location.origin)
    || inherited || "";
  rememberSource(source);

  const replace = window.history.replaceState.bind(window.history);
  if (isNavigationUrl(window.location.href)) {
    replace(window.history.state, "", withSourceParam(window.location.href, source));
  }

  for (const method of ["pushState", "replaceState"]) {
    const original = window.history[method].bind(window.history);
    window.history[method] = (state, title, url) => original(state, title,
      isNavigationUrl(url) ? withSourceParam(url) : url);
  }

  const updateLink = (link) => {
    const href = link.getAttribute("href");
    if (link.hasAttribute("download") || !isNavigationUrl(href)) return;
    const next = withSourceParam(href);
    if (href !== next) link.setAttribute("href", next);
  };
  const updateLinks = (root) => {
    if (root.matches?.("a[href]")) updateLink(root);
    root.querySelectorAll?.("a[href]").forEach(updateLink);
  };
  updateLinks(document);
  new MutationObserver((records) => {
    for (const record of records) {
      if (record.type === "attributes") updateLink(record.target);
      else record.addedNodes.forEach(updateLinks);
    }
  }).observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ["href"] });
  // Capture before React's Link handlers, including dynamically changed hrefs.
  const prepareLink = (event) => {
    const link = event.target.closest?.("a[href]");
    if (link) updateLink(link);
  };
  document.addEventListener("click", prepareLink, true);
  document.addEventListener("auxclick", prepareLink, true);
  window.addEventListener("popstate", () => {
    rememberSource(getCurrentSourceParam(window.location.search));
    updateLinks(document);
  });
}
