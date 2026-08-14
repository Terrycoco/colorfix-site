export const SOURCE_QUERY_PARAM = "src";

export function getCurrentSourceParam(search) {
  const rawSearch =
    search !== undefined
      ? String(search || "")
      : typeof window !== "undefined"
        ? window.location.search
        : "";

  try {
    return new URLSearchParams(rawSearch).get(SOURCE_QUERY_PARAM) || "";
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

  if (!value || !target) return url;
  if (target.startsWith("#")) return url;

  try {
    const currentOrigin =
      typeof window !== "undefined" && window.location?.origin
        ? window.location.origin
        : "http://colorfix.local";
    const parsed = new URL(target, currentOrigin);
    const isAbsolute = /^[a-z][a-z0-9+.-]*:/i.test(target);

    if (isAbsolute && parsed.origin !== currentOrigin) {
      return url;
    }

    parsed.searchParams.set(SOURCE_QUERY_PARAM, value);

    if (isAbsolute) return parsed.toString();
    return `${parsed.pathname}${parsed.search}${parsed.hash}`;
  } catch {
    return url;
  }
}
