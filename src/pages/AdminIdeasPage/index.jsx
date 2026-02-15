import { useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import "./admin-ideas.css";

const LIST_URL = `${API_FOLDER}/v2/admin/ideas/list.php`;
const SAVE_URL = `${API_FOLDER}/v2/admin/ideas/save.php`;
const DELETE_URL = `${API_FOLDER}/v2/admin/ideas/delete.php`;

const emptyForm = {
  idea_id: null,
  title: "",
  body: "",
  is_done: false,
};

export default function AdminIdeasPage() {
  const [ideas, setIdeas] = useState([]);
  const [form, setForm] = useState(emptyForm);
  const [loading, setLoading] = useState(true);
  const [status, setStatus] = useState("");
  const [error, setError] = useState("");

  const selectedIdeaId = form.idea_id;

  const ideaOptions = useMemo(() => {
    return ideas.map((idea) => ({
      idea_id: idea.idea_id,
      title: idea.title,
      is_done: idea.is_done,
    }));
  }, [ideas]);

  useEffect(() => {
    loadIdeas();
  }, []);

  async function loadIdeas({ keepSelection = true } = {}) {
    setLoading(true);
    setError("");
    try {
      const res = await fetch(`${LIST_URL}?_=${Date.now()}`, { credentials: "include" });
      const text = await res.text();
      if (!res.ok) {
        throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      }
      let data;
      try {
        data = JSON.parse(text);
      } catch {
        throw new Error("Invalid JSON response");
      }
      if (!data?.ok) throw new Error(data?.error || "Failed to load ideas");
      const items = Array.isArray(data.items) ? data.items : [];
      setIdeas(items);
      if (keepSelection && selectedIdeaId) {
        const found = items.find((idea) => idea.idea_id === selectedIdeaId);
        if (found) {
          setForm({
            idea_id: found.idea_id,
            title: found.title || "",
            body: found.body || "",
            is_done: !!found.is_done,
          });
        } else {
          setForm(emptyForm);
        }
      }
    } catch (err) {
      setError(err?.message || "Failed to load ideas");
    } finally {
      setLoading(false);
    }
  }

  function handleSelectChange(event) {
    const value = event.target.value;
    if (!value) {
      setForm(emptyForm);
      return;
    }
    const id = Number(value);
    const idea = ideas.find((item) => item.idea_id === id);
    if (!idea) {
      setForm(emptyForm);
      return;
    }
    setForm({
      idea_id: idea.idea_id,
      title: idea.title || "",
      body: idea.body || "",
      is_done: !!idea.is_done,
    });
  }

  async function handleSave() {
    setStatus("");
    setError("");
    const payload = {
      idea_id: form.idea_id,
      title: form.title.trim(),
      body: form.body || "",
      is_done: !!form.is_done,
    };
    if (!payload.title) {
      setError("Title is required.");
      return;
    }
    try {
      const res = await fetch(SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const text = await res.text();
      if (!res.ok) {
        throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      }
      let data;
      try {
        data = JSON.parse(text);
      } catch {
        throw new Error("Invalid JSON response");
      }
      if (!data?.ok) throw new Error(data?.error || "Save failed");
      setStatus("Idea saved.");
      const newId = data.idea_id || payload.idea_id;
      if (newId) {
        setForm((prev) => ({ ...prev, idea_id: newId }));
      }
      await loadIdeas({ keepSelection: true });
    } catch (err) {
      setError(err?.message || "Save failed");
    }
  }

  async function handleDelete() {
    if (!form.idea_id) return;
    if (!window.confirm("Delete this idea?")) return;
    setStatus("");
    setError("");
    try {
      const res = await fetch(DELETE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ idea_id: form.idea_id }),
      });
      const text = await res.text();
      if (!res.ok) {
        throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      }
      let data;
      try {
        data = JSON.parse(text);
      } catch {
        throw new Error("Invalid JSON response");
      }
      if (!data?.ok) throw new Error(data?.error || "Delete failed");
      setStatus("Idea deleted.");
      setForm(emptyForm);
      await loadIdeas({ keepSelection: false });
    } catch (err) {
      setError(err?.message || "Delete failed");
    }
  }

  return (
    <div className="admin-ideas">
      <header className="admin-ideas__header">
        <h1>Ideas</h1>
        <p>Keep notes for articles, playlists, palettes, and anything else.</p>
      </header>

      {error && <div className="admin-ideas__error">{error}</div>}
      {status && <div className="admin-ideas__status">{status}</div>}

      <section className="admin-ideas__section">
        <div className="admin-ideas__row">
          <label className="admin-ideas__field">
            <span>Find idea</span>
            <select value={form.idea_id || ""} onChange={handleSelectChange}>
              <option value="">New idea…</option>
              {ideaOptions.map((idea) => (
                <option key={idea.idea_id} value={idea.idea_id}>
                  {idea.title}{idea.is_done ? " (done)" : ""}
                </option>
              ))}
            </select>
          </label>
          <div className="admin-ideas__actions">
            <button type="button" className="admin-ideas__btn" onClick={() => setForm(emptyForm)}>
              New
            </button>
            <button
              type="button"
              className="admin-ideas__btn admin-ideas__btn--primary"
              onClick={handleSave}
            >
              Save
            </button>
            <button
              type="button"
              className="admin-ideas__btn admin-ideas__btn--danger"
              onClick={handleDelete}
              disabled={!form.idea_id}
            >
              Delete
            </button>
          </div>
        </div>
      </section>

      <section className="admin-ideas__section">
        {loading ? (
          <div className="admin-ideas__loading">Loading…</div>
        ) : (
          <div className="admin-ideas__editor">
            <label className="admin-ideas__field">
              <span>Title</span>
              <input
                type="text"
                value={form.title}
                onChange={(e) => setForm((prev) => ({ ...prev, title: e.target.value }))}
                placeholder="Idea title"
              />
            </label>
            <label className="admin-ideas__field admin-ideas__field--inline">
              <input
                type="checkbox"
                checked={!!form.is_done}
                onChange={(e) => setForm((prev) => ({ ...prev, is_done: e.target.checked }))}
              />
              Done
            </label>
            <label className="admin-ideas__field">
              <span>Notes</span>
              <textarea
                value={form.body}
                onChange={(e) => setForm((prev) => ({ ...prev, body: e.target.value }))}
                placeholder="Write your idea here…"
                rows={10}
              />
            </label>
          </div>
        )}
      </section>
    </div>
  );
}
