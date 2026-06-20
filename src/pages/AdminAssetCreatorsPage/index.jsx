import { useEffect, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import PermissionStatus from "@components/PermissionStatus";
import "./admin-asset-creators.css";

const LIST_URL = `${API_FOLDER}/v2/admin/asset-creators/list.php`;
const DETAIL_URL = `${API_FOLDER}/v2/admin/asset-creators/detail.php`;
const PLAYLISTS_URL = `${API_FOLDER}/v2/admin/playlists/list.php`;
const PROPOSE_URL = `${API_FOLDER}/v2/admin/asset-creators/propose.php`;
const SAVE_URL = `${API_FOLDER}/v2/admin/asset-creators/save.php`;
const RUN_URL = `${API_FOLDER}/v2/admin/asset-creators/run.php`;

const CREATOR_TYPES = [
  {
    value: "pinterest.before_after_composite",
    label: "Composite Pin",
  },
];

const EMPTY_SIDE = {
  photo_library_id: "",
  asset_library_id: "",
  title: "",
  public_url: "",
};

export default function AdminAssetCreatorsPage() {
  const [jobs, setJobs] = useState([]);
  const [jobOutputs, setJobOutputs] = useState({});
  const [jobPermissions, setJobPermissions] = useState({});
  const [playlists, setPlaylists] = useState([]);
  const [q, setQ] = useState("");
  const [loading, setLoading] = useState(false);
  const [loadingPlaylists, setLoadingPlaylists] = useState(false);
  const [loadingJob, setLoadingJob] = useState(false);
  const [analyzing, setAnalyzing] = useState(false);
  const [savingRecipe, setSavingRecipe] = useState(false);
  const [runningJobId, setRunningJobId] = useState(null);
  const [error, setError] = useState("");
  const [status, setStatus] = useState("");
  const [creatorModalOpen, setCreatorModalOpen] = useState(false);
  const [imagePreview, setImagePreview] = useState(null);
  const [editingJobId, setEditingJobId] = useState(null);
  const [form, setForm] = useState({
    creator_key: CREATOR_TYPES[0].value,
    playlist_id: "",
  });
  const [proposal, setProposal] = useState(null);
  const [pairs, setPairs] = useState([]);

  useEffect(() => {
    fetchJobs("");
    fetchPlaylists();
  }, []);

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

  async function analyzePlaylist(event) {
    event.preventDefault();
    if (!form.playlist_id) {
      setError("Pick a playlist first.");
      return;
    }
    setAnalyzing(true);
    setError("");
    setStatus("");
    setProposal(null);
    setPairs([]);
    try {
      const res = await fetch(PROPOSE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          creator_key: form.creator_key,
          source_type: "playlist",
          playlist_id: Number(form.playlist_id),
        }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to analyze playlist");
      setProposal(data.item);
      setPairs((data.item?.pairs || []).map(normalizePair));
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
    const includedPairs = pairs.filter((pair) => pair.include);
    if (!includedPairs.length) {
      setError("Select at least one pair to save.");
      return null;
    }

    setSavingRecipe(true);
    setError("");
    setStatus("");
    try {
      const playlist = getSelectedPlaylist(playlists, form.playlist_id) || proposal.playlist || {};
      const reviewedPairs = pairs.map((pair, index) => ({
        ...pair,
        include: !!pair.include,
        sort_order: index + 1,
        search_title: pair.search_title || pair.title || "",
        pin_title: pair.search_title || pair.title || "",
        description: pair.description || pair.caption || "",
        pin_description: pair.description || pair.caption || "",
        title: pair.search_title || pair.title || "",
        caption: pair.description || pair.caption || "",
        before: normalizeRecipeSide(pair.before),
        after: normalizeRecipeSide(pair.after),
      }));
      const payload = {
        asset_creator_job_id: editingJobId || undefined,
        creator_key: form.creator_key,
        source_type: "playlist",
        source_id: Number(form.playlist_id),
        title: `${creatorLabel(form.creator_key)}: ${playlist.title || proposal.playlist?.title || `Playlist ${form.playlist_id}`}`,
        status: "draft",
        instructions: {
          version: 1,
          creator_key: form.creator_key,
          source: {
            type: "playlist",
            playlist_id: Number(form.playlist_id),
            title: playlist.title || proposal.playlist?.title || "",
          },
          recipe_type: "before_after_pairs",
          pairs: reviewedPairs,
          warnings: proposal.warnings || [],
        },
        inputs: buildRecipeInputs(reviewedPairs),
      };

      const res = await fetch(SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
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
      setStatus(`Created ${outputs.length} pin${outputs.length === 1 ? "" : "s"} from job #${jobId}.`);
      await fetchJobs(q);
      return data.item || null;
    } catch (err) {
      setError(err?.message || "Failed to run creator");
      return null;
    } finally {
      setRunningJobId(null);
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
        Array.isArray(instructions.pairs) ? instructions.pairs : [],
        job.inputs || []
      );
      const outputs = job.outputs || [];

      setEditingJobId(job.asset_creator_job_id || jobId);
      setJobOutputs((current) => ({
        ...current,
        [job.asset_creator_job_id || jobId]: outputs,
      }));
      setForm({
        creator_key: job.creator_key || instructions.creator_key || CREATOR_TYPES[0].value,
        playlist_id: playlistId ? String(playlistId) : "",
      });
      setProposal({
        creator_key: job.creator_key || instructions.creator_key || CREATOR_TYPES[0].value,
        playlist,
        warnings: instructions.warnings || [],
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
    });
    setProposal(null);
    setPairs([]);
    setCreatorModalOpen(true);
    if (!playlists.length) {
      fetchPlaylists();
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

  return (
    <div className="admin-asset-creators">
      <header className="assetcreator-header">
        <div>
          <h1>Asset Creator</h1>
          <p>Recipes that create new files from playlist and library ingredients.</p>
        </div>
        <button type="button" className="assetcreator-command assetcreator-command--primary" onClick={openNewCreatorModal}>
          New Creator Job
        </button>
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
              <th>Creator</th>
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
                    disabled={!!runningJobId || loadingJob}
                  >
                    {runningJobId === job.asset_creator_job_id ? "Running..." : "Run"}
                  </button>
                </td>
                <td>{job.creator_key}</td>
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
          <div className="assetcreator-modal" role="dialog" aria-modal="true" aria-label="New creator job">
            <header className="assetcreator-modal-head">
              <div>
                <h2>{editingJobId ? `Edit Creator Job #${editingJobId}` : "New Creator Job"}</h2>
                <p>
                  {editingJobId
                    ? "Adjust the saved recipe, then save it back to this job."
                    : "Analyze a playlist, then adjust the proposed recipe before saving it."}
                </p>
              </div>
              <button type="button" className="assetcreator-command" onClick={closeNewCreatorModal}>
                Close
              </button>
            </header>

            <section className="assetcreator-launch assetcreator-launch--modal">
              <form className="assetcreator-launch-form" onSubmit={analyzePlaylist}>
                <label>
                  Type
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
                <button type="submit" className="assetcreator-command assetcreator-command--primary" disabled={analyzing}>
                  {analyzing ? "Analyzing..." : "Analyze"}
                </button>
                <button type="button" className="assetcreator-command" onClick={fetchPlaylists} disabled={loadingPlaylists}>
                  Refresh Playlists
                </button>
              </form>
            </section>

            {error ? <div className="assetcreator-status assetcreator-status--error">{error}</div> : null}
            {status ? <div className="assetcreator-status assetcreator-status--success">{status}</div> : null}
            {editingJobId && jobOutputs[editingJobId]?.length ? (
              <section className="assetcreator-outputs">
                <h2>Created Assets</h2>
                <div className="assetcreator-output-list">
                  {jobOutputs[editingJobId].map((output) => (
                    (() => {
                      const previewUrl = versionedAssetUrl(output.public_url || output.rel_path, output);
                      return (
                    <button
                      type="button"
                      key={output.asset_creator_output_id || output.asset_library_id}
                      className="assetcreator-output-card"
                      onClick={() =>
                        toggleImagePreview({
                          public_url: previewUrl,
                          title: output.title || `Asset #${output.asset_library_id}`,
                          side: "Generated",
                          asset_library_id: output.asset_library_id,
                          ...permissionProps(output),
                        })
                      }
                    >
                      {previewUrl ? (
                        <img src={previewUrl} alt="" />
                      ) : null}
                      <span>
                        Asset #{output.asset_library_id}
                        <br />
                        Preview pin
                      </span>
                    </button>
                      );
                    })()
                  ))}
                </div>
              </section>
            ) : null}

            {proposal ? (
              <section className="assetcreator-proposal">
                <div className="assetcreator-proposal-head">
                  <div>
                    <h2>Proposed Pairs</h2>
                    <p>
                      {proposal.playlist?.title || "Playlist"} · {pairs.length} pair{pairs.length === 1 ? "" : "s"}
                    </p>
                  </div>
                  <div className="assetcreator-proposal-actions">
                    <button type="button" className="assetcreator-command" onClick={addPair}>
                      Add Pair
                    </button>
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
                      {runningJobId ? "Creating..." : "Create Pin"}
                    </button>
                  </div>
                </div>

                {proposal.warnings?.length ? (
                  <div className="assetcreator-status assetcreator-status--warn">
                    {proposal.warnings.join(" ")}
                  </div>
                ) : null}

                <div className="assetcreator-grid-wrap assetcreator-grid-wrap--proposal">
                  <table className="assetcreator-grid assetcreator-pairs-grid">
                    <thead>
                      <tr>
                        <th>Use</th>
                        <th>Order</th>
                        <th>Before</th>
                        <th>After</th>
                        <th>Search Title</th>
                        <th>Description</th>
                        <th>Source</th>
                        <th>Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      {pairs.map((pair, index) => (
                        <tr key={pair.pair_key || index}>
                          <td className="assetcreator-use-cell">
                            <input
                              type="checkbox"
                              checked={!!pair.include}
                              onChange={(event) => updatePair(index, "include", event.target.checked)}
                            />
                          </td>
                          <td className="assetcreator-order-cell">{index + 1}</td>
                          <td>{renderSideEditor(pair, index, "before", updatePairSide, toggleImagePreview)}</td>
                          <td>{renderSideEditor(pair, index, "after", updatePairSide, toggleImagePreview)}</td>
                          <td>
                            <input
                              className="assetcreator-title-input"
                              value={pair.search_title}
                              onChange={(event) => updatePair(index, "search_title", event.target.value)}
                            />
                          </td>
                          <td>
                            <textarea
                              className="assetcreator-description-input"
                              value={pair.description}
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
                          <td colSpan={8} className="assetcreator-empty">
                            No pairs yet. Add a pair manually.
                          </td>
                        </tr>
                      ) : null}
                    </tbody>
                  </table>
                </div>
              </section>
            ) : (
              <div className="assetcreator-modal-empty">
                Pick a creator type and playlist, then click Analyze.
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
          <div className="assetcreator-image-preview__panel">
            <div className="assetcreator-image-preview__head">
              <div>
                <h2>{imagePreview.title || "Preview"}</h2>
                <p>
                  {imagePreview.side ? `${imagePreview.side} photo` : "Photo"}
                  {imagePreview.photo_library_id ? ` #${imagePreview.photo_library_id}` : ""}
                  {imagePreview.asset_library_id ? ` #${imagePreview.asset_library_id}` : ""}
                </p>
              </div>
              <button type="button" className="assetcreator-command" onClick={() => setImagePreview(null)}>
                Close
              </button>
            </div>
            <img src={imagePreview.public_url} alt={imagePreview.title || ""} />
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
  const first = Array.isArray(outputs) ? outputs[0] : null;
  if (!first) return "-";
  const url = versionedAssetUrl(first.public_url || first.rel_path || "", first);
  if (!url) return `Asset #${first.asset_library_id}`;
  return (
    <button
      type="button"
      className="assetcreator-output-preview"
      onClick={() =>
        openImagePreview({
          public_url: url,
          title: first.title || `Asset #${first.asset_library_id}`,
          side: "Generated",
          asset_library_id: first.asset_library_id,
          ...permissionProps(first),
        })
      }
    >
      <img src={url} alt="" />
      <span className="assetcreator-output-preview__label">
        <span>Asset #{first.asset_library_id}</span>
      </span>
    </button>
  );
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

function getSelectedPlaylist(playlists, playlistId) {
  const wanted = Number(playlistId || 0);
  return playlists.find((playlist) => Number(playlist.playlist_id) === wanted) || null;
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

function normalizePair(pair) {
  return {
    pair_key: pair.pair_key || `pair-${Date.now()}-${Math.random()}`,
    include: pair.include !== false,
    sort_order: pair.sort_order || 0,
    source: pair.source || "manual",
    confidence: Number(pair.confidence || 0),
    search_title: pair.search_title || pair.pin_title || pair.title || "",
    description: pair.description || pair.pin_description || pair.caption || "",
    before: { ...EMPTY_SIDE, ...(pair.before || {}) },
    after: { ...EMPTY_SIDE, ...(pair.after || {}) },
  };
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
    title: value.title || "",
    public_url: value.public_url || "",
    client_id: nullableNumber(value.client_id),
    client_name: value.client_name || "",
    client_email: value.client_email || "",
    photo_permission_status: value.photo_permission_status || "",
  };
}

function buildRecipeInputs(reviewedPairs) {
  const inputs = [];
  reviewedPairs.forEach((pair, index) => {
    if (!pair.include) return;
    ["before", "after"].forEach((side, sideIndex) => {
      const value = pair[side] || EMPTY_SIDE;
      inputs.push({
        asset_library_id: nullableNumber(value.asset_library_id),
        role: side,
        sort_order: index + 1 + sideIndex / 10,
        metadata_json: {
          pair_key: pair.pair_key || `pair-${index + 1}`,
          pair_order: index + 1,
          side,
          photo_library_id: nullableNumber(value.photo_library_id),
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
