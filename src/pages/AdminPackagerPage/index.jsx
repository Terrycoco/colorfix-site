import { useEffect, useMemo, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { API_FOLDER } from "@helpers/config";
import "../AdminPublishingPage/admin-publishing.css";

const PREPARE_PINTEREST_URL = `${API_FOLDER}/v2/admin/packager/pinterest/package-from-creator.php`;
const PACKAGER_LIST_URL = `${API_FOLDER}/v2/admin/packager/pinterest/list.php`;
const CREATOR_JOBS_URL = `${API_FOLDER}/v2/admin/asset-creators/list.php`;
const PLAYLISTS_URL = `${API_FOLDER}/v2/admin/playlists/list.php`;
const INSTANCES_URL = `${API_FOLDER}/v2/admin/playlist-instances/list.php`;
const INSTANCE_SAVE_URL = `${API_FOLDER}/v2/admin/playlist-instances/save.php`;
const INSTANCE_DEACTIVATE_TEST_URL = `${API_FOLDER}/v2/admin/playlist-instances/deactivate-publisher-test.php`;
const CTA_PAGES_URL = `${API_FOLDER}/v2/admin/cta-groups/list.php`;
const SCHEDULER_SCHEDULE_BATCH_URL = `${API_FOLDER}/v2/admin/publication-scheduler/schedule-package-batch.php`;
const PINTEREST_AUTH_STATUS_URL = `${API_FOLDER}/v2/admin/publishing/pinterest/auth-status.php`;
const PINTEREST_CONNECT_URL = `${API_FOLDER}/pinterest/connect`;
const PINTEREST_SYNC_BOARDS_URL = `${API_FOLDER}/v2/admin/publishing/pinterest/sync-boards.php`;
const PINTEREST_DISCONNECT_URL = `${API_FOLDER}/v2/admin/publishing/pinterest/disconnect.php`;
const YOUTUBE_AUTH_STATUS_URL = `${API_FOLDER}/v2/admin/publishing/youtube/auth-status.php`;
const YOUTUBE_CONNECT_URL = `${API_FOLDER}/youtube/connect`;
const YOUTUBE_PACKAGER_CONNECT_URL = `${YOUTUBE_CONNECT_URL}?return=${encodeURIComponent("/admin/packager")}`;

const emptySetupForm = {
  asset_creator_job_id: "",
  platform: "pinterest",
  environment: "test",
  destination_key: "colorfix_api_test",
  cta_group_id: "",
  package_label: "",
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
  },
  {
    value: "colorfix_makeovers",
    label: "ColorFix Makeovers",
    environment: "production",
  },
];

const youtubeDestinations = [
  {
    value: "youtube_manual",
    label: "YouTube Studio Manual",
    environment: "test",
  },
  {
    value: "youtube_channel",
    label: "ColorFix YouTube",
    environment: "production",
  },
];

