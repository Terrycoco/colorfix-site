import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowLeft, Check, Images, Search, X } from 'lucide-react';
import { photoImageUrl, searchPhotos } from '../services/photosApi';
import SharePhotos from './SharePhotos';

export default function Photos() {
  const [query, setQuery] = useState('');
  const [page, setPage] = useState(1);
  const [results, setResults] = useState([]);
  const [total, setTotal] = useState(0);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [retry, setRetry] = useState(0);
  const [selected, setSelected] = useState(() => new Map());
  const [sharing, setSharing] = useState(false);
  const generation = useRef(0);

  useEffect(() => {
    const controller = new AbortController();
    const request = ++generation.current;
    if (!query.trim()) {
      setResults([]);
      setTotal(0);
      setBusy(false);
      setError('');
      return () => controller.abort();
    }
    setBusy(true);
    setError('');
    // Debounce typing only, not the number of displayed results.
    const timer = setTimeout(async () => {
      try {
        const result = await searchPhotos(query, page, controller.signal);
        if (generation.current !== request) return;
        setResults(old => page === 1 ? result.items : [
          ...old,
          ...result.items.filter(item => !old.some(existing => Number(existing.photo_id) === Number(item.photo_id))),
        ]);
        setTotal(result.total);
      } catch (err) {
        if (controller.signal.aborted || generation.current !== request) return;
        setError(err.message || 'Could not load photos');
      } finally {
        if (generation.current === request) setBusy(false);
      }
    }, page === 1 ? 180 : 0);
    return () => { clearTimeout(timer); controller.abort(); };
  }, [query, page, retry]);

  const changeQuery = (value) => {
    setQuery(value);
    setPage(1);
    setResults([]);
    setSharing(false);
  };
  const toggle = photo => {
    const id = Number(photo.photo_id);
    setSelected(current => {
      const next = new Map(current);
      if (next.has(id)) next.delete(id);
      else next.set(id, photo);
      return next;
    });
  };
  const displayed = results;
  const hasMore = results.length < total;

  if (sharing) return <main className="sas-content sas-content--photos"><SharePhotos photoIds={Array.from(selected.keys())} onBack={() => setSharing(false)} /></main>;

  return (
    <main className="sas-content sas-content--photos">
      <Link className="sas-back" to="/"><ArrowLeft size={20} aria-hidden="true"/>Back</Link>
      <h1>Photos</h1>
      {<div className="sas-search">
        <Search size={20} aria-hidden="true" />
        <input aria-label="Search photos" type="search" placeholder="Search photos" value={query}
          onChange={event => changeQuery(event.target.value)} />
        {query && <button type="button" title="Clear search" aria-label="Clear search" onClick={() => changeQuery('')}><X size={20}/></button>}
      </div>}
      {error && <div className="sas-photo-error" role="alert">{error} <button type="button" onClick={() => { setPage(1); setRetry(n => n + 1); }}>Retry</button></div>}
      {!query.trim() && <p className="sas-photo-status">Type a word to find photos.</p>}
      <div className="sas-photo-results" aria-busy={busy}>
        {displayed.map(photo => {
          const id = Number(photo.photo_id);
          const checked = selected.has(id);
          const url = photoImageUrl(photo);
          return <label className={`sas-photo-row${checked ? ' is-selected' : ''}`} key={id}>
            <span className="sas-photo-image">
              {url ? <img loading="lazy" src={url} alt={`Photo ${id}`} /> : <span className="sas-photo-missing"><Images size={24}/>Image unavailable</span>}
              <span className="sas-photo-id">#{id}</span>
            </span>
            <span className="sas-photo-check">
              <input type="checkbox" aria-label={`Select photo ${id}`} checked={checked} onChange={() => toggle(photo)} />
              <span className="sas-photo-check-icon" aria-hidden="true">{checked && <Check size={20}/>}</span>
            </span>
          </label>;
        })}
        {busy && <p className="sas-photo-status" role="status">Loading photos…</p>}
        {!busy && !error && query.trim() && results.length === 0 && <p className="sas-photo-status">No matching photos.</p>}
      </div>
      {!busy && hasMore && <button className="sas-load-more" type="button" onClick={() => setPage(p => p + 1)}>More photos</button>}
      <div className="sas-selection-bar" aria-live="polite">
        <span>{selected.size} selected</span>
        <div className="sas-selection-actions">
          {selected.size > 0 && <button type="button" className="sas-clear-selection" onClick={() => setSelected(new Map())}>Clear</button>}
          <button type="button" className="sas-continue" disabled={!selected.size} onClick={() => { setSharing(true); window.scrollTo({top:0,behavior:'instant'}); }}>
            Continue
          </button>
        </div>
      </div>
    </main>
  );
}
