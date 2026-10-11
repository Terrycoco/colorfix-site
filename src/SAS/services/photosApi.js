const ENDPOINT = '/api/v2/admin/sas/search-photos.php';

/** The only function the Photos screen needs to search. */
export async function searchPhotos(query, page = 1, signal) {
  const params = new URLSearchParams({ q: query.trim(), page: String(page), per_page: '30' });
  const response = await fetch(`${ENDPOINT}?${params}`, { credentials: 'include', signal });
  const data = await response.json().catch(() => null);
  if (!response.ok || data?.ok !== true) {
    throw new Error(data?.error || `Photo search failed (HTTP ${response.status})`);
  }
  return {
    items: Array.isArray(data.items) ? data.items : [],
    total: Number(data.total || 0),
    page: Number(data.page || page),
    perPage: Number(data.per_page || 30),
  };
}

/**
 * Current SAS API emits web-root-relative rel_path values (/photos/...).
 * Keep URL resolution isolated so it can use the shared buildImageUrl helper
 * when this standalone entrypoint has access to that module.
 */
export function photoImageUrl(photo) {
  const path = String(photo?.rel_path || '').trim();
  if (!path) return '';
  if (path.startsWith('/photos/')) return path;
  if (path.startsWith('photos/')) return `/${path}`;
  return '';
}
