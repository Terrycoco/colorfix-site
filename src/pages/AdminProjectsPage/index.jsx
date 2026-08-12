import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import AddressModal from "@components/AddressModal";
import FuzzySearchColorSelect from "@components/FuzzySearchColorSelect";
import PhotoPickerModal from "@components/PhotoPickerModal";
import {
  AdminDetailPane,
  AdminEmptyState,
  AdminListPane,
  AdminMasterDetail,
  AdminObjectList,
  AdminObjectListItem,
} from "@components/AdminLayout";
import { API_FOLDER } from "@helpers/config";
import { buildImageUrl } from "@helpers/assetImage";
import "./admin-projects.css";
import RexManagementDialog from "@components/REX/RexManagementDialog";

const LIST_URL = `${API_FOLDER}/v2/admin/projects/list.php`;
const GET_URL = `${API_FOLDER}/v2/admin/projects/get.php`;
const SAVE_URL = `${API_FOLDER}/v2/admin/projects/save.php`;
const PROPERTY_SAVE_URL = `${API_FOLDER}/v2/admin/properties/save.php`;
const PROPERTY_ADDRESS_SAVE_URL = `${API_FOLDER}/v2/admin/properties/address-save.php`;
const PLAYLIST_ATTACH_URL = `${API_FOLDER}/v2/admin/projects/playlist-attach.php`;
const PLAYLIST_REMOVE_URL = `${API_FOLDER}/v2/admin/projects/playlist-remove.php`;
const PLAYLISTS_LIST_URL = `${API_FOLDER}/v2/admin/playlists/list.php`;
const PLAYLIST_GET_URL = `${API_FOLDER}/v2/admin/playlists/get.php`;
const OPTIONS_URL = `${API_FOLDER}/v2/admin/projects/options.php`;
const COLOR_PLAN_LIST_URL = `${API_FOLDER}/v2/admin/project-color-plans/list.php`;
const COLOR_PLAN_GET_URL = `${API_FOLDER}/v2/admin/project-color-plans/get.php`;
const COLOR_PLAN_CREATE_URL = `${API_FOLDER}/v2/admin/project-color-plans/create.php`;
const COLOR_PLAN_UPDATE_URL = `${API_FOLDER}/v2/admin/project-color-plans/update.php`;
const COLOR_PLAN_DELETE_URL = `${API_FOLDER}/v2/admin/project-color-plans/delete.php`;
const COLOR_PLAN_MEMBERS_SAVE_URL = `${API_FOLDER}/v2/admin/project-color-plans/members-save.php`;
const COLOR_PLAN_VIEWER_GET_URL = `${API_FOLDER}/v2/admin/project-color-plans/viewer-get.php`;
const COLOR_PLAN_VIEWER_SAVE_URL = `${API_FOLDER}/v2/admin/project-color-plans/viewer-save.php`;
const REX_CREATE_URL = `${API_FOLDER}/v2/admin/rex/create.php`;
const REX_LIST_URL = `${API_FOLDER}/v2/admin/rex/list.php`;

const sheenOptions = ["", "Flat", "Velvet", "Matte", "Eggshell", "Satin/Lo-Sheen", "Semi-Gloss", "Gloss", "High-Gloss", "Other"];
const viewerPhotoTypeOptions = ["FULL", "BEFORE", "INSET"];
const projectTabs = ["overview", "playlist", "color plans", "viewers", "activity"];
const viewerTabs = ["concept", "client", "painter"];
const rexExperienceOptions = [
  { key: "concept", label: "Concept" },
  { key: "client", label: "Client" },
  { key: "painter", label: "Painter" },
  { key: "public", label: "Public" },
];

const emptyViewerForms = {
  concept: { title: "", challenge: "", design_direction: "" },
  client: { scheme_title: "", final_design_description: "" },
  painter: { overall_painter_note: "" },
};

const emptyViewerPhotos = {
  concept: [],
  client: [],
  painter: [],
};

const emptyProject = {
  id: null,
  name: "",
  property_id: "",
  client_id: "",
  project_type_id: "",
  current_release: "1",
  notes: "",
  project_painter_note: "",
};

function projectDirtySnapshot(project) {
  return {
    id: project?.id ? Number(project.id) : null,
    name: String(project?.name || "").trim(),
    property_id: project?.property_id ? Number(project.property_id) : 0,
    client_id: project?.client_id ? Number(project.client_id) : 0,
    project_type_id: project?.project_type_id ? Number(project.project_type_id) : 0,
    current_release: String(project?.current_release || "1").trim().toUpperCase(),
    notes: String(project?.notes || "").trim(),
    project_painter_note: String(project?.project_painter_note || "").trim(),
  };
}

function projectSnapshotsMatch(a, b) {
  return JSON.stringify(projectDirtySnapshot(a)) === JSON.stringify(projectDirtySnapshot(b));
}

const emptyProperty = {
  name: "",
  client_id: "",
  notes: "",
};

const emptyColorPlan = {
  id: null,
  project_id: null,
  nickname: "",
  area_name: "",
  palette_type: "exterior",
  scheme_title: "",
  revision_number: 1,
  issued_at: "",
};

function formatDate(value) {
  if (!value) return "";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" });
}

function addressLine(address) {
  if (!address) return "No address entered";
  return [address.street_1, address.street_2, address.city, address.state, address.postal_code]
    .filter(Boolean)
    .join(", ");
}

function currentPlaylistLabel(project) {
  const playlist = project?.current_playlist;
  if (!playlist) return "Current playlist: Not assigned";
  return `Current playlist: ${playlist.title || playlist.slug || `Playlist #${playlist.playlist_id}`}`;
}

function colorPlanLabel(plan) {
  if (!plan) return "";
  return plan.area_name || plan.nickname || plan.scheme_title || `Color Plan #${plan.id}`;
}

function clientDisplayName(client) {
  return client?.name || client?.email || `Client #${client?.id || ""}`;
}

function sortClientsByName(items) {
  return [...items].sort((a, b) => clientDisplayName(a).localeCompare(clientDisplayName(b), undefined, { sensitivity: "base" }));
}

function normalizeViewerPhotoRows(viewerKey, rows) {
  return (Array.isArray(rows) ? rows : []).map((row, index) => ({
    ...row,
    key: `viewer-photo-${viewerKey}-${row.id || row.photo_library_id || Date.now()}-${index}`,
    order_index: Number.isFinite(Number(row.order_index)) ? Number(row.order_index) : index,
  }));
}

function mergeViewerSourceForm(savedForm, currentForm) {
  const next = { ...(savedForm || {}) };
  for (const [field, value] of Object.entries(currentForm || {})) {
    if (value !== null && value !== undefined && String(value) !== "") {
      next[field] = value;
    }
  }
  return next;
}

function hasViewerCopyText(fromKey, toKey, form) {
  if (fromKey === "concept" && toKey === "client") {
    return Boolean(form?.title || form?.scheme_title || form?.design_direction || form?.final_design_description);
  }
  if (fromKey === "client" && toKey === "painter") {
    return Boolean(form?.scheme_title || form?.title || form?.final_design_description || form?.design_direction);
  }
  return false;
}

