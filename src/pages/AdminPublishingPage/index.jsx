import { useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import PermissionStatus from "@components/PermissionStatus";
import "./admin-publishing.css";

const JOBS_URL = `${API_FOLDER}/v2/admin/publishing/pinterest/list.php`;
const SAVE_PINTEREST_URL = `${API_FOLDER}/v2/admin/publishing/pinterest/save.php`;
const PREPARE_PINTEREST_URL = `${API_FOLDER}/v2/admin/publishing/pinterest/prepare-from-creator.php`;
const MARK_PINTEREST_URL = `${API_FOLDER}/v2/admin/publishing/pinterest/mark-published.php`;
const PINTEREST_AUTH_STATUS_URL = `${API_FOLDER}/v2/admin/publishing/pinterest/auth-status.php`;
const PINTEREST_CONNECT_URL = `${API_FOLDER}/pinterest/connect`;
const PINTEREST_SYNC_BOARDS_URL = `${API_FOLDER}/v2/admin/publishing/pinterest/sync-boards.php`;
const PINTEREST_DRY_RUN_URL = `${API_FOLDER}/v2/admin/publishing/pinterest/dry-run.php`;
const PINTEREST_PUBLISH_TEST_URL = `${API_FOLDER}/v2/admin/publishing/pinterest/publish-test.php`;
const PLAYLISTS_URL = `${API_FOLDER}/v2/admin/playlists/list.php`;
const INSTANCES_URL = `${API_FOLDER}/v2/admin/playlist-instances/list.php`;
const INSTANCE_SAVE_URL = `${API_FOLDER}/v2/admin/playlist-instances/save.php`;
const INSTANCE_DEACTIVATE_TEST_URL = `${API_FOLDER}/v2/admin/playlist-instances/deactivate-publisher-test.php`;
const LANDING_PAGES_URL = `${API_FOLDER}/v2/admin/landing-pages/list.php`;
const CREATOR_JOBS_URL = `${API_FOLDER}/v2/admin/asset-creators/list.php`;
const CTA_PAGES_URL = `${API_FOLDER}/v2/admin/cta-groups/list.php`;
const SCHEDULER_SCHEDULE_URL = `${API_FOLDER}/v2/admin/publication-scheduler/schedule.php`;
const SCHEDULER_SCHEDULE_JOB_URL = `${API_FOLDER}/v2/admin/publication-scheduler/schedule-job.php`;
const SCHEDULER_DELETE_UNSCHEDULED_URL = `${API_FOLDER}/v2/admin/publication-scheduler/delete-unscheduled.php`;

const assetTypes = [
  {
    value: "pinterest.before_after_pin",
    label: "Pinterest - Before/After Pin",
    enabled: true,
  },
  {
    value: "youtube.playlist_video",
    label: "YouTube - Playlist Video",
    enabled: false,
  },
  {
    value: "youtube.pptx_draft",
    label: "YouTube - PPTX Draft",
    enabled: false,
  },
];

const PINTEREST_BOARD = {
  board_name: "ColorFix Makeovers",
  board_url: "https://www.pinterest.com/terrymarr/colorfix-makeovers/",
  board_slug: "terrymarr/colorfix-makeovers",
  board_id: null,
};

const emptyForm = {
  asset_type: "pinterest.before_after_pin",
  playlist_id: "",
  playlist_instance_id: "",
  landing_page_id: "",
  title: "",
  description: "",
  board: PINTEREST_BOARD.board_name,
  board_name: PINTEREST_BOARD.board_name,
  board_url: PINTEREST_BOARD.board_url,
  board_slug: PINTEREST_BOARD.board_slug,
  board_id: "",
  external_url: "",
  library_asset_id: "",
  published_at: "",
  notes: "",
};

const emptySetupForm = {
  asset_creator_job_id: "",
  platform: "pinterest",
  environment: "test",
  destination_key: "colorfix_api_test",
  cta_group_id: "",
  instance_title: "",
  slug: "",
};

const publishingChannels = [
  { value: "pinterest", label: "Pinterest", enabled: true },
  { value: "youtube", label: "YouTube", enabled: false },
  { value: "instagram", label: "Instagram", enabled: false },
];

const pinterestDestinations = [
  {
    value: "colorfix_api_test",
    label: "ColorFix API Test",
    environment: "test",
    board_name: "ColorFix API Test",
    board_id: null,
  },
  {
    value: "colorfix_makeovers",
    label: "ColorFix Makeovers",
    environment: "production",
    board_name: "ColorFix Makeovers",
    board_id: null,
  },
];

const importHelp = "playlist_id | library_asset_id | pinterest_url | title | board | published_at | instance_id";

export default function AdminPublishingPage() {
  const [jobs, setJobs] = useState([]);
  const [creatorJobs, setCreatorJobs] = useState([]);
  const [ctaPages, setCtaPages] = useState([]);
  const [playlists, setPlaylists] = useState([]);
  const [instances, setInstances] = useState([]);
  const [landingPages, setLandingPages] = useState([]);
  const [form, setForm] = useState(emptyForm);
  const [q, setQ] = useState("");
  const [sort, setSort] = useState({ key: "created_at", direction: "desc" });
  const [openCreate, setOpenCreate] = useState(false);
  const [openImport, setOpenImport] = useState(false);
  const [importText, setImportText] = useState("");
  const [selectedRow, setSelectedRow] = useState(null);
  const [status, setStatus] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [setupForm, setSetupForm] = useState(emptySetupForm);
  const [setupResult, setSetupResult] = useState(null);
  const [setupSaving, setSetupSaving] = useState(false);
  const [preparing, setPreparing] = useState(false);
  const [pinterestStatus, setPinterestStatus] = useState(null);
  const [pinterestSyncing, setPinterestSyncing] = useState(false);
  const [dryRun, setDryRun] = useState(null);
  const [openConnection, setOpenConnection] = useState(false);
  const [previewInstance, setPreviewInstance] = useState(null);
  const [listMode, setListMode] = useState("published");
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");

  const selectedChannel = setupForm.platform || "pinterest";
  const channelLabel = publishingChannels.find((channel) => channel.value === selectedChannel)?.label || selectedChannel;

  const rows = useMemo(() => {
    return jobs.flatMap((job) =>
      (job.outputs || []).map((output) => {
        const metadata = parseMetadata(output.metadata_json);
        return {
          ...output,
          board_name: metadata.board_name || metadata.board || "",
          board_url: metadata.board_url || "",
          board_slug: metadata.board_slug || "",
          board_id: metadata.board_id || "",
          publish_job_id: job.publish_job_id,
          source_type: job.source_type,
          source_id: job.source_id,
          job_status: job.status,
          job_title: job.title,
          playlist_title: job.playlist_title,
          instance_title: job.instance_display_title || job.instance_name || "",
        };
      })
    );
  }, [jobs]);

  const channelRows = useMemo(() => {
    return rows.filter((row) => rowMatchesChannel(row, selectedChannel));
  }, [rows, selectedChannel]);

  const channelTotals = useMemo(() => buildChannelTotals(rows), [rows]);

  const visibleRows = useMemo(() => {
    return channelRows.filter((row) => {
      if (listMode === "published" && !isPublishedRow(row)) return false;
      if (listMode === "prepared" && isPublishedRow(row)) return false;
      return rowInDateRange(row, listMode, dateFrom, dateTo);
    });
  }, [channelRows, dateFrom, dateTo, listMode]);

  const sortedRows = useMemo(() => {
    const direction = sort.direction === "asc" ? 1 : -1;
    return [...visibleRows].sort((a, b) => {
      const av = sortValue(a, sort.key);
      const bv = sortValue(b, sort.key);
      if (av < bv) return -1 * direction;
      if (av > bv) return 1 * direction;
      return Number(b.publish_output_id || 0) - Number(a.publish_output_id || 0);
    });
  }, [sort, visibleRows]);

  const displayedCreatorJobs = useMemo(() => {
    return creatorJobs.filter((job) => jobMatchesChannel(job, selectedChannel));
  }, [creatorJobs, selectedChannel]);

  const availableDestinations = useMemo(() => {
    if (selectedChannel !== "pinterest") return [];
    return pinterestDestinations.filter((destination) => destination.environment === setupForm.environment);
  }, [selectedChannel, setupForm.environment]);

  const selectedPlaylist = useMemo(() => {
    const id = Number(form.playlist_id || 0);
    return playlists.find((item) => Number(item.playlist_id || 0) === id) || null;
  }, [form.playlist_id, playlists]);

  const selectedCreatorJob = useMemo(() => {
    const id = Number(setupForm.asset_creator_job_id || 0);
    return displayedCreatorJobs.find((job) => Number(job.asset_creator_job_id || 0) === id) || null;
  }, [displayedCreatorJobs, setupForm.asset_creator_job_id]);

  const setupPlaylist = useMemo(() => {
    const playlistId = Number(selectedCreatorJob?.source_id || 0);
    return playlists.find((playlist) => Number(playlist.playlist_id || 0) === playlistId) || null;
  }, [playlists, selectedCreatorJob]);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const authStatus = params.get("pinterest_auth");
    const message = params.get("message");
    if (authStatus) {
      if (authStatus === "connected") {
        setStatus(message || "Pinterest connected.");
      } else {
        setError(message || `Pinterest OAuth ${authStatus}.`);
      }
      window.history.replaceState({}, "", window.location.pathname);
    }
    fetchPlaylists();
    fetchLandingPages();
    fetchCreatorJobs();
    fetchCtaPages();
    fetchPinterestStatus();
    fetchJobs();
  }, []);

  useEffect(() => {
    if (!form.playlist_id) {
      setInstances([]);
      setForm((prev) => ({ ...prev, playlist_instance_id: "" }));
      return;
    }
    fetchInstances(form.playlist_id);
  }, [form.playlist_id]);

  async function fetchJobs(nextQ = q) {
    setLoading(true);
    setError("");
    try {
      const params = new URLSearchParams();
      if (nextQ.trim()) params.set("q", nextQ.trim());
      params.set("_", String(Date.now()));
      const res = await fetch(`${JOBS_URL}?${params.toString()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load publishing records");
      setJobs(data.items || []);
    } catch (err) {
      setError(err?.message || "Failed to load publishing records");
    } finally {
      setLoading(false);
    }
  }

  async function fetchPlaylists() {
    try {
      const res = await fetch(`${PLAYLISTS_URL}?_=${Date.now()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load playlists");
      setPlaylists(data.items || []);
    } catch (err) {
      setError(err?.message || "Failed to load playlists");
    }
  }

  async function fetchCreatorJobs() {
    try {
      const res = await fetch(`${CREATOR_JOBS_URL}?_=${Date.now()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load creator jobs");
      const items = Array.isArray(data.items) ? data.items : [];
      setCreatorJobs(items.filter((job) => Number(job.output_count || 0) > 0));
    } catch (err) {
      setError(err?.message || "Failed to load creator jobs");
    }
  }

  async function fetchCtaPages() {
    try {
      const res = await fetch(`${CTA_PAGES_URL}?_=${Date.now()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load CTA pages");
      setCtaPages(Array.isArray(data.items) ? data.items : []);
    } catch (err) {
      setError(err?.message || "Failed to load CTA pages");
    }
  }

  async function fetchPinterestStatus() {
    try {
      const res = await fetch(`${PINTEREST_AUTH_STATUS_URL}?_=${Date.now()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load Pinterest status");
      setPinterestStatus(data.item || null);
    } catch (err) {
      setError(err?.message || "Failed to load Pinterest status");
    }
  }

  async function syncPinterestBoards() {
    setPinterestSyncing(true);
    setError("");
    setStatus("");
    try {
      const res = await fetch(PINTEREST_SYNC_BOARDS_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({}),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to sync Pinterest boards");
      setStatus("Pinterest boards synced.");
      await fetchPinterestStatus();
    } catch (err) {
      setError(err?.message || "Failed to sync Pinterest boards");
    } finally {
      setPinterestSyncing(false);
    }
  }

  async function fetchLandingPages() {
    try {
      const res = await fetch(`${LANDING_PAGES_URL}?_=${Date.now()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load landing pages");
      setLandingPages(data.items || []);
    } catch (err) {
      setError(err?.message || "Failed to load landing pages");
    }
  }

  async function fetchInstances(playlistId) {
    try {
      const params = new URLSearchParams({
        playlist_id: String(playlistId),
        active: "1",
        _: String(Date.now()),
      });
      const res = await fetch(`${INSTANCES_URL}?${params.toString()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load instances");
      setInstances(data.items || []);
    } catch (err) {
      setInstances([]);
      setError(err?.message || "Failed to load instances");
    }
  }

  function updateForm(field, value) {
    setForm((prev) => ({ ...prev, [field]: value }));
  }

  function updateSetupForm(field, value) {
    setSetupForm((prev) => {
      const next = { ...prev, [field]: value };
      if (field === "platform") {
        next.asset_creator_job_id = "";
        next.destination_key = value === "pinterest" ? "colorfix_api_test" : "";
        next.environment = "test";
        next.instance_title = "";
        next.slug = "";
      }
      if (field === "environment") {
        next.destination_key = value === "test" ? "colorfix_api_test" : "colorfix_makeovers";
        next.slug = slugifyPublisher(prev.instance_title || next.instance_title);
      }
      if (field === "asset_creator_job_id") {
        const job = creatorJobs.find((item) => Number(item.asset_creator_job_id || 0) === Number(value || 0));
        const title = publisherTitleFromJob(job, playlists);
        next.instance_title = title;
        next.slug = slugifyPublisher(title);
      }
      return next;
    });
    setSetupResult(null);
    setStatus("");
    setError("");
  }

  function changeSort(key) {
    setSort((prev) => ({
      key,
      direction: prev.key === key && prev.direction === "asc" ? "desc" : "asc",
    }));
  }

  function openNewAsset() {
    setForm(emptyForm);
    setStatus("");
    setError("");
    setOpenCreate(true);
  }

  function openImportExisting() {
    setImportText("");
    setStatus("");
    setError("");
    setOpenImport(true);
  }

  function usePlaylistText() {
    if (!selectedPlaylist) return;
    setForm((prev) => ({
      ...prev,
      title: prev.title || selectedPlaylist.headline || selectedPlaylist.title || "",
    }));
  }

  async function ensurePublisherInstance({ openPreview = false } = {}) {
    setSetupSaving(true);
    setError("");
    setStatus("");
    setSetupResult(null);
    try {
      if (setupForm.platform !== "pinterest") throw new Error(`${channelLabel} publisher setup is not wired yet.`);
      const job = selectedCreatorJob;
      if (!job) throw new Error("Choose a creator job.");
      if (job.source_type !== "playlist") throw new Error("This first publisher setup only supports playlist creator jobs.");
      const playlistId = Number(job.source_id || 0);
      if (playlistId <= 0) throw new Error("Creator job is missing its source playlist.");
      const ctaGroupId = Number(setupForm.cta_group_id || 0);
      if (ctaGroupId <= 0) throw new Error("Choose a CTA Page.");

      const environment = setupForm.environment || "test";
      const platform = setupForm.platform || "pinterest";
      const destinationKey = setupForm.destination_key || "colorfix_api_test";
      const title = setupForm.instance_title.trim() || publisherTitleFromJob(job, playlists);
      const slug = setupForm.slug.trim() || slugifyPublisher(title);
      const marker = publisherInstanceMarker(environment, platform, destinationKey, job.asset_creator_job_id);

      const existingRes = await fetch(`${INSTANCES_URL}?playlist_id=${encodeURIComponent(playlistId)}&active=1&_=${Date.now()}`, {
        credentials: "include",
      });
      const existingData = await existingRes.json();
      if (!existingRes.ok || !existingData?.ok) throw new Error(existingData?.error || "Failed to check existing instances");
      const existing = (existingData.items || []).find((instance) => (
        Number(instance.cta_group_id || 0) === ctaGroupId
        && String(instance.audience || "") === "pinterest"
        && String(instance.slug || instance.playlist_slug || "") === slug
        && String(instance.instance_notes || "").includes(marker)
      ));

      if (existing) {
        const result = {
          mode: "reused",
          playlist_instance_id: existing.playlist_instance_id,
          player_url: existing.player_url,
          slug: existing.slug,
        };
        setSetupResult(result);
        if (openPreview) setPreviewInstance(result);
        setStatus(`Reused test instance #${existing.playlist_instance_id}.`);
        return result;
      }

      const saveRes = await fetch(INSTANCE_SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          playlist_id: playlistId,
          instance_name: `Pinterest - ${title}`,
          slug,
          allow_slug_edit: true,
          display_title: title,
          display_subtitle: "",
          instance_notes: [
            marker,
            "Created by publisher setup. Internal test mode is disposable until production publish.",
          ].join("\n"),
          intro_layout: "default",
          cta_group_id: ctaGroupId,
          cta_context_key: "pinterest",
          audience: "pinterest",
          share_enabled: true,
          share_title: title,
          share_description: setupPlaylist?.headline || setupPlaylist?.title || title,
          skip_intro_on_replay: false,
          hide_stars: false,
          is_active: true,
        }),
      });
      const saveData = await saveRes.json();
      if (!saveRes.ok || !saveData?.ok) throw new Error(saveData?.error || "Failed to create playlist instance");
      const result = {
        mode: "created",
        playlist_instance_id: saveData.playlist_instance_id,
        player_url: saveData.player_url,
        slug: saveData.slug,
      };
      setSetupResult(result);
      if (openPreview) setPreviewInstance(result);
      setStatus(`Created test instance #${saveData.playlist_instance_id}.`);
      return result;
    } catch (err) {
      setError(err?.message || "Failed to create publisher instance");
      return null;
    } finally {
      setSetupSaving(false);
    }
  }

  async function preparePublisherJobs(options = {}) {
    const maxOutputs = Number(options.maxOutputs || 0);
    setPreparing(true);
    setError("");
    setStatus("");
    try {
      if (setupForm.platform !== "pinterest") throw new Error(`${channelLabel} publisher setup is not wired yet.`);
      const instance = setupResult?.playlist_instance_id ? setupResult : await ensurePublisherInstance({ openPreview: false });
      if (!instance?.playlist_instance_id) throw new Error("Create or preview the playlist instance first.");
      const res = await fetch(PREPARE_PINTEREST_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          asset_creator_job_id: Number(setupForm.asset_creator_job_id || 0),
          playlist_instance_id: Number(instance.playlist_instance_id || 0),
          cta_group_id: Number(setupForm.cta_group_id || 0),
          environment: setupForm.environment,
          destination_key: setupForm.destination_key,
          instance_title: setupForm.instance_title,
          max_outputs: maxOutputs > 0 ? maxOutputs : undefined,
        }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to prepare publishing jobs");
      const item = data.item || {};
      const scope = maxOutputs > 0 ? `first ${maxOutputs}` : "all";
      const scheduleResult = await schedulePreparedJob(item.publishing_job_id || item.publish_job_id);
      const scheduledText = scheduleResult.enqueued > 0
        ? ` Added ${scheduleResult.enqueued} missing.`
        : "";
      const skippedText = scheduleResult.already_waiting > 0
        ? ` ${scheduleResult.already_waiting} already waiting.`
        : "";
      const blockedText = scheduleResult.skipped > 0
        ? ` ${scheduleResult.skipped} skipped.`
        : "";
      setStatus(`Sent missing ${scope} publishing job row${maxOutputs === 1 ? "" : "s"} to Scheduler for batch #${item.publishing_job_id}: ${item.created_outputs || 0} new, ${item.reused_outputs || 0} reused.${scheduledText}${skippedText}${blockedText}`);
      setQ("");
      await fetchJobs("");
    } catch (err) {
      setError(err?.message || "Failed to send publishing jobs to scheduler");
    } finally {
      setPreparing(false);
    }
  }

  async function schedulePreparedJob(publishingJobId) {
    const jobId = Number(publishingJobId || 0);
    if (jobId <= 0) return { enqueued: 0, already_waiting: 0, skipped: 0 };
    const res = await fetch(SCHEDULER_SCHEDULE_JOB_URL, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        publishing_job_id: jobId,
        priority: 100,
      }),
    });
    const data = await res.json();
    if (!res.ok || !data?.ok) throw new Error(data?.error || `Failed to send publishing job #${jobId} to Scheduler`);
    return data.item || { enqueued: 0, already_waiting: 0, skipped: 0 };
  }

  async function deactivatePublisherInstance() {
    if (!setupResult?.playlist_instance_id || !selectedCreatorJob) return;
    const ok = window.confirm(`Deactivate test instance #${setupResult.playlist_instance_id}?`);
    if (!ok) return;
    setSetupSaving(true);
    setError("");
    setStatus("");
    try {
      const res = await fetch(INSTANCE_DEACTIVATE_TEST_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          playlist_instance_id: setupResult.playlist_instance_id,
          asset_creator_job_id: selectedCreatorJob.asset_creator_job_id,
          platform: setupForm.platform,
          destination_key: setupForm.destination_key,
        }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to deactivate test instance");
      setStatus(`Deactivated test instance #${setupResult.playlist_instance_id}.`);
      setSetupResult(null);
    } catch (err) {
      setError(err?.message || "Failed to deactivate test instance");
    } finally {
      setSetupSaving(false);
    }
  }

  async function createAsset(event) {
    event.preventDefault();
    setSaving(true);
    setError("");
    setStatus("");
    try {
      if (form.asset_type !== "pinterest.before_after_pin") {
        throw new Error("That asset creator is listed, but not wired yet.");
      }

      const payload = {
        ...form,
        playlist_id: Number(form.playlist_id || 0),
        playlist_instance_id: form.playlist_instance_id ? Number(form.playlist_instance_id) : null,
        landing_page_id: form.landing_page_id ? Number(form.landing_page_id) : null,
        library_asset_id: form.library_asset_id ? Number(form.library_asset_id) : null,
      };
      const res = await fetch(SAVE_PINTEREST_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to create asset record");
      setStatus(`Asset record created: ${data.item?.tracking_code || ""}`);
      setOpenCreate(false);
      setForm(emptyForm);
      setQ("");
      await fetchJobs("");
    } catch (err) {
      setError(err?.message || "Failed to create asset record");
    } finally {
      setSaving(false);
    }
  }

  async function importExistingPins(event) {
    event.preventDefault();
    const entries = parsePinImport(importText);
    if (!entries.length) {
      setError("Paste at least one existing pin row.");
      return;
    }

    setSaving(true);
    setError("");
    setStatus("");
    try {
      let count = 0;
      for (const entry of entries) {
        const res = await fetch(SAVE_PINTEREST_URL, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            playlist_id: entry.playlist_id,
            playlist_instance_id: entry.playlist_instance_id || null,
            title: entry.title,
            board: entry.board,
            board_name: entry.board || PINTEREST_BOARD.board_name,
            board_url: PINTEREST_BOARD.board_url,
            board_slug: PINTEREST_BOARD.board_slug,
            board_id: null,
            external_url: entry.external_url,
            library_asset_id: entry.library_asset_id || null,
            published_at: entry.published_at,
            notes: "Imported existing Pinterest pin.",
          }),
        });
        const data = await res.json();
        if (!res.ok || !data?.ok) {
          throw new Error(data?.error || `Failed importing playlist ${entry.playlist_id}`);
        }
        count += 1;
      }
      setStatus(`Imported ${count} existing Pinterest pin${count === 1 ? "" : "s"}.`);
      setOpenImport(false);
      setImportText("");
      await fetchJobs("");
    } catch (err) {
      setError(err?.message || "Failed to import existing pins");
    } finally {
      setSaving(false);
    }
  }

  async function markPublished(row) {
    setSaving(true);
    setError("");
    setStatus("");
    try {
      const externalUrl = window.prompt("Pinterest pin URL", row.external_url || "");
      if (externalUrl === null) return;
      const res = await fetch(MARK_PINTEREST_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          publish_job_id: row.publish_job_id,
          publish_output_id: row.publish_output_id,
          external_url: externalUrl,
        }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to mark published");
      setSelectedRow(null);
      setStatus("Output marked published.");
      await fetchJobs();
    } catch (err) {
      setError(err?.message || "Failed to mark published");
    } finally {
      setSaving(false);
    }
  }

  async function previewPinterestPayload(row) {
    setSaving(true);
    setError("");
    setStatus("");
    setDryRun(null);
    try {
      const res = await fetch(PINTEREST_DRY_RUN_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          publish_output_id: row.publish_output_id,
          environment: row.environment || "test",
        }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to build Pinterest payload");
      setDryRun(data.item || null);
      setStatus(`Dry-run Pinterest payload built for ${row.environment === "production" ? "ColorFix Makeovers" : "ColorFix API Test"}.`);
    } catch (err) {
      setError(err?.message || "Failed to build Pinterest payload");
    } finally {
      setSaving(false);
    }
  }

  async function publishPinterestTest(row) {
    const ok = window.confirm("Post this Pin to the ColorFix API Test Pinterest board? This does not lock production.");
    if (!ok) return;
    setSaving(true);
    setError("");
    setStatus("");
    try {
      const res = await fetch(PINTEREST_PUBLISH_TEST_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ publish_output_id: row.publish_output_id }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to publish test Pin");
      setStatus(`Test Pin posted: ${data.item?.pinterest_pin_url || data.item?.pinterest_pin_id || ""}`);
      await fetchJobs();
    } catch (err) {
      setError(err?.message || "Failed to publish test Pin");
    } finally {
      setSaving(false);
    }
  }

  async function schedulePinterestTest(row) {
    setSaving(true);
    setError("");
    setStatus("");
    try {
      const res = await fetch(SCHEDULER_SCHEDULE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          publish_output_id: row.publish_output_id,
          publishing_asset_id: row.publish_output_id,
          priority: 100,
        }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to schedule test Pin");
      setStatus(`Sent output #${row.publish_output_id} to Scheduler.`);
      await fetchJobs();
    } catch (err) {
      setError(err?.message || "Failed to schedule test Pin");
    } finally {
      setSaving(false);
    }
  }

  async function deleteDisposableTestRow(row) {
    const ok = window.confirm(
      `Delete test publishing row #${row.publish_output_id}? This only removes the ColorFix test publish record. It will not delete a production publish.`
    );
    if (!ok) return;
    setSaving(true);
    setError("");
    setStatus("");
    try {
      const res = await fetch(SCHEDULER_DELETE_UNSCHEDULED_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ publishing_asset_ids: [row.publish_output_id] }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to delete test publishing row");
      const item = data.item || {};
      const blocked = Array.isArray(item.blocked) ? item.blocked : [];
      if (blocked.length) {
        throw new Error(blocked.join(" "));
      }
      setStatus(`Deleted test publishing row #${row.publish_output_id}.`);
      setSelectedRow(null);
      await fetchJobs();
    } catch (err) {
      setError(err?.message || "Failed to delete test publishing row");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="admin-publishing">
      <header className="pubdb-header">
        <div>
          <h1>Publishing Jobs</h1>
        </div>
        <div className="pubdb-header__actions">
          <a className="pubdb-command" href="/admin/scheduler">
            Scheduler
          </a>
          <button type="button" className="pubdb-command" onClick={openNewAsset}>
            Manual / Backfill
          </button>
        </div>
      </header>

      <section className="pubdb-setup">
        <div className="pubdb-setup__header">
          <div>
            <h2>Publisher Setup</h2>
            <p>Create or reuse the production-looking playlist instance that finished creator assets will point to.</p>
          </div>
          <div className="pubdb-setup__rule">
            {setupForm.environment === "production" ? "Production mode. Successful publish locks the URL and asset." : "Preview/test mode. No production lock."}
          </div>
        </div>
        <form className="pubdb-setup-form" onSubmit={(event) => {
          event.preventDefault();
          ensurePublisherInstance({ openPreview: true });
        }}>
          <div className="pubdb-channel-control">
            <label>
              Channel
              <select value={setupForm.platform} onChange={(event) => updateSetupForm("platform", event.target.value)}>
                {publishingChannels.map((channel) => (
                  <option key={channel.value} value={channel.value} disabled={!channel.enabled}>
                    {channel.label}{channel.enabled ? "" : " - later"}
                  </option>
                ))}
              </select>
            </label>
            <button type="button" className="pubdb-command" onClick={() => setOpenConnection(true)}>
              Connect / Sync
            </button>
          </div>
          <label className="pubdb-setup-form__wide">
            Creator job
            <select
              value={setupForm.asset_creator_job_id}
              onChange={(event) => updateSetupForm("asset_creator_job_id", event.target.value)}
              required
            >
              <option value="">Choose creator job with outputs</option>
              {displayedCreatorJobs.map((job) => (
                <option key={job.asset_creator_job_id} value={job.asset_creator_job_id}>
                  #{job.asset_creator_job_id} - {job.title || job.creator_key} ({job.output_count || 0} assets)
                </option>
              ))}
            </select>
          </label>
          <label>
            Environment
            <select value={setupForm.environment} onChange={(event) => updateSetupForm("environment", event.target.value)}>
              <option value="test">Test</option>
              <option value="production">Production</option>
            </select>
          </label>
          <label>
            Destination
            <select value={setupForm.destination_key} onChange={(event) => updateSetupForm("destination_key", event.target.value)}>
              {availableDestinations.map((destination) => (
                <option key={destination.value} value={destination.value} disabled={destination.disabled}>
                  {destination.label}{destination.environment === "test" ? " (test)" : " (production)"}
                </option>
              ))}
            </select>
          </label>
          <label>
            CTA Page
            <select value={setupForm.cta_group_id} onChange={(event) => updateSetupForm("cta_group_id", event.target.value)} required>
              <option value="">Choose CTA Page</option>
              {ctaPages.map((page) => (
                <option key={page.id} value={page.id}>
                  #{page.id} {page.label} ({page.key})
                </option>
              ))}
            </select>
          </label>
          <label>
            Instance title
            <input
              value={setupForm.instance_title}
              onChange={(event) => updateSetupForm("instance_title", event.target.value)}
              placeholder="Generated from creator job"
            />
          </label>
          <label>
            Slug
            <input
              value={setupForm.slug}
              onChange={(event) => updateSetupForm("slug", slugifyPublisher(event.target.value))}
              placeholder="test-cottage-exterior-ideas"
            />
          </label>
          <div className="pubdb-setup__summary">
            {selectedCreatorJob ? (
              <>
                <strong>Source:</strong> playlist #{selectedCreatorJob.source_id} {setupPlaylist?.title || selectedCreatorJob.title || ""}
              </>
            ) : (
              `Choose a ${channelLabel} creator job to preview the source playlist.`
            )}
          </div>
          <div className="pubdb-setup-actions">
            <button type="submit" className="pubdb-command pubdb-command--primary" disabled={setupSaving || !selectedCreatorJob || !setupForm.cta_group_id}>
              {setupSaving ? "Opening..." : "Instance Preview"}
            </button>
            <button
              type="button"
              className="pubdb-command pubdb-command--primary"
              onClick={() => preparePublisherJobs({ maxOutputs: 1 })}
              disabled={preparing || setupSaving || !selectedCreatorJob || !setupForm.cta_group_id}
            >
              {preparing ? "Sending..." : "Send 1 Missing to Scheduler"}
            </button>
            <button
              type="button"
              className="pubdb-command"
              onClick={() => preparePublisherJobs()}
              disabled={preparing || setupSaving || !selectedCreatorJob || !setupForm.cta_group_id}
            >
              {preparing ? "Sending..." : "Send Missing to Scheduler"}
            </button>
          </div>
        </form>
        {setupResult ? (
          <div className="pubdb-setup-result">
            <strong>{setupResult.mode === "created" ? "Created" : "Reused"} instance #{setupResult.playlist_instance_id}</strong>
            <a href={setupResult.player_url} target="_blank" rel="noreferrer">Open Player</a>
            <a href={`/admin/playlist-instances?q=${encodeURIComponent(setupResult.playlist_instance_id)}`}>Open In Instances</a>
            <button type="button" className="pubdb-command pubdb-command--danger" onClick={deactivatePublisherInstance} disabled={setupSaving}>
              Deactivate Test Instance
            </button>
          </div>
        ) : null}
      </section>

      {openConnection ? (
        <ConnectionDialog
          channel={selectedChannel}
          status={pinterestStatus}
          syncing={pinterestSyncing}
          connectUrl={PINTEREST_CONNECT_URL}
          onClose={() => setOpenConnection(false)}
          onRefresh={fetchPinterestStatus}
          onSync={syncPinterestBoards}
        />
      ) : null}

      {previewInstance ? (
        <InstancePreviewDialog
          instance={previewInstance}
          onClose={() => setPreviewInstance(null)}
        />
      ) : null}

      <div className="pubdb-toolbar">
        <label>
          Search
          <input value={q} onChange={(event) => setQ(event.target.value)} />
        </label>
        <button type="button" className="pubdb-command" onClick={() => fetchJobs(q)} disabled={loading}>
          Find
        </button>
        <button type="button" className="pubdb-command" onClick={() => fetchJobs("")} disabled={loading}>
          Refresh
        </button>
        <button type="button" className="pubdb-command" onClick={openImportExisting}>
          Import Existing Pins
        </button>
        <span className="pubdb-toolbar__meta">Showing {channelLabel} records</span>
      </div>

      {error ? <div className="pubdb-status pubdb-status--error">{error}</div> : null}
      {status ? <div className="pubdb-status pubdb-status--ok">{status}</div> : null}

      <section className="pubdb-summary" aria-label="Published totals by channel">
        {channelTotals.map((item) => (
          <button
            type="button"
            key={item.channel}
            className={selectedChannel === item.channel ? "pubdb-summary-card pubdb-summary-card--active" : "pubdb-summary-card"}
            onClick={() => updateSetupForm("platform", item.channel)}
          >
            <span>{item.label}</span>
            <strong>{item.published}</strong>
            <small>{item.prepared} waiting</small>
          </button>
        ))}
      </section>

      <section className="pubdb-list-controls">
        <fieldset className="pubdb-mode">
          <legend>Show</legend>
          <label>
            <input
              type="radio"
              name="publisher-list-mode"
              value="prepared"
              checked={listMode === "prepared"}
              onChange={() => setListMode("prepared")}
            />
            Jobs waiting for Scheduler
          </label>
          <label>
            <input
              type="radio"
              name="publisher-list-mode"
              value="published"
              checked={listMode === "published"}
              onChange={() => setListMode("published")}
            />
            Published records
          </label>
        </fieldset>
        <label>
          From
          <input type="date" value={dateFrom} onChange={(event) => setDateFrom(event.target.value)} />
        </label>
        <label>
          To
          <input type="date" value={dateTo} onChange={(event) => setDateTo(event.target.value)} />
        </label>
        <button type="button" className="pubdb-command" onClick={() => {
          setDateFrom("");
          setDateTo("");
        }}>
          Clear Dates
        </button>
        <span className="pubdb-toolbar__meta">
          {sortedRows.length} {listMode === "published" ? "published" : "waiting"} {channelLabel} row{sortedRows.length === 1 ? "" : "s"}
        </span>
      </section>

      <section className="pubdb-grid-wrap">
        <table className="pubdb-grid">
          {listMode === "published" ? (
            <PublishedTableHead sort={sort} onSort={changeSort} />
          ) : (
            <PreparedTableHead sort={sort} onSort={changeSort} />
          )}
          <tbody>
            {sortedRows.map((row) => (
              <tr key={row.publish_output_id} title="Double-click for details" onDoubleClick={() => {
                setDryRun(null);
                setSelectedRow(row);
              }}>
                {listMode === "published" ? (
                  <PublishedTableRow row={row} saving={saving} onPayload={previewPinterestPayload} />
                ) : (
                  <PreparedTableRow
                    row={row}
                    saving={saving}
                    onPayload={previewPinterestPayload}
                    onPublishTest={publishPinterestTest}
                    onSchedule={schedulePinterestTest}
                    onDelete={deleteDisposableTestRow}
                  />
                )}
              </tr>
            ))}
            {!loading && sortedRows.length === 0 ? (
              <tr>
                <td colSpan={listMode === "published" ? 10 : 9} className="pubdb-empty">
                  {listMode === "published"
                    ? `No published ${channelLabel} records in this view.`
                    : "No prepared jobs waiting in this view. Choose a creator job above, then send one or all to Scheduler."}
                </td>
              </tr>
            ) : null}
          </tbody>
        </table>
      </section>

      {openCreate ? (
        <AssetDialog
          form={form}
          playlists={playlists}
          instances={instances}
          landingPages={landingPages}
          selectedPlaylist={selectedPlaylist}
          saving={saving}
          onClose={() => setOpenCreate(false)}
          onSubmit={createAsset}
          onUpdate={updateForm}
          onUsePlaylistText={usePlaylistText}
        />
      ) : null}

      {openImport ? (
        <ImportDialog
          value={importText}
          saving={saving}
          onChange={setImportText}
          onClose={() => setOpenImport(false)}
          onSubmit={importExistingPins}
        />
      ) : null}

      {selectedRow ? (
        <RowDialog
          row={selectedRow}
          saving={saving}
          dryRun={dryRun}
          onClose={() => setSelectedRow(null)}
          onMarkPublished={() => markPublished(selectedRow)}
          onPreviewPayload={() => previewPinterestPayload(selectedRow)}
          onPublishTest={() => publishPinterestTest(selectedRow)}
          onScheduleTest={() => schedulePinterestTest(selectedRow)}
          onDeleteTest={() => deleteDisposableTestRow(selectedRow)}
        />
      ) : null}
    </div>
  );
}

