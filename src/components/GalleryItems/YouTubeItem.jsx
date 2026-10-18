import { useANA } from "@ANA/useANA";

/**
 * Gallery item type: youtube
 * Admin fields: display (title), description (body), target_url (channel URL).
 * Uses the existing search-item styles for consistent gallery presentation.
 */
function YouTubeLogo() {
  return (
    <svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true" focusable="false">
      <rect x="1" y="4.1" width="22" height="15.8" rx="5" fill="#FF0033" />
      <path d="M10 8.2 16.4 12 10 15.8Z" fill="#fff" />
    </svg>
  );
}

export default function YouTubeItem({ item }) {
  const { track } = useANA();
  const destination = String(item?.target_url || item?.on_click_url || '').trim();
  const isYouTube = /^https:\/\/(?:www\.|m\.)?(?:youtube\.com|youtu\.be)(?:\/|$)/i.test(destination);

  const content = (
    <div>
      <div className="search-display">
        <span className="search-display__with-icon">
          <span className="search-display__icon" style={{ width: '1.5em', height: '1.5em' }}>
            <YouTubeLogo />
          </span>
          <span>{item?.display}</span>
        </span>
      </div>
      <div className="search-descr">{item?.description}</div>
    </div>
  );

  if (!isYouTube) return <div className="search-item item">{content}</div>;

  return (
    <a
      className="search-item item"
      href={destination}
      onClick={() => {
        void track('click', {
          destination: 'youtube',
          target_url: destination,
          placement: 'homepage',
        }, { resource_type: 'outbound_link' });
      }}
      target="_blank"
      rel="noopener noreferrer"
      style={{ textDecoration: 'none' }}
    >
      {content}
    </a>
  );
}
