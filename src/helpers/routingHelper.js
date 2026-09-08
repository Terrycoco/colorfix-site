export function buildResultsUrl(queryId, paramObj = {}) {
  const search = new URLSearchParams(paramObj).toString(); // converts to key=value&key2=value2
  return `/results/${queryId}${search ? `?${search}` : ''}`;
}

const ADMIN_PUBLIC_ROUTE_PREFIXES = [
  "/results/",
  "/color/",
  "/articles/",
  "/palette/",
];

const ADMIN_PUBLIC_ROUTES = new Set([
  "/search",
  "/quick-find",
  "/browse-palettes",
  "/matches",
  "/sbs",
  "/adv-search",
  "/adv-results",
  "/my-palette",
  "/login",
]);

export function isAdminPath(pathname) {
  return pathname === "/admin" || pathname.startsWith("/admin/");
}

export function hasAdminPublicRoute(pathname) {
  if (ADMIN_PUBLIC_ROUTES.has(pathname)) return true;
  return ADMIN_PUBLIC_ROUTE_PREFIXES.some((prefix) => pathname.startsWith(prefix));
}

export function resolveAppPath(target, currentPathname) {
  const value = String(target || "").trim();
  if (!value) return value;

  if (
    !value.startsWith("/") ||
    value.startsWith("/admin") ||
    value.startsWith("//") ||
    !isAdminPath(currentPathname)
  ) {
    return value;
  }

  const suffixIndex = value.search(/[?#]/);
  const pathname = suffixIndex >= 0 ? value.slice(0, suffixIndex) : value;
  const suffix = suffixIndex >= 0 ? value.slice(suffixIndex) : "";

  if (!hasAdminPublicRoute(pathname)) return value;
  return `/admin${pathname}${suffix}`;
}
