import { useEffect, useState } from "react";
import { useNavigate, useParams, useSearchParams } from "react-router-dom";
import ANAProvider, { resolveSessionId } from "@ANA/ANAProvider";
import ANATrack from "@ANA/ANATrack";
import { recordANAEvent } from "@ANA/ANAClient";
import {
  applySourceToParams,
  withSourceParam,
} from "@helpers/sourceParam";
import Player from "@RX/Player";
import PV from "@RX/PV";
import Thumbs from "@RX/Thumbs";
import "../RexPublicPage/rex-public-page.css";

const EMPTY_STATE = {
  loading: true,
  kind: "",
  error: "",
  rex: null,
  playbackPlan: null,
  viewer: null,
  experienceKey: "",
  collection: null,
};

export default function RexPublicPage() {
  const { token = "" } = useParams();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const [state, setState] = useState(EMPTY_STATE);

  const returnTo = normalizeInternalReturnPath(
    searchParams.get("return_to") ?? ""
  );

  const browserBackRequested =
    searchParams.get("back") === "1";

  const showViewerBack =
    Boolean(returnTo) || browserBackRequested;

  const handleViewerBack = showViewerBack
    ? () => {
        if (returnTo) {
          navigate(withSourceParam(returnTo));
          return;
        }

        window.history.back();
      }
    : undefined;

  useEffect(() => {
    if (!token) {
      setState({
        ...EMPTY_STATE,
        loading: false,
        error: "Link not found.",
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
        const rex = payload.data.rex || null;

        if (resolverKey === "route") {
          const path = String(destination.path || "").trim();

          if (!path) {
            throw new Error("REX route destination is missing.");
          }

          recordANAEvent({
            event_key: "visit",
            reservation_id: rex?.reservation_id ?? null,
            resolver_key: rex?.resolver_key ?? null,
            resource_type: rex?.resource_type ?? null,
            resource_id: rex?.resource_id ?? null,
            experience_key: rex?.experience_key ?? null,
            src: new URLSearchParams(window.location.search).get("src") || null,
            session_id: resolveSessionId(),
            referrer: document.referrer || null,
            path,
            payload: {},
          }).finally(() => {
            window.location.href = withSourceParam(path);
          });

          return;
        }

        if (resolverKey === "playlist_experience") {
          const playbackPlan = destination.playback_plan || null;

          if (!playbackPlan) {
            throw new Error(
              "REX Playlist destination is missing its playback plan."
            );
          }

          setState({
            ...EMPTY_STATE,
            loading: false,
            kind: "playlist",
            playbackPlan,
            rex,
          });
          return;
        }

        if (resolverKey === "viewer") {
          const viewer = destination.viewer || null;
          const experienceKey = String(
            destination.experience_key || ""
          )
            .trim()
            .toLowerCase();

          if (!viewer) {
            throw new Error("REX Viewer destination is missing its viewer.");
          }

          setState({
            ...EMPTY_STATE,
            loading: false,
            kind: "viewer",
            viewer,
            experienceKey,
            rex,
          });
          return;
        }

        if (resolverKey === "playlist_thumbs") {
          const collection = destination.collection || null;

          if (!collection) {
            throw new Error(
              "REX Thumbs destination is missing its collection."
            );
          }

          setState({
            ...EMPTY_STATE,
            loading: false,
            kind: "thumbs",
            collection,
            rex,
          });
          return;
        }

        throw new Error(`Unsupported REX destination: ${resolverKey}`);
      })
      .catch((error) => {
        if (controller.signal.aborted) return;

        setState({
          ...EMPTY_STATE,
          loading: false,
          error: error?.message || "Link not found.",
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
      <ANAProvider rex={state.rex}>
        <ANATrack>
          <Player playbackPlan={state.playbackPlan} />
        </ANATrack>
      </ANAProvider>
    );
  }

  if (state.kind === "viewer") {
    return (
      <ANAProvider rex={state.rex}>
        <ANATrack>
          <PV
            viewer={state.viewer}
            experienceKey={state.experienceKey}
            showBackButton={showViewerBack}
            onBack={handleViewerBack}
          />
        </ANATrack>
      </ANAProvider>
    );
  }

  if (state.kind === "thumbs") {
    return (
      <ANAProvider rex={state.rex}>
        <ANATrack>
          <Thumbs collection={state.collection} />
        </ANATrack>
      </ANAProvider>
    );
  }

  return (
    <div className="rex-public-page rex-public-page--error">
      {state.error || "Link not found."}
    </div>
  );
}

function normalizeInternalReturnPath(value) {
  const trimmed = String(value || "").trim();

  if (!trimmed) return "";
  if (!trimmed.startsWith("/") || trimmed.startsWith("//")) return "";

  return trimmed;
}
