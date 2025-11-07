export function initCap(string) {
  if (typeof string !== 'string' || string.length === 0) {
    return string; // Handle non-string or empty input
  }
  return string.charAt(0).toUpperCase() + string.slice(1).toLowerCase();
}

export function toTitleCase(str) {
  return str
    .toLowerCase()
    .split(' ')
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
}
