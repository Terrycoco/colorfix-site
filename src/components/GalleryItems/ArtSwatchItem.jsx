
import ArtSwatch from "@components/Swatches/ArtSwatch";
import { useLocation } from "react-router-dom";

export default function ArtSwatchItem({ item }) {
  const { pathname } = useLocation();
  const inArtGallery = pathname === "/art-gallery";
  const photoLibraryId =
    item?.photo_library_id || item?.photo_id;

  const artworkId = item?.artwork_id;

  const name =
    item?.display_title ||
    item?.official_title ||
    item?.title ||
    "Artwork";

  const artist = String(item?.artist || "").trim();
  const surname = artist.split(/\s+/).at(-1) || "";
  const displayName = inArtGallery ? surname : name;
  const meta = inArtGallery ? name : artist;

  return (
    <ArtSwatch
      photoLibraryId={photoLibraryId}
      photoUrl={item?.photo_url}
      name={displayName}
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
