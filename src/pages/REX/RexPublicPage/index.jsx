import { useEffect, useState } from "react";
import { useParams } from "react-router-dom";
import AnalyticsProvider from "@Analytics/AnalyticsProvider";
import PlayerPage from "@pages/PlayerPage";
import SavedPaletteSharePage from "@pages/SavedPaletteSharePage";
import { applySourceToParams } from "@helpers/sourceParam";
import Thumbs from "@RX/Thumbs";
import "../RexPublicPage/rex-public-page.css";

const EMPTY_STATE = {
  loading: true,
  kind: "",
  error: "",
  rex: null,
  collection: null,
};

export default function RexPublicPage() {
  const { token = "" } = useParams();
  const [state, setState] = useState(EMPTY_STATE);

  useEffect(() => {
    if (!token) {
      setState({
        loading: false,
        kind: "",
        error: "Link not found.",
        rex: null,
        collection: null,
      });
      return undefined;
    }

    const controller = new AbortController();
    const params = new URLSearchParams({ token });
    applySourceToParams(params);

    setState(EMPTY_STATE);

    fetch(`/api/v2/rex/resolve.php?${params.toString()}`, {
      signal: controller.signal,
      credentials: "include",
      headers: { Accept: "application/json" },
    })
      .then(async (response) => {
        const payload = await response.json().catch(() => null);

        if (!response.ok || !payload?.ok || !payload?.data) {
          throw new Error(payload?.error || "Link not found.");
        }

        const resolverKey = payload.data.resolver_key || "";
        const destination = payload.data.destination || {};

        if (resolverKey === "route") {
          const path = String(destination.path || "").trim();

          if (!path) {
            throw new Error("REX route destination is missing.");
          }

          window.location.href = path;
          return;
        }

        if (resolverKey === "playlist_experience") {
          setState({
            loading: false,
            kind: "playlist",
            error: "",
            rex: null,
            collection: null,
          });
          return;
        }

        if (resolverKey === "viewer") {
          setState({
            loading: false,
            kind: "viewer",
            error: "",
            rex: null,
            collection: null,
          });
          return;
        }

        if (resolverKey === "playlist_thumbs") {
          const collection = destination.collection || null;

          if (!collection) {
            throw new Error("REX Thumbs destination is missing its collection.");
          }

          setState({
            loading: false,
            kind: "thumbs",
            error: "",
            rex: null,
            collection,
          });
          return;
        }

        throw new Error(`Unsupported REX destination: ${resolverKey}`);
      })
      .catch((error) => {
        if (controller.signal.aborted) return;

        setState({
          loading: false,
          kind: "",
          error: error?.message || "Link not found.",
          rex: null,
          collection: null,
        });
      });

    return () => controller.abort();
  }, [token]);

  if (state.loading) {
    return (
      <div className="rex-public-page">
        Loading ColorFix link...
      </div>
    );
  }

  if (state.kind === "playlist") {
    return (
      <AnalyticsProvider rex={state.rex}>
        <PlayerPage />
      </AnalyticsProvider>
    );
  }

  if (state.kind === "viewer") {
    return <SavedPaletteSharePage />;
  }

  if (state.kind === "thumbs") {
    return <Thumbs collection={state.collection} />;
  }

  return (
    <div className="rex-public-page rex-public-page--error">
      {state.error || "Link not found."}
    </div>
  );
}