export default function AdminPackagerPage() {
  const [searchParams] = useSearchParams();
  const [creatorJobs, setCreatorJobs] = useState([]);
  const [ctaPages, setCtaPages] = useState([]);
  const [playlists, setPlaylists] = useState([]);
  const [setupForm, setSetupForm] = useState(emptySetupForm);
  const [setupResult, setSetupResult] = useState(null);
  const [setupSaving, setSetupSaving] = useState(false);
  const [packaging, setPackaging] = useState(false);
  const [pinterestStatus, setPinterestStatus] = useState(null);
  const [youtubeStatus, setYoutubeStatus] = useState(null);
  const [pinterestSyncing, setPinterestSyncing] = useState(false);
  const [openConnection, setOpenConnection] = useState(false);
  const [previewInstance, setPreviewInstance] = useState(null);
  const [status, setStatus] = useState("");
  const [error, setError] = useState("");

  const selectedChannel = setupForm.platform || "pinterest";
  const channelLabel = publishingChannels.find((channel) => channel.value === selectedChannel)?.label || selectedChannel;

  const displayedCreatorJobs = useMemo(() => {
    return creatorJobs.filter((job) => jobMatchesChannel(job, selectedChannel));
  }, [creatorJobs, selectedChannel]);

  const selectedCreatorJob = useMemo(() => {
    const id = Number(setupForm.asset_creator_job_id || 0);
    return displayedCreatorJobs.find((job) => Number(job.asset_creator_job_id || 0) === id) || null;
  }, [displayedCreatorJobs, setupForm.asset_creator_job_id]);

  const setupPlaylist = useMemo(() => {
    const playlistId = Number(selectedCreatorJob?.source_id || 0);
    return playlists.find((playlist) => Number(playlist.playlist_id || 0) === playlistId) || null;
  }, [playlists, selectedCreatorJob]);

  const availableDestinations = useMemo(() => {
    const destinations = selectedChannel === "youtube" ? youtubeDestinations : pinterestDestinations;
    return destinations.filter((destination) => destination.environment === setupForm.environment);
  }, [selectedChannel, setupForm.environment]);

  useEffect(() => {
    const pinterestAuthStatus = searchParams.get("pinterest_auth");
    const youtubeAuthStatus = searchParams.get("youtube_auth");
    const message = searchParams.get("message");

    if (pinterestAuthStatus) {
      updateSetupForm("platform", "pinterest");
      setOpenConnection(true);
      if (pinterestAuthStatus === "connected") {
        setStatus(message || "Pinterest connected.");
      } else {
        setError(message || `Pinterest OAuth ${pinterestAuthStatus}.`);
      }
      window.history.replaceState({}, "", window.location.pathname);
    }

    if (youtubeAuthStatus) {
      updateSetupForm("platform", "youtube");
      setOpenConnection(true);
      if (youtubeAuthStatus === "connected") {
        setStatus(message || "YouTube connected.");
      } else {
        setError(message || `YouTube OAuth ${youtubeAuthStatus}.`);
      }
      window.history.replaceState({}, "", window.location.pathname);
    }
    fetchPlaylists();
    fetchCreatorJobs();
    fetchCtaPages();
    fetchPinterestStatus();
    fetchYoutubeStatus();
  }, []);

  useEffect(() => {
    const creatorJobId = Number(searchParams.get("creator_job_id") || searchParams.get("asset_creator_job_id") || 0);
    if (creatorJobId > 0) {
      updateSetupForm("asset_creator_job_id", String(creatorJobId));
    }
  }, [searchParams, creatorJobs, playlists]);

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

  async function disconnectPinterest() {
    setPinterestSyncing(true);
    setError("");
    setStatus("");
    try {
      const res = await fetch(PINTEREST_DISCONNECT_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({}),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to disconnect Pinterest");
      setPinterestStatus(data.item || null);
      setStatus("Pinterest disconnected for OAuth demo.");
    } catch (err) {
      setError(err?.message || "Failed to disconnect Pinterest");
    } finally {
      setPinterestSyncing(false);
    }
  }

  function updateSetupForm(field, value) {
    setSetupForm((prev) => {
      const next = { ...prev, [field]: value };
      if (field === "platform") {
        next.asset_creator_job_id = "";
        next.destination_key = value === "youtube" ? "youtube_manual" : "colorfix_api_test";
        next.environment = "test";
        next.package_label = "";
        next.instance_title = "";
        next.slug = "";
      }
      if (field === "environment") {
        if (next.platform === "youtube") {
          next.destination_key = value === "test" ? "youtube_manual" : "youtube_channel";
        } else {
          next.destination_key = value === "test" ? "colorfix_api_test" : "colorfix_makeovers";
        }
        next.slug = slugifyPublisher(prev.instance_title || next.instance_title || prev.package_label || next.package_label);
      }
      if (field === "asset_creator_job_id") {
        const job = creatorJobs.find((item) => Number(item.asset_creator_job_id || 0) === Number(value || 0));
        const title = publisherTitleFromJob(job, playlists);
        const reservation = urlReservationFromJob(job);
        next.package_label = title;
        next.instance_title = title;
        next.slug = reservation.slug || slugifyPublisher(title);
      }
      if (field === "package_label" && !prev.instance_title) {
        next.instance_title = value;
        next.slug = slugifyPublisher(value);
      }
      if (field === "instance_title") {
        next.slug = slugifyPublisher(value);
      }
      return next;
    });
    setSetupResult(null);
    setStatus("");
    setError("");
  }

  async function ensurePublisherInstance({ openPreview = false } = {}) {
    setSetupSaving(true);
    setError("");
    setStatus("");
    setSetupResult(null);
    try {
      const job = selectedCreatorJob;
      if (!job) throw new Error("Choose a creator job.");
      if (job.source_type !== "playlist") throw new Error("Only playlist creator jobs can be packaged right now.");
      const playlistId = Number(job.source_id || 0);
      if (playlistId <= 0) throw new Error("Creator job is missing its source playlist.");
      const ctaGroupId = Number(setupForm.cta_group_id || 0);
      if (ctaGroupId <= 0) throw new Error("Choose a CTA Page.");

      const environment = setupForm.environment || "test";
      const platform = setupForm.platform || "pinterest";
      const destinationKey = setupForm.destination_key || "colorfix_api_test";
      const title = setupForm.instance_title.trim() || setupForm.package_label.trim() || publisherTitleFromJob(job, playlists);
      const reservation = urlReservationFromJob(job);
      const slug = reservation.slug || setupForm.slug.trim() || slugifyPublisher(title);
      const marker = publisherInstanceMarker(environment, platform, destinationKey, job.asset_creator_job_id);

      if (platform === "pinterest") {
        const existingBatch = await findExistingPackageBatch({
          creatorJobId: job.asset_creator_job_id,
          environment,
          ctaGroupId,
          destinationKey,
        });
        if (existingBatch?.playlist_instance_id) {
          const result = {
            mode: "reused",
            playlist_instance_id: existingBatch.playlist_instance_id,
            player_url: `/p/${existingBatch.playlist_instance_id}`,
            slug: existingBatch.instance_slug || "",
          };
          setSetupResult(result);
          if (openPreview) setPreviewInstance(result);
          setStatus(`Reused instance #${existingBatch.playlist_instance_id} from package batch #${existingBatch.package_batch_id || existingBatch.publish_job_id}.`);
          return result;
        }
      }

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
        setStatus(`Reused instance #${existing.playlist_instance_id}.`);
        return result;
      }

      const saveRes = await fetch(INSTANCE_SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          playlist_id: playlistId,
          instance_name: `${channelLabel} - ${title}`,
          slug,
          url_reservation_key: reservation.key || undefined,
          allow_slug_edit: true,
          display_title: title,
          display_subtitle: "",
          instance_notes: [
            marker,
            "Created by packager. Test packages are disposable until production publish.",
          ].join("\n"),
          intro_layout: "default",
          cta_group_id: ctaGroupId,
          cta_context_key: platform,
          audience: platform,
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
        url_reservation: saveData.url_reservation || null,
      };
      setSetupResult(result);
      if (openPreview) setPreviewInstance(result);
      setStatus(`Created instance #${saveData.playlist_instance_id}.`);
      return result;
    } catch (err) {
      setError(err?.message || "Failed to create package instance");
      return null;
    } finally {
      setSetupSaving(false);
    }
  }

  async function findExistingPackageBatch({ creatorJobId, environment, ctaGroupId, destinationKey }) {
    const res = await fetch(`${PACKAGER_LIST_URL}?_=${Date.now()}`, { credentials: "include" });
    const data = await res.json();
    if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to check existing package batches");
    return (data.items || []).find((batch) => packageBatchMatches(batch, {
      creatorJobId,
      environment,
      ctaGroupId,
      destinationKey,
    })) || null;
  }

  async function packageForScheduler() {
    setPackaging(true);
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
          title: setupForm.package_label,
          instance_title: setupForm.instance_title || setupForm.package_label,
        }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to package creator outputs");
      const item = data.item || {};
      const batchId = item.package_batch_id || item.publishing_job_id || item.publish_job_id;
      const scheduleResult = await sendPackageBatchToScheduler(batchId);
      const added = Number(scheduleResult.enqueued || 0);
      const waiting = Number(scheduleResult.already_waiting || 0);
      const skipped = Number(scheduleResult.skipped || 0);
      const pieces = [`Packaged creator job as batch #${batchId}.`];
      if (added > 0) pieces.push(`Added ${added} to Scheduler.`);
      if (waiting > 0) pieces.push(`${waiting} already waiting in Scheduler.`);
      if (skipped > 0) pieces.push(`${skipped} skipped.`);
      setStatus(pieces.join(" "));
    } catch (err) {
      setError(err?.message || "Failed to package outputs for scheduler");
    } finally {
      setPackaging(false);
    }
  }

  async function sendPackageBatchToScheduler(packageBatchId) {
    const batchId = Number(packageBatchId || 0);
    if (batchId <= 0) return { enqueued: 0, already_waiting: 0, skipped: 0 };
    const res = await fetch(SCHEDULER_SCHEDULE_BATCH_URL, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        package_batch_id: batchId,
        priority: 100,
      }),
    });
    const data = await res.json();
    if (!res.ok || !data?.ok) throw new Error(data?.error || `Failed to send package batch #${batchId} to Scheduler`);
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

  return (
    <div className="admin-publishing">
      <header className="pubdb-header">
        <div>
          <h1>Packager</h1>
          <p>Attach destination, CTA, and label settings to creator assets, then send packages to Scheduler.</p>
        </div>
        <div className="pubdb-header__actions">
          <Link className="pubdb-command" to="/admin/asset-creators">Creator</Link>
          <Link className="pubdb-command" to="/admin/scheduler">Scheduler</Link>
          <Link className="pubdb-command" to="/admin/publisher">Publisher</Link>
        </div>
      </header>

      <section className="pubdb-setup">
        <div className="pubdb-setup__header">
          <div>
            <h2>Package Creator Job</h2>
          </div>
          <div className="pubdb-setup__rule">
            {setupForm.environment === "production" ? "Production destination." : "Test destination."}
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
            <span className="pubdb-toolbar__meta">
              {channelLabel}: {connectionLabel(selectedChannel, selectedChannel === "youtube" ? youtubeStatus : pinterestStatus)}
            </span>
          </div>
          <label className="pubdb-setup-form__wide">
            Creator job
            <select
              value={setupForm.asset_creator_job_id}
              onChange={(event) => updateSetupForm("asset_creator_job_id", event.target.value)}
              required
            >
              <option value="">Choose creator job with assets</option>
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
                <option key={destination.value} value={destination.value}>
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
            Package label
            <input
              value={setupForm.package_label}
              onChange={(event) => updateSetupForm("package_label", event.target.value)}
              placeholder="Cottage Palettes"
            />
          </label>
          <label>
            Instance title
            <input
              value={setupForm.instance_title}
              onChange={(event) => updateSetupForm("instance_title", event.target.value)}
              placeholder="Shown on landing/player page"
            />
          </label>
          <label>
            Slug
            <input
              value={setupForm.slug}
              onChange={(event) => updateSetupForm("slug", slugifyPublisher(event.target.value))}
              placeholder="cottage-palettes"
            />
          </label>
          <div className="pubdb-setup__summary">
            {selectedCreatorJob ? (
              <>
                <strong>Source:</strong> playlist #{selectedCreatorJob.source_id} {setupPlaylist?.title || selectedCreatorJob.title || ""}
                {urlReservationFromJob(selectedCreatorJob).publicUrl ? (
                  <span> · Reserved URL: {urlReservationFromJob(selectedCreatorJob).publicUrl}</span>
                ) : null}
              </>
            ) : (
              `Choose a ${channelLabel} creator job.`
            )}
          </div>
          <div className="pubdb-setup-actions">
            <button type="submit" className="pubdb-command pubdb-command--primary" disabled={setupSaving || !selectedCreatorJob || !setupForm.cta_group_id}>
              {setupSaving ? "Opening..." : "Instance Preview"}
            </button>
            <button
              type="button"
              className="pubdb-command pubdb-command--primary"
              onClick={() => packageForScheduler()}
              disabled={packaging || setupSaving || !selectedCreatorJob || !setupForm.cta_group_id}
            >
              {packaging ? "Sending..." : "Package Job to Scheduler"}
            </button>
          </div>
        </form>
        {setupResult ? (
          <div className="pubdb-setup-result">
            <strong>{setupResult.mode === "created" ? "Created" : "Reused"} instance #{setupResult.playlist_instance_id}</strong>
            <a href={setupResult.player_url} target="_blank" rel="noreferrer">Open Player</a>
            <a href={`/admin/playlist-instances?q=${encodeURIComponent(setupResult.playlist_instance_id)}`}>Open In Instances</a>
            {setupForm.environment === "test" ? (
              <button type="button" className="pubdb-command pubdb-command--danger" onClick={deactivatePublisherInstance} disabled={setupSaving}>
                Deactivate Test Instance
              </button>
            ) : null}
          </div>
        ) : null}
      </section>

      {error ? <div className="pubdb-status pubdb-status--error">{error}</div> : null}
      {status ? <div className="pubdb-status pubdb-status--ok">{status}</div> : null}

      {openConnection ? (
        <ConnectionDialog
          channel={selectedChannel}
          status={selectedChannel === "youtube" ? youtubeStatus : pinterestStatus}
          syncing={selectedChannel === "pinterest" ? pinterestSyncing : false}
          connectUrl={selectedChannel === "youtube" ? YOUTUBE_PACKAGER_CONNECT_URL : PINTEREST_CONNECT_URL}
          onClose={() => setOpenConnection(false)}
          onRefresh={selectedChannel === "youtube" ? fetchYoutubeStatus : fetchPinterestStatus}
          onSync={syncPinterestBoards}
          onDisconnect={disconnectPinterest}
        />
      ) : null}

      {previewInstance ? (
        <InstancePreviewDialog
          instance={previewInstance}
          onClose={() => setPreviewInstance(null)}
        />
      ) : null}
    </div>
  );
}

