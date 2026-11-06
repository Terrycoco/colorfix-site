import { useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowLeft, Copy, MessageSquare, Play, QrCode, Search, Share2, X } from 'lucide-react';
import { openSmsComposer } from '../services/podShare';
import { ensurePlaylistRex, listRexPlaylists } from '../services/playlistsApi';
import SASSignIn from './SASSignIn';

export default function Playlists() {
  const [items, setItems] = useState([]);
  const [query, setQuery] = useState('');
  const [selected, setSelected] = useState(null);
  const [showQr, setShowQr] = useState(false);
  const [copied, setCopied] = useState(false);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(true);
  const [authRequired, setAuthRequired] = useState(false);
  const [retry, setRetry] = useState(0);
  const [preparing, setPreparing] = useState(false);
  const selectionRequest = useRef(null);
  useEffect(() => () => selectionRequest.current?.abort(), []);
  useEffect(() => {
    if (!copied) return;
    const timeout = setTimeout(() => setCopied(false), 2000);
    return () => clearTimeout(timeout);
  }, [copied]);

  useEffect(() => {
    if (authRequired) return;
    const controller = new AbortController();
    setLoading(true);
    setError('');
    listRexPlaylists(controller.signal)
      .then(setItems)
      .catch(err => {
        if (controller.signal.aborted) return;
        if (err.status === 401) setAuthRequired(true);
        else setError(err.message || 'Could not load playlists');
      })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [authRequired, retry]);

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    return items.filter(p => !q || String(p.title || '').trim().toLowerCase().startsWith(q));
  }, [items, query]);

  const choose = async (playlist, experience) => {
    // Resolve once, before showing sharing actions. Quick Play and all share
    // actions then use this exact URL without creating or dispatching anything.
    if (selectionRequest.current) return;
    const controller = new AbortController();
    selectionRequest.current = controller;
    setPreparing(true); setError('');
    try {
      const url = await ensurePlaylistRex(playlist.playlist_id, experience.experience_key, controller.signal);
      if (!controller.signal.aborted) {
        setSelected({ playlist, experience, url }); setShowQr(false); setCopied(false);
      }
    } catch (err) {
      if (controller.signal.aborted) return;
      if (err.status === 401) setAuthRequired(true);
      else setError(err.message || 'Could not prepare playlist');
    } finally {
      selectionRequest.current = null;
      if (!controller.signal.aborted) setPreparing(false);
    }
  };
  const url = selected?.url || '';
  const qrUrl = url ? `https://api.qrserver.com/v1/create-qr-code/?size=320x320&data=${encodeURIComponent(url)}` : '';
  async function copy() {
    try { await navigator.clipboard.writeText(url); setCopied(true); setError(''); }
    catch { setError('Copy failed. Select the URL below to copy manually.'); }
  }
  async function share() {
    if (!navigator.share) { setError('Sharing unavailable here. Use Text or Copy Link.'); return; }
    try { await navigator.share({ title: selected.playlist.title, url }); setError(''); }
    catch (err) { if (err?.name !== 'AbortError') setError('Could not open share sheet.'); }
  }
  const label = e => e.experience_key || e.label || 'Playlist';

  if (authRequired) return <main className="sas-content">
    <Link className="sas-back" to="/"><ArrowLeft size={20}/>Back</Link>
    <SASSignIn onSignedIn={() => { setAuthRequired(false); setRetry(n => n + 1); }} />
  </main>;

  return <main className="sas-content sas-content--playlists">
    {selected ? <button className="sas-share-back" type="button" onClick={() => { setSelected(null); setError(''); }}><ArrowLeft size={20}/>Playlists</button>
      : <Link className="sas-back" to="/"><ArrowLeft size={20}/>Back</Link>}
    <h1>{selected ? selected.playlist.title : 'Playlists'}</h1>
    {selected ? <>
      <p className="sas-share-summary">Experience: <strong>{label(selected.experience)}</strong></p>
      <div className="sas-share-options">
        <button type="button" disabled={!url} onClick={() => openSmsComposer(url)}><MessageSquare/><strong>Text</strong><small>Open SMS</small></button>
        <button type="button" disabled={!url} onClick={() => setShowQr(v => !v)}><QrCode/><strong>QR Code</strong><small>Show to scan</small></button>
        <button type="button" disabled={!url} onClick={share}><Share2/><strong>Share</strong><small>AirDrop & more</small></button>
        <button type="button" disabled={!url} onClick={() => window.open(url, '_blank', 'noopener,noreferrer')}><Play/><strong>Quick Play</strong><small>Open playlist now</small></button>
      </div>
      {showQr && <div className="sas-share-qr"><img src={qrUrl} alt="Playlist QR code"/><p>Have the recipient scan this code.</p></div>}
      <label className="sas-share-url-label" htmlFor="sas-playlist-url">Share URL</label>
      <div className="sas-playlist-url-control">
        <button type="button" disabled={!url} onClick={copy}><Copy size={18}/><span>{copied ? 'Copied!' : 'Copy Link'}</span></button>
        <input id="sas-playlist-url" type="text" readOnly value={url} title={url} onFocus={e => e.target.select()}/>
      </div>
      <span className="sas-playlist-copy-status" role="status">{copied ? 'Link copied to clipboard.' : ''}</span>
    </> : <>
      <div className="sas-search"><Search size={20}/><input type="search" value={query} placeholder="Find a playlist" aria-label="Find a playlist" onChange={e => setQuery(e.target.value)}/>{query && <button type="button" aria-label="Clear search" onClick={() => setQuery('')}><X size={20}/></button>}</div>
      {loading && <p className="sas-photo-status">Loading playlists…</p>}
      {!loading && !error && !filtered.length && <p className="sas-photo-status">{query ? 'No matching playlists.' : 'No saved playlists.'}</p>}
      {preparing && <p className="sas-photo-status" role="status">Preparing playlist link…</p>}
      <div className="sas-quick-list sas-playlist-list" aria-busy={preparing}>
        {filtered.map(p => <section className="sas-playlist-item" key={p.playlist_id}>
          <strong>{p.title}</strong>
          <div className="sas-playlist-experiences">
            {p.experiences.map(e => <button key={e.experience_key} type="button" disabled={preparing} onClick={() => choose(p,e)}>{label(e)}</button>)}
          </div>
        </section>)}
      </div>
    </>}
    {error && <div className="sas-photo-error" role="alert">{error}{!selected && <button type="button" onClick={() => setRetry(n => n + 1)}>Retry</button>}</div>}
  </main>;
}
