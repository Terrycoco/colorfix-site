import { API_FOLDER } from './config';
import { withSourceParam } from './sourceParam';

// This entry resolves/creates the stable REX server-side before opening the familiar PV.
export function artInspiredPhotoViewerUrl(photo) {
  const id = Number(photo?.photo_library_id || photo?.photo_id);
  if (photo?.source_type !== 'art_inspired' || !Number(photo?.inspiration_photo_id) || !(id > 0)) return '';
  return withSourceParam(`${API_FOLDER}/v2/palette-viewer-photo.php?photo_library_id=${id}`);
}
