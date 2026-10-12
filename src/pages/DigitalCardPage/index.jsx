import { useState } from 'react';
import BrandBumperLogo from '@components/BrandBumperLogo';
import paintbrush from './paintbrush.png';
import './digital-card.css';

const HOME = 'https://colorfix.terrymarr.com/';
const CONTACT = 'https://colorfix.terrymarr.com/send-note';
const YOUTUBE = 'https://www.youtube.com/@colorfixbyterry';
const EMAIL = 'terry@terrymarr.com';
const CARD_URL = 'https://colorfix.terrymarr.com/card';

function createContactFile() {
  const vcf = [
    'BEGIN:VCARD',
    'VERSION:3.0',
    'N:Marr;Terry;;;',
    'FN:Terry Marr',
    'ORG:ColorFix by Terry',
    'TITLE:Color Designer',
    `EMAIL;TYPE=INTERNET,WORK:${EMAIL}`,
    `URL:${HOME}`,
    'NOTE:Interior and exterior color design',
    'END:VCARD',
    '',
  ].join('\r\n');
  const href = URL.createObjectURL(new Blob([vcf], { type: 'text/vcard;charset=utf-8' }));
  const link = document.createElement('a');
  link.href = href;
  link.download = 'Terry-Marr-ColorFix.vcf';
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.setTimeout(() => URL.revokeObjectURL(href), 1000);
}

export default function DigitalCardPage() {
  const [copyStatus, setCopyStatus] = useState('');

  async function shareCard() {
    if (typeof navigator.share === 'function') {
      try {
        await navigator.share({ title: 'Terry Marr | ColorFix', text: 'Interior and exterior color design', url: CARD_URL });
        return;
      } catch (error) {
        if (error?.name === 'AbortError') return;
      }
    }
    try {
      await navigator.clipboard.writeText(CARD_URL);
      setCopyStatus('Card link copied');
    } catch {
      setCopyStatus('Copy this link: ' + CARD_URL);
    }
  }

  return (
    <main className="digital-card-page">
      <article className="digital-card" aria-label="Terry Marr digital business card">
        <section className="digital-card-person">
          <img className="digital-card-brush" src={paintbrush} alt="" aria-hidden="true" />
          <h1>Terry Marr</h1>
          <p className="digital-card-role">COLOR DESIGNER</p>
          <p className="digital-card-description">Interior and exterior color design</p>
        </section>

        <nav className="digital-card-actions" aria-label="Contact and explore ColorFix">
          <button className="digital-card-primary" type="button" onClick={createContactFile}>Save Contact</button>
          <div className="digital-card-link-grid">
            <a href={`mailto:${EMAIL}`}>Email Terry</a>
            <a href={CONTACT}>Contact Terry</a>
            <a href={HOME}>Website</a>
            <a href={YOUTUBE} target="_blank" rel="noopener noreferrer">YouTube</a>
          </div>
          <button className="digital-card-share" type="button" onClick={shareCard}>Share This Card</button>
        </nav>
        {copyStatus && <p className="digital-card-feedback" role="status">{copyStatus}</p>}
        <footer className="digital-card-brand" aria-label="ColorFix by Terry">
          <BrandBumperLogo background="white" />
        </footer>
      </article>
    </main>
  );
}
