import { useState } from 'react';
import { ArrowLeft, Copy, MessageSquare, QrCode, Share2 } from 'lucide-react';
import { buildPodShareUrl, openSmsComposer } from '../services/podShare';

export default function SharePhotos({ photoIds, onBack }) {
  const [showQr, setShowQr] = useState(false);
  const [error, setError] = useState('');
  const [copied, setCopied] = useState(false);
  const [includeCaptions, setIncludeCaptions] = useState(true);
  const [includeBefore, setIncludeBefore] = useState(true);

  const podUrl = new URL(buildPodShareUrl(photoIds), window.location.origin);
  podUrl.searchParams.set('captions', includeCaptions ? '1' : '0');
  podUrl.searchParams.set('before', includeBefore ? '1' : '0');
  podUrl.searchParams.delete('befores'); // POD expects the singular `before` parameter.
  const url = podUrl.toString();

  // Reuse the QR image service already used by ColorFix QR reservations.
  const qrImageUrl = `https://api.qrserver.com/v1/create-qr-code/?size=320x320&data=${encodeURIComponent(url)}`;

  async function copyLink() {
    try {
      await navigator.clipboard.writeText(url);
      setCopied(true);
      setError('');
    } catch {
      setError('Copy failed. Select and copy the link below.');
    }
  }

  async function nativeShare() {
    if (!navigator.share) {
      setError('Native sharing is not supported in this browser. Use Text or Copy Link.');
      return;
    }
    try {
      await navigator.share({ url });
      setError('');
    } catch (err) {
      if (err?.name !== 'AbortError') setError('Could not open the share sheet.');
    }
  }

  return <section className="sas-share" aria-label="Share selected photos">
    <button className="sas-share-back" type="button" onClick={onBack}>
      <ArrowLeft size={20} aria-hidden="true"/> Back to photos
    </button>
    <h1>Share photos</h1>
    <p className="sas-share-summary">{photoIds.length} {photoIds.length === 1 ? 'photo' : 'photos'} · POD presentation</p>
    <div className="sas-share-preferences" aria-label="POD presentation options">
      <label style={{ display: 'flex', alignItems: 'center', gap: '0.6rem', marginBottom: '0.75rem' }}>
        <input type="checkbox" checked={includeCaptions} onChange={event => { setIncludeCaptions(event.target.checked); setCopied(false); }} />
        Include Captions
      </label>
      <label style={{ display: 'flex', alignItems: 'center', gap: '0.6rem', marginBottom: '0.75rem' }}>
        <input type="checkbox" checked={includeBefore} onChange={event => { setIncludeBefore(event.target.checked); setCopied(false); }} />
        Include Before Photos
      </label>
    </div>
    <div className="sas-share-options">
      <button type="button" onClick={() => { setError(''); openSmsComposer(url); }}><MessageSquare/><strong>Text</strong><small>Open SMS</small></button>
      <button type="button" onClick={() => { setError(''); setShowQr(v => !v); }} aria-pressed={showQr}><QrCode/><strong>QR Code</strong><small>Show to scan</small></button>
      <button type="button" onClick={nativeShare}><Share2/><strong>Share</strong><small>AirDrop & more</small></button>
      <button type="button" onClick={copyLink}><Copy/><strong>Copy Link</strong><small>{copied ? 'Copied' : 'Use anywhere'}</small></button>
    </div>
    {showQr && <div className="sas-share-qr" role="region" aria-label="QR code for presentation">
      <img src={qrImageUrl} alt="QR code to open the ColorFix presentation" onError={() => setError('Could not load QR code. Use Text or Copy Link.')} />
      <p>Have the other person scan this with their phone camera.</p>
    </div>}
    {error && <p className="sas-photo-error" role="alert">{error}</p>}
    <label className="sas-share-url-label" htmlFor="sas-share-url">Share URL</label>
    <textarea id="sas-share-url" className="sas-share-url" value={url} readOnly rows={3} onFocus={e => e.target.select()}/>
  </section>;
}
