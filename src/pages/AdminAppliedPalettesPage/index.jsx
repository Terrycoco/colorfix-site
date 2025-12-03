import { useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import "./admin-applied.css";

const LIST_URL = `${API_FOLDER}/v2/admin/applied-palettes/list.php`;
const RENDER_URL = `${API_FOLDER}/v2/admin/applied-palettes/render.php`;
const DELETE_URL = `${API_FOLDER}/v2/admin/applied-palettes/delete.php`;
const SEND_EMAIL_URL = `${API_FOLDER}/v2/admin/applied-palettes/send-email.php`;
const UPDATE_URL = `${API_FOLDER}/v2/admin/applied-palettes/update.php`;

export default function AdminAppliedPalettesPage() {
  const origin = typeof window !== "undefined" ? window.location.origin : "";
  const [q, setQ] = useState("");
  const [draftQ, setDraftQ] = useState("");
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [refreshKey, setRefreshKey] = useState(0);
  const [renderingId, setRenderingId] = useState(null);
  const [deletingId, setDeletingId] = useState(null);
  const [copiedId, setCopiedId] = useState(null);
  const [shareModal, setShareModal] = useState({
    open: false,
    palette: null,
    toName: "",
    toEmail: "",
    message: "",
    status: { sending: false, success: "", error: "" },
  });
  const [editModal, setEditModal] = useState({
    open: false,
    palette: null,
    title: "",
    notes: "",
    tags: "",
    status: { saving: false, error: "", success: "" },
  });

  useEffect(() => {
    let active = true;
    setLoading(true);
    setError("");
    const url = new URL(LIST_URL, window.location.origin);
    if (q.trim()) url.searchParams.set("q", q.trim());
    fetch(url.toString(), { credentials: "include" })
      .then((res) => res.json())
      .then((data) => {
        if (!active) return;
        if (!data?.ok) throw new Error(data?.error || "Failed to load");
        setItems(data.items || []);
      })
      .catch((err) => {
        if (!active) return;
        setError(err?.message || "Failed to load");
        setItems([]);
      })
      .finally(() => active && setLoading(false));
    return () => {
      active = false;
    };
  }, [q, refreshKey]);

  const refreshList = () => setRefreshKey((x) => x + 1);

  const buildViewLink = (item) => `${origin}${item.view_url || `/view/${item.id}`}`;
  const renderUrl = (item) => (item.render_rel_path ? `${origin}${item.render_rel_path}` : null);

  const copyLink = async (item) => {
    const link = buildViewLink(item);
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(link);
      } else {
        const tmp = document.createElement("textarea");
        tmp.value = link;
        document.body.appendChild(tmp);
        tmp.select();
        document.execCommand("copy");
        tmp.remove();
      }
      setCopiedId(item.id);
      setTimeout(() => setCopiedId(null), 2000);
    } catch (err) {
      console.error(err);
    }
  };

  const renderPalette = async (item, { silent = false } = {}) => {
    if (!silent) setRenderingId(item.id);
    try {
      const res = await fetch(RENDER_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ palette_id: item.id }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || "Render failed");
      }
      refreshList();
    } catch (err) {
      if (!silent) alert(err?.message || "Render failed");
      throw err;
    } finally {
      if (!silent) setRenderingId(null);
    }
  };

  const handleRender = (item) => renderPalette(item);

  const handleView = async (item) => {
    try {
      await renderPalette(item, { silent: true });
      const link = `${buildViewLink({ ...item, needs_rerender: false })}?admin=1`;
      window.location.href = link;
    } catch (err) {
      alert(err?.message || "Unable to open view");
    }
  };

  const handleDelete = async (item) => {
    if (!window.confirm(`Delete palette #${item.id}? This removes cached renders too.`)) return;
    setDeletingId(item.id);
    try {
      const res = await fetch(DELETE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ palette_id: item.id }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || "Delete failed");
      }
      refreshList();
    } catch (err) {
      alert(err?.message || "Delete failed");
    } finally {
      setDeletingId(null);
    }
  };

  const openShareModal = (item) => {
    const defaultMessage = "Hi there! Check out your custom ColorFix palette.";
    setShareModal({
      open: true,
      palette: item,
      toName: "",
      toEmail: "",
      message: defaultMessage,
      status: { sending: false, success: "", error: "" },
    });
  };

  const openEditModal = (item) => {
    setEditModal({
      open: true,
      palette: item,
      title: item.title || "",
      notes: item.notes || "",
      tags: item.tags || "",
      status: { saving: false, error: "", success: "" },
    });
  };

  const closeEditModal = () => {
    setEditModal({
      open: false,
      palette: null,
      title: "",
      notes: "",
      tags: "",
      status: { saving: false, error: "", success: "" },
    });
  };

  const submitEdit = async () => {
    if (!editModal.palette) return;
    setEditModal((prev) => ({
      ...prev,
      status: { saving: true, error: "", success: "" },
    }));
    try {
      const res = await fetch(UPDATE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          palette_id: editModal.palette.id,
          title: editModal.title,
          notes: editModal.notes,
          tags: editModal.tags,
        }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || "Update failed");
      }
      setEditModal((prev) => ({
        ...prev,
        status: { saving: false, error: "", success: "Saved" },
      }));
      refreshList();
      setTimeout(closeEditModal, 600);
    } catch (err) {
      setEditModal((prev) => ({
        ...prev,
        status: { saving: false, error: err?.message || "Update failed", success: "" },
      }));
    }
  };

  const closeShareModal = () => {
    setShareModal({
      open: false,
      palette: null,
      toName: "",
      toEmail: "",
      message: "",
      status: { sending: false, success: "", error: "" },
    });
  };

  const sendEmail = async () => {
    if (!shareModal.palette) return;
    if (!shareModal.toEmail.trim()) {
      setShareModal((prev) => ({
        ...prev,
        status: { ...prev.status, error: "Recipient email required" },
      }));
      return;
    }
    setShareModal((prev) => ({
      ...prev,
      status: { sending: true, success: "", error: "" },
    }));
    try {
      const res = await fetch(SEND_EMAIL_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          palette_id: shareModal.palette.id,
          to_name: shareModal.toName,
          to_email: shareModal.toEmail,
          message: shareModal.message,
        }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || "Email failed");
      }
      setShareModal((prev) => ({
        ...prev,
        status: { sending: false, success: "Email sent!", error: "" },
      }));
    } catch (err) {
      setShareModal((prev) => ({
        ...prev,
        status: { sending: false, success: "", error: err?.message || "Email failed" },
      }));
    }
  };

  const shareLink = shareModal.palette ? buildViewLink(shareModal.palette) : "";

  return (
    <div className="admin-ap">
      <div className="admin-ap__toolbar">
        <h1>Applied Palettes</h1>
        <div className="admin-ap__search">
          <input
            type="text"
            placeholder="Search by title or asset"
            value={draftQ}
            onChange={(e) => setDraftQ(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter") setQ(draftQ);
            }}
          />
          <button className="admin-ap__action-btn" onClick={() => setQ(draftQ)}>
            Search
          </button>
          {q && (
            <button className="admin-ap__action-btn ghost" onClick={() => { setQ(""); setDraftQ(""); }}>
              Clear
            </button>
          )}
        </div>
      </div>

      {loading && <div>Loading…</div>}
      {error && <div className="error">{error}</div>}

      <div className="admin-ap__list">
        {items.map((item) => (
          <div key={item.id} className="admin-ap__row">
            <div className="admin-ap__main">
              <div className="admin-ap__title">
                {item.title || `Palette #${item.id}`}
              </div>
              <div className="admin-ap__meta">
                {item.asset_id} · #{item.id}
              </div>
              <div className={`admin-ap__status ${item.needs_rerender ? "is-warn" : "is-ok"}`}>
                {item.needs_rerender ? "Needs rerender" : (item.render_rel_path ? "Rendered" : "Not rendered")}
              </div>
              {item.tags && (
                <div className="admin-ap__tags">
                  {item.tags.split(",").map((tag) => (
                    <span key={tag} className="admin-ap__tag-chip">{tag}</span>
                  ))}
                </div>
              )}
              <div className="admin-ap__link">
                <input value={buildViewLink(item)} readOnly />
                <button className="admin-ap__action-btn ghost" onClick={() => copyLink(item)}>
                  {copiedId === item.id ? "Copied" : "Copy"}
                </button>
              </div>
              {renderUrl(item) && (
                <div className="admin-ap__render-link">
                  <a href={renderUrl(item)} target="_blank" rel="noreferrer">
                    Open cached render
                  </a>
                </div>
              )}
            </div>
            <div className="admin-ap__actions">
              <button className="admin-ap__action-btn" onClick={() => handleView(item)}>
                View
              </button>
              <button
                className="admin-ap__action-btn"
                onClick={() => window.open(`/admin/mask-tester?asset=${encodeURIComponent(item.asset_id)}`, "_blank", "noopener")}
              >
                Mask Tester
              </button>
              <button
                className="admin-ap__action-btn"
                onClick={() => openEditModal(item)}
              >
                Edit
              </button>
              <button
                className="admin-ap__action-btn"
                onClick={() => openShareModal(item)}
              >
                Share
              </button>
              <button
                className="admin-ap__action-btn danger"
                disabled={deletingId === item.id}
                onClick={() => handleDelete(item)}
              >
                {deletingId === item.id ? "Deleting…" : "Delete"}
              </button>
            </div>
          </div>
        ))}
        {!loading && !items.length && <div>No applied palettes yet.</div>}
      </div>

      {shareModal.open && shareModal.palette && (
        <div className="admin-ap__modal" role="dialog" aria-modal="true">
          <div className="admin-ap__modal-panel">
            <div className="admin-ap__modal-head">
              <h2>Share {shareModal.palette.title || `Palette #${shareModal.palette.id}`}</h2>
              <button className="admin-ap__action-btn ghost" onClick={closeShareModal}>Close</button>
            </div>
            <label>
              Direct Link
              <div className="admin-ap__link">
                <input value={shareLink} readOnly />
                <button className="admin-ap__action-btn ghost" onClick={() => copyLink(shareModal.palette)}>
                  Copy
                </button>
              </div>
            </label>
            <div className="admin-ap__modal-divider">Email Client</div>
            <label>
              Client Name
              <input
                value={shareModal.toName}
                onChange={(e) => setShareModal((prev) => ({ ...prev, toName: e.target.value }))}
              />
            </label>
            <label>
              Client Email*
              <input
                type="email"
                value={shareModal.toEmail}
                onChange={(e) => setShareModal((prev) => ({ ...prev, toEmail: e.target.value }))}
              />
            </label>
            <label>
              Message
              <textarea
                rows={3}
                value={shareModal.message}
                onChange={(e) => setShareModal((prev) => ({ ...prev, message: e.target.value }))}
              />
            </label>
            {shareModal.status.error && <div className="error">{shareModal.status.error}</div>}
            {shareModal.status.success && <div className="notice">{shareModal.status.success}</div>}
            <div className="admin-ap__modal-actions">
              <button
                className="admin-ap__action-btn"
                disabled={shareModal.status.sending}
                onClick={sendEmail}
              >
                {shareModal.status.sending ? "Sending…" : "Send Email"}
              </button>
            </div>
          </div>
        </div>
      )}

      {editModal.open && editModal.palette && (
        <div className="admin-ap__modal" role="dialog" aria-modal="true">
          <div className="admin-ap__modal-panel">
            <div className="admin-ap__modal-head">
              <h2>Edit {editModal.palette.title || `Palette #${editModal.palette.id}`}</h2>
              <button className="admin-ap__action-btn ghost" onClick={closeEditModal}>Close</button>
            </div>
            <label>
              Nickname / Title
              <input
                value={editModal.title}
                onChange={(e) => setEditModal((prev) => ({ ...prev, title: e.target.value }))}
              />
            </label>
            <label>
              Description / Notes
              <textarea
                rows={3}
                value={editModal.notes}
                onChange={(e) => setEditModal((prev) => ({ ...prev, notes: e.target.value }))}
              />
            </label>
            <label>
              Tags (comma separated)
              <input
                value={editModal.tags}
                onChange={(e) => setEditModal((prev) => ({ ...prev, tags: e.target.value }))}
              />
            </label>
            {editModal.status.error && <div className="error">{editModal.status.error}</div>}
            {editModal.status.success && <div className="notice">{editModal.status.success}</div>}
            <div className="admin-ap__modal-actions">
              <button
                className="admin-ap__action-btn"
                onClick={submitEdit}
                disabled={editModal.status.saving}
              >
                {editModal.status.saving ? "Saving…" : "Save"}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
