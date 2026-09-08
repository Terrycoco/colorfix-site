import { useEffect, useState } from "react";
import { Play } from "lucide-react";
import { useLocation, useNavigate } from "react-router-dom";
import { API_FOLDER } from "@helpers/config";
import { extractAssetId, fetchAssetUrl, isAssetRef, parsePhotoRef } from "@helpers/assetImage";
import { photoThumbUrl } from "@helpers/imageThumb";
import { resolveAppPath } from "@helpers/routingHelper";

const PlaylistItem = ({ item }) => {
  const navigate = useNavigate();
  const location = useLocation();
  const [imageSrc, setImageSrc] = useState("");

  const handleClick = () => {
    if (item?.target_url) {
      navigate(resolveAppPath(item.target_url, location.pathname));
    }
  };

  useEffect(() => {
    let cancelled = false;

    const resolveImage = async () => {
      const value = String(item?.image_url || "").trim();
      if (!value) {
        if (!cancelled) setImageSrc("");
        return;
      }

      const parsedPhoto = parsePhotoRef(value);
      if (parsedPhoto.url) {
        if (!cancelled) setImageSrc(photoThumbUrl(parsedPhoto.photoId, 420, 72) || parsedPhoto.url);
        return;
      }

      if (parsedPhoto.photoId) {
        try {
          const thumbUrl = photoThumbUrl(parsedPhoto.photoId, 420, 72);
          if (thumbUrl) {
            if (!cancelled) setImageSrc(thumbUrl);
            return;
          }
          const params = new URLSearchParams();
          params.set("photo_library_ids", parsedPhoto.photoId);
          params.set("limit", "1");
          params.set("_", String(Date.now()));
          const res = await fetch(`${API_FOLDER}/v2/admin/photo-library/list.php?${params.toString()}`, {
            credentials: "include",
          });
          const data = await res.json();
          const url = data?.items?.[0]?.rel_path || "";
          if (!cancelled) setImageSrc(url);
          return;
        } catch {
          if (!cancelled) setImageSrc("");
          return;
        }
      }

      if (isAssetRef(value)) {
        const url = await fetchAssetUrl(extractAssetId(value));
        if (!cancelled) setImageSrc(url || "");
        return;
      }

      if (!cancelled) setImageSrc(value);
    };

    void resolveImage();
    return () => {
      cancelled = true;
    };
  }, [item?.image_url]);

  return (
    <div
      key={item.id}
      className="search-item playlist-item item"
      onClick={handleClick}
    >
      <div>
        {imageSrc ? (
          <div className="playlist-item__image">
            <div className="playlist-item__badge">Before</div>
            <div className="playlist-item__play" aria-hidden="true">
              <Play className="playlist-item__play-icon" />
            </div>
            <img src={imageSrc} alt={item.display || item.title || "Playlist preview"} loading="lazy" decoding="async" />
          </div>
        ) : null}
        <div className="playlist-display">
          <span>{item.display || item.title}</span>
        </div>
        <div className="search-descr">{item.description || item.subtitle}</div>
      </div>
    </div>
  );
};

export default PlaylistItem;
