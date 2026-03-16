import { useState } from "react";
import { REQUEST_PLAYLIST_URL } from "./requestPlaylistApi";
import "./request-playlist.css";

function PhotoTipsModal({ open, onClose }) {
  if (!open) return null;

  function handleBackdropClick() {
    onClose();
  }

  function handleDialogClick(event) {
    event.stopPropagation();
  }

  return (
    <div
      className="request-playlist-modal-backdrop"
      onClick={handleBackdropClick}
      role="presentation"
    >
      <div
        className="request-playlist-modal"
        onClick={handleDialogClick}
        role="dialog"
        aria-modal="true"
        aria-labelledby="photo-tips-title"
      >
        <button
          type="button"
          className="request-playlist-modal-close"
          onClick={onClose}
          aria-label="Close photo tips"
        >
          ×
        </button>

        <h2 id="photo-tips-title">How to Take a Good Photo</h2>

        <ul className="request-playlist-modal-list">
          <li>Use natural daylight when possible.</li>
          <li>Stand back so the whole room or exterior area is visible.</li>
          <li>Include multiple angles of the same space.</li>
          <li>
            Show any fixed elements that affect color decisions, such as flooring,
            stone, cabinets, brick, or countertops.
          </li>
          <li>
            For open-concept spaces, include connected areas so Terry can
            understand how they relate.
          </li>
            <li>
    For exterior photos, try to avoid midday when shadows are strongest.
  </li>
<li>
  Don’t worry about choosing the perfect photo — Terry will select the best
  image of your requested space or view to use in your Makeover Playlist. If a
  clearer photo would help, Terry may ask you to take another one.
</li>
        </ul>

        <div className="request-playlist-modal-actions">
          <button
            type="button"
            className="request-playlist-modal-dismiss"
            onClick={onClose}
          >
            Close
          </button>
        </div>
      </div>
    </div>
  );
}

