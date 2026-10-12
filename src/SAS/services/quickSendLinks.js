// Add the verified PUBLIC destinations here. Never insert an admin-only URL.
// A blank URL keeps the item visible but not shareable until configured.
export const QUICK_SEND_LINKS = [
  { id: 'contact', title: 'Contact Terry', description: 'Start a design inquiry', url: '' },
  { id: 'business-card', title: 'Digital Business Card', description: 'Share ColorFix contact details', url: '' },
  { id: 'youtube', title: 'YouTube Channel', description: 'Explore ColorFix videos', url: '' },
];

export function verifiedPublicUrl(value) {
  if (!value || typeof value !== 'string') return '';
  try {
    const parsed = new URL(value.trim());
    return ['https:', 'http:'].includes(parsed.protocol) ? parsed.toString() : '';
  } catch { return ''; }
}
