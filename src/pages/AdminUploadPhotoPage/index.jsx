import { useState, useRef } from "react";
import { API_FOLDER } from "@helpers/config";
import "./uploader.css";

/**
 * Admin uploader for prepared bases (dark/medium/light) + optional masks[]
 * POST → /api/v2/admin/photo-upload.php
 * Expects response shape: { ok, asset_id, photo_id, base_size, touched: [{kind,role?,w,h}] }
 */
export default function PhotoPreparedUploader() {
  const [assetId, setAssetId] = useState("");
  const [stylePrimary, setStylePrimary] = useState("");
  const [verdict, setVerdict] = useState("");
  const [status, setStatus] = useState("");
  const [lighting, setLighting] = useState("");
  const [rights, setRights] = useState("");
  const [tags, setTags] = useState("");

  const [fileDark, setFileDark] = useState(null);
  const [fileMedium, setFileMedium] = useState(null);
  const [fileLight, setFileLight] = useState(null);
  const masksRef = useRef(null);

  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState(null);
  const [error, setError] = useState("");

  async function onSubmit(e) {
    e.preventDefault();
    setBusy(true);
    setError("");
    setResult(null);

    try {
      const form = new FormData();
      if (assetId.trim()) form.append("asset_id", assetId.trim());

      // Optional meta supported by controller
      if (stylePrimary.trim()) form.append("style", stylePrimary.trim());
      if (verdict.trim()) form.append("verdict", verdict.trim());
      if (status.trim()) form.append("status", status.trim());
      if (lighting.trim()) form.append("lighting", lighting.trim());
      if (rights.trim()) form.append("rights", rights.trim());
      if (tags.trim()) form.append("tags", tags.trim());

      // Trio files
      if (fileDark) form.append("prepared_dark", fileDark, fileDark.name);
      if (fileMedium) form.append("prepared_medium", fileMedium, fileMedium.name);
      if (fileLight) form.append("prepared_light", fileLight, fileLight.name);

      // Optional masks[]
      const maskFiles = masksRef.current?.files || [];
      for (let i = 0; i < maskFiles.length; i++) {
        form.append("masks[]", maskFiles[i], maskFiles[i].name);
      }

      const res = await fetch(`${API_FOLDER}/v2/admin/photo-upload.php`, {
        method: "POST",
        body: form,
      });

      const text = await res.text();
      let json;
      try {
        json = JSON.parse(text);
      } catch {
        throw new Error(`Invalid JSON:\n${text.slice(0, 400)}`);
      }

      if (!res.ok || json?.error) {
        throw new Error(json?.message || json?.error || `HTTP ${res.status}`);
      }

      setResult(json);
      if (!assetId && json.asset_id) setAssetId(json.asset_id);
    } catch (err) {
      setError(err?.message || String(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="prep-uploader">
      <h2>Upload Prepared Bases (Dark / Medium / Light) + Masks</h2>

      <form onSubmit={onSubmit} className="prep-form" encType="multipart/form-data">
        <div className="row">
          <label>Asset ID (optional)</label>
          <input
            type="text"
            placeholder="e.g., PHO_ABC123 (leave blank to auto-generate)"
            value={assetId}
            onChange={(e) => setAssetId(e.target.value)}
          />
        </div>

        <div className="row">
          <label>Style (optional)</label>
          <input
            type="text"
            placeholder="e.g., Adobe, Ranch, Victorian"
            value={stylePrimary}
            onChange={(e) => setStylePrimary(e.target.value)}
          />
        </div>

        <div className="row">
          <label>Verdict (optional)</label>
          <input
            type="text"
            placeholder="e.g., fan, love-it, nope"
            value={verdict}
            onChange={(e) => setVerdict(e.target.value)}
          />
        </div>

        <div className="row">
          <label>Status (optional)</label>
          <input
            type="text"
            placeholder="e.g., draft, keeper"
            value={status}
            onChange={(e) => setStatus(e.target.value)}
          />
        </div>

        <div className="row">
          <label>Lighting (optional)</label>
          <input
            type="text"
            placeholder="e.g., shade, bright sun"
            value={lighting}
            onChange={(e) => setLighting(e.target.value)}
          />
        </div>

        <div className="row">
          <label>Rights (optional)</label>
          <input
            type="text"
            placeholder="e.g., owned, ok-to-post"
            value={rights}
            onChange={(e) => setRights(e.target.value)}
          />
        </div>

        <div className="row">
          <label>Tags (comma-separated)</label>
          <input
            type="text"
            placeholder="e.g., white, shutters, adobe, front-door"
            value={tags}
            onChange={(e) => setTags(e.target.value)}
          />
        </div>

        <div className="group">
          <div className="row">
            <label>Prepared (Dark)</label>
            <input
              type="file"
              accept=".jpg,.jpeg,.png,.webp"
              onChange={(e) => setFileDark(e.target.files?.[0] || null)}
            />
          </div>

          <div className="row">
            <label>Prepared (Medium)</label>
            <input
              type="file"
              accept=".jpg,.jpeg,.png,.webp"
              onChange={(e) => setFileMedium(e.target.files?.[0] || null)}
            />
          </div>

          <div className="row">
            <label>Prepared (Light)</label>
            <input
              type="file"
              accept=".jpg,.jpeg,.png,.webp"
              onChange={(e) => setFileLight(e.target.files?.[0] || null)}
            />
          </div>
        </div>

        <div className="row">
          <label>Masks (PNG, multiple)</label>
          <input
            type="file"
            accept=".png"
            multiple
            ref={masksRef}
          />
        </div>

        <div className="actions">
          <button type="submit" disabled={busy}>
            {busy ? "Uploading…" : "Upload"}
          </button>
        </div>
      </form>

      {error && <div className="error">{error}</div>}

      {result && (
        <div className="result">
          <div><strong>Saved</strong></div>
          <div>Asset ID: {result.asset_id}</div>
          <div>Photo ID: {result.photo_id}</div>
          {result.base_size?.w && result.base_size?.h && (
            <div>Base Size: {result.base_size.w} × {result.base_size.h}</div>
          )}
          <ul>
            {(result.touched || []).map((t, i) => (
              <li key={`${t.kind}-${t.role || ''}-${i}`}>
                {t.kind}{t.role ? `:${t.role}` : ""} → {t.w || "?"}×{t.h || "?"}
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}
