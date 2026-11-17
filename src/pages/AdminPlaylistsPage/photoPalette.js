/** Resolve a photo-owned palette independently of the paged palette dropdown. */
export async function resolvePhotoPalette(paletteId, options, apiFolder, fetcher = fetch) {
  const id = Number(paletteId);
  if (!id) return null;
  const cached = options.find((option) => Number(option.saved_palette_id || option.id) === id);
  if (cached?.palette_hash) return cached;
  const response = await fetcher(`${apiFolder}/v2/admin/palettes/detail.php?id=${id}`, { credentials: "include" });
  const data = await response.json();
  if (!response.ok || !data?.ok || !data.item?.palette_hash) {
    throw new Error(data?.error || `Could not load the palette attached to this photo (#${id}).`);
  }
  return { ...data.item, saved_palette_id: id };
}
