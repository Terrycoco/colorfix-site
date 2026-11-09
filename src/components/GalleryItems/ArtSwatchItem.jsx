
import ArtSwatch from "@components/Swatches/ArtSwatch";

export default function ArtSwatchItem({ item }) {
  const photoLibraryId =
    item?.photo_library_id || item?.photo_id;

  const artworkId = item?.artwork_id;

  const name =
    item?.display_title ||
    item?.official_title ||
    item?.title ||
    "Artwork";

  const meta = item?.artist || "";

  return (
    <ArtSwatch
      photoLibraryId={photoLibraryId}
      photoUrl={item?.photo_url}
      name={name}
      meta={meta}
      cropX={item?.crop_x}
      cropY={item?.crop_y}
      cropWidth={item?.crop_width}
      cropHeight={item?.crop_height}
      to={
        artworkId
          ? `/inspired-by/${encodeURIComponent(artworkId)}`
          : undefined
      }
      markerId={`art-${artworkId || photoLibraryId}`}
    />
  );
}
