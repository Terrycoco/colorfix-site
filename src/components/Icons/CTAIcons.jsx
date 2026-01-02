// CTAIcons.jsx

export default function CTAIcon({ name, className = "" }) {
  switch (name) {
    case "replay":
      return (
        <svg
          className={className}
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
        >
          <path d="M3 12a9 9 0 1 0 3-6" />
          <polyline points="3 4 3 10 9 10" />
        </svg>
      );

    case "share":
      return (
        <svg
          className={className}
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
        >
          <circle cx="18" cy="5" r="3" />
          <circle cx="6" cy="12" r="3" />
          <circle cx="18" cy="19" r="3" />
          <line x1="8.59" y1="13.51" x2="15.42" y2="17.49" />
          <line x1="15.41" y1="6.51" x2="8.59" y2="10.49" />
        </svg>
      );

    case "palette":
      return (
        <svg
          className={className}
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
        >
          <path d="M12 3a9 9 0 1 0 0 18c1.5 0 2-1 2-2s-1-2-2-2h-1a2 2 0 0 1 0-4h3a2 2 0 0 0 2-2c0-3.3-3.6-6-7-6z" />
          <circle cx="7.5" cy="10.5" r="0.5" />
          <circle cx="9.5" cy="7.5" r="0.5" />
          <circle cx="14.5" cy="7.5" r="0.5" />
          <circle cx="16.5" cy="10.5" r="0.5" />
        </svg>
      );

    default:
      return null;
  }
}
