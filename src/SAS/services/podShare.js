/** SAS creates links; POD owns presentation and rendering. */
export function buildPodShareUrl(photoIds) {
  const ids = [...new Set(photoIds.map(Number))];
  if (!ids.length || ids.some(id => !Number.isSafeInteger(id) || id <= 0)) {
    throw new Error('Select at least one valid photo.');
  }
  const url = new URL('/pod', 'https://colorfix.terrymarr.com');
  url.searchParams.set('photos', ids.join(','));
  url.searchParams.set('captions', '1');
  url.searchParams.set('before', '1');
  url.searchParams.set('src', 'sas');
  return url.toString();
}

export function openSmsComposer(url) {
  // iOS and Android differ in the SMS body separator. On iOS, &body
  // is widely supported even for a blank recipient.
  const isIOS = /iPad|iPhone|iPod/i.test(navigator.userAgent) ||
    (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const separator = isIOS ? '&' : '?';
  window.location.href = `sms:${separator}body=${encodeURIComponent(url)}`;
}