export default function RequestPlaylistPage() {
  const [form, setForm] = useState({
    name: "",
    email: "",
    projectType: "",
    spaces: "",
    preferences: "",
    photos: [],
  });

  const [status, setStatus] = useState({
    loading: false,
    error: "",
    success: "",
    folder: "",
  });

  const [showPhotoTips, setShowPhotoTips] = useState(false);

  function updateField(field, value) {
    setForm((prev) => ({ ...prev, [field]: value }));
  }

  function handlePhotoSelect(event) {
    const nextFiles = Array.from(event.target.files || []);
    if (nextFiles.length === 0) return;

    setForm((prev) => {
      const existing = Array.isArray(prev.photos) ? prev.photos : [];
      const seen = new Set(
        existing.map((file) => `${file.name}-${file.size}-${file.lastModified}`)
      );
      const merged = [...existing];

      nextFiles.forEach((file) => {
        const key = `${file.name}-${file.size}-${file.lastModified}`;
        if (!seen.has(key)) {
          seen.add(key);
          merged.push(file);
        }
      });

      return { ...prev, photos: merged };
    });

    event.target.value = "";
  }

  function removePhoto(indexToRemove) {
    setForm((prev) => ({
      ...prev,
      photos: prev.photos.filter((_, index) => index !== indexToRemove),
    }));
  }

  async function handleSubmit(event) {
    event.preventDefault();
    setStatus({ loading: true, error: "", success: "", folder: "" });

    try {
      const body = new FormData();
      body.append("name", form.name);
      body.append("email", form.email);
      body.append("projectType", form.projectType);
      body.append("spaces", form.spaces);
      body.append("preferences", form.preferences);
      Array.from(form.photos || []).forEach((file) => body.append("photos[]", file));

      const res = await fetch(REQUEST_PLAYLIST_URL, {
        method: "POST",
        body,
      });

      const text = await res.text();
      let data = {};

      try {
        data = text ? JSON.parse(text) : {};
      } catch {
        data = {};
      }

      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || text || "Failed to submit request");
      }

      setStatus({
        loading: false,
        error: "",
        success: "Request submitted. Terry has been notified.",
        folder: data?.request_folder || "",
      });

      setForm({
        name: "",
        email: "",
        projectType: "",
        spaces: "",
        preferences: "",
        photos: [],
      });

      const fileInput = document.getElementById("photos");
      if (fileInput) fileInput.value = "";
    } catch (err) {
      setStatus({
        loading: false,
        error: err?.message || "Failed to submit request",
        success: "",
        folder: "",
      });
    }
  }

  return (
    <div className="request-playlist-page">
      <section className="request-playlist-section request-playlist-hero">
        <h1>Request Your Makeover Playlist</h1>
        <p className="request-playlist-intro">
          Send Terry a few details about your space and upload your photos to get
          started.
        </p>
        <p className="request-playlist-subcopy">
          Terry reviews your space, lighting, and goals before creating your
          custom Makeover Playlist.
        </p>
      </section>

      <section className="request-playlist-section request-playlist-form-wrap">
        <form className="request-playlist-form" onSubmit={handleSubmit}>
          <div className="request-playlist-grid">
            <div className="request-playlist-field">
              <label htmlFor="name">Your Name</label>
              <input
                id="name"
                name="name"
                type="text"
                autoComplete="name"
                placeholder="Jane Smith"
                value={form.name}
                onChange={(e) => updateField("name", e.target.value)}
                required
              />
            </div>

            <div className="request-playlist-field">
              <label htmlFor="email">Email Address</label>
              <input
                id="email"
                name="email"
                type="email"
                autoComplete="email"
                placeholder="jane@example.com"
                value={form.email}
                onChange={(e) => updateField("email", e.target.value)}
                required
              />
            </div>
          </div>

          <div className="request-playlist-field">
            <label htmlFor="projectType">Project Type</label>
            <select
              id="projectType"
              name="projectType"
              value={form.projectType}
              onChange={(e) => updateField("projectType", e.target.value)}
              required
            >
              <option value="" disabled>
                Select one
              </option>
              <option value="interior-room">Interior room</option>
              <option value="open-living-area">Open living area</option>
              <option value="exterior">Exterior</option>
              <option value="multiple-spaces">Multiple spaces</option>
            </select>
          </div>

<div className="request-playlist-field">
  <label htmlFor="photos">Upload Photos</label>

  <div className="request-playlist-upload-row">
    <label className="request-playlist-upload-btn" htmlFor="photos">
      Choose Photos
    </label>

    <input
      id="photos"
      name="photos"
      type="file"
      multiple
      accept="image/*,.heic,.heif"
      onChange={handlePhotoSelect}
    />
  </div>

  <div className="request-playlist-upload-hint-row">
    <p className="request-playlist-upload-note">
      Upload as many photos as you'd like. Multiple angles are helpful.
    </p>

    <button
      type="button"
      className="request-playlist-photo-hint"
      onClick={() => setShowPhotoTips(true)}
    >
      How to take a good photo
    </button>
  </div>

<p className="request-playlist-help">
  Upload photos of the spaces you'd like Terry to consider. You can include
  multiple angles of the same room or area so Terry can understand the layout
  and lighting. Be sure to photograph and mention anything fixed or not
  changing — such as flooring, countertops, stone, cabinets, brick, or large
  furniture — since these elements affect color decisions.
</p>

<p className="request-playlist-help">
  These photos are reference images. Terry will choose the best photo for the
  final rendering. You are not charged for extra photos — pricing is based only
  on the final renderings included in your Makeover Playlist.
</p>
</div>

          <div className="request-playlist-field">
            <label htmlFor="spaces">What spaces or views should be included in your playlist?</label>
            <textarea
              id="spaces"
              name="spaces"
              rows="7"
              placeholder="Examples: living room, dining room, front exterior, fireplace view"
              value={form.spaces}
              onChange={(e) => updateField("spaces", e.target.value)}
              required
            />
          </div>

<div className="request-playlist-field">
  <label htmlFor="goals">Your Goals & Color Preferences</label>
  <textarea
    id="goals"
    name="goals"
    rows="5"
    placeholder="How is the space used? What feeling should it have?

Are there colors you love or strongly dislike?

Are there lighting issues, furniture pieces, architectural features, or materials Terry should take into account?"
    value={form.preferences}
    onChange={(e) => updateField("preferences", e.target.value)}
    required
  />
</div>

 <div className="request-playlist-summary">
  <h2>What Happens Next</h2>
  <ul>
    <li>Terry reviews your photos and project details.</li>
    <li>If anything needs clarification, Terry may contact you.</li>
    <li>You&apos;ll receive confirmation of the renderings and total price.</li>
    <li>After approval and payment, Terry creates your Makeover Playlist.</li>
  </ul>
</div>

          <div className="request-playlist-actions">
            <button
              type="submit"
              className="request-playlist-submit"
              disabled={status.loading}
            >
              {status.loading ? "Submitting..." : "Submit Request"}
            </button>
          </div>

          {status.error ? (
            <div className="request-playlist-status is-error">{status.error}</div>
          ) : null}

          {status.success ? (
            <div className="request-playlist-status is-success">
              <div>{status.success}</div>
            </div>
          ) : null}
        </form>
      </section>

      <PhotoTipsModal
        open={showPhotoTips}
        onClose={() => setShowPhotoTips(false)}
      />
    </div>
  );
}
