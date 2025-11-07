export function getColorUrl(color_url, base_url) {
  if (!color_url) {
    console.log('getColorUrl: No color_url provided');
    return null;
  }

  // Already absolute?
  if (color_url.startsWith('http://') || color_url.startsWith('https://')) {
    console.log('getColorUrl: Using absolute color_url:', color_url);
    return color_url;
  }

  // Needs base_url
  if (!base_url) {
    console.log('getColorUrl: Relative path but no base_url provided:', color_url);
    return null;
  }

  const full = base_url.replace(/\/+$/, '') + '/' + color_url.replace(/^\/+/, '');
  console.log('getColorUrl: Resolved full URL:', full);
  return full;
}
