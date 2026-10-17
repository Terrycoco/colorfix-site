import './front-page-playlist-set-item.css';
import { photoThumbUrl } from '@helpers/imageThumb';
import { toFastPlayerPath } from '@helpers/playerUrls';
import { withSourceParam } from '@helpers/sourceParam';

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
            key={tile.id || tile.rex_url || tile.player_url}
            className="front-page-playlist-set-item__tile"
            href={withSourceParam(tile.rex_url || toFastPlayerPath(tile.player_url))}
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

export default FrontPagePlaylistSetItem;
