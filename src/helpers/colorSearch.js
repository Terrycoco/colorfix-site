export function colorSearchTarget(input) {
  const term = String(input || '').trim();
  const hex = term.match(/^#?([0-9a-f]{6})$/i);
  if (hex) return `/results/18?hex=${hex[1].toUpperCase()}`;
  return `/results/18?name=${encodeURIComponent(`%${term}%`)}&code=${encodeURIComponent(`%${term}%`)}`;
}
