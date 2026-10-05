export function photoPath(photo) {
  const value = String(
    photo?.raw_rel_path || photo?.rel_path || photo?.image_url || photo?.file_path || ""
  ).trim();
  if (!value) return "";

  const url = new URL(value, "https://colorfix.terrymarr.com");
  url.searchParams.delete("v");
  url.searchParams.delete("r");
  url.hash = "";
  return `${url.origin}${url.pathname}${url.search}`;
}

export function photoIdentity(photo) {
  const id = Number(photo?.photo_library_id || 0);
  if (id > 0) return `library:${id}`;
  const path = photoPath(photo);
  return path ? `path:${path}` : "";
}

export function samePhoto(a, b) {
  const aId = Number(a?.photo_library_id || 0);
  const bId = Number(b?.photo_library_id || 0);
  if (aId > 0 && bId > 0) return aId === bId;
  const aPath = photoPath(a);
  return Boolean(aPath && aPath === photoPath(b));
}