export default function AdminProjectsPage() {
  const [projects, setProjects] = useState([]);
  const [properties, setProperties] = useState([]);
  const [projectTypes, setProjectTypes] = useState([]);
  const [clients, setClients] = useState([]);
  const [selectedProjectId, setSelectedProjectId] = useState(null);
  const [projectForm, setProjectForm] = useState(emptyProject);
  const [projectBaseline, setProjectBaseline] = useState(emptyProject);
  const [newPropertyForm, setNewPropertyForm] = useState(emptyProperty);
  const [newPropertyAddress, setNewPropertyAddress] = useState(null);
  const [addressModalOpen, setAddressModalOpen] = useState(false);
  const [showProjectForm, setShowProjectForm] = useState(false);
  const [showInlineProperty, setShowInlineProperty] = useState(false);
  const [playlists, setPlaylists] = useState([]);
  const [playlistOptions, setPlaylistOptions] = useState([]);
  const [playlistAttachId, setPlaylistAttachId] = useState("");
  const [rexExperienceByPlaylist, setRexExperienceByPlaylist] = useState({});
  const [releaseVersionOptions, setReleaseVersionOptions] = useState([1]);
  const [activeTab, setActiveTab] = useState("overview");
  const [colorPlans, setColorPlans] = useState([]);
  const [selectedColorPlanId, setSelectedColorPlanId] = useState("");
  const [colorPlanForm, setColorPlanForm] = useState(emptyColorPlan);
  const [colorPlanMembers, setColorPlanMembers] = useState([]);
  const [deletedColorPlanMemberIds, setDeletedColorPlanMemberIds] = useState([]);
  const [activeColorPlanTab, setActiveColorPlanTab] = useState("plan");
  const [activeViewerTab, setActiveViewerTab] = useState("concept");
  const [viewerForms, setViewerForms] = useState(emptyViewerForms);
  const [viewerPhotos, setViewerPhotos] = useState(emptyViewerPhotos);
  const [viewerPhotoPickerOpen, setViewerPhotoPickerOpen] = useState(false);
  const [viewerPhotoPickerTarget, setViewerPhotoPickerTarget] = useState("concept");
  const [viewerRexByKey, setViewerRexByKey] = useState({});
  const [loadingColorPlan, setLoadingColorPlan] = useState(false);
  const [filters, setFilters] = useState({ q: "", project_type_id: "" });
  const [loadingList, setLoadingList] = useState(true);
  const [loadingDetail, setLoadingDetail] = useState(false);
  const [saving, setSaving] = useState(false);
  const [status, setStatus] = useState("");
  const [error, setError] = useState("");
  const [rexDialog, setRexDialog] = useState({
    open: false,
    reservationIds: [],
    title: "",
  });
  const selectedProjectIdRef = useRef(null);
  const projectModeRef = useRef("detail");
  const initialProjectRouteHandledRef = useRef(false);

  const selectedProject = useMemo(
    () => projects.find((item) => Number(item.id) === Number(selectedProjectId)) || null,
    [projects, selectedProjectId]
  );

  const selectedWorkingColorPlan = useMemo(() => {
    if (colorPlanForm?.id && Number(colorPlanForm.id) === Number(selectedColorPlanId)) return colorPlanForm;
    return colorPlans.find((plan) => Number(plan.id) === Number(selectedColorPlanId)) || null;
  }, [colorPlanForm, colorPlans, selectedColorPlanId]);

  const isProjectDirty = useMemo(
    () => !projectSnapshotsMatch(projectForm, projectBaseline),
    [projectBaseline, projectForm]
  );

  const confirmProjectChangeLoss = useCallback(() => {
    if (!isProjectDirty) return true;
    return window.confirm("You have unsaved project changes. Leave without saving?");
  }, [isProjectDirty]);

  useEffect(() => {
    if (!isProjectDirty) return undefined;
    const handleBeforeUnload = (event) => {
      event.preventDefault();
      event.returnValue = "";
      return "";
    };
    window.addEventListener("beforeunload", handleBeforeUnload);
    return () => window.removeEventListener("beforeunload", handleBeforeUnload);
  }, [isProjectDirty]);

  const loadOptions = useCallback(async () => {
    const res = await fetch(`${OPTIONS_URL}?_=${Date.now()}`, { credentials: "include" });
    const text = await res.text();
    if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
    const data = JSON.parse(text);
    if (!data?.ok) throw new Error(data?.error || "Failed to load project options");
    const nextTypes = Array.isArray(data.project_types) ? data.project_types : [];
    setProperties(Array.isArray(data.properties) ? data.properties : []);
    setProjectTypes(nextTypes);
    setClients(sortClientsByName(Array.isArray(data.clients) ? data.clients : []));
    return nextTypes;
  }, []);

  const loadPlaylistOptions = useCallback(async () => {
    const res = await fetch(`${PLAYLISTS_LIST_URL}?_=${Date.now()}`, { credentials: "include" });
    const text = await res.text();
    if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
    const data = JSON.parse(text);
    if (!data?.ok) throw new Error(data?.error || "Failed to load playlists");
    setPlaylistOptions(Array.isArray(data.items) ? data.items : []);
  }, []);



  const loadReleaseVersionOptions = useCallback(async (projectPlaylists = []) => {
    const playlistIds = Array.from(new Set(
      (Array.isArray(projectPlaylists) ? projectPlaylists : [])
        .map((row) => Number(row?.playlist_id || 0))
        .filter((id) => id > 0)
    ));

    const versions = new Set([1]);
    if (playlistIds.length) {
      const results = await Promise.all(
        playlistIds.map(async (playlistId) => {
          try {
            const res = await fetch(`${PLAYLIST_GET_URL}?playlist_id=${encodeURIComponent(playlistId)}&_=${Date.now()}`, {
              credentials: "include",
            });
            const text = await res.text();
            if (!res.ok) return [];
            const data = JSON.parse(text);
            if (!data?.ok) return [];
            return Array.isArray(data.items) ? data.items : [];
          } catch {
            return [];
          }
        })
      );

      results.flat().forEach((item) => {
        const version = Number(item?.version_number || 0);
        if (Number.isInteger(version) && version > 0) {
          versions.add(version);
        }
      });
    }

    const next = Array.from(versions).sort((a, b) => a - b);
    setReleaseVersionOptions(next);
    return next;
  }, []);

  const loadProject = useCallback(async (id) => {
    if (!id) return;
    projectModeRef.current = "detail";
    selectedProjectIdRef.current = id;
    setLoadingDetail(true);
    setError("");
    try {
      const res = await fetch(`${GET_URL}?id=${encodeURIComponent(id)}&_=${Date.now()}`, { credentials: "include" });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      const data = JSON.parse(text);
      if (!data?.ok || !data?.project) throw new Error(data?.error || "Failed to load project");
      const nextProjectForm = {
        id: data.project.id,
        name: data.project.name || "",
        property_id: data.project.property_id || "",
        client_id: data.project.client_id || "",
        project_type_id: data.project.project_type_id || "",
        current_release: String(data.project.current_release || "1").toUpperCase(),
        notes: data.project.notes || "",
        project_painter_note: data.project.project_painter_note || "",
      };
      setProjectForm(nextProjectForm);
      setProjectBaseline(nextProjectForm);
      const nextPlaylists = Array.isArray(data.playlists) ? data.playlists : [];
      setPlaylists(nextPlaylists);
      await loadReleaseVersionOptions(nextPlaylists);
      setPlaylistAttachId("");
      setColorPlans([]);
      setSelectedColorPlanId("");
      setColorPlanForm({ ...emptyColorPlan, project_id: data.project.id });
      setColorPlanMembers([]);
      setDeletedColorPlanMemberIds([]);
      setActiveViewerTab("concept");
      setViewerForms(emptyViewerForms);
      setViewerPhotos(emptyViewerPhotos);
      setViewerPhotoPickerOpen(false);
      setViewerRexByKey({});
      setShowProjectForm(false);
      setActiveTab("overview");
    } catch (err) {
      setError(err?.message || "Failed to load project");
    } finally {
      setLoadingDetail(false);
    }
  }, [loadReleaseVersionOptions]);

  const loadColorPlan = useCallback(async (planId, projectId = selectedProjectId) => {
    if (!planId || !projectId) return;
    setLoadingColorPlan(true);
    setError("");
    setViewerRexByKey({});
    try {
      const params = new URLSearchParams();
      params.set("id", String(planId));
      params.set("project_id", String(projectId));
      params.set("_", String(Date.now()));
      const res = await fetch(`${COLOR_PLAN_GET_URL}?${params.toString()}`, { credentials: "include" });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      const data = JSON.parse(text);
      if (!data?.ok || !data?.plan) throw new Error(data?.error || "Failed to load Color Plan");
      setColorPlanForm({
        ...emptyColorPlan,
        ...data.plan,
        issued_at: data.plan.issued_at ? String(data.plan.issued_at).slice(0, 16).replace(" ", "T") : "",
      });
      setColorPlanMembers((Array.isArray(data.members) ? data.members : []).map((row) => ({
        ...row,
        key: `member-${row.id}`,
        color: row.color || null,
      })));
      setDeletedColorPlanMemberIds([]);
      await loadViewerSetup(planId);
    } catch (err) {
      setError(err?.message || "Failed to load Color Plan");
    } finally {
      setLoadingColorPlan(false);
    }
  }, [selectedProjectId]);

  const loadColorPlans = useCallback(async (projectId = selectedProjectId, preferredPlanId = null) => {
    if (!projectId) return;
    setLoadingColorPlan(true);
    setError("");
    try {
      const res = await fetch(`${COLOR_PLAN_LIST_URL}?project_id=${encodeURIComponent(projectId)}&_=${Date.now()}`, { credentials: "include" });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      const data = JSON.parse(text);
      if (!data?.ok) throw new Error(data?.error || "Failed to load Color Plans");
      const items = Array.isArray(data.items) ? data.items : [];
      setColorPlans(items);
      const itemIds = new Set(items.map((item) => String(item.id)));
      const preferredId = preferredPlanId && itemIds.has(String(preferredPlanId)) ? String(preferredPlanId) : "";
      const currentId = selectedColorPlanId && itemIds.has(String(selectedColorPlanId)) ? String(selectedColorPlanId) : "";
      const targetId = preferredId || currentId || (items[0] ? String(items[0].id) : "");
      if (targetId) {
        setSelectedColorPlanId(String(targetId));
        await loadColorPlan(targetId, projectId);
      } else {
        setSelectedColorPlanId("");
        setColorPlanForm({ ...emptyColorPlan, project_id: projectId });
        setColorPlanMembers([]);
        setDeletedColorPlanMemberIds([]);
        setViewerForms(emptyViewerForms);
        setViewerPhotos(emptyViewerPhotos);
        setViewerRexByKey({});
      }
    } catch (err) {
      setError(err?.message || "Failed to load Color Plans");
    } finally {
      setLoadingColorPlan(false);
    }
  }, [loadColorPlan, selectedColorPlanId, selectedProjectId]);

  useEffect(() => {
    if (!selectedProjectId || showProjectForm) return;
    void loadColorPlans(selectedProjectId);
  }, [loadColorPlans, selectedProjectId, showProjectForm]);

  const startNewProject = useCallback((propertyId = "", typeOptions = []) => {
    projectModeRef.current = "new";
    selectedProjectIdRef.current = null;
    setSelectedProjectId(null);
    setProjectForm({
      ...emptyProject,
      property_id: propertyId || "",
      project_type_id: typeOptions[0]?.id || "",
    });
    setProjectBaseline({
      ...emptyProject,
      property_id: propertyId || "",
      project_type_id: typeOptions[0]?.id || "",
    });
    setPlaylists([]);
    setReleaseVersionOptions([1]);
    setColorPlans([]);
    setSelectedColorPlanId("");
    setColorPlanForm(emptyColorPlan);
    setColorPlanMembers([]);
    setDeletedColorPlanMemberIds([]);
    setActiveViewerTab("concept");
    setViewerForms(emptyViewerForms);
    setViewerPhotos(emptyViewerPhotos);
    setViewerPhotoPickerOpen(false);
    setViewerRexByKey({});
    setShowProjectForm(true);
    setShowInlineProperty(false);
    setNewPropertyForm(emptyProperty);
    setNewPropertyAddress(null);
    setActiveTab("overview");
    setStatus("");
    setError("");
  }, []);


  const loadProjects = useCallback(async (preferredId = null) => {
    setLoadingList(true);
    setError("");
    try {
      const loadedProjectTypes = await loadOptions();
      await loadPlaylistOptions();
      const params = new URLSearchParams();
      if (filters.q.trim()) params.set("q", filters.q.trim());
      if (filters.project_type_id) params.set("project_type_id", filters.project_type_id);
      params.set("_", String(Date.now()));
      const res = await fetch(`${LIST_URL}?${params.toString()}`, { credentials: "include" });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      const data = JSON.parse(text);
      if (!data?.ok) throw new Error(data?.error || "Failed to load projects");
      const items = Array.isArray(data.items) ? data.items : [];
      setProjects(items);

      const shouldUseInitialRoute = !initialProjectRouteHandledRef.current;
      const query = new URLSearchParams(window.location.search);
      const requestedProjectId = Number(query.get("project_id") || 0);
      const requestedPropertyId = Number(query.get("property_id") || 0);
      const shouldOpenNew = query.get("action") === "new";

      if (shouldUseInitialRoute && shouldOpenNew && !preferredId) {
        initialProjectRouteHandledRef.current = true;
        startNewProject(requestedPropertyId > 0 ? requestedPropertyId : "", loadedProjectTypes);
        return;
      }

      if (!preferredId && (!shouldUseInitialRoute || !requestedProjectId) && projectModeRef.current === "new") {
        return;
      }

      const targetId = preferredId || (shouldUseInitialRoute ? requestedProjectId : 0) || selectedProjectIdRef.current || (items[0] ? Number(items[0].id) : null);
      initialProjectRouteHandledRef.current = true;
      if (targetId) {
        projectModeRef.current = "detail";
        selectedProjectIdRef.current = targetId;
        setSelectedProjectId(targetId);
        await loadProject(targetId);
      } else {
        startNewProject(requestedPropertyId > 0 ? requestedPropertyId : "", loadedProjectTypes);
      }
    } catch (err) {
      setError(err?.message || "Failed to load projects");
    } finally {
      setLoadingList(false);
    }
  }, [filters.project_type_id, filters.q, loadOptions, loadPlaylistOptions, loadProject, startNewProject]);

  useEffect(() => {
    void loadProjects();
  }, [loadProjects]);

  function updateProjectForm(field, value) {
    setProjectForm((prev) => {
      if (field === "property_id") {
        const property = properties.find((item) => Number(item.id) === Number(value));
        return {
          ...prev,
          property_id: value,
          client_id: property?.client_id || "",
        };
      }
      return { ...prev, [field]: value };
    });
  }

  function updateNewPropertyForm(field, value) {
    setNewPropertyForm((prev) => ({ ...prev, [field]: value }));
  }

  async function createInlineProperty() {
    const name = newPropertyForm.name.trim();
    if (!name) {
      setError("Property name required.");
      return null;
    }
    const res = await fetch(PROPERTY_SAVE_URL, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        name,
        client_id: Number(newPropertyForm.client_id || 0) || null,
        notes: newPropertyForm.notes.trim(),
      }),
    });
    const text = await res.text();
    if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
    const data = JSON.parse(text);
    if (!data?.ok) throw new Error(data?.error || "Failed to create property");
    await loadOptions();
    if (newPropertyAddress?.street_1) {
      const addressRes = await fetch(PROPERTY_ADDRESS_SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ property_id: data.id, address: newPropertyAddress }),
      });
      const addressText = await addressRes.text();
      if (!addressRes.ok) throw new Error(`HTTP ${addressRes.status}: ${addressText.slice(0, 200)}`);
      const addressData = JSON.parse(addressText);
      if (!addressData?.ok) throw new Error(addressData?.error || "Failed to save property address");
    }
    setNewPropertyForm(emptyProperty);
    setNewPropertyAddress(null);
    setShowInlineProperty(false);
    updateProjectForm("property_id", data.id);
    return data.id;
  }

  async function saveProject() {
    setSaving(true);
    setStatus("");
    setError("");
    try {
      let propertyId = Number(projectForm.property_id || 0);
      if (showInlineProperty && !propertyId) {
        propertyId = await createInlineProperty();
      }
      if (propertyId > 0 && !showInlineProperty) {
        const property = properties.find((item) => Number(item.id) === propertyId);
        const nextClientId = Number(projectForm.client_id || 0) || null;
        const currentClientId = property?.client_id ? Number(property.client_id) : null;
        if (property && nextClientId !== currentClientId) {
          const propertyRes = await fetch(PROPERTY_SAVE_URL, {
            method: "POST",
            credentials: "include",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              id: propertyId,
              name: property.name,
              client_id: nextClientId,
              address_id: property.address_id || null,
              notes: property.notes || "",
            }),
          });
          const propertyText = await propertyRes.text();
          if (!propertyRes.ok) throw new Error(`HTTP ${propertyRes.status}: ${propertyText.slice(0, 200)}`);
          const propertyData = JSON.parse(propertyText);
          if (!propertyData?.ok) throw new Error(propertyData?.error || "Failed to save property client");
        }
      }
      const payload = {
        ...projectForm,
        property_id: propertyId,
        project_type_id: Number(projectForm.project_type_id || 0),
        name: projectForm.name.trim(),
        notes: projectForm.notes.trim(),
        project_painter_note: (projectForm.project_painter_note || "").trim(),
      };
      const res = await fetch(SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      const data = JSON.parse(text);
      if (!data?.ok) throw new Error(data?.error || "Failed to save project");
      setStatus("Project saved.");
      if (!projectForm.id && window.location.search.includes("action=new")) {
        window.history.replaceState({}, "", "/admin/projects");
      }
      await loadProjects(Number(data.id));
    } catch (err) {
      setError(err?.message || "Failed to save project");
    } finally {
      setSaving(false);
    }
  }

  async function attachPlaylist() {
    const playlistId = Number(playlistAttachId || 0);
    if (!projectForm.id || playlistId <= 0) {
      setError("Choose a playlist to attach.");
      return;
    }

    setSaving(true);
    setStatus("");
    setError("");
    try {
      const res = await fetch(PLAYLIST_ATTACH_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          project_id: projectForm.id,
          playlist_id: playlistId,
        }),
      });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      const data = JSON.parse(text);
      if (!data?.ok) throw new Error(data?.error || "Failed to attach playlist");
      setStatus(data.attached ? "Playlist attached." : "Playlist was already attached.");
      setPlaylistAttachId("");
      await loadProject(projectForm.id);
      await loadProjects(projectForm.id);
      setActiveTab("playlist");
    } catch (err) {
      setError(err?.message || "Failed to attach playlist");
    } finally {
      setSaving(false);
    }
  }

  async function removePlaylist(projectPlaylistId) {
    const linkId = Number(projectPlaylistId || 0);
    if (!projectForm.id || linkId <= 0) {
      setError("Playlist link required.");
      return;
    }
    const target = playlists.find((playlist) => Number(playlist.project_playlist_id) === linkId);
    const label = target?.title || `Playlist link #${linkId}`;
    if (!window.confirm(`Remove ${label} from this project?`)) return;

    setSaving(true);
    setStatus("");
    setError("");
    try {
      const res = await fetch(PLAYLIST_REMOVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          project_id: projectForm.id,
          project_playlist_id: linkId,
        }),
      });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      const data = JSON.parse(text);
      if (!data?.ok) throw new Error(data?.error || "Failed to remove playlist");
      setStatus(data.removed ? "Playlist removed from project." : "Playlist was already removed.");
      await loadProject(projectForm.id);
      await loadProjects(projectForm.id);
      setActiveTab("playlist");
    } catch (err) {
      setError(err?.message || "Failed to remove playlist");
    } finally {
      setSaving(false);
    }
  }

  async function openPlaylistRexReservation(playlist) {
    const playlistId = Number(playlist?.playlist_id || 0);
    const experienceKey = String(rexExperienceByPlaylist[playlistId] || "concept").trim().toLowerCase();
    const experience = rexExperienceOptions.find((item) => item.key === experienceKey);

    if (playlistId <= 0 || !experience) {
      setError("Choose a valid playlist experience.");
      return;
    }

    setSaving(true);
    setStatus("");
    setError("");

    try {
      const params = new URLSearchParams();
      params.set("resource_type", "playlist");
      params.set("resource_id", String(playlistId));
      params.set("_", String(Date.now()));

      const res = await fetch(`${REX_LIST_URL}?${params.toString()}`, {
        credentials: "include",
      });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      const data = JSON.parse(text);
      if (!data?.ok) throw new Error(data?.error || "Failed to load REX reservations");

      const existing = (Array.isArray(data.items) ? data.items : []).find((item) => {
        if (item?.resolver_key !== "playlist_experience" || item?.status !== "active") return false;
        const fields = Array.isArray(item?.descriptor?.fields) ? item.descriptor.fields : [];
        const experienceField = fields.find((field) => field?.label === "Experience");
        return String(experienceField?.value || "").trim().toLowerCase() === experienceKey;
      });

      if (!existing) {
        setError(`No active ${experience.label} REX reservation exists for this playlist.`);
        return;
      }

      const token = String(existing.token || "").trim();
      if (!token) {
        throw new Error(`REX reservation #${existing.id} has no token.`);
      }

      window.open(`/t/${encodeURIComponent(token)}`, "_blank", "noopener,noreferrer");
      setStatus(`Opened ${experience.label} REX reservation #${existing.id}.`);
    } catch (err) {
      setError(err?.message || "Failed to open REX reservation");
    } finally {
      setSaving(false);
    }
  }

  async function createPlaylistRexReservation(playlist) {
    const playlistId = Number(playlist?.playlist_id || 0);
    const experienceKey = String(rexExperienceByPlaylist[playlistId] || "concept").trim().toLowerCase();
    const experience = rexExperienceOptions.find((item) => item.key === experienceKey);

    if (playlistId <= 0 || !experience) {
      setError("Choose a valid playlist experience.");
      return;
    }

    if (experienceKey === "painter" && String(projectForm.current_release || "").toUpperCase() !== "FINAL") {
      setError("Painter REX requires the Project Current Version to be FINAL.");
      return;
    }

    setSaving(true);
    setStatus("");
    setError("");

    try {
      const listParams = new URLSearchParams();
      listParams.set("resource_type", "playlist");
      listParams.set("resource_id", String(playlistId));
      listParams.set("_", String(Date.now()));

      const listRes = await fetch(`${REX_LIST_URL}?${listParams.toString()}`, {
        credentials: "include",
      });
      const listText = await listRes.text();
      if (!listRes.ok) throw new Error(`HTTP ${listRes.status}: ${listText.slice(0, 200)}`);
      const listData = JSON.parse(listText);
      if (!listData?.ok) throw new Error(listData?.error || "Failed to check REX reservations");

      const existing = (Array.isArray(listData.items) ? listData.items : []).find((item) => {
        if (item?.resolver_key !== "playlist_experience" || item?.status !== "active") return false;
        const fields = Array.isArray(item?.descriptor?.fields) ? item.descriptor.fields : [];
        const experienceField = fields.find((field) => field?.label === "Experience");
        return String(experienceField?.value || "").trim().toLowerCase() === experienceKey;
      });

      if (existing) {
        setStatus(`${experience.label} REX reservation already exists (#${existing.id}).`);
        return;
      }

      const res = await fetch(REX_CREATE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          label: `${playlist?.title || `Playlist #${playlistId}`} — ${experience.label}`,
          resolver_key: "playlist_experience",
          resource_type: "playlist",
          resource_id: playlistId,
          source_key: null,
          context: {
            experience_key: experienceKey,
          },
        }),
      });
      const responseText = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${responseText.slice(0, 200)}`);
      const data = JSON.parse(responseText);
      if (!data?.ok) throw new Error(data?.error || "Failed to create REX reservation");

      setStatus(`${experience.label} REX reservation created.`);
      await loadProjects(projectForm.id);
      setActiveTab("playlist");
    } catch (err) {
      setError(err?.message || "Failed to create REX reservation");
    } finally {
      setSaving(false);
    }
  }

  function updateColorPlanForm(field, value) {
    setColorPlanForm((prev) => {
      if (field !== "nickname") {
        return { ...prev, [field]: value };
      }
      const next = { ...prev, nickname: value };
      if (!prev.area_name || prev.area_name === prev.nickname) {
        next.area_name = value;
      }
      if (!prev.scheme_title || prev.scheme_title === prev.nickname) {
        next.scheme_title = value;
      }
      return next;
    });
  }

  async function createColorPlan() {
    if (!projectForm.id) {
      setError("Save the project before adding Color Plans.");
      return;
    }
    setSaving(true);
    setStatus("");
    setError("");
    try {
      const res = await fetch(COLOR_PLAN_CREATE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ project_id: projectForm.id, nickname: "New Color Plan" }),
      });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      const data = JSON.parse(text);
      if (!data?.ok || !data?.id) throw new Error(data?.error || "Failed to create Color Plan");
      setStatus("Color Plan created.");
      setActiveColorPlanTab("plan");
      await loadColorPlans(projectForm.id, data.id);
    } catch (err) {
      setError(err?.message || "Failed to create Color Plan");
    } finally {
      setSaving(false);
    }
  }

  async function saveColorPlan() {
    if (!colorPlanForm.id || !projectForm.id) {
      setError("Choose or create a Color Plan first.");
      return;
    }
    setSaving(true);
    setStatus("");
    setError("");
    try {
      const res = await fetch(COLOR_PLAN_UPDATE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          ...colorPlanForm,
          project_id: projectForm.id,
          revision_number: Number(colorPlanForm.revision_number || 1),
        }),
      });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      const data = JSON.parse(text);
      if (!data?.ok) throw new Error(data?.error || "Failed to save Color Plan");
      setStatus("Color Plan saved.");
      await loadColorPlans(projectForm.id, colorPlanForm.id);
    } catch (err) {
      setError(err?.message || "Failed to save Color Plan");
    } finally {
      setSaving(false);
    }
  }

  async function deleteColorPlan() {
    if (!colorPlanForm.id || !projectForm.id) {
      setError("Choose a Color Plan first.");
      return;
    }
    const label = colorPlanForm.nickname || colorPlanForm.area_name || `Color Plan #${colorPlanForm.id}`;
    if (!window.confirm(`Delete "${label}" and its colors/photos?`)) return;
    setSaving(true);
    setStatus("");
    setError("");
    try {
      const res = await fetch(COLOR_PLAN_DELETE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          id: colorPlanForm.id,
          project_id: projectForm.id,
        }),
      });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      const data = JSON.parse(text);
      if (!data?.ok) throw new Error(data?.error || "Failed to delete Color Plan");
      setStatus(data.deleted ? "Color Plan deleted." : "Color Plan was already removed.");
      setSelectedColorPlanId("");
      setColorPlanForm({ ...emptyColorPlan, project_id: projectForm.id });
      setColorPlanMembers([]);
      setDeletedColorPlanMemberIds([]);
      setViewerForms(emptyViewerForms);
      setViewerPhotos(emptyViewerPhotos);
      setViewerRexByKey({});
      await loadColorPlans(projectForm.id, null);
    } catch (err) {
      setError(err?.message || "Failed to delete Color Plan");
    } finally {
      setSaving(false);
    }
  }

  function addColorPlanMember(color = null) {
    setColorPlanMembers((prev) => [
      ...prev,
      {
        key: `new-member-${Date.now()}`,
        id: null,
        color_id: color?.id || "",
        color,
        role_name: "",
        sheen: "",
        note: "",
        order_index: prev.length,
      },
    ]);
  }

  function updateColorPlanMember(index, field, value) {
    setColorPlanMembers((prev) => prev.map((row, rowIndex) => {
      if (rowIndex !== index) return row;
      if (field === "color") {
        return { ...row, color: value, color_id: value?.id || "" };
      }
      return { ...row, [field]: value };
    }));
  }

  function removeColorPlanMember(index) {
    setColorPlanMembers((prev) => {
      const target = prev[index];
      if (target?.id) {
        setDeletedColorPlanMemberIds((ids) => [...ids, target.id]);
      }
      return prev.filter((_, rowIndex) => rowIndex !== index).map((row, rowIndex) => ({ ...row, order_index: rowIndex }));
    });
  }

  function moveColorPlanMember(index, direction) {
    setColorPlanMembers((prev) => {
      const nextIndex = index + direction;
      if (nextIndex < 0 || nextIndex >= prev.length) return prev;
      const next = [...prev];
      [next[index], next[nextIndex]] = [next[nextIndex], next[index]];
      return next.map((row, rowIndex) => ({ ...row, order_index: rowIndex }));
    });
  }

  async function saveColorPlanMembers() {
    if (!colorPlanForm.id) {
      setError("Choose or create a Color Plan first.");
      return;
    }
    setSaving(true);
    setStatus("");
    setError("");
    try {
      const res = await fetch(COLOR_PLAN_MEMBERS_SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          project_color_plan_id: colorPlanForm.id,
          delete_ids: deletedColorPlanMemberIds,
          members: colorPlanMembers.map((row, index) => ({
            id: row.id || null,
            color_id: Number(row.color_id || row.color?.id || 0),
            role_name: row.role_name || "",
            sheen: row.sheen || "",
            note: row.note || "",
            order_index: index,
          })),
        }),
      });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      const data = JSON.parse(text);
      if (!data?.ok) throw new Error(data?.error || "Failed to save Color Plan colors");
      setStatus("Color Plan colors saved.");
      await loadColorPlan(colorPlanForm.id, projectForm.id);
    } catch (err) {
      setError(err?.message || "Failed to save Color Plan colors");
    } finally {
      setSaving(false);
    }
  }

  function selectWorkingColorPlan(planId) {
    setSelectedColorPlanId(planId);
    setViewerRexByKey({});
    if (planId) {
      void loadColorPlan(planId, projectForm.id);
    } else {
      setColorPlanForm({ ...emptyColorPlan, project_id: projectForm.id });
      setColorPlanMembers([]);
      setViewerForms(emptyViewerForms);
      setViewerPhotos(emptyViewerPhotos);
      setViewerRexByKey({});
    }
  }

  async function fetchViewerSetup(planId) {
    if (!planId) return;
    const params = new URLSearchParams();
    params.set("project_color_plan_id", String(planId));
    params.set("_", String(Date.now()));
    const res = await fetch(`${COLOR_PLAN_VIEWER_GET_URL}?${params.toString()}`, { credentials: "include" });
    const text = await res.text();
    if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
    const data = JSON.parse(text);
    if (!data?.ok) throw new Error(data?.error || "Failed to load viewer setup");
    const viewers = data.viewers || {};
    const nextForms = { ...emptyViewerForms };
    const nextPhotos = { ...emptyViewerPhotos };
    for (const key of viewerTabs) {
      nextForms[key] = { ...emptyViewerForms[key], ...(viewers[key]?.form || {}) };
      nextPhotos[key] = normalizeViewerPhotoRows(key, viewers[key]?.photos || []);
    }
    return { forms: nextForms, photos: nextPhotos };
  }

  async function loadViewerSetup(planId) {
    if (!planId) return;
    try {
      const setup = await fetchViewerSetup(planId);
      if (!setup) return;
      setViewerForms(setup.forms);
      setViewerPhotos(setup.photos);
    } catch (err) {
      setError(err?.message || "Failed to load viewer setup");
    }
  }

  function updateViewerForm(viewerKey, field, value) {
    setViewerForms((prev) => ({
      ...prev,
      [viewerKey]: {
        ...prev[viewerKey],
        [field]: value,
      },
    }));
  }

  function addViewerPhoto(photo) {
    if (!photo) return;
    const target = viewerPhotoPickerTarget || activeViewerTab;
    setViewerPhotos((prev) => ({
      ...prev,
      [target]: [
        ...(prev[target] || []),
        {
          key: `viewer-photo-${target}-${Date.now()}`,
          photo_library_id: photo.photo_library_id || null,
          rel_path: photo.raw_rel_path || photo.image_url || "",
          photo_title: photo.title || "",
          photo_type: "FULL",
          order_index: prev[target]?.length || 0,
        },
      ],
    }));
    setViewerPhotoPickerOpen(false);
  }

  function updateViewerPhoto(viewerKey, index, field, value) {
    setViewerPhotos((prev) => ({
      ...prev,
      [viewerKey]: (prev[viewerKey] || []).map((row, rowIndex) => (
        rowIndex === index ? { ...row, [field]: value } : row
      )),
    }));
  }

  function removeViewerPhoto(viewerKey, index) {
    setViewerPhotos((prev) => ({
      ...prev,
      [viewerKey]: (prev[viewerKey] || [])
        .filter((_, rowIndex) => rowIndex !== index)
        .map((row, rowIndex) => ({ ...row, order_index: rowIndex })),
    }));
  }

  function moveViewerPhoto(viewerKey, index, direction) {
    setViewerPhotos((prev) => {
      const current = [...(prev[viewerKey] || [])];
      const nextIndex = index + direction;
      if (nextIndex < 0 || nextIndex >= current.length) return prev;
      [current[index], current[nextIndex]] = [current[nextIndex], current[index]];
      return {
        ...prev,
        [viewerKey]: current.map((row, rowIndex) => ({ ...row, order_index: rowIndex })),
      };
    });
  }

  async function copyViewerPhotos(fromKey, toKey) {
    if (!colorPlanForm.id) {
      setError("Choose or create a Color Plan first.");
      return;
    }

    setStatus("");
    setError("");
    const localSourceForm = viewerForms[fromKey] || {};
    const localSourcePhotos = viewerPhotos[fromKey] || [];
    let sourceForm = localSourceForm;
    let sourcePhotos = localSourcePhotos;
    let sourceKeyUsed = fromKey;

    if (localSourcePhotos.length === 0 || !hasViewerCopyText(fromKey, toKey, localSourceForm)) {
      try {
        const setup = await fetchViewerSetup(colorPlanForm.id);
        sourceForm = mergeViewerSourceForm(setup?.forms?.[fromKey], localSourceForm);
        sourcePhotos = localSourcePhotos.length ? localSourcePhotos : (setup?.photos?.[fromKey] || []);
        if (fromKey === "client" && toKey === "painter" && sourcePhotos.length === 0) {
          sourceKeyUsed = "concept";
          sourcePhotos = setup?.photos?.concept || [];
          sourceForm = mergeViewerSourceForm(setup?.forms?.concept, sourceForm);
        }
      } catch (err) {
        setError(err?.message || "Failed to load saved viewer setup before copying");
        return;
      }
    }

    const copiedPhotos = sourcePhotos.map((row, index) => ({
      ...row,
      id: null,
      key: `viewer-photo-${toKey}-${Date.now()}-${index}`,
      order_index: index,
    }));

    setViewerForms((prev) => {
      const nextTarget = { ...(prev[toKey] || {}) };
      if (fromKey === "concept" && toKey === "client") {
        nextTarget.scheme_title = sourceForm.title || sourceForm.scheme_title || "";
        nextTarget.final_design_description = sourceForm.design_direction || sourceForm.final_design_description || "";
      }
      if (fromKey === "client" && toKey === "painter") {
        nextTarget.overall_painter_note = sourceForm.final_design_description || sourceForm.design_direction || "";
      }
      return {
        ...prev,
        [toKey]: nextTarget,
      };
    });

    setViewerPhotos((prev) => ({
      ...prev,
      [toKey]: copiedPhotos,
    }));

    setStatus(`Copied ${viewerKeyLabel(sourceKeyUsed)} title/copy and ${copiedPhotos.length} photo${copiedPhotos.length === 1 ? "" : "s"} to ${viewerKeyLabel(toKey)}.`);
  }

  async function saveViewerSetup(viewerKeyOverride = activeViewerTab) {
    if (!colorPlanForm.id) {
      setError("Choose or create a Color Plan first.");
      return false;
    }
    const viewerKey = viewerKeyOverride || activeViewerTab;
    setSaving(true);
    setStatus("");
    setError("");
    try {
      if (viewerKey === "painter" && projectForm.id) {
        const projectRes = await fetch(SAVE_URL, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            ...projectForm,
            property_id: Number(projectForm.property_id || 0),
            project_type_id: Number(projectForm.project_type_id || 0),
            name: String(projectForm.name || "").trim(),
            notes: String(projectForm.notes || "").trim(),
            project_painter_note: String(projectForm.project_painter_note || "").trim(),
          }),
        });
        const projectText = await projectRes.text();
        if (!projectRes.ok) throw new Error(`HTTP ${projectRes.status}: ${projectText.slice(0, 200)}`);
        const projectData = JSON.parse(projectText);
        if (!projectData?.ok) throw new Error(projectData?.error || "Failed to save project painter note");
      }

      const res = await fetch(COLOR_PLAN_VIEWER_SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          project_color_plan_id: colorPlanForm.id,
          viewer_key: viewerKey,
          form: viewerForms[viewerKey] || {},
          photos: (viewerPhotos[viewerKey] || []).map((row, index) => ({
            photo_library_id: Number(row.photo_library_id || 0) || null,
            rel_path: row.rel_path || "",
            photo_type: row.photo_type || "FULL",
            order_index: index,
          })),
        }),
      });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      const data = JSON.parse(text);
      if (!data?.ok) throw new Error(data?.error || "Failed to save viewer setup");
      const rex = data.rex?.viewer || null;
      if (rex?.public_url) {
        setViewerRexByKey((prev) => ({
          ...prev,
          [viewerKey]: rex,
        }));
      }
      const warning = data.rex_warning || data.rex?.relationship?.warning || "";
      setStatus(`${viewerKey.charAt(0).toUpperCase()}${viewerKey.slice(1)} viewer saved.${warning ? ` REX warning: ${warning}` : ""}`);
      await loadViewerSetup(colorPlanForm.id);
      return { ok: true, rex, warning };
    } catch (err) {
      setError(err?.message || "Failed to save viewer setup");
      return { ok: false };
    } finally {
      setSaving(false);
    }
  }

  async function previewViewer(viewerKey) {
    const targetKey = viewerKey || activeViewerTab;
    const previewWindow = window.open("about:blank", "_blank");
    if (!previewWindow) {
      setError("Browser blocked the preview window. Allow popups for this site and try again.");
      return;
    }

    const saved = await saveViewerSetup(targetKey);
    if (!saved?.ok) {
      previewWindow.close();
      return;
    }

    const publicUrl = saved.rex?.public_url || viewerRexByKey[targetKey]?.public_url || "";
    if (!publicUrl) {
      previewWindow.close();
      setError(saved.warning || "Save Viewer first to create the Viewer REX URL.");
      return;
    }
    previewWindow.location.href = publicUrl;
  }

  return (
    <div className="admin-projects">
      <AdminMasterDetail
        storageKey="admin-projects-list-width"
        defaultListWidth={340}
        minListWidth={280}
        maxListWidth={520}
        list={
          <AdminListPane
            title="Projects"
            actions={
              <button
                type="button"
                className="admin-projects__btn admin-projects__btn--primary admin-projects__btn--small"
                onClick={() => {
                  if (confirmProjectChangeLoss()) {
                    startNewProject("", projectTypes);
                  }
                }}
              >
                New
              </button>
            }
            toolbar={
              <div className="admin-projects__filters">
                <input
                  type="text"
                  placeholder="Search projects"
                  value={filters.q}
                  onChange={(event) => setFilters((prev) => ({ ...prev, q: event.target.value }))}
                />
                <select
                  value={filters.project_type_id}
                  onChange={(event) => setFilters((prev) => ({ ...prev, project_type_id: event.target.value }))}
                >
                  <option value="">All project types</option>
                  {projectTypes.map((type) => (
                    <option key={type.id} value={type.id}>{type.name}</option>
                  ))}
                </select>
              </div>
            }
          >
            {loadingList ? (
              <AdminEmptyState title="Loading projects" />
            ) : projects.length === 0 ? (
              <AdminEmptyState title="No projects yet" message="Create a project to begin." />
            ) : (
              <AdminObjectList ariaLabel="Projects">
                {projects.map((project) => (
                  <AdminObjectListItem
                    key={project.id}
                    id={project.id}
                    title={project.name || "Untitled project"}
                    meta={[
                      [project.property_name || "No property", project.client_name || "No client assigned"].join(" · "),
                      `${project.project_type_name || "Project type"} · Version ${String(project.current_release || "1").toUpperCase()}`,
                      currentPlaylistLabel(project),
                    ]}
                    selected={Number(selectedProjectId) === Number(project.id)}
                    status={{
                      active: (project.rex?.length || 0) > 0,
                      count: project.rex?.length || 0,
                      label: `${project.rex?.length || 0} active REX reservation${project.rex?.length === 1 ? "" : "s"}`,
                    }}
                    onStatusClick={() => {
                      const reservationIds = Array.isArray(project.rex) ? project.rex : [];

                      if (!reservationIds.length) return;

                      setRexDialog({
                        open: true,
                        reservationIds,
                        title: project.name || "Untitled project",
                      });
                    }}
                    onSelect={() => {
                      if (!confirmProjectChangeLoss()) return;
                      setSelectedProjectId(project.id);
                      void loadProject(project.id);
                    }}
                  />
                ))}
              </AdminObjectList>
            )}
          </AdminListPane>
        }
        detail={
          <AdminDetailPane ariaLabel="Project detail" className="admin-projects__main">
            {error ? <div className="admin-projects__message admin-projects__message--error">{error}</div> : null}
            {status ? <div className="admin-projects__message admin-projects__message--status">{status}</div> : null}

            {showProjectForm ? (
              <ProjectForm
                form={projectForm}
                clients={clients}
                properties={properties}
                projectTypes={projectTypes}
                releaseVersionOptions={releaseVersionOptions}
                saving={saving}
                showInlineProperty={showInlineProperty}
                newPropertyForm={newPropertyForm}
                newPropertyAddress={newPropertyAddress}
                onChange={updateProjectForm}
                onSave={saveProject}
                onCancel={() => {
                  if (selectedProjectId) {
                    setShowProjectForm(false);
                  } else {
                    startNewProject("", projectTypes);
                  }
                }}
                onToggleInlineProperty={() => {
                  setShowInlineProperty((prev) => !prev);
                  updateProjectForm("property_id", "");
                }}
                onNewPropertyChange={updateNewPropertyForm}
                onEditNewPropertyAddress={() => setAddressModalOpen(true)}
              />
            ) : (
              <section className="admin-projects__panel">
                {loadingDetail ? (
                  <AdminEmptyState title="Loading project" />
                ) : selectedProject ? (
                  <>
                    <div className="admin-projects__panel-header admin-projects__detail-header">
                      <div>
                        <h2>{selectedProject.name}</h2>
                        <div className="admin-projects__detail-meta">
                          <span>{selectedProject.property_name || "No property"}</span>
                          <span>{selectedProject.client_name || "No client assigned"}</span>
                          <span>{selectedProject.project_type_name}</span>
                          <span>Updated {formatDate(selectedProject.updated_at)}</span>
                        </div>
                      </div>
                    </div>

                    <div className="admin-projects__tabs" role="tablist">
                      {projectTabs.map((tab) => (
                        <button
                          key={tab}
                          type="button"
                          className={`admin-projects__tab${activeTab === tab ? " is-active" : ""}`}
                          onClick={() => setActiveTab(tab)}
                        >
                          {tab}
                        </button>
                      ))}
                    </div>

                    <WorkingOnSelector
                      plans={colorPlans}
                      selectedPlanId={selectedColorPlanId}
                      loading={loadingColorPlan}
                      onSelectPlan={selectWorkingColorPlan}
                    />

                    {activeTab === "overview" ? (
                      <Overview
                        project={{
                          ...selectedProject,
                          ...projectForm,
                          current_release: projectForm.current_release || "1",
                        }}
                        clients={clients}
                        properties={properties}
                        projectTypes={projectTypes}
                        releaseVersionOptions={releaseVersionOptions}
                        saving={saving}
                        dirty={isProjectDirty}
                        onChange={updateProjectForm}
                        onCurrentReleaseChange={(value) => {
                          updateProjectForm("current_release", value);
                          return true;
                        }}
                        onSave={saveProject}
                      />
                    ) : activeTab === "playlist" ? (
                      <PlaylistsSection
                        playlists={playlists}
                        playlistOptions={playlistOptions}
                        playlistAttachId={playlistAttachId}
                        rexExperienceByPlaylist={rexExperienceByPlaylist}
                        currentRelease={projectForm.current_release || "1"}
                        saving={saving}
                        onPlaylistAttachIdChange={setPlaylistAttachId}
                        onRexExperienceChange={(playlistId, experienceKey) => {
                          setRexExperienceByPlaylist((prev) => ({
                            ...prev,
                            [playlistId]: experienceKey,
                          }));
                        }}
                        onOpenRexReservation={openPlaylistRexReservation}
                        onCreateRexReservation={createPlaylistRexReservation}
                        onAttachPlaylist={attachPlaylist}
                        onRemovePlaylist={removePlaylist}
                      />
                    ) : activeTab === "color plans" ? (
                      <ColorPlansSection
                        plans={colorPlans}
                        selectedPlanId={selectedColorPlanId}
                        workingPlan={selectedWorkingColorPlan}
                        form={colorPlanForm}
                        members={colorPlanMembers}
                        activeInnerTab={activeColorPlanTab}
                        saving={saving}
                        loading={loadingColorPlan}
                        onSelectPlan={selectWorkingColorPlan}
                        onNewPlan={createColorPlan}
                        onInnerTabChange={setActiveColorPlanTab}
                        onPlanChange={updateColorPlanForm}
                        onSavePlan={saveColorPlan}
                        onDeletePlan={deleteColorPlan}
                        onAddMember={addColorPlanMember}
                        onUpdateMember={updateColorPlanMember}
                        onRemoveMember={removeColorPlanMember}
                        onMoveMember={moveColorPlanMember}
                        onSaveMembers={saveColorPlanMembers}
                      />
                    ) : activeTab === "viewers" ? (
                      <ProjectViewersSection
                        project={selectedProject}
                        workingPlan={selectedWorkingColorPlan}
                        members={colorPlanMembers}
                        activeViewerTab={activeViewerTab}
                        viewerForms={viewerForms}
                        viewerPhotos={viewerPhotos}
                        onViewerTabChange={setActiveViewerTab}
                        onViewerFormChange={updateViewerForm}
                        onOpenPhotoPicker={(viewerKey) => {
                          setViewerPhotoPickerTarget(viewerKey);
                          setViewerPhotoPickerOpen(true);
                        }}
                        onUpdatePhoto={updateViewerPhoto}
                        onRemovePhoto={removeViewerPhoto}
                        onMovePhoto={moveViewerPhoto}
                        onCopyPhotos={copyViewerPhotos}
                        projectPainterNote={projectForm.project_painter_note || ""}
                        onProjectPainterNoteChange={(value) => updateProjectForm("project_painter_note", value)}
                        onSaveViewer={saveViewerSetup}
                        onPreviewViewer={previewViewer}
                        saving={saving}
                      />
                    ) : (
                      <Placeholder text="Project activity tracking will be added later" />
                    )}

                  </>
                ) : (
                  <AdminEmptyState title="Select or create a project" />
                )}
              </section>
            )}
          </AdminDetailPane>
        }
      />

      <RexManagementDialog
        open={rexDialog.open}
        reservationIds={rexDialog.reservationIds}
        title={rexDialog.title}
        onClose={() => {
          setRexDialog({
            open: false,
            reservationIds: [],
            title: "",
          });
        }}
      />

      <AddressModal
        open={addressModalOpen}
        title="Property Address"
        address={newPropertyAddress}
        onClose={() => setAddressModalOpen(false)}
        onSave={(address) => {
          setNewPropertyAddress(address);
          setAddressModalOpen(false);
        }}
        onRemove={newPropertyAddress ? () => {
          setNewPropertyAddress(null);
          setAddressModalOpen(false);
        } : undefined}
      />
      <PhotoPickerModal
        open={viewerPhotoPickerOpen}
        title="Pick Viewer Photo"
        onClose={() => setViewerPhotoPickerOpen(false)}
        onPick={addViewerPhoto}
      />
    </div>
  );
}

