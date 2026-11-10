import { useEffect, useMemo, useState } from 'react';
import Gallery from '@components/Gallery/Gallery';
import { API_FOLDER } from '@helpers/config';
import TopSpacer from '@layout/TopSpacer';

// The Gallery component already owns the gold art frames and detail links.
const galleryMeta = { item_type: 'art-swatch', has_header: 0 };

function artistSurname(fullName) {
  const name = String(fullName || '').trim();
  if (!name) return '';
  // Last-name wording for the hover tooltip, keeping the full name in metadata.
  return name.split(/\s+/).at(-1);
}

export default function ArtGalleryPage() {
  const [items, setItems] = useState([]);
  const [status, setStatus] = useState('loading');
  const [error, setError] = useState('');

  useEffect(() => {
    const controller = new AbortController();
    async function load() {
      try {
        const response = await fetch(`${API_FOLDER}/v2/artwork/page.php?action=list`, {
          signal: controller.signal,
        });
        if (!response.ok) throw new Error(`Artwork gallery HTTP ${response.status}`);
        const data = await response.json();
        if (!data.ok || !Array.isArray(data.items)) throw new Error(data.error || 'Invalid artwork response');
        if (!controller.signal.aborted) {
          setItems(data.items);
          setStatus('ready');
        }
      } catch (err) {
        if (!controller.signal.aborted) {
          setError(err.message || 'Unable to load artworks');
          setStatus('error');
        }
      }
    }
    load();
    return () => controller.abort();
  }, []);

  const galleryItems = useMemo(() => {
    const collator = new Intl.Collator(undefined, { sensitivity: 'base', numeric: true });
    return items.map(item => ({
      ...item,
      artist: artistSurname(item.artist),
    })).sort((a, b) => {
      const byArtist = collator.compare(a.artist || '', b.artist || '');
      if (byArtist) return byArtist;
      const titleA = a.display_title || a.official_title || a.title || '';
      const titleB = b.display_title || b.official_title || b.title || '';
      return collator.compare(titleA, titleB);
    });
  }, [items]);

  return (
    <div className="gallery-wrapper art-gallery-page">
      <TopSpacer />
      <header style={{ textAlign: 'center', padding: '16px 16px 22px' }}>
        <h1 style={{ fontSize: 'clamp(1.65rem, 3vw, 2.2rem)', margin: '0 0 6px' }}>Art Gallery</h1>
        <p style={{ margin: 0, opacity: 0.78 }}>Palettes Inspired by Great Works of Art</p>
      </header>
      {status === 'loading' && <p role="status" style={{ textAlign: 'center' }}>Loading artworks…</p>}
      {status === 'error' && <p role="alert" style={{ textAlign: 'center' }}>{error}</p>}
      {status === 'ready' && (galleryItems.length
        ? <Gallery items={galleryItems} meta={galleryMeta} />
        : <p style={{ textAlign: 'center' }}>No artworks are currently available.</p>)}
    </div>
  );
}
