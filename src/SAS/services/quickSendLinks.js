// Add the verified PUBLIC destinations here. Never insert an admin-only URL.
// A blank URL keeps the item visible but not shareable until configured.
export const QUICK_SEND_LINKS = [
  {
    id: 'home',
    title: 'Home Page',
    description: 'ColorFix by Terry',
    url: 'https://colorfix.terrymarr.com/?src=sas',
  },
    {
  id: 'browse-playlists',
  title: 'Browse Playlists',
  description: 'Explore ColorFix makeovers and color lessons',
  url: 'https://colorfix.terrymarr.com/picker?set=11&src=sas',
},
  {
    id: 'contact',
    title: 'Contact Terry',
    description: 'Start a design inquiry',
    url: 'https://colorfix.terrymarr.com/send-note?src=sas',
  },
  {
    id: 'business-card',
    title: 'Digital Business Card',
    description: 'Share ColorFix contact details',
    url: 'https://colorfix.terrymarr.com/card?src=sas',
  },
  {
    id: 'youtube',
    title: 'YouTube Channel',
    description: 'Explore ColorFix videos',
    url: 'https://www.youtube.com/@colorfixbyterry',
  },

];

export function verifiedPublicUrl(value) {
  if (!value || typeof value !== 'string') return '';
  try {
    const parsed = new URL(value.trim());
    return ['https:', 'http:'].includes(parsed.protocol) ? parsed.toString() : '';
  } catch { return ''; }
}