function ProjectForm({
  form,
  clients,
  properties,
  projectTypes,
  releaseVersionOptions,
  saving,
  showInlineProperty,
  newPropertyForm,
  newPropertyAddress,
  onChange,
  onSave,
  onCancel,
  onToggleInlineProperty,
  onNewPropertyChange,
  onEditNewPropertyAddress,
}) {
  return (
    <section className="admin-projects__panel">
      <div className="admin-projects__panel-header">
        <h2>{form.id ? "Edit Project" : "New Project"}</h2>
        <div className="admin-projects__actions">
          <button type="button" className="admin-projects__btn" onClick={onCancel} disabled={saving}>Cancel</button>
          <button type="button" className="admin-projects__btn admin-projects__btn--primary" onClick={onSave} disabled={saving}>
            {saving ? "Saving..." : "Save Project"}
          </button>
        </div>
      </div>

      <div className="admin-projects__form-grid">
        <label>
          <span>Project name</span>
          <input value={form.name} onChange={(event) => onChange("name", event.target.value)} />
        </label>
        <label>
          <span>Project type</span>
          <select value={form.project_type_id || ""} onChange={(event) => onChange("project_type_id", event.target.value)}>
            <option value="">Choose project type</option>
            {projectTypes.map((type) => (
              <option key={type.id} value={type.id}>{type.name}</option>
            ))}
          </select>
        </label>
        <CurrentReleaseControl
          value={form.current_release || "1"}
          versionOptions={releaseVersionOptions}
          onCommit={(value) => {
            onChange("current_release", value);
            return true;
          }}
        />
        <label>
          <span>Property</span>
          <select value={form.property_id || ""} onChange={(event) => onChange("property_id", event.target.value)} disabled={showInlineProperty}>
            <option value="">Choose existing property</option>
            {properties.map((property) => (
              <option key={property.id} value={property.id}>{property.name}</option>
            ))}
          </select>
        </label>
        <div className="admin-projects__wide">
          <button type="button" className="admin-projects__btn" onClick={onToggleInlineProperty}>
            {showInlineProperty ? "Choose Existing Property" : "Create New Property"}
          </button>
        </div>
        {showInlineProperty ? (
          <div className="admin-projects__inline-box admin-projects__wide">
            <h3>New Property</h3>
            <div className="admin-projects__form-grid">
              <label>
                <span>Property name</span>
                <input value={newPropertyForm.name} onChange={(event) => onNewPropertyChange("name", event.target.value)} />
              </label>
              <label>
                <span>Client</span>
                <select value={newPropertyForm.client_id || ""} onChange={(event) => onNewPropertyChange("client_id", event.target.value)}>
                  <option value="">No client assigned</option>
                  {clients.map((client) => (
                    <option key={client.id} value={client.id}>{clientDisplayName(client)}</option>
                  ))}
                </select>
              </label>
              <label className="admin-projects__wide">
                <span>Notes</span>
                <textarea rows={3} value={newPropertyForm.notes} onChange={(event) => onNewPropertyChange("notes", event.target.value)} />
              </label>
              <div className="admin-projects__field admin-projects__wide">
                <span>Address</span>
                <div className="admin-projects__inline-address">
                  <strong>{addressLine(newPropertyAddress)}</strong>
                  <button type="button" className="admin-projects__btn" onClick={onEditNewPropertyAddress}>
                    {newPropertyAddress ? "Edit Address" : "Add Address"}
                  </button>
                </div>
              </div>
            </div>
            <p>Address is optional.</p>
          </div>
        ) : null}
        <label className="admin-projects__wide">
          <span>Notes</span>
          <textarea rows={4} value={form.notes} onChange={(event) => onChange("notes", event.target.value)} />
        </label>
      </div>
    </section>
  );
}

