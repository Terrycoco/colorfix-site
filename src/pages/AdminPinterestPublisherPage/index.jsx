import { useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import "./admin-pinterest-publisher.css";

const JOBS_URL = `${API_FOLDER}/v2/admin/publishing/pinterest/list.php`;
const SAVE_URL = `${API_FOLDER}/v2/admin/publishing/pinterest/save.php`;
const MARK_URL = `${API_FOLDER}/v2/admin/publishing/pinterest/mark-published.php`;
const PLAYLISTS_URL = `${API_FOLDER}/v2/admin/playlists/list.php`;
const INSTANCES_URL = `${API_FOLDER}/v2/admin/playlist-instances/list.php`;

const emptyForm = {
  playlist_id: "",
  playlist_instance_id: "",
  title: "",
  description: "",
  board: "",
  external_url: "",
  published_at: "",
  notes: "",
};

export default function AdminPinterestPublisherPage() {
  const [jobs, setJobs] = useState([]);
  const [playlists, setPlaylists] = useState([]);
  const [instances, setInstances] = useState([]);
  const [form, setForm] = useState(emptyForm);
  const [q, setQ] = useState("");
  const [status, setStatus] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);

  const selectedPlaylist = useMemo(() => {
    const id = Number(form.playlist_id || 0);
    return playlists.find((item) => Number(item.playlist_id || 0) === id) || null;
  }, [form.playlist_id, playlists]);

  useEffect(() => {
    fetchPlaylists();
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
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load Pinterest jobs");
      setJobs(data.items || []);
    } catch (err) {
      setError(err?.message || "Failed to load Pinterest jobs");
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

  function usePlaylistText() {
    if (!selectedPlaylist) return;
    setForm((prev) => ({
      ...prev,
      title: prev.title || selectedPlaylist.headline || selectedPlaylist.title || "",
    }));
  }

  async function handleSave(event) {
    event.preventDefault();
    setSaving(true);
    setError("");
    setStatus("");
    try {
      const payload = {
        ...form,
        playlist_id: Number(form.playlist_id || 0),
        playlist_instance_id: form.playlist_instance_id ? Number(form.playlist_instance_id) : null,
      };
      const res = await fetch(SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to save Pinterest job");
      setStatus(`Pinterest publish record created: ${data.item?.tracking_code || ""}`);
      setForm(emptyForm);
      await fetchJobs("");
      setQ("");
    } catch (err) {
      setError(err?.message || "Failed to save Pinterest job");
    } finally {
      setSaving(false);
    }
  }

  async function markPublished(job, output) {
    setSaving(true);
    setError("");
    setStatus("");
    try {
      const externalUrl = window.prompt("Pinterest pin URL", output.external_url || "");
      if (externalUrl === null) return;
      const res = await fetch(MARK_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          publish_job_id: job.publish_job_id,
          publish_output_id: output.publish_output_id,
          external_url: externalUrl,
        }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to mark published");
      setStatus("Pinterest output marked published.");
      await fetchJobs();
    } catch (err) {
      setError(err?.message || "Failed to mark published");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="admin-pinterest-publisher">
      <header className="appub-header">
        <div>
          <h1>Pinterest Publisher</h1>
          <p>First-pass publishing records for Pinterest pins from ColorFix playlists.</p>
        </div>
      </header>

      {error ? <div className="appub-status appub-status--error">{error}</div> : null}
      {status ? <div className="appub-status appub-status--ok">{status}</div> : null}

      <section className="appub-panel">
        <h2>Record Pinterest Pin</h2>
        <form className="appub-form" onSubmit={handleSave}>
          <label>
            Playlist
            <select
              value={form.playlist_id}
              onChange={(event) => updateForm("playlist_id", event.target.value)}
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
              onChange={(event) => updateForm("playlist_instance_id", event.target.value)}
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

          <div className="appub-form__wide appub-inline-field">
            <label>
              Pin title
              <input
                value={form.title}
                onChange={(event) => updateForm("title", event.target.value)}
                placeholder="Leave blank to use playlist/share title"
              />
            </label>
            <button type="button" onClick={usePlaylistText} disabled={!selectedPlaylist}>
              Use Playlist Text
            </button>
          </div>

          <label className="appub-form__wide">
            Description
            <textarea
              rows={3}
              value={form.description}
              onChange={(event) => updateForm("description", event.target.value)}
              placeholder="Pinterest description, if already written"
            />
          </label>

          <label>
            Board
            <input
              value={form.board}
              onChange={(event) => updateForm("board", event.target.value)}
              placeholder="Board name"
            />
          </label>

          <label>
            Pinterest URL
            <input
              value={form.external_url}
              onChange={(event) => updateForm("external_url", event.target.value)}
              placeholder="https://www.pinterest.com/pin/..."
            />
          </label>

          <label>
            Published at
            <input
              type="datetime-local"
              value={form.published_at}
              onChange={(event) => updateForm("published_at", event.target.value)}
            />
          </label>

          <label className="appub-form__wide">
            Notes
            <textarea
              rows={2}
              value={form.notes}
              onChange={(event) => updateForm("notes", event.target.value)}
              placeholder="Internal notes"
            />
          </label>

          <div className="appub-actions appub-form__wide">
            <button type="submit" className="primary-btn" disabled={saving || !form.playlist_id}>
              {saving ? "Saving..." : "Create Pinterest Record"}
            </button>
          </div>
        </form>
      </section>

      <section className="appub-panel">
        <div className="appub-list-header">
          <h2>Pinterest Jobs</h2>
          <div className="appub-search">
            <input
              value={q}
              onChange={(event) => setQ(event.target.value)}
              placeholder="Search playlist or title"
            />
            <button type="button" onClick={() => fetchJobs(q)} disabled={loading}>
              Search
            </button>
          </div>
        </div>

        {loading ? <div className="appub-empty">Loading...</div> : null}
        {!loading && jobs.length === 0 ? <div className="appub-empty">No Pinterest jobs yet.</div> : null}
        <div className="appub-jobs">
          {jobs.map((job) => (
            <article className="appub-job" key={job.publish_job_id}>
              <div className="appub-job__main">
                <div className="appub-job__eyebrow">Job #{job.publish_job_id} · Playlist #{job.source_id}</div>
                <h3>{job.title}</h3>
                <div className="appub-job__meta">
                  <span>{job.status}</span>
                  <span>{job.playlist_title || "Playlist"}</span>
                  {job.instance_display_title || job.instance_name ? (
                    <span>{job.instance_display_title || job.instance_name}</span>
                  ) : null}
                </div>
              </div>
              <div className="appub-outputs">
                {(job.outputs || []).map((output) => (
                  <div className="appub-output" key={output.publish_output_id}>
                    <div>
                      <strong>{output.output_type}</strong>
                      <span>{output.status}</span>
                    </div>
                    <code>{output.tracking_code}</code>
                    {output.tracking_url ? (
                      <a href={output.tracking_url} target="_blank" rel="noreferrer">
                        Tracking link
                      </a>
                    ) : null}
                    {output.external_url ? (
                      <a href={output.external_url} target="_blank" rel="noreferrer">
                        Pinterest pin
                      </a>
                    ) : (
                      <button type="button" onClick={() => markPublished(job, output)} disabled={saving}>
                        Mark Published
                      </button>
                    )}
                    {output.published_at ? <span>{output.published_at}</span> : null}
                  </div>
                ))}
              </div>
            </article>
          ))}
        </div>
      </section>
    </div>
  );
}
