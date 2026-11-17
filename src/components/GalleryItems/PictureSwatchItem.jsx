import { artInspiredPhotoViewerUrl } from "@helpers/photoViewerUrl";
import PictureSwatch from "@components/Swatches/PictureSwatch";

const PictureSwatchItem = ({ item }) => {
  const brand = item?.palette_brand ? String(item.palette_brand).toUpperCase() : "";
  const name = item?.palette_name || "Saved Palette";
  const meta = brand ? brand : (item?.palette_id ? `Palette #${item.palette_id}` : "");
  const to = artInspiredPhotoViewerUrl(item) || item?.rex_url || buildPaletteViewerUrl(item);
  const markerId = `photo-${item?.photo_library_id || item?.photo_id || item?.id || item?.palette_id || ""}`;
  const handleClick = to || !item?.photo_url ? undefined : () => {
    window.location.href = item.photo_url;
  };

  return (
    <PictureSwatch
      photoUrl={item?.photo_url}
      photoLibraryId={item?.photo_library_id || item?.photo_id}
      name={name}
      cropX={item?.crop_x}
      cropY={item?.crop_y}
      cropWidth={item?.crop_width}
      cropHeight={item?.crop_height}
      meta={meta}
      to={to}
      onClick={handleClick}
      markerId={markerId}
    />
  );
};

function buildPaletteViewerUrl(item) {
  if (item?.palette_hash) {
    return `/palette/${encodeURIComponent(item.palette_hash)}/share`;
  }

  const savedPaletteId = Number(item?.saved_palette_id || item?.palette_id || 0);
  if (savedPaletteId > 0) {
    return `/palette/${savedPaletteId}/share`;
  }

  return undefined;
}

export default PictureSwatchItem;