function Overview({
  project,
  clients,
  properties,
  projectTypes,
  releaseVersionOptions = [],
  saving = false,
  dirty = false,
  onChange,
  onCurrentReleaseChange,
  onSave,
}) {
  const selectedProperty = properties.find((property) => Number(property.id) === Number(project.property_id));
  const address = selectedProperty?.address || project.address;

  return (
    <div className="admin-projects__overview-grid">
      <label className="admin-projects__overview-field">
        <span>Project name</span>
        <input value={project.name || ""} onChange={(event) => onChange("name", event.target.value)} />
      </label>
      <label className="admin-projects__overview-field">
        <span>Project type</span>
        <select value={project.project_type_id || ""} onChange={(event) => onChange("project_type_id", event.target.value)}>
          <option value="">Choose project type</option>
          {projectTypes.map((type) => (
            <option key={type.id} value={type.id}>{type.name}</option>
          ))}
        </select>
      </label>
      <div className="admin-projects__overview-field admin-projects__overview-field--control">
        <CurrentReleaseControl
          value={project.current_release || "1"}
          versionOptions={releaseVersionOptions}
          onDraftChange={(value) => onChange("current_release", value)}
          onCommit={onCurrentReleaseChange}
        />
    
      </div>
      <label className="admin-projects__overview-field">
        <span>Property</span>
        <select value={project.property_id || ""} onChange={(event) => onChange("property_id", event.target.value)}>
          <option value="">Choose property</option>
          {properties.map((property) => (
            <option key={property.id} value={property.id}>{property.name}</option>
          ))}
        </select>
      </label>
      <label className="admin-projects__overview-field">
        <span>Client</span>
        <select value={project.client_id || ""} onChange={(event) => onChange("client_id", event.target.value)}>
          <option value="">No client assigned</option>
          {clients.map((client) => (
            <option key={client.id} value={client.id}>{clientDisplayName(client)}</option>
          ))}
        </select>
      </label>
      <Field label="Address" value={addressLine(address)} />
      <Field label="Created" value={formatDate(project.created_at)} />
      <Field label="Updated" value={formatDate(project.updated_at)} />
      <label className="admin-projects__overview-field">
        <span>Notes</span>
        <textarea rows={3} value={project.notes || ""} onChange={(event) => onChange("notes", event.target.value)} />
      </label>
      <div className="admin-projects__overview-field admin-projects__overview-actions">
        <button
          type="button"
          className={`admin-projects__btn admin-projects__btn--primary admin-projects__btn--small admin-projects__save-project${dirty ? " is-dirty" : ""}`}
          onClick={onSave}
          disabled={saving || !dirty}
        >
          {saving ? "Saving..." : "Save Project"}
        </button>
      </div>
    </div>
  );
}

