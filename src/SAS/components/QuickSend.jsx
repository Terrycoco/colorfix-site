import { useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowLeft, Copy, ExternalLink, MessageSquare, QrCode, Share2 } from 'lucide-react';
import { openSmsComposer } from '../services/podShare';
import { QUICK_SEND_LINKS, verifiedPublicUrl } from '../services/quickSendLinks';

export default function QuickSend() {
  const [chosen, setChosen] = useState(null);
  const [showQr, setShowQr] = useState(false);
  const [copied, setCopied] = useState(false);
  const [error, setError] = useState('');
  const select = link => { setChosen(link); setShowQr(false); setCopied(false); setError(''); };
  const url = chosen ? verifiedPublicUrl(chosen.url) : '';
  const qrUrl = url ? `https://api.qrserver.com/v1/create-qr-code/?size=320x320&data=${encodeURIComponent(url)}` : '';

  async function copy() {
    try { await navigator.clipboard.writeText(url); setCopied(true); setError(''); }
    catch { setError('Copy failed. Select the URL below to copy it manually.'); }
  }
  async function share() {
    if (!navigator.share) { setError('Sharing is unavailable in this browser. Use Text or Copy Link.'); return; }
    try { await navigator.share({ title: chosen.title, url }); setError(''); }
    catch (e) { if (e?.name !== 'AbortError') setError('Could not open the share sheet.'); }
  }

  return <main className="sas-content sas-content--quick">
    {chosen ? <button type="button" className="sas-share-back" onClick={() => select(null)}><ArrowLeft size={20}/>Quick Send</button> : <Link className="sas-back" to="/"><ArrowLeft size={20}/>Back</Link>}
    <h1>{chosen ? chosen.title : 'Quick Send'}</h1>
    {!chosen ? <div className="sas-quick-list">
      {QUICK_SEND_LINKS.map(link => {
        const ready = !!verifiedPublicUrl(link.url);
        return <button type="button" className="sas-quick-item" key={link.id} disabled={!ready} onClick={() => select(link)}>
          <span><strong>{link.title}</strong><small>{ready ? link.description : 'Link not configured yet'}</small></span><ExternalLink size={20}/>
        </button>;
      })}
    </div> : <>
      <p className="sas-share-summary">{chosen.description}</p>
      <div className="sas-share-options">
        <button type="button" onClick={() => openSmsComposer(url)}><MessageSquare/><strong>Text</strong><small>Open SMS</small></button>
        <button type="button" onClick={() => setShowQr(v => !v)} aria-pressed={showQr}><QrCode/><strong>QR Code</strong><small>Show to scan</small></button>
        <button type="button" onClick={share}><Share2/><strong>Share</strong><small>AirDrop & more</small></button>
        <button type="button" onClick={copy}><Copy/><strong>Copy Link</strong><small>{copied ? 'Copied' : 'Use anywhere'}</small></button>
      </div>
      {showQr && <div className="sas-share-qr"><img src={qrUrl} alt={`QR code for ${chosen.title}`} onError={() => setError('QR code failed to load.')}/><p>Have the other person scan this QR code.</p></div>}
      {error && <p className="sas-photo-error" role="alert">{error}</p>}
      <label className="sas-share-url-label" htmlFor="sas-quick-url">Share URL</label>
      <textarea id="sas-quick-url" className="sas-share-url" readOnly value={url} rows={3} onFocus={e => e.target.select()}/>
    </>}
  </main>;
}
