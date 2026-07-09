import { useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import { dateTimeSortValue, formatDateTime } from "@helpers/time";
import PermissionStatus from "@components/PermissionStatus";
import "./admin-publishing.css";

const JOBS_URL = `${API_FOLDER}/v2/admin/packager/pinterest/list.php`;
const SAVE_PINTEREST_URL = `${API_FOLDER}/v2/admin/publishing/pinterest/save.php`;
const PREPARE_PINTEREST_URL = `${API_FOLDER}/v2/admin/packager/pinterest/package-from-creator.php`;
const MARK_PINTEREST_URL = `${API_FOLDER}/v2/admin/publishing/pinterest/mark-published.php`;
const PINTEREST_AUTH_STATUS_URL = `${API_FOLDER}/v2/admin/publishing/pinterest/auth-status.php`;
const PINTEREST_CONNECT_URL = `${API_FOLDER}/pinterest/connect`;
const PINTEREST_SYNC_BOARDS_URL = `${API_FOLDER}/v2/admin/publishing/pinterest/sync-boards.php`;
const YOUTUBE_AUTH_STATUS_URL = `${API_FOLDER}/v2/admin/publishing/youtube/auth-status.php`;
const YOUTUBE_CONNECT_URL = `${API_FOLDER}/youtube/connect`;
const YOUTUBE_PUBLISHER_CONNECT_URL = `${YOUTUBE_CONNECT_URL}?return=${encodeURIComponent("/admin/publisher")}`;
const PINTEREST_PUBLISH_TEST_URL = `${API_FOLDER}/v2/admin/publishing/pinterest/publish-test.php`;
const PLAYLISTS_URL = `${API_FOLDER}/v2/admin/playlists/list.php`;
const INSTANCES_URL = `${API_FOLDER}/v2/admin/playlist-instances/list.php`;
const INSTANCE_SAVE_URL = `${API_FOLDER}/v2/admin/playlist-instances/save.php`;
const INSTANCE_DEACTIVATE_TEST_URL = `${API_FOLDER}/v2/admin/playlist-instances/deactivate-publisher-test.php`;
const LANDING_PAGES_URL = `${API_FOLDER}/v2/admin/landing-pages/list.php`;
const CREATOR_JOBS_URL = `${API_FOLDER}/v2/admin/asset-creators/list.php`;
const CTA_PAGES_URL = `${API_FOLDER}/v2/admin/cta-groups/list.php`;
const SCHEDULER_SCHEDULE_URL = `${API_FOLDER}/v2/admin/publication-scheduler/schedule.php`;
const SCHEDULER_SCHEDULE_JOB_URL = `${API_FOLDER}/v2/admin/publication-scheduler/schedule-package-batch.php`;
const SCHEDULER_DELETE_UNSCHEDULED_URL = `${API_FOLDER}/v2/admin/publication-scheduler/delete-unscheduled.php`;
const SCHEDULER_PUBLISH_NOW_URL = `${API_FOLDER}/v2/admin/publication-scheduler/publish-now.php`;

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
  { value: "youtube", label: "YouTube", enabled: true },
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
  const [youtubeStatus, setYoutubeStatus] = useState(null);
  const [pinterestSyncing, setPinterestSyncing] = useState(false);
  const [openConnection, setOpenConnection] = useState(false);
  const [previewInstance, setPreviewInstance] = useState(null);
  const [listMode, setListMode] = useState("published");
  const [channelFilter, setChannelFilter] = useState("pinterest");
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");

  const selectedChannel = channelFilter || "pinterest";
  const channelLabel = publishingChannels.find((channel) => channel.value === selectedChannel)?.label || selectedChannel;

  const rows = useMemo(() => {
    return jobs.flatMap((job) =>
      (job.outputs || []).map((output) => hydrateDisplayRow(job, output))
    );
  }, [jobs]);

  const channelRows = useMemo(() => {
    return rows.filter((row) => rowMatchesChannel(row, selectedChannel));
  }, [rows, selectedChannel]);

  const channelTotals = useMemo(() => buildChannelTotals(rows), [rows]);
  const channelOptions = useMemo(() => buildChannelOptions(channelTotals), [channelTotals]);

  const visibleRows = useMemo(() => {
    return channelRows.filter((row) => {
      if (!isPublisherVisibleRow(row)) return false;
      return rowInDateRange(row, "published", dateFrom, dateTo);
    });
  }, [channelRows, dateFrom, dateTo]);

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
    const youtubeAuthStatus = params.get("youtube_auth");
    const message = params.get("message");
    if (authStatus) {
      if (authStatus === "connected") {
        setStatus(message || "Pinterest connected.");
      } else {
        setError(message || `Pinterest OAuth ${authStatus}.`);
      }
      window.history.replaceState({}, "", window.location.pathname);
    }
    if (youtubeAuthStatus) {
      setChannelFilter("youtube");
      setOpenConnection(true);
      if (youtubeAuthStatus === "connected") {
        setStatus(message || "YouTube connected.");
      } else {
        setError(message || `YouTube OAuth ${youtubeAuthStatus}.`);
      }
      window.history.replaceState({}, "", window.location.pathname);
    }
    fetchPlaylists();
    fetchLandingPages();
    fetchCreatorJobs();
    fetchCtaPages();
    fetchPinterestStatus();
    fetchYoutubeStatus();
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
      const items = data.items || [];
      setJobs(items);
      return items;
    } catch (err) {
      setError(err?.message || "Failed to load publishing records");
      return null;
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

  async function fetchYoutubeStatus() {
    try {
      const res = await fetch(`${YOUTUBE_AUTH_STATUS_URL}?_=${Date.now()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load YouTube status");
      setYoutubeStatus(data.item || null);
    } catch (err) {
      setError(err?.message || "Failed to load YouTube status");
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
      const existing = findPublisherInstance(existingData.items || [], {
        ctaGroupId,
        platform,
        destinationKey,
        creatorJobId: job.asset_creator_job_id,
      });

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

  async function packageForScheduler(options = {}) {
    const maxOutputs = Number(options.maxOutputs || 0);
    setPreparing(true);
    setError("");
    setStatus("");
    try {
      if (setupForm.platform !== "pinterest") throw new Error(`${channelLabel} packager setup is not wired yet.`);
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
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to package creator outputs");
      const item = data.item || {};
      const scope = maxOutputs > 0 ? `first ${maxOutputs}` : "all";
      const batchId = item.package_batch_id || item.publishing_job_id || item.publish_job_id;
      const scheduleResult = await sendPackageBatchToScheduler(batchId);
      const scheduledText = scheduleResult.enqueued > 0
        ? ` Added ${scheduleResult.enqueued} missing.`
        : "";
      const skippedText = scheduleResult.already_waiting > 0
        ? ` ${scheduleResult.already_waiting} already waiting.`
        : "";
      const blockedText = scheduleResult.skipped > 0
        ? ` ${scheduleResult.skipped} skipped.`
        : "";
      setStatus(`Packaged ${scope} creator output${maxOutputs === 1 ? "" : "s"} and sent missing packages to Scheduler for batch #${batchId}: ${item.created_packages ?? item.created_outputs ?? 0} new, ${item.reused_packages ?? item.reused_outputs ?? 0} reused.${scheduledText}${skippedText}${blockedText}`);
      setQ("");
      await fetchJobs("");
    } catch (err) {
      setError(err?.message || "Failed to package outputs for scheduler");
    } finally {
      setPreparing(false);
    }
  }

  async function sendPackageBatchToScheduler(packageBatchId) {
    const jobId = Number(packageBatchId || 0);
    if (jobId <= 0) return { enqueued: 0, already_waiting: 0, skipped: 0 };
    const res = await fetch(SCHEDULER_SCHEDULE_JOB_URL, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        package_batch_id: jobId,
        priority: 100,
      }),
    });
    const data = await res.json();
    if (!res.ok || !data?.ok) throw new Error(data?.error || `Failed to send package batch #${jobId} to Scheduler`);
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

  async function retryPublish(row) {
    const packageId = Number(row.package_id || row.publish_output_id || 0);
    if (packageId <= 0) {
      setError("Missing package ID for retry.");
      return;
    }
    const ok = window.confirm(`Retry publishing package #${packageId}?`);
    if (!ok) return;

    setSaving(true);
    setError("");
    setStatus("");
    try {
      const payload = {
        package_id: packageId,
        worker_id: "admin-publisher-retry",
      };
      if (row.queue_item_id) payload.queue_item_id = Number(row.queue_item_id);

      const res = await fetch(SCHEDULER_PUBLISH_NOW_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Retry failed");

      const item = data.item || {};
      if (item.success === false || String(item.status || "").toLowerCase() === "failed") {
        const message = item.error_message || item.error_code || "Retry failed";
        setError(`Retry failed: ${message}`);
      } else {
        setStatus(`Retry ${item.status || "completed"}${item.published_url ? `: ${item.published_url}` : ""}`);
      }
      const refreshed = await fetchJobs();
      const updatedRow = findPackageRow(refreshed, packageId);
      if (updatedRow) setSelectedRow(updatedRow);
    } catch (err) {
      setError(err?.message || "Retry failed");
    } finally {
      setSaving(false);
    }
  }

  async function deleteDisposableTestRow(row) {
    const ok = window.confirm(
      `Delete test publishing row #${row.publish_output_id}? Delete the Pinterest test-board pin manually first if needed. This removes the ColorFix test publish record and may delete its orphaned publisher instance after the last package in that test batch is removed. Production publishes are blocked.`
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
      const deletedInstances = Array.isArray(item.deleted_playlist_instance_ids) ? item.deleted_playlist_instance_ids : [];
      const instanceMessage = deletedInstances.length
        ? ` Deleted orphan publisher instance #${deletedInstances.join(", #")}.`
        : "";
      setStatus(`Deleted test publishing row #${row.publish_output_id}.${instanceMessage}`);
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
          <h1>Published Packages</h1>
        </div>
        <div className="pubdb-header__actions">
          <a className="pubdb-command" href="/admin/scheduler">
            Back to Scheduler
          </a>
          <button type="button" className="pubdb-command" onClick={openNewAsset}>
            Manual / Backfill
          </button>
        </div>
      </header>

      <div className="pubdb-toolbar">
        <label>
          Channel
          <select value={selectedChannel} onChange={(event) => setChannelFilter(event.target.value)}>
            {channelOptions.map((channel) => (
              <option key={channel.value} value={channel.value}>
                {channel.label}
              </option>
            ))}
          </select>
        </label>
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
        <button type="button" className="pubdb-command" onClick={() => setOpenConnection(true)}>
          Connect Channel
        </button>
        <span className="pubdb-toolbar__meta">YouTube: {youtubeConnectionLabel(youtubeStatus)}</span>
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
            onClick={() => setChannelFilter(item.channel)}
          >
            <span>{item.label}</span>
            <strong>{item.published}</strong>
            <small>{item.errors ? `${item.errors} error${item.errors === 1 ? "" : "s"}` : "published"}</small>
          </button>
        ))}
      </section>

      <section className="pubdb-list-controls">
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
          {sortedRows.length} {channelLabel} published/error record{sortedRows.length === 1 ? "" : "s"}
        </span>
      </section>

      {openConnection ? (
        <ConnectionDialog
          channel={selectedChannel}
          status={selectedChannel === "youtube" ? youtubeStatus : pinterestStatus}
          syncing={selectedChannel === "pinterest" ? pinterestSyncing : false}
          connectUrl={selectedChannel === "youtube" ? YOUTUBE_PUBLISHER_CONNECT_URL : PINTEREST_CONNECT_URL}
          onClose={() => setOpenConnection(false)}
          onRefresh={selectedChannel === "youtube" ? fetchYoutubeStatus : fetchPinterestStatus}
          onSync={syncPinterestBoards}
        />
      ) : null}

      <section className="pubdb-grid-wrap">
        <table className="pubdb-grid">
          <PublishedTableHead sort={sort} onSort={changeSort} />
          <tbody>
            {sortedRows.map((row) => (
              <tr key={row.publish_output_id} title="Double-click for details" onDoubleClick={() => {
                setSelectedRow(row);
              }}>
                <PublishedTableRow
                  row={row}
                  saving={saving}
                  onDetails={() => {
                    setSelectedRow(row);
                  }}
                />
              </tr>
            ))}
            {!loading && sortedRows.length === 0 ? (
              <tr>
                <td colSpan={12} className="pubdb-empty">
                  No published or errored {channelLabel} records in this view.
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
          onClose={() => setSelectedRow(null)}
          onMarkPublished={() => markPublished(selectedRow)}
          onPublishTest={() => publishPinterestTest(selectedRow)}
          onScheduleTest={() => schedulePinterestTest(selectedRow)}
          onDeleteTest={() => deleteDisposableTestRow(selectedRow)}
          onRetry={() => retryPublish(selectedRow)}
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
        <SortableTh label="Published Local" keyName="published_at" sort={sort} onSort={onSort} />
        <SortableTh label="Status" keyName="status" sort={sort} onSort={onSort} />
        <SortableTh label="Channel" keyName="channel_key" sort={sort} onSort={onSort} />
        <SortableTh label="Env" keyName="environment" sort={sort} onSort={onSort} />
        <SortableTh label="Type" keyName="output_type" sort={sort} onSort={onSort} />
        <SortableTh label="Asset" keyName="library_asset_id" sort={sort} onSort={onSort} />
        <SortableTh label="Playlist" keyName="playlist_title" sort={sort} onSort={onSort} />
        <SortableTh label="Instance" keyName="playlist_instance_id" sort={sort} onSort={onSort} />
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

function PublishedTableRow({ row, saving, onDetails }) {
  const errored = isErroredRow(row);
  return (
    <>
      <td>{errored && !row.published_at ? "-" : formatPublisherTime(row.published_at)}</td>
      <td>
        <span className={errored ? "pubdb-status-chip pubdb-status-chip--error" : "pubdb-status-chip"}>
          {errored ? "Error" : row.status || "Published"}
        </span>
      </td>
      <td>{channelDisplayLabel(row.channel_key || row.platform)}</td>
      <td>{row.environment || "-"}</td>
      <td>{pinTypeLabel(row.output_type)}</td>
      <td>#{row.library_asset_id || row.publish_output_id}</td>
      <td>#{row.source_id} {row.playlist_title || row.job_title}</td>
      <td className="pubdb-cell-wrap">{playlistInstanceLink(row)}</td>
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
        <button type="button" onClick={onDetails}>
          Details
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

function ConnectionDialog({ channel: channelKey, status, syncing, connectUrl, onClose, onRefresh, onSync }) {
  const title = channelKey === "youtube" ? "YouTube Connection" : channelKey === "pinterest" ? "Pinterest Connection" : "Channel Connection";
  if (channelKey === "youtube") {
    const channelInfo = status?.channel || {};
    const auth = status?.auth || {};
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
              <p>OAuth status for the ColorFix YouTube upload channel.</p>
            </div>
            <button type="button" onClick={onClose}>Close</button>
          </header>
          <section className="pubdb-pinterest pubdb-pinterest--modal">
            {missingScopes.length ? (
              <div className="pubdb-alert pubdb-alert--error">
                Missing YouTube scope{missingScopes.length === 1 ? "" : "s"}: {missingScopes.join(", ")}. Click Connect YouTube Channel again to request the upload permission.
              </div>
            ) : null}
            <div className="pubdb-pinterest__actions">
              <a className="pubdb-command pubdb-command--primary" href={connectUrl}>Connect YouTube Channel</a>
              <button type="button" className="pubdb-command" onClick={onRefresh}>Refresh</button>
            </div>
            <dl className="pubdb-pinterest__details">
              <dt>YouTube</dt>
              <dd>{channelInfo.status === "connected" || auth.status === "connected" ? "Connected" : "Not connected"}</dd>
              <dt>Channel record</dt>
              <dd>{channelInfo.label || "ColorFix YouTube"} {channelInfo.publishing_channel_id ? `#${channelInfo.publishing_channel_id}` : ""}</dd>
              <dt>Granted scopes</dt>
              <dd>{Array.isArray(scopes) && scopes.length ? scopes.join(", ") : "Not connected yet"}</dd>
              <dt>Connected at</dt>
              <dd>{auth.connected_at || "-"}</dd>
              <dt>Access token expires</dt>
              <dd>{channelInfo.auth_expires_at || "-"}</dd>
              <dt>Last auth error</dt>
              <dd>{auth.last_auth_error || "-"}</dd>
            </dl>
          </section>
        </div>
      </div>
    );
  }

  if (channelKey !== "pinterest") {
    return (
      <div className="pubdb-modal-backdrop" role="presentation">
        <div className="pubdb-modal pubdb-modal--small" role="dialog" aria-modal="true" aria-label={title}>
          <header className="pubdb-modal__header">
            <h2>{title}</h2>
            <button type="button" onClick={onClose}>Close</button>
          </header>
          <div className="pubdb-connection-empty">This channel is not wired yet.</div>
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

function hydrateDisplayRow(job, output) {
  const metadata = parseMetadata(output.metadata_json);
  return {
    ...output,
    board_name: output.board_name || metadata.board_name || metadata.board || "",
    board_url: output.board_url || metadata.board_url || "",
    board_slug: output.board_slug || metadata.board_slug || "",
    board_id: output.board_id || metadata.board_id || "",
    publish_job_id: job.publish_job_id,
    source_type: job.source_type,
    source_id: job.source_id,
    job_status: job.status,
    job_title: job.title,
    playlist_title: job.playlist_title,
    instance_title: job.instance_display_title || job.instance_name || "",
    playlist_instance_id: job.playlist_instance_id || output.playlist_instance_id || null,
    instance_slug: job.instance_slug || output.instance_slug || "",
  };
}

function findPackageRow(jobs, packageId) {
  if (!Array.isArray(jobs)) return null;
  for (const job of jobs) {
    const output = (job.outputs || []).find((item) => Number(item.package_id || item.publish_output_id || 0) === Number(packageId || 0));
    if (output) return hydrateDisplayRow(job, output);
  }
  return null;
}

function isPublishedRow(row) {
  const status = String(row.status || "").toLowerCase();
  return Boolean(row.published_at || row.external_url || status === "published" || status === "posted" || status === "test_published");
}

function isPublisherVisibleRow(row) {
  return isPublishedRow(row) || isErroredRow(row);
}

function isErroredRow(row) {
  const status = String(row.status || "").toLowerCase();
  const scheduleStatus = String(row.schedule_status || "").toLowerCase();
  const attemptStatus = String(row.publisher_attempt_status || "").toLowerCase();
  const errorStatuses = new Set(["error", "failed", "scheduled_retry_pending"]);
  return (
    errorStatuses.has(status)
    || errorStatuses.has(scheduleStatus)
    || errorStatuses.has(attemptStatus)
    || Boolean(row.publisher_error_message || row.publisher_error_code || row.schedule_error || row.schedule_error_code || row.last_error_message || row.last_error_code)
  );
}

function publisherErrorStage(row) {
  if (row.publisher_error_message || row.publisher_error_code || String(row.publisher_attempt_status || "").toLowerCase() === "failed") {
    return row.publisher_service || "Publisher";
  }
  if (row.schedule_error || row.schedule_error_code || String(row.schedule_status || "").toLowerCase() === "failed") {
    return "Scheduler";
  }
  if (row.last_error_message || row.last_error_code || String(row.status || "").toLowerCase() === "failed") {
    return "Package";
  }
  return "Publisher";
}

function publisherErrorMessage(row) {
  return row.publisher_error_message
    || row.publisher_error_code
    || row.schedule_error
    || row.schedule_error_code
    || row.last_error_message
    || row.last_error_code
    || "";
}

function attemptSummary(row) {
  const attempts = row.attempt_count ?? "";
  const max = row.max_attempts ?? "";
  if (attempts !== "" && max !== "") return `${attempts} / ${max}`;
  if (attempts !== "") return String(attempts);
  return "-";
}

function rowInDateRange(row, mode, from, to) {
  const value = mode === "published" ? row.published_at : row.created_at;
  if (!from && !to) return true;
  if (mode === "published" && isErroredRow(row) && !row.published_at) return true;
  const timestamp = dateTimeSortValue(value, { sourceTimeZone: mode === "published" ? "utc" : "local" });
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
      errors: matching.filter((row) => isErroredRow(row) && !isPublishedRow(row)).length,
      prepared: matching.filter((row) => !isPublishedRow(row)).length,
    };
  });
}

function buildChannelOptions(totals) {
  const options = totals.map((item) => ({
    value: item.channel,
    label: `${item.label} (${item.published}${item.errors ? ` + ${item.errors} errors` : ""})`,
  }));
  if (!options.some((item) => item.value === "pinterest")) {
    options.unshift({ value: "pinterest", label: "Pinterest (0)" });
  }
  return options;
}

function channelDisplayLabel(value) {
  const raw = String(value || "").toLowerCase();
  if (raw.includes("pinterest")) return "Pinterest";
  if (raw.includes("youtube")) return "YouTube";
  if (raw.includes("instagram")) return "Instagram";
  return String(value || "Channel").replace(/[_-]+/g, " ").replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function youtubeConnectionLabel(status) {
  const channel = status?.channel || {};
  const auth = status?.auth || {};
  return channel.status === "connected" || auth.status === "connected" ? "Connected" : "Not connected";
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
  if (key === "publish_output_id" || key === "publish_job_id" || key === "source_id" || key === "library_asset_id" || key === "playlist_instance_id") {
    return Number(row[key] || 0);
  }
  if (key === "created_at" || key === "published_at") {
    return dateTimeSortValue(row[key], { sourceTimeZone: key === "published_at" ? "utc" : "local" });
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

function findPublisherInstance(items, { ctaGroupId, platform, destinationKey, creatorJobId }) {
  return (items || []).find((instance) => {
    const notes = String(instance.instance_notes || "");
    return Number(instance.cta_group_id || 0) === Number(ctaGroupId || 0)
      && String(instance.audience || "").toLowerCase() === "pinterest"
      && notes.includes(`publisher_platform=${platform}`)
      && notes.includes(`publisher_destination=${destinationKey}`)
      && notes.includes(`asset_creator_job_id=${creatorJobId}`);
  }) || null;
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

function RowDialog({ row, saving, onClose, onMarkPublished, onPublishTest, onScheduleTest, onDeleteTest, onRetry }) {
  const requestPayload = formatJson(row.publisher_request_payload_json);
  const responsePayload = formatJson(row.publisher_response_payload_json);
  const errored = isErroredRow(row);
  const errorMessage = publisherErrorMessage(row);
  const hasReceipt = Boolean(row.publisher_attempt_id || requestPayload || responsePayload || row.publisher_error_message || row.publisher_error_code);
  const published = isPublishedRow(row);

  return (
    <div className="pubdb-modal-backdrop" role="presentation" onClick={onClose}>
      <div className="pubdb-modal pubdb-modal--receipt" role="dialog" aria-modal="true" aria-label="Publishing job" onClick={(event) => event.stopPropagation()}>
        <header className="pubdb-modal__header">
          <div>
            <h2>Package #{row.package_id || row.publish_output_id}</h2>
            <p>{row.title || "Published package"}</p>
          </div>
          <button type="button" onClick={onClose}>Close</button>
        </header>
        <dl className="pubdb-details">
          <dt>Package batch</dt><dd>#{row.package_batch_id || row.publish_job_id || "-"}</dd>
          <dt>Package ID</dt><dd>#{row.package_id || row.publish_output_id}</dd>
          <dt>Channel</dt><dd>{row.channel_key}</dd>
          <dt>Environment</dt><dd>{row.environment || "test"}</dd>
          <dt>Type</dt><dd>{row.output_type}</dd>
          <dt>Library ID</dt><dd>{row.library_asset_id || "-"}</dd>
          <dt>Permission</dt><dd><PermissionStatus {...permissionProps(row)} showLabel /></dd>
          <dt>Status</dt><dd>{row.status}</dd>
          <dt>Scheduler status</dt><dd>{row.schedule_status || "-"}</dd>
          <dt>Board</dt><dd>{row.board_name || PINTEREST_BOARD.board_name}</dd>
          <dt>Board slug</dt><dd>{row.board_slug || PINTEREST_BOARD.board_slug}</dd>
          <dt>Board ID</dt><dd>{row.board_id || "Pending Pinterest API board sync"}</dd>
          <dt>Playlist</dt><dd>#{row.source_id} {row.playlist_title || row.job_title}</dd>
          <dt>Playlist instance</dt><dd>{playlistInstanceLink(row)}</dd>
          <dt>Tracking code</dt><dd>{row.tracking_code || "-"}</dd>
          <dt>Tracking URL</dt>
          <dd>{row.tracking_url ? <a href={row.tracking_url} target="_blank" rel="noreferrer">{row.tracking_url}</a> : "-"}</dd>
          <dt>Destination URL</dt>
          <dd>{row.destination_url ? <a href={row.destination_url} target="_blank" rel="noreferrer">{row.destination_url}</a> : "-"}</dd>
          <dt>Live URL</dt>
          <dd>{row.external_url ? <a href={row.external_url} target="_blank" rel="noreferrer">{row.external_url}</a> : "-"}</dd>
          <dt>Platform post ID</dt><dd>{row.external_id || "-"}</dd>
          <dt>Published</dt><dd>{formatPublisherTime(row.published_at)}</dd>
          <dt>Published UTC</dt><dd>{row.published_at || "-"}</dd>
        </dl>
        {errored ? (
          <section className="pubdb-error-panel" aria-label="Publish error">
            <h3>Publish Error</h3>
            <dl className="pubdb-details pubdb-details--compact">
              <dt>Where</dt><dd>{publisherErrorStage(row)}</dd>
              <dt>Message</dt><dd>{errorMessage || "-"}</dd>
              <dt>Queue item</dt><dd>{row.queue_item_id ? `#${row.queue_item_id}` : "-"}</dd>
              <dt>Attempts</dt><dd>{attemptSummary(row)}</dd>
              <dt>Next retry</dt><dd>{formatPublisherTime(row.next_retry_at)}</dd>
              <dt>Scheduler code</dt><dd>{row.schedule_error_code || "-"}</dd>
              <dt>Scheduler message</dt><dd>{row.schedule_error || "-"}</dd>
              <dt>Publisher code</dt><dd>{row.publisher_error_code || "-"}</dd>
              <dt>Publisher message</dt><dd>{row.publisher_error_message || "-"}</dd>
              <dt>Package code</dt><dd>{row.last_error_code || "-"}</dd>
              <dt>Package message</dt><dd>{row.last_error_message || "-"}</dd>
            </dl>
          </section>
        ) : null}
        {hasReceipt ? (
          <section className="pubdb-receipt" aria-label="Publication receipt">
            <h3>Publication Receipt</h3>
            <dl className="pubdb-details pubdb-details--compact">
              <dt>Attempt</dt><dd>{row.publisher_attempt_id ? `#${row.publisher_attempt_id}` : "-"}</dd>
              <dt>Service</dt><dd>{row.publisher_service || "PinterestPublisher"}</dd>
              <dt>Attempt status</dt><dd>{row.publisher_attempt_status || row.status || "-"}</dd>
              <dt>Started</dt><dd>{formatPublisherTime(row.publisher_started_at)}</dd>
              <dt>Finished</dt><dd>{formatPublisherTime(row.publisher_finished_at)}</dd>
              <dt>Error</dt><dd>{row.publisher_error_message || row.publisher_error_code || "-"}</dd>
            </dl>
            {requestPayload ? (
              <>
                <h4>Request</h4>
                <pre>{requestPayload}</pre>
              </>
            ) : null}
            {responsePayload ? (
              <>
                <h4>Pinterest Response</h4>
                <pre>{responsePayload}</pre>
              </>
            ) : null}
          </section>
        ) : null}
        <div className="pubdb-modal__actions">
          <button type="button" onClick={onClose}>Close</button>
          {errored ? (
            <button type="button" className="pubdb-command--primary" onClick={onRetry} disabled={saving}>
              {saving ? "Retrying..." : "Retry"}
            </button>
          ) : null}
          {published || row.environment === "production" ? null : (
            <button type="button" className="pubdb-command--primary" onClick={onPublishTest} disabled={saving}>
              Publish Test Pin
            </button>
          )}
          {published ? null : (
            <button type="button" onClick={onScheduleTest} disabled={saving}>
              {row.environment === "production" ? "Send to Scheduler" : "Schedule Test Pin"}
            </button>
          )}
          {row.environment === "test" ? (
            <button type="button" className="pubdb-command--danger" onClick={onDeleteTest} disabled={saving}>
              {published ? "Delete Test Publication" : "Delete Test Row"}
            </button>
          ) : null}
          {!row.external_url && !errored ? (
            <button type="button" className="pubdb-command--primary" onClick={onMarkPublished} disabled={saving}>
              Mark Published
            </button>
          ) : null}
        </div>
      </div>
    </div>
  );
}

function formatPublisherTime(value) {
  return formatDateTime(value, { sourceTimeZone: "utc" });
}

function playlistInstanceLink(row) {
  const id = Number(row.playlist_instance_id || 0);
  if (id <= 0) return "-";
  const label = row.instance_title || row.instance_slug || "";
  return (
    <a href={`/admin/playlist-instances?q=${encodeURIComponent(id)}`}>
      #{id}{label ? ` ${label}` : ""}
    </a>
  );
}

function formatJson(value) {
  if (!value) return "";
  if (typeof value === "object") return JSON.stringify(value, null, 2);
  try {
    return JSON.stringify(JSON.parse(value), null, 2);
  } catch {
    return String(value);
  }
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