function CurrentReleaseControl({ value, versionOptions = [], onDraftChange, onCommit }) {
  const [draft, setDraft] = useState(String(value || "1").toUpperCase());
  const listId = "project-current-release-options";

  const options = useMemo(() => {
    const numeric = Array.from(
      new Set(
        (Array.isArray(versionOptions) ? versionOptions : [])
          .map((item) => Number(item))
          .filter((item) => Number.isInteger(item) && item > 0)
      )
    ).sort((a, b) => a - b);

    return [...numeric.map(String), "FINAL"];
  }, [versionOptions]);

  useEffect(() => {
    setDraft(String(value || "1").toUpperCase());
  }, [value]);

  async function commit() {
    const normalized = String(draft || "").trim().toUpperCase();

    const valid =
      normalized === "FINAL" ||
      (/^\d+$/.test(normalized) && Number(normalized) >= 1);

    if (!valid) {
      setDraft(String(value || "1").toUpperCase());
      onDraftChange?.(String(value || "1").toUpperCase());
      return;
    }

    const accepted = await onCommit?.(normalized);

    if (accepted === false) {
      setDraft(String(value || "1").toUpperCase());
    } else {
      setDraft(normalized);
    }
  }

  return (
    <label className="admin-projects__experience-control">
      <span>Current Version</span>

      <input
        type="text"
        list={listId}
        value={draft}
        autoComplete="off"
        onChange={(event) => {
          const nextValue = event.target.value.toUpperCase();
          setDraft(nextValue);
          onDraftChange?.(nextValue);
        }}
        onBlur={() => void commit()}
        onKeyDown={(event) => {
          if (event.key === "Enter") {
            event.preventDefault();
            void commit();
          }
        }}
        aria-label="Current Version"
      />

      <datalist id={listId}>
        {options.map((option) => (
          <option key={option} value={option} />
        ))}
      </datalist>
    </label>
  );
}

