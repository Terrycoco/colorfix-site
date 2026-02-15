import { useEffect, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import "./admin-kickers.css";

const LIST_URL = `${API_FOLDER}/v2/admin/kickers/list.php`;
const SAVE_URL = `${API_FOLDER}/v2/admin/kickers/save.php`;
const DELETE_URL = `${API_FOLDER}/v2/admin/kickers/delete.php`;

const emptyForm = {
  kicker_id: null,
  slug: "",
  display_text: "",
  is_active: true,
  sort_order: 0,
};

function slugify(value = "") {
  return value
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "") || `kicker-${Date.now()}`;
}

export default function AdminKickersPage() {
  const [kickers, setKickers] = useState([]);
  const [newForm, setNewForm] = useState(emptyForm);
  const [loading, setLoading] = useState(true);
  const [status, setStatus] = useState("");
  const [error, setError] = useState("");

  useEffect(() => {
    loadKickers();
  }, []);

  async function loadKickers() {
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
      if (!data?.ok) throw new Error(data?.error || "Failed to load kickers");
      setKickers(Array.isArray(data.items) ? data.items : []);
    } catch (err) {
      setError(err?.message || "Failed to load kickers");
    } finally {
      setLoading(false);
    }
  }

  function updateKickerLocal(kickerId, changes) {
    setKickers((prev) =>
      prev.map((item) => (item.kicker_id === kickerId ? { ...item, ...changes } : item))
    );
  }

  async function saveKicker(payload) {
    setStatus("");
    setError("");
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
    return data;
  }

  async function handleSave(item) {
    const slug = item.slug?.trim() || slugify(item.display_text);
    const payload = {
      kicker_id: item.kicker_id ?? null,
      slug,
      display_text: item.display_text?.trim() ?? "",
      is_active: !!item.is_active,
      sort_order: Number(item.sort_order) || 0,
    };
    if (!payload.display_text) {
      setError("Display text is required.");
      return;
    }
    try {
      const data = await saveKicker(payload);
      setStatus("Kicker saved.");
      if (!item.kicker_id) {
        setNewForm(emptyForm);
      }
      await loadKickers();
      updateKickerLocal(data.kicker_id || payload.kicker_id, { slug });
    } catch (err) {
      setError(err?.message || "Save failed");
    }
  }

  async function handleDelete(item) {
    const usage = [
      `saved: ${item.saved_count || 0}`,
      `applied: ${item.applied_count || 0}`,
      `playlist instances: ${item.playlist_instance_count || 0}`,
    ].join(", ");
    const warning =
      item.saved_count || item.applied_count || item.playlist_instance_count
        ? `This kicker is in use (${usage}). Deleting will clear those references. Continue?`
        : "Delete this kicker?";
    if (!window.confirm(warning)) return;

    setStatus("");
    setError("");
    try {
      const res = await fetch(DELETE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ kicker_id: item.kicker_id }),
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
      setStatus("Kicker deleted.");
      await loadKickers();
    } catch (err) {
      setError(err?.message || "Delete failed");
    }
  }

  return (
    <div className="admin-kickers">
      <header className="admin-kickers__header">
        <h1>Kickers</h1>
        <p>Manage editorial kicker labels used across palettes and playlists.</p>
      </header>

      {error && <div className="admin-kickers__error">{error}</div>}
      {status && <div className="admin-kickers__status">{status}</div>}

      <section className="admin-kickers__section">
        <h2>Add Kicker</h2>
        <div className="admin-kickers__row">
          <input
            type="text"
            placeholder="Display text"
            value={newForm.display_text}
            onChange={(e) => setNewForm((prev) => ({ ...prev, display_text: e.target.value }))}
          />
          <input
            type="text"
            placeholder="Slug"
            value={newForm.slug}
            onChange={(e) => setNewForm((prev) => ({ ...prev, slug: e.target.value }))}
          />
          <input
            type="number"
            placeholder="Sort"
            value={newForm.sort_order}
            onChange={(e) => setNewForm((prev) => ({ ...prev, sort_order: e.target.value }))}
          />
          <label className="admin-kickers__toggle">
            <input
              type="checkbox"
              checked={!!newForm.is_active}
              onChange={(e) => setNewForm((prev) => ({ ...prev, is_active: e.target.checked }))}
            />
            Active
          </label>
          <button type="button" onClick={() => handleSave(newForm)}>
            Add
          </button>
        </div>
      </section>

      <section className="admin-kickers__section">
        <h2>All Kickers</h2>
        {loading ? (
          <div className="admin-kickers__loading">Loading…</div>
        ) : (
          <div className="admin-kickers__table-wrap">
            <table className="admin-kickers__table">
              <thead>
                <tr>
                  <th>Display Text</th>
                  <th>Slug</th>
                  <th>Sort</th>
                  <th>Active</th>
                  <th>Usage</th>
                  <th>Updated</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {kickers.map((item) => (
                  <tr key={item.kicker_id}>
                    <td>
                      <input
                        type="text"
                        value={item.display_text || ""}
                        onChange={(e) =>
                          updateKickerLocal(item.kicker_id, { display_text: e.target.value })
                        }
                      />
                    </td>
                    <td>
                      <input
                        type="text"
                        value={item.slug || ""}
                        onChange={(e) =>
                          updateKickerLocal(item.kicker_id, { slug: e.target.value })
                        }
                      />
                    </td>
                    <td>
                      <input
                        type="number"
                        value={item.sort_order ?? 0}
                        onChange={(e) =>
                          updateKickerLocal(item.kicker_id, { sort_order: e.target.value })
                        }
                      />
                    </td>
                    <td>
                      <input
                        type="checkbox"
                        checked={!!item.is_active}
                        onChange={(e) =>
                          updateKickerLocal(item.kicker_id, { is_active: e.target.checked })
                        }
                      />
                    </td>
                    <td className="admin-kickers__usage">
                      <span>saved {item.saved_count || 0}</span>
                      <span>applied {item.applied_count || 0}</span>
                      <span>playlist {item.playlist_instance_count || 0}</span>
                    </td>
                    <td>{item.updated_at || "—"}</td>
                    <td className="admin-kickers__actions">
                      <button type="button" onClick={() => handleSave(item)}>
                        Save
                      </button>
                      <button
                        type="button"
                        className="admin-kickers__danger"
                        onClick={() => handleDelete(item)}
                      >
                        Delete
                      </button>
                    </td>
                  </tr>
                ))}
                {!kickers.length && (
                  <tr>
                    <td colSpan={7} className="admin-kickers__empty">
                      No kickers yet.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  );
}
