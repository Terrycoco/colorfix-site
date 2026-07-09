import PictureSwatch from "@components/Swatches/PictureSwatch";

const PictureSwatchItem = ({ item }) => {
  const brand = item?.palette_brand ? String(item.palette_brand).toUpperCase() : "";
  const name = item?.palette_name || "Saved Palette";
  const meta = brand ? brand : (item?.palette_id ? `Palette #${item.palette_id}` : "");
  const to = buildPaletteViewerUrl(item);
  const markerId = `photo-${item?.photo_library_id || item?.photo_id || item?.id || item?.palette_id || ""}`;
  const handleClick = to || !item?.photo_url ? undefined : () => {
    window.location.href = item.photo_url;
  };

  return (
    <PictureSwatch
      photoUrl={item?.photo_url}
      photoLibraryId={item?.photo_library_id || item?.photo_id}
      name={name}
      meta={meta}
      to={to}
      onClick={handleClick}
      markerId={markerId}
    />
  );
};

function buildPaletteViewerUrl(item) {
  const setId = Number(item?.saved_palette_set_id || 0);
  const query = setId > 0 ? `?set_id=${encodeURIComponent(String(setId))}` : "";

  if (item?.palette_hash) {
    return `/palette/${encodeURIComponent(item.palette_hash)}/share${query}`;
  }

  const savedPaletteId = Number(item?.saved_palette_id || item?.palette_id || 0);
  if (savedPaletteId > 0) {
    return `/palette/${savedPaletteId}/share${query}`;
  }

  return undefined;
}

export default PictureSwatchItem;