function Field({ label, value, wide = false }) {
  return (
    <div className={`admin-projects__overview-field${wide ? " admin-projects__wide" : ""}`}>
      <span>{label}</span>
      <strong>{value || "Not assigned"}</strong>
    </div>
  );
}

function WorkingOnSelector({ plans, selectedPlanId, loading, onSelectPlan }) {
  return (
    <div className="admin-projects__working-on">
      <label>
        <span>Working on:</span>
        <select value={selectedPlanId || ""} onChange={(event) => onSelectPlan(event.target.value)} disabled={loading || plans.length === 0}>
          <option value="">{plans.length ? "Choose Color Plan / Area" : "No Color Plans yet"}</option>
          {plans.map((plan) => (
            <option key={plan.id} value={plan.id}>{colorPlanLabel(plan)}</option>
          ))}
        </select>
      </label>
    </div>
  );
}

function PhotosSection({ photos }) {
  return (
    <div className="admin-projects__section-stack">
      <div className="admin-projects__actions">
        <button type="button" className="admin-projects__btn" disabled>Upload New Photo</button>
        <button type="button" className="admin-projects__btn" disabled>Attach Existing Photo</button>
      </div>
      {photos.length === 0 ? (
        <div className="admin-projects__empty">No photos attached.</div>
      ) : photos.map((photo) => (
        <div key={photo.project_photo_id} className="admin-projects__linked-row">
          <span>{photo.title || `Photo #${photo.photo_library_id}`}</span>
          <span>{photo.role || "No role"}</span>
        </div>
      ))}
    </div>
  );
}

