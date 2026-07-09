import { useEffect, useRef, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { Clipboard } from "lucide-react";
import { API_FOLDER } from "@helpers/config";
import PermissionStatus from "@components/PermissionStatus";
import "./admin-asset-creators.css";

const LIST_URL = `${API_FOLDER}/v2/admin/asset-creators/list.php`;
const DETAIL_URL = `${API_FOLDER}/v2/admin/asset-creators/detail.php`;
const PLAYLISTS_URL = `${API_FOLDER}/v2/admin/playlists/list.php`;
const ASSET_LIBRARY_URL = `${API_FOLDER}/v2/admin/asset-library/list.php`;
const PROPOSE_URL = `${API_FOLDER}/v2/admin/asset-creators/propose.php`;
const SAVE_URL = `${API_FOLDER}/v2/admin/asset-creators/save.php`;
const RUN_URL = `${API_FOLDER}/v2/admin/asset-creators/run.php`;
const DELETE_URL = `${API_FOLDER}/v2/admin/asset-creators/delete.php`;
const PREVIEW_URL = `${API_FOLDER}/v2/admin/asset-creators/preview.php`;
const DEFAULTS_URL = `${API_FOLDER}/v2/admin/asset-creators/defaults.php`;

const CREATOR_TYPES = [
  {
    value: "pinterest.before_after_composite",
    label: "Pinterest",
    channel: "pinterest",
  },
  {
    value: "youtube.playlist_video",
    label: "YouTube",
    channel: "youtube",
  },
];

const PINTEREST_BOARD = {
  board_name: "ColorFix Makeovers",
  board_url: "https://www.pinterest.com/terrymarr/colorfix-makeovers/",
  board_slug: "terrymarr/colorfix-makeovers",
  board_id: null,
};

const DEFAULT_ANALYZER_DESCRIPTION = "This [house style / room type] was struggling with [problem]. By [what you changed], the eye is now drawn toward [focal point or benefit]. See the complete before-and-after makeover, color palette, and design reasoning.";

const EMPTY_SIDE = {
  photo_library_id: "",
  asset_library_id: "",
  title: "",
  public_url: "",
};

export default function AdminAssetCreatorsPage() {
  const [searchParams] = useSearchParams();
  const autoAnalyzeKeyRef = useRef("");
  const loadedDescriptionDefaultRef = useRef(DEFAULT_ANALYZER_DESCRIPTION);
  const [jobs, setJobs] = useState([]);
  const [jobOutputs, setJobOutputs] = useState({});
  const [jobPermissions, setJobPermissions] = useState({});
  const [playlists, setPlaylists] = useState([]);
  const [musicAssets, setMusicAssets] = useState([]);
  const [q, setQ] = useState("");
  const [loading, setLoading] = useState(false);
  const [loadingPlaylists, setLoadingPlaylists] = useState(false);
  const [loadingMusic, setLoadingMusic] = useState(false);
  const [loadingDefaults, setLoadingDefaults] = useState(false);
  const [loadingJob, setLoadingJob] = useState(false);
  const [analyzing, setAnalyzing] = useState(false);
  const [savingRecipe, setSavingRecipe] = useState(false);
  const [previewingRecipe, setPreviewingRecipe] = useState(false);
  const [runningJobId, setRunningJobId] = useState(null);
  const [deletingJobId, setDeletingJobId] = useState(null);
  const [deletingOutputsJobId, setDeletingOutputsJobId] = useState(null);
  const [error, setError] = useState("");
  const [status, setStatus] = useState("");
  const [creatorModalOpen, setCreatorModalOpen] = useState(false);
  const [imagePreview, setImagePreview] = useState(null);
  const [editingJobId, setEditingJobId] = useState(null);
  const [form, setForm] = useState({
    creator_key: CREATOR_TYPES[0].value,
    playlist_id: "",
    default_title: "",
    default_description: DEFAULT_ANALYZER_DESCRIPTION,
    music_asset_library_id: "",
    music_volume: "0.18",
  });
  const [proposal, setProposal] = useState(null);
  const [recipePreview, setRecipePreview] = useState(null);
  const [pairs, setPairs] = useState([]);
  const [pinTypeFilter, setPinTypeFilter] = useState("all");

  useEffect(() => {
    fetchJobs("");
    fetchPlaylists();
    fetchMusicAssets();
  }, []);

  useEffect(() => {
    const playlistId = Number(searchParams.get("playlist_id") || 0);
    const shouldOpenModal = searchParams.get("modal") === "1" || searchParams.get("analyze") === "1";
    if (!playlistId || !shouldOpenModal) return;

    const requestKey = `${playlistId}:pinterest`;
    if (autoAnalyzeKeyRef.current === requestKey) return;
    autoAnalyzeKeyRef.current = requestKey;

    setForm({
      creator_key: CREATOR_TYPES[0].value,
      playlist_id: String(playlistId),
      default_title: "",
      default_description: DEFAULT_ANALYZER_DESCRIPTION,
      music_asset_library_id: "",
      music_volume: "0.18",
    });
    setEditingJobId(null);
    setProposal(null);
    setPairs([]);
    setPinTypeFilter("all");
    setCreatorModalOpen(true);
    if (searchParams.get("analyze") === "1") {
      analyzePlaylistById(playlistId, CREATOR_TYPES[0].value, {
        default_title: "",
        default_description: DEFAULT_ANALYZER_DESCRIPTION,
        music_asset_library_id: "",
        music_volume: "0.18",
      });
    }
  }, [searchParams]);

  useEffect(() => {
    if (!creatorModalOpen || editingJobId) return;
    loadAnalyzerDefaults({ overwrite: false });
  }, [creatorModalOpen, editingJobId, form.creator_key, form.playlist_id, playlists]);

  async function fetchJobs(nextQ = q) {
    setLoading(true);
    setError("");
    try {
      const params = new URLSearchParams();
      if (nextQ.trim()) params.set("q", nextQ.trim());
      params.set("_", String(Date.now()));
      const res = await fetch(`${LIST_URL}?${params.toString()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load creator jobs");
      const items = data.items || [];
      setJobs(items);
      loadJobOutputs(items);
    } catch (err) {
      setError(err?.message || "Failed to load creator jobs");
    } finally {
      setLoading(false);
    }
  }

  async function loadJobOutputs(items) {
    const jobsToLoad = items || [];
    if (!jobsToLoad.length) {
      setJobOutputs({});
      setJobPermissions({});
      return;
    }
    const details = await Promise.all(
      jobsToLoad.map(async (job) => {
        try {
          const params = new URLSearchParams({
            id: String(job.asset_creator_job_id),
            _: String(Date.now()),
          });
          const res = await fetch(`${DETAIL_URL}?${params.toString()}`, { credentials: "include" });
          const data = await res.json();
          if (!res.ok || !data?.ok) return [job.asset_creator_job_id, [], null];
          const item = data.item || {};
          return [
            job.asset_creator_job_id,
            item.outputs || [],
            firstPermissionItem([...(item.outputs || []), ...(item.inputs || [])]),
          ];
        } catch {
          return [job.asset_creator_job_id, [], null];
        }
      })
    );
    setJobOutputs(Object.fromEntries(details.map(([jobId, outputs]) => [jobId, outputs])));
    setJobPermissions(Object.fromEntries(details.map(([jobId, , permission]) => [jobId, permission])));
  }

  async function fetchPlaylists() {
    setLoadingPlaylists(true);
    try {
      const res = await fetch(`${PLAYLISTS_URL}?_=${Date.now()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load playlists");
      setPlaylists(data.items || []);
    } catch (err) {
      setError(err?.message || "Failed to load playlists");
    } finally {
      setLoadingPlaylists(false);
    }
  }

  async function fetchMusicAssets() {
    setLoadingMusic(true);
    try {
      const params = new URLSearchParams({
        asset_kind: "audio",
        sort: "newest",
        limit: "200",
        _: String(Date.now()),
      });
      const res = await fetch(`${ASSET_LIBRARY_URL}?${params.toString()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load music assets");
      setMusicAssets(data.items || []);
    } catch (err) {
      setError(err?.message || "Failed to load music assets");
    } finally {
      setLoadingMusic(false);
    }
  }

  async function loadAnalyzerDefaults({ overwrite = false } = {}) {
    const playlist = getSelectedPlaylist(playlists, form.playlist_id);
    const playlistType = playlist?.type || "any";
    setLoadingDefaults(true);
    try {
      const params = new URLSearchParams({
        creator_key: form.creator_key,
        platform: platformForCreator(form.creator_key),
        asset_type: defaultAssetTypeForCreator(form.creator_key),
        playlist_type: playlistType || "any",
        field_key: "description",
        _: String(Date.now()),
      });
      const res = await fetch(`${DEFAULTS_URL}?${params.toString()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load analyzer defaults");
      const template = String(data.item?.template_text || "").trim();
      if (!template) return;
      setForm((current) => {
        const currentDescription = String(current.default_description || "").trim();
        const previousLoaded = String(loadedDescriptionDefaultRef.current || "").trim();
        const canReplace = overwrite
          || currentDescription === ""
          || currentDescription === previousLoaded
          || currentDescription === DEFAULT_ANALYZER_DESCRIPTION;
        loadedDescriptionDefaultRef.current = template;
        return canReplace ? { ...current, default_description: template } : current;
      });
    } catch (err) {
      setError(err?.message || "Failed to load analyzer defaults");
    } finally {
      setLoadingDefaults(false);
    }
  }

  async function analyzePlaylist(event) {
    event.preventDefault();
    if (!form.playlist_id) {
      setError("Pick a playlist first.");
      return;
    }
    await analyzePlaylistById(Number(form.playlist_id), form.creator_key, {
      default_title: form.default_title,
      default_description: form.default_description,
      music_asset_library_id: form.music_asset_library_id,
      music_volume: form.music_volume,
    });
  }

  async function analyzePlaylistById(playlistId, creatorKey = CREATOR_TYPES[0].value, defaults = {}) {
    if (!playlistId) {
      setError("Pick a playlist first.");
      return;
    }
    setAnalyzing(true);
    setError("");
    setStatus("");
    setProposal(null);
    setPairs([]);
    setPinTypeFilter("all");
    try {
      const res = await fetch(PROPOSE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          creator_key: creatorKey,
          source_type: "playlist",
          playlist_id: Number(playlistId),
          default_title: defaults.default_title || "",
          default_description: defaults.default_description || "",
          music_asset_library_id: defaults.music_asset_library_id || "",
          music_volume: defaults.music_volume || "0.18",
        }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to analyze playlist");
      setProposal(data.item);
      setRecipePreview(null);
      setPairs(proposalRows(data.item).map(normalizePair));
    } catch (err) {
      setError(err?.message || "Failed to analyze playlist");
    } finally {
      setAnalyzing(false);
    }
  }

  async function saveRecipe(options = {}) {
    const { closeModal = true, showStatus = true } = options;
    if (!proposal || !form.playlist_id) {
      setError("Analyze a playlist before saving the recipe.");
      return null;
    }
    const isYoutube = isYoutubeCreator(form.creator_key);
    const includedPairs = pairs.filter((pair) => pair.include);
    if (!includedPairs.length) {
      setError("Select at least one pair to save.");
      return null;
    }

    setSavingRecipe(true);
    setError("");
    setStatus("");
    try {
      const payload = buildCurrentRecipePayload();
      if (!payload) return null;
      const savePayload = {
        ...payload,
        asset_creator_job_id: editingJobId || undefined,
        status: "draft",
      };

      const res = await fetch(SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(savePayload),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to save recipe");
      const savedJobId = data.item?.asset_creator_job_id || editingJobId || null;
      setEditingJobId(savedJobId);
      if (showStatus) {
        setStatus(`Saved creator job #${savedJobId || ""}.`);
      }
      if (closeModal) {
        setCreatorModalOpen(false);
        setEditingJobId(null);
        setProposal(null);
        setPairs([]);
      }
      await fetchJobs(q);
      return data.item || null;
    } catch (err) {
      setError(err?.message || "Failed to save recipe");
      return null;
    } finally {
      setSavingRecipe(false);
    }
  }

  function buildCurrentRecipePayload() {
    if (!proposal || !form.playlist_id) {
      setError("Analyze a playlist first.");
      return null;
    }
    const isYoutube = isYoutubeCreator(form.creator_key);
    const playlist = getSelectedPlaylist(playlists, form.playlist_id) || proposal.playlist || {};
    const urlReservation = proposal.url_reservation || proposal.instructions?.playlist_instance_url_reservation || null;
    const finalPlaylistUrl = String(urlReservation?.public_url || "").trim();
    const music = selectedMusicForRecipe(form, musicAssets, proposal);
    const reviewedPairs = pairs.map((pair, index) => {
      const pinType = isYoutube ? "youtube_video" : (pair.pin_type || "composite");
      const title = titleForPinType(pair.search_title || pair.title || "", pinType);
      return {
        ...pair,
        include: !!pair.include,
        sort_order: index + 1,
        search_title: title,
        pin_title: title,
        description: pair.description || pair.caption || "",
        pin_description: pair.description || pair.caption || "",
        title,
        caption: pair.description || pair.caption || "",
        pin_type: pinType,
        asset_type: assetTypeForPinType(pinType, form.creator_key),
        before: normalizeRecipeSide(pair.before),
        after: normalizeRecipeSide(pair.after),
        asset: normalizeRecipeSide(pinType === "composite" ? (pair.asset || pair.after) : (pair.after || pair.asset)),
        ...(isYoutube ? { music } : {}),
      };
    });

    return {
      creator_key: form.creator_key,
      source_type: "playlist",
      source_id: Number(form.playlist_id),
      title: `${creatorLabel(form.creator_key)}: ${playlist.title || proposal.playlist?.title || `Playlist ${form.playlist_id}`}`,
      instructions: {
        version: 1,
        creator_key: form.creator_key,
        source: {
          type: "playlist",
          playlist_id: Number(form.playlist_id),
          title: playlist.title || proposal.playlist?.title || "",
          final_playlist_url: finalPlaylistUrl,
          playlist_instance_url_reservation: urlReservation,
          ...(isYoutube ? { music } : {}),
        },
        recipe_type: isYoutube ? "youtube_playlist_video" : "pinterest_pin_rows",
        ...(isYoutube ? {} : { pinterest_board: PINTEREST_BOARD }),
        analyzer_defaults: {
          title: form.default_title || "",
          description: form.default_description || "",
          final_playlist_url: finalPlaylistUrl,
          ...(isYoutube ? { music_asset_library_id: form.music_asset_library_id || "", music_volume: form.music_volume || "0.18" } : {}),
        },
        ...(isYoutube ? { music } : {}),
        ...(isYoutube ? { video_rows: reviewedPairs } : { pin_rows: reviewedPairs }),
        playlist_instance_url_reservation: urlReservation,
        pairs: reviewedPairs,
        warnings: proposal.warnings || [],
      },
      inputs: buildRecipeInputs(reviewedPairs, music),
    };
  }

  async function previewRecipe() {
    if (!isYoutubeCreator(form.creator_key)) {
      setError("Recipe preview is wired for YouTube video first.");
      return;
    }
    const payload = buildCurrentRecipePayload();
    if (!payload) return;

    setPreviewingRecipe(true);
    setRecipePreview(null);
    setError("");
    setStatus("");
    try {
      const res = await fetch(PREVIEW_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          creator_key: form.creator_key,
          preview_mode: "local_video",
          persist: false,
          recipe: payload.instructions,
        }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to preview recipe");
      setRecipePreview(data.preview || null);
      setStatus("Preview video created.");
    } catch (err) {
      setError(err?.message || "Failed to preview recipe");
    } finally {
      setPreviewingRecipe(false);
    }
  }

  async function runCreatorJob(jobId) {
    if (!jobId) {
      setError("Save the recipe before running the creator.");
      return null;
    }
    setRunningJobId(jobId);
    setError("");
    setStatus("");
    try {
      const res = await fetch(RUN_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ asset_creator_job_id: jobId }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to run creator");
      const outputs = data.item?.outputs || [];
      if (data.item?.job?.asset_creator_job_id) {
        setJobOutputs((current) => ({
          ...current,
          [data.item.job.asset_creator_job_id]: data.item.job.outputs || outputs,
        }));
      }
      setStatus(`Created ${outputs.length} asset${outputs.length === 1 ? "" : "s"} from job #${jobId}.`);
      await fetchJobs(q);
      return data.item || null;
    } catch (err) {
      setError(err?.message || "Failed to run creator");
      return null;
    } finally {
      setRunningJobId(null);
    }
  }

  async function deleteCreatorJob(jobId) {
    if (!jobId) return;
    const ok = window.confirm(`Delete creator job #${jobId} and all deletable generated outputs? Source playlist photos will not be deleted.`);
    if (!ok) return;

    setDeletingJobId(jobId);
    setError("");
    setStatus("");
    try {
      const res = await fetch(DELETE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ asset_creator_job_id: jobId }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to delete creator job");
      setJobs((current) => current.filter((job) => Number(job.asset_creator_job_id) !== Number(jobId)));
      setJobOutputs((current) => {
        const next = { ...current };
        delete next[jobId];
        return next;
      });
      setJobPermissions((current) => {
        const next = { ...current };
        delete next[jobId];
        return next;
      });
      setStatus(`Deleted creator job #${jobId}.`);
      await fetchJobs(q);
    } catch (err) {
      setError(err?.message || "Failed to delete creator job");
    } finally {
      setDeletingJobId(null);
    }
  }

  async function deleteCreatorOutputs(jobId) {
    if (!jobId) return;
    const ok = window.confirm(`Delete generated outputs for creator job #${jobId}? The recipe and source playlist photos will stay.`);
    if (!ok) return;

    setDeletingOutputsJobId(jobId);
    setError("");
    setStatus("");
    try {
      const res = await fetch(DELETE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ asset_creator_job_id: jobId, action: "outputs" }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to delete creator outputs");
      setJobOutputs((current) => ({
        ...current,
        [jobId]: [],
      }));
      setStatus(`Deleted ${data.item?.deleted_count || 0} generated output${Number(data.item?.deleted_count || 0) === 1 ? "" : "s"} from creator job #${jobId}.`);
      await fetchJobs(q);
    } catch (err) {
      setError(err?.message || "Failed to delete creator outputs");
    } finally {
      setDeletingOutputsJobId(null);
    }
  }

  async function saveAndRunRecipe() {
    const saved = await saveRecipe({ closeModal: false, showStatus: false });
    const jobId = saved?.asset_creator_job_id || editingJobId;
    if (!jobId) return;
    await runCreatorJob(jobId);
  }

  function updatePair(index, key, value) {
    setPairs((current) =>
      current.map((pair, pairIndex) => (pairIndex === index ? { ...pair, [key]: value } : pair))
    );
  }

  function updatePairSide(index, side, key, value) {
    setPairs((current) =>
      current.map((pair, pairIndex) =>
        pairIndex === index
          ? { ...pair, [side]: { ...pair[side], [key]: value } }
          : pair
      )
    );
  }

  function addPair() {
    setPairs((current) => [
      ...current,
      normalizePair({
        pair_key: `manual-${Date.now()}`,
        include: true,
        sort_order: current.length + 1,
        source: "manual",
        confidence: 1,
        search_title: "",
        description: "",
        pin_type: "composite",
        asset_type: "pin_composite",
        before: EMPTY_SIDE,
        after: EMPTY_SIDE,
      }),
    ]);
  }

  function removePair(index) {
    setPairs((current) => current.filter((_, pairIndex) => pairIndex !== index));
  }

  async function openExistingJob(jobId) {
    if (!jobId) return;
    setLoadingJob(true);
    setError("");
    setStatus("");
    setImagePreview(null);
    try {
      const params = new URLSearchParams({
        id: String(jobId),
        _: String(Date.now()),
      });
      const res = await fetch(`${DETAIL_URL}?${params.toString()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load creator job");

      const job = data.item || {};
      const instructions = parseInstructions(job.instructions_json);
      const source = instructions.source || {};
      const playlistId = Number(job.source_id || source.playlist_id || 0);
      const playlist = getSelectedPlaylist(playlists, playlistId) || {
        playlist_id: playlistId,
        title: source.title || job.title || "",
      };
      const nextPairs = withLiveInputPermissions(
        instructionRows(instructions),
        job.inputs || []
      );
      const outputs = job.outputs || [];
      const analyzerDefaults = analyzerDefaultsFromInstructions(instructions, nextPairs);
      const music = musicFromInstructions(instructions);

      setEditingJobId(job.asset_creator_job_id || jobId);
      setJobOutputs((current) => ({
        ...current,
        [job.asset_creator_job_id || jobId]: outputs,
      }));
      setForm({
        creator_key: job.creator_key || instructions.creator_key || CREATOR_TYPES[0].value,
        playlist_id: playlistId ? String(playlistId) : "",
        default_title: analyzerDefaults.title,
        default_description: analyzerDefaults.description,
        music_asset_library_id: music?.asset_library_id ? String(music.asset_library_id) : "",
        music_volume: String(music?.volume ?? analyzerDefaults.music_volume ?? "0.18"),
      });
      setProposal({
        creator_key: job.creator_key || instructions.creator_key || CREATOR_TYPES[0].value,
        playlist,
        warnings: instructions.warnings || [],
        instructions,
      });
      setPairs(nextPairs.map(normalizePair));
      setCreatorModalOpen(true);
      if (!playlists.length) {
        fetchPlaylists();
      }
    } catch (err) {
      setError(err?.message || "Failed to load creator job");
    } finally {
      setLoadingJob(false);
    }
  }

  function movePair(index, direction) {
    setPairs((current) => {
      const nextIndex = index + direction;
      if (nextIndex < 0 || nextIndex >= current.length) return current;
      const next = [...current];
      const [moved] = next.splice(index, 1);
      next.splice(nextIndex, 0, moved);
      return next.map((pair, pairIndex) => ({ ...pair, sort_order: pairIndex + 1 }));
    });
  }

  function openNewCreatorModal() {
    setError("");
    setStatus("");
    setEditingJobId(null);
    setForm({
      creator_key: CREATOR_TYPES[0].value,
      playlist_id: "",
      default_title: "",
      default_description: DEFAULT_ANALYZER_DESCRIPTION,
      music_asset_library_id: "",
      music_volume: "0.18",
    });
    setProposal(null);
    setPairs([]);
    setPinTypeFilter("all");
    setCreatorModalOpen(true);
    if (!playlists.length) {
      fetchPlaylists();
    }
    if (!musicAssets.length) {
      fetchMusicAssets();
    }
  }

  function closeNewCreatorModal() {
    setCreatorModalOpen(false);
    setImagePreview(null);
    setEditingJobId(null);
  }

  function toggleImagePreview(nextPreview) {
    const nextUrl = String(nextPreview?.public_url || "").trim();
    if (!nextUrl) return;
    setImagePreview((current) => {
      const currentUrl = String(current?.public_url || "").trim();
      return currentUrl === nextUrl ? null : nextPreview;
    });
  }

  function moveImagePreview(direction) {
    setImagePreview((current) => {
      const items = Array.isArray(current?.items) ? current.items : [];
      if (!items.length) return current;
      const currentIndex = Number(current?.index || 0);
      const nextIndex = (currentIndex + direction + items.length) % items.length;
      return outputPreviewFromItems(items, nextIndex);
    });
  }

  const visiblePairs = pairs
    .map((pair, index) => ({ pair, index }))
    .filter(({ pair }) => pinTypeFilter === "all" || (pair.pin_type || "composite") === pinTypeFilter);
  const finalPlaylistUrl = String(
    proposal?.url_reservation?.public_url
      || proposal?.instructions?.playlist_instance_url_reservation?.public_url
      || ""
  ).trim();

  return (
    <div className="admin-asset-creators">
      <header className="assetcreator-header">
        <div>
          <h1>Asset Creator</h1>
          <p>Generated publishing assets from playlist recipes.</p>
        </div>
        <div className="assetcreator-header-actions">
          <Link
            className="assetcreator-command assetcreator-command-link"
            to="/admin/publishing-defaults"
          >
            Defaults
          </Link>
          <button type="button" className="assetcreator-command assetcreator-command--primary" onClick={openNewCreatorModal}>
            Analyzer
          </button>
          <Link className="assetcreator-command assetcreator-command--primary assetcreator-command-link" to="/admin/packager">
            Packager
          </Link>
        </div>
      </header>

      <div className="assetcreator-toolbar">
        <label>
          Search
          <input value={q} onChange={(event) => setQ(event.target.value)} />
        </label>
        <button type="button" className="assetcreator-command" onClick={() => fetchJobs(q)} disabled={loading}>
          Find
        </button>
        <button type="button" className="assetcreator-command" onClick={() => fetchJobs("")} disabled={loading}>
          Refresh
        </button>
      </div>

      {error && !creatorModalOpen ? <div className="assetcreator-status assetcreator-status--error">{error}</div> : null}
      {status && !creatorModalOpen ? <div className="assetcreator-status assetcreator-status--success">{status}</div> : null}

      <section className="assetcreator-grid-wrap">
        <table className="assetcreator-grid">
          <thead>
            <tr>
              <th>Job ID</th>
              <th>Actions</th>
              <th>Channel</th>
              <th>Status</th>
              <th>Title</th>
              <th>Source</th>
              <th>Inputs</th>
              <th>Outputs</th>
              <th>Preview</th>
              <th>Last Run</th>
              <th>Updated</th>
            </tr>
          </thead>
          <tbody>
            {jobs.map((job) => (
              <tr key={job.asset_creator_job_id}>
                <td>
                  <span className="assetcreator-job-id">
                    <PermissionStatus {...permissionProps(jobPermissions[job.asset_creator_job_id])} />
                    <span>{job.asset_creator_job_id}</span>
                  </span>
                </td>
                <td className="assetcreator-job-actions">
                  <button
                    type="button"
                    className="assetcreator-mini assetcreator-mini--strong"
                    onClick={() => openExistingJob(job.asset_creator_job_id)}
                    disabled={loadingJob || runningJobId === job.asset_creator_job_id}
                  >
                    Open
                  </button>
                  <button
                    type="button"
                    className="assetcreator-mini"
                    onClick={() => runCreatorJob(job.asset_creator_job_id)}
                    disabled={!!runningJobId || loadingJob || deletingJobId === job.asset_creator_job_id || deletingOutputsJobId === job.asset_creator_job_id}
                  >
                    {runningJobId === job.asset_creator_job_id ? "Running..." : "Run"}
                  </button>
                  <Link
                    className={job.output_count > 0 ? "assetcreator-mini assetcreator-mini-link" : "assetcreator-mini assetcreator-mini-link assetcreator-mini-link--disabled"}
                    to={`/admin/packager?creator_job_id=${encodeURIComponent(job.asset_creator_job_id)}`}
                    aria-disabled={job.output_count > 0 ? undefined : "true"}
                    onClick={(event) => {
                      if (!(job.output_count > 0)) event.preventDefault();
                    }}
                  >
                    Packager
                  </Link>
                  <button
                    type="button"
                    className="assetcreator-mini assetcreator-mini--danger"
                    onClick={() => deleteCreatorJob(job.asset_creator_job_id)}
                    disabled={!!runningJobId || loadingJob || deletingJobId === job.asset_creator_job_id || deletingOutputsJobId === job.asset_creator_job_id}
                  >
                    {deletingJobId === job.asset_creator_job_id ? "Deleting..." : "Delete"}
                  </button>
                </td>
                <td>{creatorLabel(job.creator_key)}</td>
                <td>{job.status}</td>
                <td>{job.title || "-"}</td>
                <td>{sourceLabel(job)}</td>
                <td>{job.input_count || 0}</td>
                <td>{job.output_count || 0}</td>
                <td>{renderOutputPreview(jobOutputs[job.asset_creator_job_id], toggleImagePreview)}</td>
                <td className="assetcreator-date-cell">{formatShortDateTime(job.last_run_at)}</td>
                <td className="assetcreator-date-cell">{formatShortDateTime(job.updated_at)}</td>
              </tr>
            ))}
            {!loading && jobs.length === 0 ? (
              <tr>
                <td colSpan={11} className="assetcreator-empty">
                  No creator jobs yet.
                </td>
              </tr>
            ) : null}
          </tbody>
        </table>
      </section>

      {creatorModalOpen ? (
        <div className="assetcreator-modal-backdrop">
          <div className="assetcreator-modal" role="dialog" aria-modal="true" aria-label="Analyzer">
            <header className="assetcreator-modal-head">
              <div>
                <h2>{editingJobId ? `Analyzer Job #${editingJobId}` : "Analyzer"}</h2>
                <p>
                  {editingJobId
                    ? "Adjust the saved recipe, then save it back to this job."
                    : "What is possible from this playlist?"}
                </p>
              </div>
              <button type="button" className="assetcreator-command" onClick={closeNewCreatorModal}>
                Close
              </button>
            </header>

            <section className="assetcreator-launch assetcreator-launch--modal">
              <form className="assetcreator-launch-form" onSubmit={analyzePlaylist}>
                <label>
                  Channel
                  <select
                    value={form.creator_key}
                    onChange={(event) => setForm((current) => ({ ...current, creator_key: event.target.value }))}
                  >
                    {CREATOR_TYPES.map((type) => (
                      <option key={type.value} value={type.value}>
                        {type.label}
                      </option>
                    ))}
                  </select>
                </label>
                <label className="assetcreator-playlist-field">
                  Playlist
                  <select
                    value={form.playlist_id}
                    onChange={(event) => setForm((current) => ({ ...current, playlist_id: event.target.value }))}
                    disabled={loadingPlaylists}
                  >
                    <option value="">Pick playlist</option>
                    {playlists.map((playlist) => (
                      <option key={playlist.playlist_id} value={playlist.playlist_id}>
                        #{playlist.playlist_id} {playlist.title}
                      </option>
                    ))}
                  </select>
                </label>
                <label className="assetcreator-default-title-field">
                  Default Title
                  <input
                    value={form.default_title}
                    placeholder="Leave blank for analyzer titles"
                    onChange={(event) => setForm((current) => ({ ...current, default_title: event.target.value }))}
                  />
                </label>
                <label className="assetcreator-default-description-field">
                  <span className="assetcreator-label-row">
                    <span>Default Description</span>
                    <span className="assetcreator-label-actions">
                      <button
                        type="button"
                        className="assetcreator-inline-command"
                        onClick={() => loadAnalyzerDefaults({ overwrite: true })}
                        disabled={loadingDefaults}
                      >
                        {loadingDefaults ? "Loading..." : "Load Default"}
                      </button>
                      <Link
                        className="assetcreator-inline-command assetcreator-inline-command-link"
                        to={defaultsLinkFor(form.creator_key, getSelectedPlaylist(playlists, form.playlist_id))}
                      >
                        Defaults
                      </Link>
                    </span>
                  </span>
                  <textarea
                    value={form.default_description}
                    rows={3}
                    onChange={(event) => setForm((current) => ({ ...current, default_description: event.target.value }))}
                  />
                </label>
                {isYoutubeCreator(form.creator_key) ? (
                  <>
                    <label className="assetcreator-music-field">
                      Music File
                      <select
                        value={form.music_asset_library_id}
                        onChange={(event) => setForm((current) => ({ ...current, music_asset_library_id: event.target.value }))}
                        disabled={loadingMusic}
                      >
                        <option value="">No music</option>
                        {musicAssets.map((asset) => (
                          <option key={asset.asset_library_id} value={asset.asset_library_id}>
                            #{asset.asset_library_id} {asset.title || asset.rel_path || "Audio"}
                          </option>
                        ))}
                      </select>
                    </label>
                    <label className="assetcreator-volume-field">
                      Music Volume
                      <input
                        type="number"
                        min="0"
                        max="1"
                        step="0.01"
                        value={form.music_volume}
                        onChange={(event) => setForm((current) => ({ ...current, music_volume: event.target.value }))}
                      />
                    </label>
                  </>
                ) : null}
                <CopyTextField
                  label="Final Playlist URL"
                  value={finalPlaylistUrl}
                  placeholder="Click Analyze to reserve the future instance URL"
                  onCopy={(value) => copyText(value, "Final Playlist URL", setStatus)}
                />
                <button type="submit" className="assetcreator-command assetcreator-command--primary" disabled={analyzing}>
                  {analyzing ? "Analyzing..." : "Analyze"}
                </button>
                <button type="button" className="assetcreator-command" onClick={fetchPlaylists} disabled={loadingPlaylists}>
                  Refresh Playlists
                </button>
                {isYoutubeCreator(form.creator_key) ? (
                  <button type="button" className="assetcreator-command" onClick={fetchMusicAssets} disabled={loadingMusic}>
                    Refresh Music
                  </button>
                ) : null}
              </form>
            </section>

            {error ? <div className="assetcreator-status assetcreator-status--error">{error}</div> : null}
            {status ? <div className="assetcreator-status assetcreator-status--success">{status}</div> : null}
            {editingJobId && jobOutputs[editingJobId]?.length ? (
              <section className="assetcreator-outputs">
                <h2>Created Assets</h2>
                <span>
                  {jobOutputs[editingJobId].length} asset{jobOutputs[editingJobId].length === 1 ? "" : "s"} created
                </span>
                {isVideoAsset(jobOutputs[editingJobId][0]) ? (
                  <a
                    className="assetcreator-mini assetcreator-mini-link"
                    href={versionedAssetUrl(jobOutputs[editingJobId][0].public_url || jobOutputs[editingJobId][0].rel_path || "", jobOutputs[editingJobId][0])}
                    target="_blank"
                    rel="noreferrer"
                  >
                    Open video
                  </a>
                ) : (
                  <button
                    type="button"
                    className="assetcreator-mini assetcreator-mini--strong"
                    onClick={() => toggleImagePreview(outputPreviewFromItems(jobOutputs[editingJobId], 0))}
                  >
                    Preview assets
                  </button>
                )}
                <button
                  type="button"
                  className="assetcreator-mini assetcreator-mini--danger"
                  onClick={() => deleteCreatorOutputs(editingJobId)}
                  disabled={deletingOutputsJobId === editingJobId || !!runningJobId}
                >
                  {deletingOutputsJobId === editingJobId ? "Deleting..." : "Delete Outputs"}
                </button>
              </section>
            ) : null}

            {proposal ? (
              <section className="assetcreator-proposal">
                <div className="assetcreator-proposal-head">
                  <div>
                    <h2>{isYoutubeCreator(form.creator_key) ? "Possible Video" : "Possible Pins"}</h2>
                    <p>
                      {proposal.playlist?.title || "Playlist"} · {pairs.length} row{pairs.length === 1 ? "" : "s"}
                      {pinTypeFilter !== "all" ? ` · showing ${visiblePairs.length}` : ""}
                    </p>
                  </div>
                  <div className="assetcreator-proposal-actions">
                    {!isYoutubeCreator(form.creator_key) ? <label className="assetcreator-inline-filter">
                      Pin Type
                      <select value={pinTypeFilter} onChange={(event) => setPinTypeFilter(event.target.value)}>
                        <option value="all">All</option>
                        <option value="composite">Composite</option>
                        <option value="idea">Idea</option>
                        <option value="idea_palette">Idea + Palette</option>
                      </select>
                    </label> : null}
                    <button type="button" className="assetcreator-command" onClick={addPair}>
                      Add Row
                    </button>
                    {isYoutubeCreator(form.creator_key) ? (
                      <button
                        type="button"
                        className="assetcreator-command"
                        onClick={previewRecipe}
                        disabled={previewingRecipe || savingRecipe || !!runningJobId}
                      >
                        {previewingRecipe ? "Previewing..." : "Preview Video"}
                      </button>
                    ) : null}
                    <button
                      type="button"
                      className="assetcreator-command assetcreator-command--primary"
                      onClick={saveRecipe}
                      disabled={savingRecipe}
                    >
                      {savingRecipe ? "Saving..." : "Save Recipe"}
                    </button>
                    <button
                      type="button"
                      className="assetcreator-command assetcreator-command--primary"
                      onClick={saveAndRunRecipe}
                      disabled={savingRecipe || !!runningJobId}
                    >
                      {runningJobId ? "Creating..." : isYoutubeCreator(form.creator_key) ? "Create Video" : "Create Pin"}
                    </button>
                  </div>
                </div>

                {proposal.warnings?.length ? (
                  <div className="assetcreator-status assetcreator-status--warn">
                    {proposal.warnings.join(" ")}
                  </div>
                ) : null}
                {recipePreview ? (
                  <div className="assetcreator-preview-result">
                    <div>
                      <strong>Preview video</strong>
                      <span>
                        {recipePreview.slide_count || 0} slide{Number(recipePreview.slide_count || 0) === 1 ? "" : "s"}
                        {recipePreview.duration_seconds ? ` · ${recipePreview.duration_seconds}s` : ""}
                      </span>
                    </div>
                    <input value={recipePreview.local_path || ""} readOnly />
                    {recipePreview.open_url ? (
                      <a className="assetcreator-mini assetcreator-mini-link" href={recipePreview.open_url} target="_blank" rel="noreferrer">
                        Open
                      </a>
                    ) : null}
                  </div>
                ) : null}

                <div className="assetcreator-grid-wrap assetcreator-grid-wrap--proposal">
                  <table className="assetcreator-grid assetcreator-pairs-grid">
                    <thead>
                      <tr>
                        <th>Use</th>
                        <th>Order</th>
                        <th>Type</th>
                        <th>Before</th>
                        <th>{isYoutubeCreator(form.creator_key) ? "Video Source" : "Photo / After"}</th>
                        <th>Search Title</th>
                        <th>Description</th>
                        <th>Source</th>
                        <th>Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      {visiblePairs.map(({ pair, index }) => (
                        <tr key={pair.pair_key || index}>
                          <td className="assetcreator-use-cell">
                            <input
                              type="checkbox"
                              checked={!!pair.include}
                              onChange={(event) => updatePair(index, "include", event.target.checked)}
                            />
                          </td>
                          <td className="assetcreator-order-cell">{index + 1}</td>
                          <td>
                            <select
                              value={pair.pin_type || (isYoutubeCreator(form.creator_key) ? "youtube_video" : "composite")}
                              onChange={(event) => updatePair(index, "pin_type", event.target.value)}
                              disabled={isYoutubeCreator(form.creator_key)}
                            >
                              {isYoutubeCreator(form.creator_key) ? <option value="youtube_video">youtube_video</option> : null}
                              <option value="composite">composite</option>
                              <option value="idea">idea</option>
                              <option value="idea_palette">idea_palette</option>
                            </select>
                          </td>
                          <td>{pair.pin_type === "composite" ? renderSideEditor(pair, index, "before", updatePairSide, toggleImagePreview) : "-"}</td>
                          <td>{renderSideEditor(pair, index, "after", updatePairSide, toggleImagePreview)}</td>
                          <td>
                            <textarea
                              className="assetcreator-title-input"
                              value={pair.search_title}
                              rows={3}
                              onChange={(event) => updatePair(index, "search_title", event.target.value)}
                            />
                          </td>
                          <td>
                            <textarea
                              className="assetcreator-description-input"
                              value={pair.description}
                              wrap="soft"
                              onChange={(event) => updatePair(index, "description", event.target.value)}
                            />
                          </td>
                          <td>
                            <span className="assetcreator-source">{pair.source}</span>
                            <span className="assetcreator-confidence">{Math.round((pair.confidence || 0) * 100)}%</span>
                          </td>
                          <td className="assetcreator-actions-cell">
                            <button type="button" className="assetcreator-mini" onClick={() => movePair(index, -1)}>
                              Up
                            </button>
                            <button type="button" className="assetcreator-mini" onClick={() => movePair(index, 1)}>
                              Down
                            </button>
                            <button type="button" className="assetcreator-mini assetcreator-mini--danger" onClick={() => removePair(index)}>
                              Remove
                            </button>
                          </td>
                        </tr>
                      ))}
                      {pairs.length === 0 ? (
                        <tr>
                          <td colSpan={9} className="assetcreator-empty">
                            No possible assets yet. Add a row manually.
                          </td>
                        </tr>
                      ) : visiblePairs.length === 0 ? (
                        <tr>
                          <td colSpan={9} className="assetcreator-empty">
                            No rows match this pin type filter.
                          </td>
                        </tr>
                      ) : null}
                    </tbody>
                  </table>
                </div>
              </section>
            ) : (
              <div className="assetcreator-modal-empty">
                Pick a channel and playlist, then click Analyze.
              </div>
            )}
          </div>
        </div>
      ) : null}

      {imagePreview ? (
        <div
          className="assetcreator-image-preview"
          role="dialog"
          aria-modal="true"
          aria-label="Image preview"
          onClick={() => setImagePreview(null)}
        >
          <div className="assetcreator-image-preview__panel" onClick={(event) => event.stopPropagation()}>
            <div className="assetcreator-image-preview__head">
              <div>
                <h2>{imagePreview.pin_type_label || "Pin Source"}</h2>
                <p>
                  {imagePreview.title || "Preview"}
                  {imagePreview.photo_library_id ? ` #${imagePreview.photo_library_id}` : ""}
                  {imagePreview.asset_library_id ? ` #${imagePreview.asset_library_id}` : ""}
                  {imagePreview.items?.length ? ` · ${Number(imagePreview.index || 0) + 1} of ${imagePreview.items.length}` : ""}
                </p>
              </div>
              <button type="button" className="assetcreator-command" onClick={() => setImagePreview(null)}>
                Close
              </button>
            </div>
            <div className="assetcreator-image-preview__body">
              {imagePreview.items?.length > 1 ? (
                <button
                  type="button"
                  className="assetcreator-image-preview__nav assetcreator-image-preview__nav--prev"
                  aria-label="Previous asset"
                  onClick={() => moveImagePreview(-1)}
                >
                  &lt;
                </button>
              ) : null}
              <img src={imagePreview.public_url} alt={imagePreview.title || ""} />
              {imagePreview.items?.length > 1 ? (
                <button
                  type="button"
                  className="assetcreator-image-preview__nav assetcreator-image-preview__nav--next"
                  aria-label="Next asset"
                  onClick={() => moveImagePreview(1)}
                >
                  &gt;
                </button>
              ) : null}
            </div>
          </div>
        </div>
      ) : null}
    </div>
  );
}

function sourceLabel(job) {
  if (!job.source_type) return "-";
  return job.source_id ? `${job.source_type} #${job.source_id}` : job.source_type;
}

function formatShortDateTime(value) {
  const text = String(value || "").trim();
  if (!text) return "-";
  const match = text.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
  if (!match) return text;
  return `${match[2]}/${match[3]} ${match[4]}:${match[5]}`;
}

function renderOutputPreview(outputs = [], openImagePreview = () => {}) {
  const items = Array.isArray(outputs) ? outputs : [];
  const first = items[0] || null;
  if (!first) return "-";
  if (isVideoAsset(first)) {
    const url = versionedAssetUrl(first.public_url || first.rel_path || "", first);
    return url ? (
      <a className="assetcreator-output-preview assetcreator-output-preview--video" href={url} target="_blank" rel="noreferrer">
        <span className="assetcreator-output-preview__video-icon">MP4</span>
        <span className="assetcreator-output-preview__label">
          <span>Asset #{first.asset_library_id}</span>
          {items.length > 1 ? <span>+{items.length - 1}</span> : null}
        </span>
      </a>
    ) : `Asset #${first.asset_library_id}`;
  }
  const url = versionedAssetUrl(first.public_url || first.rel_path || "", first);
  if (!url) return `Asset #${first.asset_library_id}`;
  return (
    <button
      type="button"
      className="assetcreator-output-preview"
      onClick={() => openImagePreview(outputPreviewFromItems(items, 0))}
    >
      <img src={url} alt="" />
      <span className="assetcreator-output-preview__label">
        <span>Asset #{first.asset_library_id}</span>
        {items.length > 1 ? <span>+{items.length - 1}</span> : null}
      </span>
    </button>
  );
}

function outputPreviewFromItems(outputs = [], index = 0) {
  const items = (Array.isArray(outputs) ? outputs : [])
    .map(normalizeOutputPreviewItem)
    .filter((item) => item.public_url);
  const safeIndex = Math.min(Math.max(Number(index || 0), 0), Math.max(items.length - 1, 0));
  const item = items[safeIndex] || {};
  return {
    ...item,
    index: safeIndex,
    items,
  };
}

function normalizeOutputPreviewItem(output = {}) {
  const metadata = parseInstructions(output.metadata_json);
  const pinType = inferOutputPinType(output, metadata);
  return {
    public_url: versionedAssetUrl(output.public_url || output.rel_path || "", output),
    title: output.title || metadata.search_title || `Asset #${output.asset_library_id}`,
    side: "Generated",
    pin_type: pinType,
    pin_type_label: pinTypeLabel(pinType),
    asset_library_id: output.asset_library_id,
    photo_library_id: output.photo_library_id,
    ...permissionProps(output),
  };
}

function isVideoAsset(item = {}) {
  const kind = String(item.asset_kind || "").toLowerCase();
  const mime = String(item.mime_type || "").toLowerCase();
  const path = String(item.rel_path || item.public_url || "").toLowerCase();
  return kind === "video" || mime.startsWith("video/") || /\.(mp4|mov|webm|m4v)(\?|$)/.test(path);
}

function inferOutputPinType(output = {}, metadata = {}) {
  const direct = String(metadata.pin_type || output.pin_type || "").trim();
  if (direct) return direct;
  const assetType = String(metadata.asset_type || output.asset_type || "").trim();
  if (assetType === "pin_composite") return "composite";
  if (assetType === "pin_idea") return "idea";
  if (assetType === "pin_idea_palette") return "idea_palette";
  const path = String(output.rel_path || output.public_url || "").toLowerCase();
  if (path.includes("idea-palette") || path.includes("idea_palette")) return "idea_palette";
  if (path.includes("idea")) return "idea";
  if (path.includes("composite") || path.includes("before_after")) return "composite";
  return "";
}

function pinTypeLabel(pinType) {
  if (pinType === "composite") return "Composite Pin";
  if (pinType === "idea") return "Idea Pin";
  if (pinType === "idea_palette") return "Idea + Palette Pin";
  return "";
}

function firstPermissionItem(items = []) {
  return (items || []).find((item) =>
    Number(item?.client_id || 0) > 0
    || String(item?.photo_permission_status || "").trim()
  ) || null;
}

function versionedAssetUrl(url, item = {}) {
  const raw = String(url || "").trim();
  if (!raw) return "";
  const version = String(
    item.generated_at
      || item.updated_at
      || item.asset_creator_output_id
      || item.asset_library_id
      || ""
  ).trim();
  if (!version) return raw;
  const separator = raw.includes("?") ? "&" : "?";
  return `${raw}${separator}v=${encodeURIComponent(version)}`;
}

function creatorLabel(creatorKey) {
  return CREATOR_TYPES.find((type) => type.value === creatorKey)?.label || creatorKey;
}

function isYoutubeCreator(creatorKey) {
  return String(creatorKey || "").trim() === "youtube.playlist_video";
}

function platformForCreator(creatorKey) {
  const configured = CREATOR_TYPES.find((type) => type.value === creatorKey);
  if (configured?.channel) return configured.channel;
  return String(creatorKey || "").split(".")[0] || "any";
}

function defaultAssetTypeForCreator(creatorKey) {
  if (isYoutubeCreator(creatorKey)) return "youtube_playlist_video";
  if (String(creatorKey || "") === "pinterest.before_after_composite") return "pinterest_pin";
  return "any";
}

function defaultsLinkFor(creatorKey, playlist = null) {
  const params = new URLSearchParams({
    creator_key: creatorKey || CREATOR_TYPES[0].value,
    channel: platformForCreator(creatorKey),
    playlist_type: playlist?.type || "any",
    field_key: "description",
  });
  return `/admin/publishing-defaults?${params.toString()}`;
}

function proposalRows(proposal = {}) {
  if (Array.isArray(proposal.video_rows)) return proposal.video_rows;
  if (Array.isArray(proposal.pin_rows)) return proposal.pin_rows;
  if (Array.isArray(proposal.pairs)) return proposal.pairs;
  return [];
}

function instructionRows(instructions = {}) {
  if (Array.isArray(instructions.video_rows)) return instructions.video_rows;
  if (Array.isArray(instructions.pin_rows)) return instructions.pin_rows;
  if (Array.isArray(instructions.pairs)) return instructions.pairs;
  return [];
}

function getSelectedPlaylist(playlists, playlistId) {
  const wanted = Number(playlistId || 0);
  return playlists.find((playlist) => Number(playlist.playlist_id) === wanted) || null;
}

async function copyText(value, label, setStatus = () => {}) {
  const text = String(value || "").trim();
  if (!text) return;
  try {
    if (!navigator.clipboard?.writeText) {
      window.prompt(`Copy ${label}:`, text);
      return;
    }
    await navigator.clipboard.writeText(text);
    setStatus(`Copied ${label}.`);
  } catch {
    window.prompt(`Copy ${label}:`, text);
  }
}

function CopyTextField({ label, value, placeholder = "", onCopy }) {
  const text = String(value || "").trim();
  return (
    <label className="assetcreator-copy-field">
      {label}
      <span className="assetcreator-copy-field__control">
        <input value={text} placeholder={placeholder} readOnly />
        <button
          type="button"
          className="assetcreator-copy-field__button"
          onClick={() => onCopy(text)}
          disabled={!text}
          aria-label={`Copy ${label}`}
          title={`Copy ${label}`}
        >
          <Clipboard size={15} aria-hidden="true" />
        </button>
      </span>
    </label>
  );
}

function parseInstructions(value) {
  if (!value) return {};
  if (typeof value === "object") return value;
  try {
    const parsed = JSON.parse(value);
    return parsed && typeof parsed === "object" ? parsed : {};
  } catch {
    return {};
  }
}

function analyzerDefaultsFromInstructions(instructions = {}, pairs = []) {
  const defaults = instructions.analyzer_defaults || instructions.analyzerDefaults || {};
  const title = String(defaults.title || defaults.default_title || "").trim();
  const description = String(defaults.description || defaults.default_description || "").trim();
  if (title || description) {
    return {
      title,
      description: description || DEFAULT_ANALYZER_DESCRIPTION,
      music_volume: defaults.music_volume || "0.18",
    };
  }

  const firstPair = (Array.isArray(pairs) ? pairs : []).find(Boolean) || {};
  return {
    title: String(firstPair.search_title || firstPair.pin_title || firstPair.title || "").trim(),
    description: String(
      firstPair.description
        || firstPair.pin_description
        || firstPair.caption
        || DEFAULT_ANALYZER_DESCRIPTION
    ).trim(),
    music_volume: defaults.music_volume || "0.18",
  };
}

function musicFromInstructions(instructions = {}) {
  const music = instructions.music || instructions.source?.music || instructions.analyzer_defaults?.music || null;
  if (!music || typeof music !== "object") return null;
  const assetId = Number(music.asset_library_id || 0);
  const publicUrl = String(music.public_url || music.src || music.rel_path || "").trim();
  if (!assetId && !publicUrl) return null;
  return {
    asset_library_id: assetId || null,
    title: music.title || "",
    rel_path: music.rel_path || "",
    public_url: publicUrl,
    mime_type: music.mime_type || "",
    volume: Number.isFinite(Number(music.volume)) ? Number(music.volume) : 0.18,
  };
}

function selectedMusicForRecipe(form, musicAssets = [], proposal = {}) {
  const selectedId = Number(form.music_asset_library_id || 0);
  if (selectedId <= 0) return null;

  const fromList = musicAssets.find((asset) => Number(asset.asset_library_id) === selectedId);
  const fromProposal = musicFromInstructions(proposal.instructions || proposal) || null;
  const asset = fromList || (Number(fromProposal?.asset_library_id || 0) === selectedId ? fromProposal : null);
  if (!asset) {
    return {
      asset_library_id: selectedId,
      title: "",
      rel_path: "",
      public_url: "",
      mime_type: "",
      volume: normalizedMusicVolume(form.music_volume),
    };
  }

  return {
    asset_library_id: selectedId,
    title: asset.title || "",
    rel_path: asset.rel_path || "",
    public_url: asset.public_url || asset.src || asset.rel_path || "",
    mime_type: asset.mime_type || "",
    volume: normalizedMusicVolume(form.music_volume),
  };
}

function normalizedMusicVolume(value) {
  const numeric = Number(value);
  if (!Number.isFinite(numeric)) return 0.18;
  return Math.max(0, Math.min(1, numeric));
}

function normalizePair(pair) {
  const pinType = pair.pin_type || "composite";
  return {
    pair_key: pair.pair_key || `pair-${Date.now()}-${Math.random()}`,
    pin_type: pinType,
    asset_type: assetTypeForPinType(pinType),
    include: pair.include !== false,
    sort_order: pair.sort_order || 0,
    source: pair.source || "manual",
    confidence: Number(pair.confidence || 0),
    search_title: pair.search_title || pair.pin_title || pair.title || "",
    description: pair.description || pair.pin_description || pair.caption || "",
    before: { ...EMPTY_SIDE, ...(pair.before || {}) },
    after: { ...EMPTY_SIDE, ...(pair.after || {}) },
    asset: { ...EMPTY_SIDE, ...(pair.asset || pair.after || {}) },
    slides: Array.isArray(pair.slides) ? pair.slides : [],
    slide_count: Number(pair.slide_count || (Array.isArray(pair.slides) ? pair.slides.length : 0)),
    output: pair.output || null,
  };
}

function assetTypeForPinType(pinType = "composite", creatorKey = "") {
  if (isYoutubeCreator(creatorKey) || pinType === "youtube_video") return "youtube_playlist_video";
  if (pinType === "idea_palette") return "pin_idea_palette";
  if (pinType === "idea") return "pin_idea";
  return "pin_composite";
}

function titleForPinType(title, pinType = "composite") {
  const value = String(title || "").trim();
  if (pinType === "idea_palette") {
    return value.replace(/\bIdeas\b/gi, "Palettes");
  }
  return value;
}

function withLiveInputPermissions(pairs, inputs = []) {
  if (!Array.isArray(inputs) || inputs.length === 0) return pairs;
  const byPairAndSide = new Map();
  const byPhotoAndSide = new Map();
  inputs.forEach((input) => {
    const metadata = parseInstructions(input.metadata_json);
    const pairKey = String(metadata.pair_key || "").trim();
    const side = String(input.role || metadata.side || "").trim();
    if (pairKey && side) {
      byPairAndSide.set(`${pairKey}:${side}`, input);
    }
    const photoId = Number(metadata.photo_library_id || 0);
    if (photoId > 0 && side) {
      byPhotoAndSide.set(`${photoId}:${side}`, input);
    }
  });

  return pairs.map((pair) => ({
    ...pair,
    before: withLiveSidePermission(pair.before, pair.pair_key, "before", byPairAndSide, byPhotoAndSide),
    after: withLiveSidePermission(pair.after, pair.pair_key, "after", byPairAndSide, byPhotoAndSide),
  }));
}

function withLiveSidePermission(side, pairKey, role, byPairAndSide, byPhotoAndSide) {
  const value = side || EMPTY_SIDE;
  const photoId = Number(value.photo_library_id || 0);
  const input = byPairAndSide.get(`${pairKey}:${role}`) || byPhotoAndSide.get(`${photoId}:${role}`);
  if (!input) return value;
  return {
    ...value,
    client_id: input.client_id || value.client_id || null,
    client_name: input.client_name || value.client_name || "",
    client_email: input.client_email || value.client_email || "",
    photo_permission_status: input.photo_permission_status || value.photo_permission_status || "",
  };
}

function normalizeRecipeSide(side) {
  const value = side || EMPTY_SIDE;
  return {
    asset_library_id: nullableNumber(value.asset_library_id),
    photo_library_id: nullableNumber(value.photo_library_id),
    saved_palette_id: nullableNumber(value.saved_palette_id),
    saved_palette_set_id: nullableNumber(value.saved_palette_set_id),
    palette_hash: value.palette_hash || "",
    ap_id: nullableNumber(value.ap_id),
    title: value.title || "",
    public_url: value.public_url || "",
    client_id: nullableNumber(value.client_id),
    client_name: value.client_name || "",
    client_email: value.client_email || "",
    photo_permission_status: value.photo_permission_status || "",
  };
}

function buildRecipeInputs(reviewedPairs, music = null) {
  const inputs = [];
  if (music?.asset_library_id) {
    inputs.push({
      asset_library_id: nullableNumber(music.asset_library_id),
      role: "background_music",
      sort_order: 0,
      metadata_json: {
        asset_type: "youtube_background_music",
        title: music.title || "",
        public_url: music.public_url || "",
        rel_path: music.rel_path || "",
        mime_type: music.mime_type || "",
        volume: normalizedMusicVolume(music.volume),
      },
    });
  }
  reviewedPairs.forEach((pair, index) => {
    if (!pair.include) return;
    if (pair.pin_type === "youtube_video") {
      inputs.push({
        asset_library_id: nullableNumber(pair.asset?.asset_library_id || pair.after?.asset_library_id),
        role: "video_source",
        sort_order: index + 1,
        metadata_json: {
          pair_key: pair.pair_key || `video-${index + 1}`,
          pair_order: index + 1,
          pin_type: "youtube_video",
          asset_type: "youtube_playlist_video",
          playlist_item_count: Number(pair.slide_count || pair.slides?.length || 0),
          search_title: pair.search_title || pair.title || "",
          description: pair.description || pair.caption || "",
          source: pair.source || "",
          confidence: Number(pair.confidence || 0),
        },
      });
      return;
    }
    const sides = pair.pin_type === "composite" ? ["before", "after"] : ["asset"];
    sides.forEach((side, sideIndex) => {
      const value = pair[side] || EMPTY_SIDE;
      inputs.push({
        asset_library_id: nullableNumber(value.asset_library_id),
        role: side === "asset" ? "source" : side,
        sort_order: index + 1 + sideIndex / 10,
        metadata_json: {
          pair_key: pair.pair_key || `pair-${index + 1}`,
          pair_order: index + 1,
          pin_type: pair.pin_type || "composite",
          asset_type: assetTypeForPinType(pair.pin_type),
          side,
          photo_library_id: nullableNumber(value.photo_library_id),
          saved_palette_id: nullableNumber(value.saved_palette_id),
          saved_palette_set_id: nullableNumber(value.saved_palette_set_id),
          palette_hash: value.palette_hash || "",
          ap_id: nullableNumber(value.ap_id),
          title: value.title || "",
          public_url: value.public_url || "",
          client_id: nullableNumber(value.client_id),
          client_name: value.client_name || "",
          client_email: value.client_email || "",
          photo_permission_status: value.photo_permission_status || "",
          search_title: pair.search_title || pair.title || "",
          description: pair.description || pair.caption || "",
          pin_title: pair.search_title || pair.title || "",
          pin_description: pair.description || pair.caption || "",
          source: pair.source || "",
          confidence: Number(pair.confidence || 0),
        },
      });
    });
  });
  return inputs;
}

function nullableNumber(value) {
  const num = Number(value || 0);
  return num > 0 ? num : null;
}

function renderSideEditor(pair, index, side, updatePairSide, openImagePreview) {
  const value = pair[side] || EMPTY_SIDE;
  const pinType = pair.pin_type || "composite";
  return (
    <div className="assetcreator-side-editor">
      {value.public_url ? (
        <div className="assetcreator-thumb-wrap">
          <PermissionStatus {...permissionProps(value)} className="assetcreator-thumb-permission" />
          <button
            type="button"
            className="assetcreator-thumb-button"
            onClick={() =>
              openImagePreview({
                ...value,
                side,
                pin_type: pinType,
                pin_type_label: pinTypeLabel(pinType),
              })
            }
          >
            <img src={value.public_url} alt="" />
          </button>
        </div>
      ) : (
        <div className="assetcreator-thumb-wrap">
          <PermissionStatus {...permissionProps(value)} className="assetcreator-thumb-permission" />
          <div className="assetcreator-thumb-empty">No image</div>
        </div>
      )}
      <label>
        Photo ID
        <input
          className="assetcreator-number-input"
          value={value.photo_library_id || ""}
          onChange={(event) => updatePairSide(index, side, "photo_library_id", event.target.value)}
        />
      </label>
      <label>
        Asset ID
        <input
          className="assetcreator-number-input"
          value={value.asset_library_id || ""}
          onChange={(event) => updatePairSide(index, side, "asset_library_id", event.target.value)}
        />
      </label>
      <label>
        URL
        <input
          className="assetcreator-url-input"
          value={value.public_url || ""}
          onChange={(event) => updatePairSide(index, side, "public_url", event.target.value)}
        />
      </label>
    </div>
  );
}

function permissionProps(item = {}) {
  return {
    status: item.photo_permission_status,
    photoLibraryId: item.permission_photo_library_id || item.photo_library_id,
    clientId: item.client_id,
    clientName: item.client_name,
    clientEmail: item.client_email,
  };
}
