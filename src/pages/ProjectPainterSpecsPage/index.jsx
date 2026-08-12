import { useEffect, useMemo, useState } from "react";
import PainterPaletteViewer from "@components/Viewers/PainterPaletteViewer";
import { buildImageUrl } from "@helpers/assetImage";
import "../SavedPaletteSharePage/saved-palette-share.css";

export default function ProjectPainterSpecsPage() {
  const [state, setState] = useState({ loading: true, error: "", data: null });
  const token = useMemo(() => {
    if (typeof window === "undefined") return "";
    const params = new URLSearchParams(window.location.search);
    return params.get("reservation_token") || params.get("token") || "";
  }, []);

  const currentPath = useMemo(() => {
    if (typeof window === "undefined") return "";
    return `${window.location.pathname}${window.location.search}${window.location.hash}`;
  }, []);

  useEffect(() => {
    if (!token) {
      setState({ loading: false, error: "Painter specs unavailable", data: null });
      return undefined;
    }
    const controller = new AbortController();
    setState({ loading: true, error: "", data: null });
    const params = new URLSearchParams({ reservation_token: token });
    fetch(`/api/v2/project-painter-specs.php?${params.toString()}`, {
      signal: controller.signal,
    })
      .then((response) => response.json())
      .then((payload) => {
        if (!payload?.ok || !payload?.data) {
          throw new Error(payload?.error || "Painter specs unavailable");
        }
        setState({ loading: false, error: "", data: payload.data });
      })
      .catch((error) => {
        if (controller.signal.aborted) return;
        setState({ loading: false, error: error?.message || "Painter specs unavailable", data: null });
      });
    return () => controller.abort();
  }, [token]);

  if (state.loading) {
    return (
      <div className="saved-palette-share">
        <div className="sps-card">Loading painter specs...</div>
      </div>
    );
  }

  if (state.error) {
    return (
      <div className="saved-palette-share">
        <div className="sps-card sps-error">{state.error}</div>
      </div>
    );
  }

  const project = state.data?.project || {};
  const projectTitle = project.name || "Painter Specification Sheet";
  const plans = buildPainterPlans(state.data?.plans || []);
  const playlistUrl = appendParams("/p/reserved", {
    reservation_token: token,
    fresh: "1",
    return_to: currentPath,
  });

  return (
    <PainterPaletteViewer
      meta={{
        source: "project_painter_specs",
        title: projectTitle,
        display_title: projectTitle,
        notes: project.project_painter_note || "",
        current_release: project.current_release || "",
        not_final_warning: project.not_final_warning || "",
      }}
      painterView={{
        projectName: projectTitle,
        address: addressLine(project),
      }}
      plans={plans}
      adminMode={false}
      showBackButton={false}
      showShare={true}
      playlistUrl={playlistUrl}
      playlistLabel="Watch Playlist"
    />
  );
}

function buildPainterPlans(plans) {
  return (Array.isArray(plans) ? plans : []).map((plan) => {
    const painterViewer = plan.painter_viewer || {};
    const painterForm = painterViewer.form || {};
    return {
      id: Number(plan.id || 0) || null,
      title: plan.area_name || plan.nickname || plan.scheme_title || `Color Plan #${plan.id}`,
      schemeTitle: painterForm.scheme_title || plan.scheme_title || plan.nickname || plan.area_name || "",
      paletteType: plan.palette_type || "",
      issuedLabel: plan.issued_at ? formatDate(plan.issued_at) : "",
      painterNote: painterForm.overall_painter_note || "",
      photos: painterViewerPhotos(painterViewer.photos || []),
      swatches: viewerSwatches(plan.members || []),
    };
  }).filter((row) => row.swatches.length > 0 || row.photos.length > 0 || row.painterNote || row.title);
}

function painterViewerPhotos(photos) {
  return (Array.isArray(photos) ? photos : [])
    .filter((row) => String(row.photo_type || "").toUpperCase() !== "BEFORE")
    .map((row) => ({
      ...row,
      url: buildImageUrl(row.rel_path || row.image_url || ""),
      alt_text: row.photo_title || row.photo_type || "Project photo",
      type: row.photo_type || "FULL",
      role: row.photo_type || "FULL",
    }))
    .filter((row) => row.url);
}

function viewerSwatches(members) {
  return (Array.isArray(members) ? members : [])
    .filter((row) => row.color || row.color_id)
    .map((row) => ({
      id: Number(row.color_id || row.color?.id || 0) || undefined,
      name: row.color?.name || "",
      code: row.color?.code || row.color?.number || "",
      brand: row.color?.brand || "",
      brand_name: row.color?.brand_name || row.color?.brand || "",
      hex6: String(row.color?.hex6 || row.color?.hex || "").replace(/^#/, ""),
      role: row.role_name || "",
      role_name: row.role_name || "",
      sheen: row.sheen || "",
      note: row.note || "",
    }));
}

function addressLine(project) {
  return [
    project.street_1,
    project.street_2,
    project.city,
    project.state,
    project.postal_code,
  ].filter(Boolean).join(", ");
}

function appendParams(path, params) {
  const query = new URLSearchParams();
  Object.entries(params || {}).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== "") {
      query.set(key, String(value));
    }
  });
  const qs = query.toString();
  return qs ? `${path}?${qs}` : path;
}

function formatDate(value) {
  if (!value) return "";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "";
  return date.toLocaleDateString(undefined, {
    month: "short",
    day: "numeric",
    year: "numeric",
  });
}
