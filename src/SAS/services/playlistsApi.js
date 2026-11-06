const ENDPOINT = '/api/v2/admin/sas/list-rex-playlists.php';

export async function listRexPlaylists(signal) {
  const response = await fetch(ENDPOINT, { credentials: 'include', signal });
  const data = await response.json().catch(() => null);
  if (!response.ok || data?.ok !== true) {
    const error = new Error(data?.error || `Playlist lookup failed (HTTP ${response.status})`);
    error.status = response.status;
    throw error;
  }
  return Array.isArray(data.items) ? data.items : [];
}

// Use the desktop Admin Play endpoint: it reuses or ensures the selected REX.
// Never call this while listing/searching, or from a sharing action.
export async function ensurePlaylistRex(playlistId, experienceKey, signal) {
  const params = new URLSearchParams({ playlist_id: String(playlistId), experience_key: experienceKey });
  const response = await fetch(`/api/v2/admin/rex/playlist-url.php?${params}`, {
    credentials: 'include', cache: 'no-store', signal,
  });
  const data = await response.json().catch(() => null);
  const url = data?.item ? sasRexUrl(data.item.token, data.item.public_url) : '';
  if (!response.ok || data?.ok !== true || !url) {
    const error = new Error(data?.error || data?.reason || 'Could not prepare this playlist link.');
    error.status = response.status;
    throw error;
  }
  return url;
}

// Preserve the returned link parameters and append existing SAS attribution.
export function sasRexUrl(token, publicUrl) {
  if (typeof token !== 'string' || !/^[A-Za-z0-9_-]+$/.test(token)) return '';
  const url = new URL(publicUrl || `/t/${token}`, 'https://colorfix.terrymarr.com');
  url.searchParams.set('src', 'sas');
  return url.toString();
}