function ConnectionDialog({ channel: channelKey, status, syncing, connectUrl, onClose, onRefresh, onSync, onDisconnect }) {
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

  const auth = status?.auth || {};
  const destinations = status?.destinations || {};
  const scopes = auth.granted_scopes || status?.scopes_requested || [];

  return (
    <div className="pubdb-modal-backdrop" role="presentation">
      <div className="pubdb-modal" role="dialog" aria-modal="true" aria-label={title}>
        <header className="pubdb-modal__header">
          <div>
            <h2>{title}</h2>
            <p>OAuth, granted scopes, and synced boards.</p>
          </div>
          <button type="button" onClick={onClose}>Close</button>
        </header>
        <section className="pubdb-pinterest pubdb-pinterest--modal">
          <div className="pubdb-pinterest__actions">
            <a className="pubdb-command pubdb-command--primary" href={connectUrl}>Connect Pinterest</a>
            <button type="button" className="pubdb-command" onClick={onSync} disabled={syncing}>
              {syncing ? "Syncing..." : "Sync Pinterest Boards"}
            </button>
            <button type="button" className="pubdb-command pubdb-command--danger" onClick={onDisconnect} disabled={syncing}>
              Disconnect Pinterest
            </button>
            <button type="button" className="pubdb-command" onClick={onRefresh}>Refresh</button>
          </div>
          <dl className="pubdb-pinterest__details">
            <dt>Status</dt>
            <dd>{auth.status || "not connected"}</dd>
            <dt>Granted scopes</dt>
            <dd>{Array.isArray(scopes) ? scopes.join(", ") : String(scopes || "-")}</dd>
            <dt>Expires</dt>
            <dd>{auth.expires_at || "-"}</dd>
            <dt>Last auth error</dt>
            <dd>{auth.last_error || "-"}</dd>
          </dl>
          <div className="pubdb-board-sync">
            <BoardSyncItem label="Test" board={destinations.test} />
            <BoardSyncItem label="Production" board={destinations.production} />
          </div>
        </section>
      </div>
    </div>
  );
}