function ImportDialog({ value, saving, onChange, onClose, onSubmit }) {
  return (
    <div className="pubdb-modal-backdrop" role="presentation">
      <div className="pubdb-modal" role="dialog" aria-modal="true" aria-label="Import existing Pinterest pins">
        <header className="pubdb-modal__header">
          <h2>Import Existing Pins</h2>
          <button type="button" onClick={onClose}>Close</button>
        </header>
        <form className="pubdb-import" onSubmit={onSubmit}>
          <p>Paste one pin per line. Use pipes, tabs, or commas.</p>
          <code>{importHelp}</code>
          <textarea
            rows={10}
            value={value}
            onChange={(event) => onChange(event.target.value)}
            placeholder={"37 | 642 | https://www.pinterest.com/pin/... | Front door makeover | Exterior Paint | 2026-06-08"}
          />
          <div className="pubdb-modal__actions">
            <button type="button" onClick={onClose}>Cancel</button>
            <button type="submit" className="pubdb-command--primary" disabled={saving}>
              {saving ? "Importing..." : "Import"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

function InstancePreviewDialog({ instance, onClose }) {
  return (
    <div className="pubdb-modal-backdrop pubdb-modal-backdrop--preview" role="presentation">
      <div className="pubdb-modal pubdb-modal--preview" role="dialog" aria-modal="true" aria-label="Playlist instance preview">
        <header className="pubdb-modal__header">
          <div>
            <h2>Instance Preview</h2>
            <p>Instance #{instance.playlist_instance_id}</p>
          </div>
          <div className="pubdb-preview-actions">
            <a className="pubdb-command" href={instance.player_url} target="_blank" rel="noreferrer">
              Open Player
            </a>
            <button type="button" onClick={onClose}>Close</button>
          </div>
        </header>
        <iframe
          className="pubdb-preview-frame"
          title={`Instance ${instance.playlist_instance_id} preview`}
          src={instance.player_url}
        />
      </div>
    </div>
  );
}

function SortableTh({ label, keyName, sort, onSort }) {
  const active = sort.key === keyName;
  const marker = active ? (sort.direction === "asc" ? "▲" : "▼") : "";
  return (
    <th>
      <button type="button" className={active ? "pubdb-sort pubdb-sort--active" : "pubdb-sort"} onClick={() => onSort(keyName)}>
        <span>{label}</span>
        <span aria-hidden="true">{marker}</span>
      </button>
    </th>
  );
}

function PublishedTableHead({ sort, onSort }) {
  return (
    <thead>
      <tr>
        <SortableTh label="Published" keyName="published_at" sort={sort} onSort={onSort} />
        <SortableTh label="Channel" keyName="channel_key" sort={sort} onSort={onSort} />
        <SortableTh label="Env" keyName="environment" sort={sort} onSort={onSort} />
        <SortableTh label="Type" keyName="output_type" sort={sort} onSort={onSort} />
        <SortableTh label="Asset" keyName="library_asset_id" sort={sort} onSort={onSort} />
        <SortableTh label="Playlist" keyName="playlist_title" sort={sort} onSort={onSort} />
        <SortableTh label="Title" keyName="title" sort={sort} onSort={onSort} />
        <SortableTh label="Destination" keyName="destination_url" sort={sort} onSort={onSort} />
        <SortableTh label="Live" keyName="external_url" sort={sort} onSort={onSort} />
        <th>Actions</th>
      </tr>
    </thead>
  );
}

function PreparedTableHead({ sort, onSort }) {
  return (
    <thead>
      <tr>
        <SortableTh label="Created" keyName="created_at" sort={sort} onSort={onSort} />
        <SortableTh label="Job" keyName="publish_job_id" sort={sort} onSort={onSort} />
        <SortableTh label="Channel" keyName="channel_key" sort={sort} onSort={onSort} />
        <SortableTh label="Type" keyName="output_type" sort={sort} onSort={onSort} />
        <SortableTh label="Asset" keyName="library_asset_id" sort={sort} onSort={onSort} />
        <th>Permission</th>
        <SortableTh label="Status" keyName="status" sort={sort} onSort={onSort} />
        <SortableTh label="Playlist" keyName="playlist_title" sort={sort} onSort={onSort} />
        <th>Actions</th>
      </tr>
    </thead>
  );
}

function PublishedTableRow({ row, saving, onPayload }) {
  return (
    <>
      <td>{row.published_at || "-"}</td>
      <td>{channelDisplayLabel(row.channel_key || row.platform)}</td>
      <td>{row.environment || "-"}</td>
      <td>{pinTypeLabel(row.output_type)}</td>
      <td>#{row.library_asset_id || row.publish_output_id}</td>
      <td>#{row.source_id} {row.playlist_title || row.job_title}</td>
      <td className="pubdb-cell-wrap">{row.title || "-"}</td>
      <td className="pubdb-cell-wrap">{shortUrl(row.destination_url || row.tracking_url)}</td>
      <td>
        {row.external_url ? (
          <a href={row.external_url} target="_blank" rel="noreferrer">
            Open
          </a>
        ) : "-"}
      </td>
      <td className="pubdb-row-actions" onClick={(event) => event.stopPropagation()}>
        <button type="button" onClick={() => onPayload(row)} disabled={saving}>
          Payload
        </button>
        {row.external_url ? (
          <a className="pubdb-mini-link" href={row.external_url} target="_blank" rel="noreferrer">
            View
          </a>
        ) : null}
      </td>
    </>
  );
}

function PreparedTableRow({ row, saving, onPayload, onPublishTest, onSchedule, onDelete }) {
  return (
    <>
      <td>{row.created_at || "-"}</td>
      <td>Job #{row.publish_job_id}<br />Row #{row.publish_output_id}</td>
      <td>{channelDisplayLabel(row.channel_key || row.platform)}</td>
      <td>{pinTypeLabel(row.output_type)}</td>
      <td>#{row.library_asset_id || "-"}</td>
      <td><PermissionStatus {...permissionProps(row)} /></td>
      <td>{row.status}</td>
      <td className="pubdb-cell-wrap">#{row.source_id} {row.playlist_title || row.job_title}</td>
      <td className="pubdb-row-actions" onClick={(event) => event.stopPropagation()}>
        <button type="button" onClick={() => onPayload(row)} disabled={saving}>
          Payload
        </button>
        {row.environment === "production" ? null : (
          <button type="button" className="pubdb-command--primary" onClick={() => onPublishTest(row)} disabled={saving}>
            Publish Test
          </button>
        )}
        <button type="button" onClick={() => onSchedule(row)} disabled={saving}>
          To Scheduler
        </button>
        {row.environment === "test" ? (
          <button type="button" className="pubdb-command--danger" onClick={() => onDelete(row)} disabled={saving}>
            Delete Test
          </button>
        ) : null}
      </td>
    </>
  );
}

function ConnectionDialog({ channel: channelKey, status, syncing, connectUrl, onClose, onRefresh, onSync }) {
  const title = channelKey === "pinterest" ? "Pinterest Connection" : "Channel Connection";
  if (channelKey !== "pinterest") {
    return (
      <div className="pubdb-modal-backdrop" role="presentation">
        <div className="pubdb-modal pubdb-modal--small" role="dialog" aria-modal="true" aria-label={title}>
          <header className="pubdb-modal__header">
            <h2>{title}</h2>
            <button type="button" onClick={onClose}>Close</button>
          </header>
          <div className="pubdb-connection-empty">
            This channel is not wired yet.
          </div>
        </div>
      </div>
    );
  }

  const channelInfo = status?.channel || {};
  const auth = status?.auth || {};
  const destinations = status?.destinations || {};
  const scopes = auth.granted_scopes || status?.scopes_requested || [];
  const requestedScopes = status?.scopes_requested || [];
  const missingScopes = Array.isArray(requestedScopes)
    ? requestedScopes.filter((scope) => !Array.isArray(scopes) || !scopes.includes(scope))
    : [];

  return (
    <div className="pubdb-modal-backdrop" role="presentation">
      <div className="pubdb-modal" role="dialog" aria-modal="true" aria-label={title}>
        <header className="pubdb-modal__header">
          <div>
            <h2>{title}</h2>
            <p>OAuth, granted scopes, synced boards, and test-publish readiness.</p>
          </div>
          <button type="button" onClick={onClose}>Close</button>
        </header>
        <section className="pubdb-pinterest pubdb-pinterest--modal">
          {missingScopes.length ? (
            <div className="pubdb-alert pubdb-alert--error">
              Missing Pinterest scope{missingScopes.length === 1 ? "" : "s"}: {missingScopes.join(", ")}. Click Connect Pinterest again to request the full permission set.
            </div>
          ) : null}
          <div className="pubdb-pinterest__actions">
            <a className="pubdb-command pubdb-command--primary" href={connectUrl}>Connect Pinterest</a>
            <button type="button" className="pubdb-command" onClick={onSync} disabled={syncing || channelInfo.status !== "connected"}>
              {syncing ? "Syncing..." : "Sync Pinterest Boards"}
            </button>
            <button type="button" className="pubdb-command" onClick={onRefresh}>Refresh</button>
          </div>
          <dl className="pubdb-pinterest__details">
            <dt>Status</dt>
            <dd>{channelInfo.status || auth.status || "unknown"}</dd>
            <dt>Granted scopes</dt>
            <dd>{Array.isArray(scopes) && scopes.length ? scopes.join(", ") : "Not connected yet"}</dd>
            <dt>Expires</dt>
            <dd>{channelInfo.auth_expires_at || "-"}</dd>
            <dt>Last auth error</dt>
            <dd>{auth.last_auth_error || "-"}</dd>
          </dl>
          <div className="pubdb-board-sync">
            {["test", "production"].map((environment) => {
              const destination = destinations[environment] || {};
              return (
                <div className="pubdb-board-sync__item" key={environment}>
                  <strong>{environment === "test" ? "Test" : "Production"}</strong>
                  <span>{destination.board_name || "-"}</span>
                  <span>Board ID: {destination.board_id || "missing"}</span>
                  {destination.board_url ? <a href={destination.board_url} target="_blank" rel="noreferrer">Open board</a> : <span>No board URL</span>}
                </div>
              );
            })}
          </div>
        </section>
      </div>
    </div>
  );
}

function rowMatchesChannel(row, channel) {
  if (!channel) return true;
  const haystack = [
    row.platform,
    row.channel_key,
    row.output_type,
    row.asset_type,
    row.job_title,
  ].join(" ").toLowerCase();
  return haystack.includes(String(channel).toLowerCase());
}

function isPublishedRow(row) {
  const status = String(row.status || "").toLowerCase();
  return Boolean(row.published_at || row.external_url || status === "published" || status === "posted" || status === "test_published");
}

function rowInDateRange(row, mode, from, to) {
  const value = mode === "published" ? row.published_at : row.created_at;
  if (!from && !to) return true;
  const timestamp = Date.parse(value || "");
  if (!timestamp) return false;
  if (from) {
    const fromTime = Date.parse(`${from}T00:00:00`);
    if (timestamp < fromTime) return false;
  }
  if (to) {
    const toTime = Date.parse(`${to}T23:59:59`);
    if (timestamp > toTime) return false;
  }
  return true;
}

function buildChannelTotals(rows) {
  const keys = new Set(publishingChannels.filter((channel) => channel.enabled).map((channel) => channel.value));
  rows.forEach((row) => {
    if (row.platform) keys.add(String(row.platform));
    if (row.channel_key) {
      const key = String(row.channel_key).toLowerCase().includes("pinterest") ? "pinterest" : String(row.channel_key);
      keys.add(key);
    }
  });

  return Array.from(keys).map((channel) => {
    const matching = rows.filter((row) => rowMatchesChannel(row, channel));
    return {
      channel,
      label: channelDisplayLabel(channel),
      published: matching.filter(isPublishedRow).length,
      prepared: matching.filter((row) => !isPublishedRow(row)).length,
    };
  });
}

function channelDisplayLabel(value) {
  const raw = String(value || "").toLowerCase();
  if (raw.includes("pinterest")) return "Pinterest";
  if (raw.includes("youtube")) return "YouTube";
  if (raw.includes("instagram")) return "Instagram";
  return String(value || "Channel").replace(/[_-]+/g, " ").replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function pinTypeLabel(value) {
  const raw = String(value || "");
  return raw
    .replace(/^pinterest[._-]/i, "")
    .replace(/_/g, " ")
    .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function shortUrl(value) {
  const raw = String(value || "");
  if (!raw) return "-";
  return raw.length > 54 ? `${raw.slice(0, 51)}...` : raw;
}

function jobMatchesChannel(job, channel) {
  if (!channel) return true;
  const haystack = [
    job.platform,
    job.channel_key,
    job.creator_key,
    job.title,
  ].join(" ").toLowerCase();
  return haystack.includes(String(channel).toLowerCase());
}

function sortValue(row, key) {
  if (key === "publish_output_id" || key === "publish_job_id" || key === "source_id" || key === "library_asset_id") {
    return Number(row[key] || 0);
  }
  if (key === "created_at" || key === "published_at") {
    return Date.parse(row[key] || "") || 0;
  }
  if (key === "playlist_title") {
    return String(row.playlist_title || row.job_title || "").toLowerCase();
  }
  if (key === "external_url") {
    return row.external_url ? 1 : 0;
  }
  return String(row[key] || "").toLowerCase();
}

function parseMetadata(value) {
  if (!value) return {};
  if (typeof value === "object") return value;
  try {
    const parsed = JSON.parse(value);
    return parsed && typeof parsed === "object" ? parsed : {};
  } catch {
    return {};
  }
}

function parsePinImport(text) {
  return String(text || "")
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean)
    .map((line) => {
      const delimiter = line.includes("|") ? "|" : line.includes("\t") ? "\t" : ",";
      const parts = line.split(delimiter).map((part) => part.trim());
      const hasLibraryId = /^\d+$/.test(parts[1] || "") && /^https?:\/\//i.test(parts[2] || "");
      if (hasLibraryId) {
        return {
          playlist_id: Number(parts[0] || 0),
          library_asset_id: Number(parts[1] || 0),
          external_url: parts[2] || "",
          title: parts[3] || "",
          board: parts[4] || "",
          published_at: parts[5] || "",
          playlist_instance_id: parts[6] ? Number(parts[6]) : null,
        };
      }
      return {
        playlist_id: Number(parts[0] || 0),
        external_url: parts[1] || "",
        title: parts[2] || "",
        board: parts[3] || "",
        published_at: parts[4] || "",
        playlist_instance_id: parts[5] ? Number(parts[5]) : null,
      };
    })
    .filter((entry) => entry.playlist_id > 0 && entry.external_url);
}

function publisherTitleFromJob(job, playlists = []) {
  if (!job) return "";
  const playlistId = Number(job.source_id || 0);
  const playlist = playlists.find((item) => Number(item.playlist_id || 0) === playlistId);
  const raw = playlist?.headline || playlist?.title || job.title || `Playlist ${playlistId}`;
  return String(raw || "")
    .replace(/^Pinterest:\s*/i, "")
    .replace(/^Composite Pin:\s*/i, "")
    .trim();
}

function slugifyPublisher(value) {
  return String(value || "")
    .trim()
    .toLowerCase()
    .replace(/&/g, " and ")
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");
}

function publisherInstanceMarker(environment, platform, destinationKey, jobId) {
  return [
    `publisher_environment=${environment}`,
    `publisher_platform=${platform}`,
    `publisher_destination=${destinationKey}`,
    `asset_creator_job_id=${jobId}`,
  ].join("; ");
}

function AssetDialog({
  form,
  playlists,
  instances,
  landingPages,
  selectedPlaylist,
  saving,
  onClose,
  onSubmit,
  onUpdate,
  onUsePlaylistText,
}) {
  return (
    <div className="pubdb-modal-backdrop" role="presentation">
      <div className="pubdb-modal" role="dialog" aria-modal="true" aria-label="Create publishing job">
        <header className="pubdb-modal__header">
          <h2>Create Manual Publishing Job</h2>
          <button type="button" onClick={onClose}>Close</button>
        </header>
        <form className="pubdb-form" onSubmit={onSubmit}>
          <label>
            Asset type
            <select value={form.asset_type} onChange={(event) => onUpdate("asset_type", event.target.value)}>
              {assetTypes.map((type) => (
                <option key={type.value} value={type.value} disabled={!type.enabled}>
                  {type.label}{type.enabled ? "" : " (not wired yet)"}
                </option>
              ))}
            </select>
          </label>

          <label>
            Playlist
            <select
              value={form.playlist_id}
              onChange={(event) => onUpdate("playlist_id", event.target.value)}
              required
            >
              <option value="">Choose playlist</option>
              {playlists.map((playlist) => (
                <option key={playlist.playlist_id} value={playlist.playlist_id}>
                  #{playlist.playlist_id} {playlist.title}
                </option>
              ))}
            </select>
          </label>

          <label>
            Instance
            <select
              value={form.playlist_instance_id}
              onChange={(event) => onUpdate("playlist_instance_id", event.target.value)}
              disabled={!instances.length}
            >
              <option value="">Default active instance</option>
              {instances.map((instance) => (
                <option key={instance.playlist_instance_id} value={instance.playlist_instance_id}>
                  #{instance.playlist_instance_id} {instance.display_title || instance.instance_name}
                </option>
              ))}
            </select>
          </label>

          <label>
            Landing page
            <select
              value={form.landing_page_id}
              onChange={(event) => onUpdate("landing_page_id", event.target.value)}
            >
              <option value="">None - fallback to player URL</option>
              {landingPages.map((page) => (
                <option key={page.id} value={page.id}>
                  #{page.id} /s/{page.slug} ({page.status})
                </option>
              ))}
            </select>
          </label>

          <div className="pubdb-form__inline pubdb-form__wide">
            <label>
              Title
              <input
                value={form.title}
                onChange={(event) => onUpdate("title", event.target.value)}
                placeholder="Leave blank to use playlist/share title"
              />
            </label>
            <button type="button" onClick={onUsePlaylistText} disabled={!selectedPlaylist}>
              Use Playlist Text
            </button>
          </div>

          <label className="pubdb-form__wide">
            Description
            <textarea
              rows={3}
              value={form.description}
              onChange={(event) => onUpdate("description", event.target.value)}
            />
          </label>

          <label>
            Board
            <input
              value={form.board}
              onChange={(event) => {
                onUpdate("board", event.target.value);
                onUpdate("board_name", event.target.value);
              }}
            />
          </label>

          <label>
            Board URL
            <input value={form.board_url} onChange={(event) => onUpdate("board_url", event.target.value)} />
          </label>

          <label>
            Board slug
            <input value={form.board_slug} onChange={(event) => onUpdate("board_slug", event.target.value)} />
          </label>

          <label>
            Existing live URL
            <input value={form.external_url} onChange={(event) => onUpdate("external_url", event.target.value)} />
          </label>

          <label>
            Library asset ID
            <input
              value={form.library_asset_id}
              onChange={(event) => onUpdate("library_asset_id", event.target.value)}
              inputMode="numeric"
              placeholder="Optional"
            />
          </label>

          <label>
            Published at
            <input
              type="datetime-local"
              value={form.published_at}
              onChange={(event) => onUpdate("published_at", event.target.value)}
            />
          </label>

          <label className="pubdb-form__wide">
            Notes
            <textarea rows={2} value={form.notes} onChange={(event) => onUpdate("notes", event.target.value)} />
          </label>

          <div className="pubdb-modal__actions pubdb-form__wide">
            <button type="button" onClick={onClose}>Cancel</button>
            <button type="submit" className="pubdb-command--primary" disabled={saving || !form.playlist_id}>
              {saving ? "Creating..." : "Create"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

function RowDialog({ row, saving, dryRun, onClose, onMarkPublished, onPreviewPayload, onPublishTest, onScheduleTest, onDeleteTest }) {
  return (
    <div className="pubdb-modal-backdrop" role="presentation" onClick={onClose}>
      <div className="pubdb-modal pubdb-modal--small" role="dialog" aria-modal="true" aria-label="Publishing job" onClick={(event) => event.stopPropagation()}>
        <header className="pubdb-modal__header">
          <h2>Output #{row.publish_output_id}</h2>
          <button type="button" onClick={onClose}>Close</button>
        </header>
        <dl className="pubdb-details">
          <dt>Channel</dt><dd>{row.channel_key}</dd>
          <dt>Environment</dt><dd>{row.environment || "test"}</dd>
          <dt>Type</dt><dd>{row.output_type}</dd>
          <dt>Library ID</dt><dd>{row.library_asset_id || "-"}</dd>
          <dt>Permission</dt><dd><PermissionStatus {...permissionProps(row)} showLabel /></dd>
          <dt>Status</dt><dd>{row.status}</dd>
          <dt>Board</dt><dd>{row.board_name || PINTEREST_BOARD.board_name}</dd>
          <dt>Board slug</dt><dd>{row.board_slug || PINTEREST_BOARD.board_slug}</dd>
          <dt>Board ID</dt><dd>{row.board_id || "Pending Pinterest API board sync"}</dd>
          <dt>Playlist</dt><dd>#{row.source_id} {row.playlist_title || row.job_title}</dd>
          <dt>Tracking code</dt><dd>{row.tracking_code || "-"}</dd>
          <dt>Tracking URL</dt>
          <dd>{row.tracking_url ? <a href={row.tracking_url} target="_blank" rel="noreferrer">{row.tracking_url}</a> : "-"}</dd>
          <dt>Destination URL</dt>
          <dd>{row.destination_url ? <a href={row.destination_url} target="_blank" rel="noreferrer">{row.destination_url}</a> : "-"}</dd>
          <dt>Live URL</dt>
          <dd>{row.external_url ? <a href={row.external_url} target="_blank" rel="noreferrer">{row.external_url}</a> : "-"}</dd>
          <dt>Published at</dt><dd>{row.published_at || "-"}</dd>
        </dl>
        <div className="pubdb-modal__actions">
          <button type="button" onClick={onClose}>Close</button>
          <button type="button" onClick={onPreviewPayload} disabled={saving}>
            Preview Pinterest Payload
          </button>
          {row.environment === "production" ? null : (
            <button type="button" className="pubdb-command--primary" onClick={onPublishTest} disabled={saving}>
              Publish Test Pin
            </button>
          )}
          <button type="button" onClick={onScheduleTest} disabled={saving}>
            {row.environment === "production" ? "Send to Scheduler" : "Schedule Test Pin"}
          </button>
          {row.environment === "test" ? (
            <button type="button" className="pubdb-command--danger" onClick={onDeleteTest} disabled={saving}>
              Delete Test Row
            </button>
          ) : null}
          {!row.external_url ? (
            <button type="button" className="pubdb-command--primary" onClick={onMarkPublished} disabled={saving}>
              Mark Published
            </button>
          ) : null}
        </div>
        {dryRun ? (
          <div className="pubdb-dryrun">
            <h3>Dry-Run Payload: {dryRun.environment}</h3>
            <div className={dryRun.readiness?.ready ? "pubdb-dryrun__ready" : "pubdb-dryrun__blocked"}>
              {dryRun.readiness?.ready ? "Ready for ColorFix API Test" : `Blocked: ${(dryRun.readiness?.errors || []).join("; ")}`}
            </div>
            <pre>{JSON.stringify(dryRun.payload || {}, null, 2)}</pre>
          </div>
        ) : null}
      </div>
    </div>
  );
}

function permissionProps(item = {}) {
  return {
    status: item.photo_permission_status,
    photoLibraryId: item.permission_photo_library_id,
    clientId: item.client_id,
    clientName: item.client_name,
    clientEmail: item.client_email,
  };
}
