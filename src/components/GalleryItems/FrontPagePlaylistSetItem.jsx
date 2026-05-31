import './front-page-playlist-set-item.css';
import { photoThumbUrl } from '@helpers/imageThumb';
import { toFastPlayerPath } from '@helpers/playerUrls';

const FrontPagePlaylistSetItem = ({ item }) => {
  const tiles = Array.isArray(item?.items) ? item.items : [];
  if (!tiles.length) return null;

  return (
    <section className="front-page-playlist-set-item">
      <div className="front-page-playlist-set-item__tiles">
        {tiles.map((tile, index) => {
          const imageSrc = photoThumbUrl(tile.photo_library_id, 480, 72, tile.photo_url) || tile.photo_url;
          return (
          <a
            key={tile.id || tile.player_url}
            className="front-page-playlist-set-item__tile"
            href={appendSourceTag(toFastPlayerPath(tile.player_url), 'site')}
          >
            <div className="front-page-playlist-set-item__image">
              {imageSrc ? (
                <img
                  src={imageSrc}
                  alt={tile.title || 'Playlist'}
                  loading={index === 0 ? 'eager' : 'lazy'}
                  fetchPriority={index === 0 ? 'high' : 'auto'}
                  decoding="async"
                />
              ) : (
                <div className="front-page-playlist-set-item__placeholder">No Image</div>
              )}
            </div>
            <div className="front-page-playlist-set-item__title">{tile.title}</div>
            {tile.subtitle ? (
              <div className="front-page-playlist-set-item__subtitle">{tile.subtitle}</div>
            ) : null}
          </a>
        )})}
      </div>
    </section>
  );
};

function appendSourceTag(url, source) {
  const rawUrl = String(url || '').trim();
  const normalizedSource = String(source || '').trim().toLowerCase();
  if (!rawUrl || !normalizedSource) return rawUrl;

  if (/^https?:\/\//i.test(rawUrl)) {
    try {
      const parsed = new URL(rawUrl);
      parsed.searchParams.set('src', normalizedSource);
      return parsed.toString();
    } catch {
      return rawUrl;
    }
  }

  try {
    const parsed = new URL(rawUrl, window.location.origin);
    parsed.searchParams.set('src', normalizedSource);
    return `${parsed.pathname}${parsed.search}${parsed.hash}`;
  } catch {
    const separator = rawUrl.includes('?') ? '&' : '?';
    return `${rawUrl}${separator}src=${encodeURIComponent(normalizedSource)}`;
  }
}

export default FrontPagePlaylistSetItem;
