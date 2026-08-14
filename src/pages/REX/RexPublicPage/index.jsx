import { useEffect, useState } from "react";
import { useParams } from "react-router-dom";
import PlayerPage from "@pages/PlayerPage";
import SavedPaletteSharePage from "@pages/SavedPaletteSharePage";
import { applySourceToParams } from "@helpers/sourceParam";
import "../RexPublicPage/rex-public-page.css";

export default function RexPublicPage() {
  const { token = "" } = useParams();
  const [state, setState] = useState({ loading: true, kind: "", error: "" });

  useEffect(() => {
    if (!token) {
      setState({ loading: false, kind: "", error: "Link not found." });
      return undefined;
    }

    const controller = new AbortController();
    const params = new URLSearchParams({ reservation_token: token, fresh: "1" });
    applySourceToParams(params);
    setState({ loading: true, kind: "", error: "" });

    fetch(`/api/v2/player-playlist.php?${params.toString()}`, {
      signal: controller.signal,
      credentials: "include",
      headers: { Accept: "application/json" },
    })
      .then(async (response) => {
        const payload = await response.json().catch(() => null);
        if (response.ok && payload?.ok && payload?.data) {
          setState({ loading: false, kind: "playlist", error: "" });
          return;
        }
        setState({ loading: false, kind: "viewer", error: "" });
      })
      .catch((error) => {
        if (controller.signal.aborted) return;
        setState({ loading: false, kind: "", error: error?.message || "Link not found." });
      });

    return () => controller.abort();
  }, [token]);

  if (state.loading) {
    return <div className="rex-public-page">Loading ColorFix link...</div>;
  }

  if (state.kind === "playlist") {
    return <PlayerPage />;
  }

  if (state.kind === "viewer") {
    return <SavedPaletteSharePage />;
  }

  return <div className="rex-public-page rex-public-page--error">{state.error || "Link not found."}</div>;
}