function PlaylistsSection({
  playlists,
  playlistOptions,
  playlistAttachId,
  rexExperienceByPlaylist,
  currentRelease,
  saving,
  onPlaylistAttachIdChange,
  onRexExperienceChange,
  onOpenRexReservation,
  onCreateRexReservation,
  onAttachPlaylist,
  onRemovePlaylist,
}) {
  return (
    <div className="admin-projects__section-stack">
      <div className="admin-projects__attach-row">
        <label>
          <span>Playlist</span>
          <select value={playlistAttachId} onChange={(event) => onPlaylistAttachIdChange(event.target.value)}>
            <option value="">Choose playlist</option>
            {playlistOptions.map((playlist) => (
              <option key={playlist.playlist_id} value={playlist.playlist_id}>
                #{playlist.playlist_id} {playlist.title || "Untitled playlist"}
              </option>
            ))}
          </select>
        </label>
        <button
          type="button"
          className="admin-projects__btn admin-projects__btn--primary"
          onClick={onAttachPlaylist}
          disabled={saving || !playlistAttachId}
        >
          Attach Playlist
        </button>
      </div>
      {playlists.length === 0 ? (
        <div className="admin-projects__empty">No playlists attached.</div>
      ) : playlists.map((playlist) => (
        <div key={playlist.project_playlist_id} className="admin-projects__linked-row">
          <span>
            <strong>{playlist.title || `Playlist #${playlist.playlist_id}`}</strong>
            <small>Playlist #{playlist.playlist_id}</small>
          </span>
          <span className="admin-projects__playlist-actions">
            <span>{playlist.slug || "No slug"}</span>
            <select
              value={rexExperienceByPlaylist?.[playlist.playlist_id] || "concept"}
              onChange={(event) => onRexExperienceChange(playlist.playlist_id, event.target.value)}
              disabled={saving}
              aria-label={`REX experience for ${playlist.title || `Playlist #${playlist.playlist_id}`}`}
            >
              {rexExperienceOptions.map((experience) => (
                <option
                  key={experience.key}
                  value={experience.key}
                  disabled={experience.key === "painter" && String(currentRelease || "").toUpperCase() !== "FINAL"}
                >
                  {experience.label}
                </option>
              ))}
            </select>
            <button
              type="button"
              className="admin-projects__btn"
              onClick={() => onOpenRexReservation(playlist)}
              disabled={saving}
            >
              Open / Play
            </button>
            <button
              type="button"
              className="admin-projects__btn admin-projects__btn--primary"
              onClick={() => onCreateRexReservation(playlist)}
              disabled={saving}
            >
              Create REX
            </button>
            <button
              type="button"
              className="admin-projects__btn admin-projects__btn--danger"
              onClick={() => onRemovePlaylist(playlist.project_playlist_id)}
              disabled={saving}
            >
              Remove
            </button>
          </span>
        </div>
      ))}
    </div>
  );
}

function ColorPlansSection({
  plans,
  selectedPlanId,
  workingPlan,
  form,
  members,
  activeInnerTab,
  saving,
  loading,
  onSelectPlan,
  onNewPlan,
  onInnerTabChange,
  onPlanChange,
  onSavePlan,
  onDeletePlan,
  onAddMember,
  onUpdateMember,
  onRemoveMember,
  onMoveMember,
  onSaveMembers,
}) {
  const innerTabs = ["plan", "colors"];
  const hasPlan = !!form?.id;

  return (
    <div className="admin-projects__section-stack admin-projects__color-plans">
      <div className="admin-projects__context-line">
        <span>Selected area</span>
        <strong>{colorPlanLabel(workingPlan) || "No Color Plan selected"}</strong>
      </div>
      <div className="admin-projects__attach-row">
        <label>
          <span>Color Plan</span>
          <select value={selectedPlanId || ""} onChange={(event) => onSelectPlan(event.target.value)} disabled={loading || plans.length === 0}>
            <option value="">{plans.length ? "Choose Color Plan" : "No Color Plans yet"}</option>
            {plans.map((plan) => (
              <option key={plan.id} value={plan.id}>
                {plan.nickname || `Color Plan #${plan.id}`}
              </option>
            ))}
          </select>
        </label>
        <button type="button" className="admin-projects__btn admin-projects__btn--primary" onClick={onNewPlan} disabled={saving}>
          New Color Plan
        </button>
      </div>

      {loading ? <div className="admin-projects__empty">Loading Color Plan...</div> : null}

      {!loading && hasPlan ? (
        <>
          <div className="admin-projects__tabs admin-projects__tabs--inner" role="tablist">
            {innerTabs.map((tab) => (
              <button
                key={tab}
                type="button"
                className={`admin-projects__tab${activeInnerTab === tab ? " is-active" : ""}`}
                onClick={() => onInnerTabChange(tab)}
              >
                {tab}
              </button>
            ))}
          </div>

          {activeInnerTab === "plan" ? (
            <ColorPlanFields
              form={form}
              saving={saving}
              onChange={onPlanChange}
              onSave={onSavePlan}
              onDelete={onDeletePlan}
            />
          ) : activeInnerTab === "colors" ? (
            <ColorPlanMembers
              members={members}
              saving={saving}
              onAddMember={onAddMember}
              onUpdateMember={onUpdateMember}
              onRemoveMember={onRemoveMember}
              onMoveMember={onMoveMember}
              onSave={onSaveMembers}
            />
          ) : null}
        </>
      ) : null}

      {!loading && !hasPlan ? (
        <div className="admin-projects__empty">Create a Color Plan to start editing private colors.</div>
      ) : null}
    </div>
  );
}

function ProjectViewersSection({
  project,
  workingPlan,
  members,
  activeViewerTab,
  viewerForms,
  viewerPhotos,
  onViewerTabChange,
  onViewerFormChange,
  onOpenPhotoPicker,
  onUpdatePhoto,
  onRemovePhoto,
  onMovePhoto,
  onCopyPhotos,
  projectPainterNote,
  onProjectPainterNoteChange,
  onSaveViewer,
  onPreviewViewer,
  saving,
}) {
  const form = viewerForms[activeViewerTab] || {};
  const photos = viewerPhotos[activeViewerTab] || [];

  return (
    <div className="admin-projects__section-stack admin-projects__viewers">
      <div className="admin-projects__tabs admin-projects__tabs--inner" role="tablist">
        {viewerTabs.map((tab) => (
          <button
            key={tab}
            type="button"
            className={`admin-projects__tab${activeViewerTab === tab ? " is-active" : ""}`}
            onClick={() => onViewerTabChange(tab)}
          >
            {tab}
          </button>
        ))}
      </div>

      <div className="admin-projects__viewer-top-actions">
        <button type="button" className="admin-projects__btn admin-projects__btn--primary" onClick={() => onSaveViewer(activeViewerTab)} disabled={saving || !workingPlan}>
          {saving ? "Saving..." : "Save Viewer"}
        </button>
        <button type="button" className="admin-projects__btn" onClick={() => onPreviewViewer(activeViewerTab)} disabled={saving || !workingPlan}>
          {saving ? "Saving..." : "Preview Viewer"}
        </button>
        {activeViewerTab === "client" ? (
          <button type="button" className="admin-projects__btn" onClick={() => onCopyPhotos("concept", "client")} disabled={!workingPlan}>
            Copy from Concept
          </button>
        ) : null}
        {activeViewerTab === "painter" ? (
          <button type="button" className="admin-projects__btn" onClick={() => onCopyPhotos("client", "painter")} disabled={!workingPlan}>
            Copy from Client
          </button>
        ) : null}
      </div>

      {!workingPlan ? (
        <div className="admin-projects__empty">Create or select a Color Plan before setting up area-specific viewers.</div>
      ) : (
        <ViewerWorkbench
          viewerKey={activeViewerTab}
          project={project}
          workingPlan={workingPlan}
          members={members}
          form={form}
          photos={photos}
          onFormChange={onViewerFormChange}
          onOpenPhotoPicker={onOpenPhotoPicker}
          onUpdatePhoto={onUpdatePhoto}
          onRemovePhoto={onRemovePhoto}
          onMovePhoto={onMovePhoto}
          projectPainterNote={projectPainterNote}
          onProjectPainterNoteChange={onProjectPainterNoteChange}
          onSaveViewer={onSaveViewer}
          onPreviewViewer={onPreviewViewer}
          saving={saving}
        />
      )}
    </div>
  );
}

function ViewerWorkbench({
  viewerKey,
  form,
  photos,
  onFormChange,
  onOpenPhotoPicker,
  onUpdatePhoto,
  onRemovePhoto,
  onMovePhoto,
  projectPainterNote,
  onProjectPainterNoteChange,
  onSaveViewer,
  onPreviewViewer,
  saving,
}) {
  return (
    <div className="admin-projects__viewer-workbench">
      <section className="admin-projects__viewer-section">
        <h3>{viewerKey === "painter" ? "NOTES" : "COPY"}</h3>
        <ViewerCopyFields
          viewerKey={viewerKey}
          form={form}
          projectPainterNote={projectPainterNote}
          onProjectPainterNoteChange={onProjectPainterNoteChange}
          onFormChange={onFormChange}
        />
      </section>

      <section className="admin-projects__viewer-section">
        <div className="admin-projects__viewer-section-head">
          <h3>PHOTOS</h3>
          <div className="admin-projects__actions">
            <button type="button" className="admin-projects__btn" onClick={() => onOpenPhotoPicker(viewerKey)}>
              Add from Photo Library
            </button>
          </div>
        </div>
        <ViewerPhotos
          viewerKey={viewerKey}
          photos={photos}
          onUpdatePhoto={onUpdatePhoto}
          onRemovePhoto={onRemovePhoto}
          onMovePhoto={onMovePhoto}
        />
      </section>

      <section className="admin-projects__viewer-section">
        <h3>PREVIEW / DELIVERY</h3>
        <div className="admin-projects__delivery-row">
          <button type="button" className="admin-projects__btn admin-projects__btn--primary" onClick={() => onSaveViewer(viewerKey)} disabled={saving}>
            {saving ? "Saving..." : "Save Viewer"}
          </button>
          <button type="button" className="admin-projects__btn" onClick={() => onPreviewViewer(viewerKey)} disabled={saving}>
            {saving ? "Saving..." : "Preview Viewer"}
          </button>
        </div>
      </section>
    </div>
  );
}

function viewerKeyLabel(key) {
  if (key === "client") return "Client";
  if (key === "painter") return "Painter";
  return "Concept";
}

function ViewerCopyFields({ viewerKey, form, projectPainterNote, onProjectPainterNoteChange, onFormChange }) {
  if (viewerKey === "client") {
    return (
      <div className="admin-projects__form-grid admin-projects__viewer-copy-grid">
        <label>
          <span>Scheme Title</span>
          <input value={form.scheme_title || ""} onChange={(event) => onFormChange(viewerKey, "scheme_title", event.target.value)} />
        </label>
        <label className="admin-projects__wide">
          <span>Final Design Description</span>
          <textarea rows={4} value={form.final_design_description || ""} onChange={(event) => onFormChange(viewerKey, "final_design_description", event.target.value)} />
        </label>
      </div>
    );
  }

  if (viewerKey === "painter") {
    return (
      <div className="admin-projects__form-grid admin-projects__viewer-copy-grid">
        <label className="admin-projects__wide">
          <span>Painter Project Note:</span>
          <textarea
            rows={3}
            value={projectPainterNote || ""}
            onChange={(event) => onProjectPainterNoteChange(event.target.value)}
          />
        </label>
        <label className="admin-projects__wide">
          <span>Painter Area Note:</span>
          <textarea rows={4} value={form.overall_painter_note || ""} onChange={(event) => onFormChange(viewerKey, "overall_painter_note", event.target.value)} />
        </label>
      </div>
    );
  }

  return (
      <div className="admin-projects__form-grid admin-projects__viewer-copy-grid">
        <label>
          <span>Concept Title</span>
          <input value={form.title || ""} onChange={(event) => onFormChange(viewerKey, "title", event.target.value)} />
        </label>
      <label className="admin-projects__wide">
        <span>Challenge</span>
        <textarea rows={3} value={form.challenge || ""} onChange={(event) => onFormChange(viewerKey, "challenge", event.target.value)} />
      </label>
      <label className="admin-projects__wide">
        <span>Design Direction</span>
        <textarea rows={4} value={form.design_direction || ""} onChange={(event) => onFormChange(viewerKey, "design_direction", event.target.value)} />
      </label>
    </div>
  );
}

function ViewerPhotos({ viewerKey, photos, onUpdatePhoto, onRemovePhoto, onMovePhoto }) {
  return (
    <div className="admin-projects__data-list admin-projects__viewer-photo-list">
      <div className="admin-projects__data-head admin-projects__viewer-photo-row">
        <span>Thumbnail</span>
        <span>Type</span>
        <span>Order</span>
      </div>
      {photos.length === 0 ? (
        <div className="admin-projects__empty">No viewer photos selected.</div>
      ) : photos.map((row, index) => (
        <div key={row.key || index} className="admin-projects__data-row admin-projects__viewer-photo-row">
          <div className="admin-projects__photo-cell">
            {row.rel_path ? <img src={buildImageUrl(row.rel_path)} alt="" /> : <div>No preview</div>}
            <span>
              <strong>{row.photo_title || `Photo #${row.photo_library_id || "new"}`}</strong>
              <small>{row.rel_path}</small>
            </span>
          </div>
          <select value={row.photo_type || "FULL"} onChange={(event) => onUpdatePhoto(viewerKey, index, "photo_type", event.target.value)}>
            {viewerPhotoTypeOptions.map((type) => (
              <option key={type} value={type}>{type}</option>
            ))}
          </select>
          <RowOrderActions
            index={index}
            total={photos.length}
            saving={false}
            onMove={(rowIndex, direction) => onMovePhoto(viewerKey, rowIndex, direction)}
            onRemove={(rowIndex) => onRemovePhoto(viewerKey, rowIndex)}
          />
        </div>
      ))}
    </div>
  );
}

function ColorPlanFields({ form, saving, onChange, onSave, onDelete }) {
  return (
    <div className="admin-projects__form-grid admin-projects__color-plan-form">
      <label>
        <span>Nickname</span>
        <input value={form.nickname || ""} onChange={(event) => onChange("nickname", event.target.value)} />
      </label>
      <label>
        <span>Area</span>
        <input value={form.area_name || ""} onChange={(event) => onChange("area_name", event.target.value)} />
      </label>
      <label>
        <span>Palette Type</span>
        <select value={form.palette_type || "exterior"} onChange={(event) => onChange("palette_type", event.target.value)}>
          <option value="exterior">Exterior</option>
          <option value="interior">Interior</option>
          <option value="hoa">HOA</option>
        </select>
      </label>
      <label>
        <span>Revision</span>
        <input type="number" min="1" value={form.revision_number || 1} onChange={(event) => onChange("revision_number", event.target.value)} />
      </label>
      <label className="admin-projects__wide">
        <span>Scheme Title</span>
        <input value={form.scheme_title || ""} onChange={(event) => onChange("scheme_title", event.target.value)} />
      </label>
      <label>
        <span>Issued At</span>
        <input type="datetime-local" value={form.issued_at || ""} onChange={(event) => onChange("issued_at", event.target.value)} />
      </label>
      <div className="admin-projects__actions admin-projects__wide">
        <button type="button" className="admin-projects__btn admin-projects__btn--primary" onClick={onSave} disabled={saving}>
          {saving ? "Saving..." : "Save Plan"}
        </button>
        <button
          type="button"
          className="admin-projects__btn admin-projects__btn--danger admin-projects__btn--small"
          onClick={onDelete}
          disabled={saving || !form?.id}
        >
          Delete Plan
        </button>
      </div>
    </div>
  );
}

function ColorPlanMembers({ members, saving, onAddMember, onUpdateMember, onRemoveMember, onMoveMember, onSave }) {
  return (
    <div className="admin-projects__section-stack">
      <div className="admin-projects__actions">
        <FuzzySearchColorSelect
          className="admin-projects__color-search"
          onSelect={(color) => color && onAddMember(color)}
          showLabel={false}
          autoFocus={false}
          preventAutoFocus
          compact
        />
        <button type="button" className="admin-projects__btn admin-projects__btn--primary" onClick={onSave} disabled={saving}>
          {saving ? "Saving..." : "Save Colors"}
        </button>
      </div>
      <div className="admin-projects__data-list admin-projects__member-list">
        <div className="admin-projects__data-head admin-projects__member-row">
          <span>Color</span>
          <span>Role</span>
          <span>Sheen</span>
          <span>Painter's Note</span>
          <span>Order</span>
        </div>
        {members.length === 0 ? (
          <div className="admin-projects__empty">No colors yet.</div>
        ) : members.map((row, index) => (
          <div key={row.key || row.id || index} className="admin-projects__data-row admin-projects__member-row">
            <div className="admin-projects__color-cell">
              <div className="admin-projects__swatch" style={{ backgroundColor: colorHex(row.color) }} />
              <div className="admin-projects__color-pick">
                <strong>{row.color?.name || "No color selected"}</strong>
                <small>{[row.color?.brand_name || row.color?.brand, row.color?.code].filter(Boolean).join(" ") || "Choose color below"}</small>
                <FuzzySearchColorSelect
                  value={row.color || null}
                  onSelect={(color) => onUpdateMember(index, "color", color)}
                  showLabel={false}
                  autoFocus={false}
                  preventAutoFocus
                  compact
                />
              </div>
            </div>
            <input value={row.role_name || ""} onChange={(event) => onUpdateMember(index, "role_name", event.target.value)} />
            <select value={row.sheen || ""} onChange={(event) => onUpdateMember(index, "sheen", event.target.value)}>
              {sheenOptions.map((sheen) => (
                <option key={sheen || "blank"} value={sheen}>{sheen || "Optional"}</option>
              ))}
            </select>
            <textarea rows={2} value={row.note || ""} onChange={(event) => onUpdateMember(index, "note", event.target.value)} />
            <RowOrderActions
              index={index}
              total={members.length}
              saving={saving}
              onMove={onMoveMember}
              onRemove={onRemoveMember}
            />
          </div>
        ))}
      </div>
    </div>
  );
}

function RowOrderActions({ index, total, saving, onMove, onRemove }) {
  return (
    <div className="admin-projects__row-actions">
      <button type="button" className="admin-projects__btn" onClick={() => onMove(index, -1)} disabled={saving || index === 0}>Up</button>
      <button type="button" className="admin-projects__btn" onClick={() => onMove(index, 1)} disabled={saving || index >= total - 1}>Down</button>
      <button type="button" className="admin-projects__btn admin-projects__btn--danger" onClick={() => onRemove(index)} disabled={saving}>Remove</button>
    </div>
  );
}

function colorHex(color) {
  if (!color) return "#eef3fa";
  if (color.hex6) return `#${String(color.hex6).replace(/^#/, "")}`;
  if (typeof color.r === "number" && typeof color.g === "number" && typeof color.b === "number") {
    return `rgb(${color.r}, ${color.g}, ${color.b})`;
  }
  return "#eef3fa";
}

function Placeholder({ text }) {
  return <div className="admin-projects__placeholder">{text}</div>;
}
