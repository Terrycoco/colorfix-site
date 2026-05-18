import { useEffect, useState } from "react";
import { useLocation } from "react-router-dom";
import { API_FOLDER } from "@helpers/config";

const WATCH_GET_URL = `${API_FOLDER}/v2/admin/watch-config/get.php`;
const SET_ITEMS_LIST_URL = `${API_FOLDER}/v2/admin/playlist-instance-set-items/list.php`;
const FALLBACK_SET_ID = 3;

export default function WatchRedirectPage() {
  const location = useLocation();
  const [message, setMessage] = useState("Loading watch link…");

  useEffect(() => {
    let cancelled = false;

    async function resolveTarget() {
      try {
        const watchRes = await fetch(`${WATCH_GET_URL}?_=${Date.now()}`, {
          credentials: "include",
        });
        const watchData = await watchRes.json();

        let playlistInstanceId = Number(watchData?.item?.playlist_instance_id || 0);
        let playlistUrl = watchData?.item?.player_url || "";

        if (!playlistInstanceId) {
          const setRes = await fetch(
            `${SET_ITEMS_LIST_URL}?set_id=${FALLBACK_SET_ID}&_=${Date.now()}`,
            { credentials: "include" }
          );
          const setData = await setRes.json();
          if (setRes.ok && setData?.ok && Array.isArray(setData.items)) {
            const firstInstanceItem = setData.items.find(
              (item) => Number(item?.playlist_instance_id || 0) > 0
            );
            playlistInstanceId = Number(firstInstanceItem?.playlist_instance_id || 0);
            playlistUrl = firstInstanceItem?.player_url || "";
          }
        }

        if (!playlistInstanceId) {
          throw new Error("Watch link not configured yet");
        }

        const next = new URLSearchParams(location.search);
        next.delete("id");
        const qs = next.toString();
        const targetPath = playlistUrl || `/playlist/${playlistInstanceId}`;
        const target = `${targetPath}${qs ? `?${qs}` : ""}`;

        if (!cancelled) {
          window.location.replace(target);
        }
      } catch (err) {
        if (!cancelled) {
          setMessage(err?.message || "Watch link not configured yet");
        }
      }
    }

    resolveTarget();
    return () => {
      cancelled = true;
    };
  }, [location.search]);

  return (
    <div className="route-loader" role="status" aria-live="polite">
      {message}
    </div>
  );
}