function BoardSyncItem({ label, board }) {
  return (
    <div className="pubdb-board-sync__item">
      <strong>{label}</strong>
      <span>{board?.board_name || "-"}</span>
      <span>Board ID: {board?.board_id || "missing"}</span>
      <span>{board?.board_url ? <a href={board.board_url} target="_blank" rel="noreferrer">Open board</a> : "No board URL"}</span>
    </div>
  );
}

function connectionLabel(channelKey, status) {
  if (channelKey === "youtube") return youtubeConnectionLabel(status);
  if (channelKey === "pinterest") {
    const auth = status?.auth || {};
    return String(auth.status || "").toLowerCase() === "connected" ? "Connected" : "Not connected";
  }
  return "Not wired";
}

function youtubeConnectionLabel(status) {
  const channel = status?.channel || {};
  const auth = status?.auth || {};
  return channel.status === "connected" || auth.status === "connected" ? "Connected" : "Not connected";
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

function publisherTitleFromJob(job, playlists = []) {
  if (!job) return "";
  const playlistId = Number(job.source_id || 0);
  const playlist = playlists.find((item) => Number(item.playlist_id || 0) === playlistId);
  const raw = playlist?.headline || playlist?.title || job.title || `Playlist ${playlistId}`;
  return String(raw || "")
    .replace(/^Pinterest:\s*/i, "")
    .replace(/^YouTube:\s*/i, "")
    .replace(/^Composite Pin:\s*/i, "")
    .trim();
}

function urlReservationFromJob(job = {}) {
  return {
    key: String(job?.url_reservation_key || "").trim(),
    slug: String(job?.reserved_playlist_slug || "").trim(),
    publicUrl: String(job?.reserved_playlist_url || "").trim(),
    path: String(job?.reserved_playlist_path || "").trim(),
  };
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
      && String(instance.audience || "").toLowerCase() === String(platform || "").toLowerCase()
      && notes.includes(`publisher_platform=${platform}`)
      && notes.includes(`publisher_destination=${destinationKey}`)
      && notes.includes(`asset_creator_job_id=${creatorJobId}`);
  }) || null;
}

function packageBatchMatches(batch, { creatorJobId, environment, ctaGroupId, destinationKey }) {
  const metadata = parseJson(batch?.metadata_json);
  return Number(batch?.creator_job_id || 0) === Number(creatorJobId || 0)
    && String(batch?.environment || "") === String(environment || "")
    && Number(batch?.cta_group_id || 0) === Number(ctaGroupId || 0)
    && String(metadata.destination_key || "") === String(destinationKey || "")
    && Number(batch?.playlist_instance_id || 0) > 0;
}

function parseJson(value) {
  if (!value) return {};
  if (typeof value === "object") return value;
  try {
    const parsed = JSON.parse(value);
    return parsed && typeof parsed === "object" ? parsed : {};
  } catch {
    return {};
  }
}

function slugifyPublisher(value) {
  return String(value || "")
    .trim()
    .toLowerCase()
    .replace(/&/g, " and ")
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "");
}

function jobMatchesChannel(job, channel) {
  const key = String(job?.creator_key || job?.platform || "").toLowerCase();
  if (channel === "pinterest") return key.includes("pinterest");
  if (channel === "youtube") return key.includes("youtube");
  if (channel === "instagram") return key.includes("instagram");
  return true;
}
