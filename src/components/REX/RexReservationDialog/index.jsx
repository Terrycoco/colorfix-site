import { useEffect, useMemo, useState } from "react";
import ModalDialog from "@components/ModalDialog";
import { API_FOLDER } from "@helpers/config";
import "./RexReservationDialog.css";

const PREVIEW_URL = `${API_FOLDER}/v2/admin/rex/preview.php`;
const CREATE_URL = `${API_FOLDER}/v2/admin/rex/create.php`;
const CREATE_ALIAS_URL = `${API_FOLDER}/v2/admin/rex/create-alias.php`;

const EMPTY_FORM = {
  label: "",
  resolverKey: "",
  resourceType: "",
  resourceId: "",
  contextText: "{}",
  alias: "",
  adminNote: "",
};

function normalizeRequest(request) {
  const context =
    request?.context && typeof request.context === "object" && !Array.isArray(request.context)
      ? request.context
      : {};

  return {
    label: String(request?.label ?? ""),
    resolverKey: String(request?.resolverKey ?? ""),
    resourceType: String(request?.resourceType ?? ""),
    resourceId:
      request?.resourceId === null || request?.resourceId === undefined
        ? ""
        : String(request.resourceId),
    contextText: JSON.stringify(context, null, 2),
    alias: String(request?.alias ?? ""),
    adminNote: String(request?.adminNote ?? ""),
  };
}

async function readJsonResponse(response) {
  const text = await response.text();

  let data = null;
  try {
    data = text ? JSON.parse(text) : null;
  } catch {
    throw new Error(`REX returned invalid JSON (HTTP ${response.status}).`);
  }

  if (!response.ok || !data?.ok) {
    throw new Error(data?.error || `REX request failed (HTTP ${response.status}).`);
  }

  return data;
}

function parseContext(contextText) {
  const raw = String(contextText ?? "").trim();
  if (raw === "") return {};

  let parsed;
  try {
    parsed = JSON.parse(raw);
  } catch {
    throw new Error("Context must be valid JSON.");
  }

  if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) {
    throw new Error("Context must be a JSON object.");
  }

  return parsed;
}

function destinationFingerprint(form) {
  return JSON.stringify({
    resolverKey: String(form.resolverKey || "").trim(),
    resourceType: String(form.resourceType || "").trim(),
    resourceId: Number(form.resourceId || 0),
    contextText: String(form.contextText || "").trim(),
  });
}

export default function RexReservationDialog({
  open = false,
  request = null,
  onClose,
  onCreated,
}) {
  const [form, setForm] = useState(EMPTY_FORM);
  const [preview, setPreview] = useState(null);
  const [previewFingerprint, setPreviewFingerprint] = useState("");
  const [previewing, setPreviewing] = useState(false);
  const [creating, setCreating] = useState(false);
  const [error, setError] = useState("");
  const [created, setCreated] = useState(null);

  useEffect(() => {
    if (!open) {
      setForm(EMPTY_FORM);
      setPreview(null);
      setPreviewFingerprint("");
      setPreviewing(false);
      setCreating(false);
      setError("");
      setCreated(null);
      return;
    }

    setForm(normalizeRequest(request));
    setPreview(null);
    setPreviewFingerprint("");
    setError("");
    setCreated(null);
  }, [open, request]);

  const currentFingerprint = useMemo(
    () => destinationFingerprint(form),
    [form.resolverKey, form.resourceType, form.resourceId, form.contextText]
  );

  const previewIsCurrent =
    Boolean(preview) &&
    previewFingerprint !== "" &&
    previewFingerprint === currentFingerprint;

  const canPreview =
    String(form.resolverKey || "").trim() !== "" &&
    String(form.resourceType || "").trim() !== "" &&
    Number(form.resourceId || 0) > 0 &&
    !previewing &&
    !creating;

  const canCreate =
    String(form.label || "").trim() !== "" &&
    previewIsCurrent &&
    !previewing &&
    !creating &&
    !created;

  function updateField(key, value) {
    setForm((current) => ({ ...current, [key]: value }));

    if (["resolverKey", "resourceType", "resourceId", "contextText"].includes(key)) {
      setPreview(null);
      setPreviewFingerprint("");
    }

    setError("");
  }

  async function previewDestination() {
    if (!canPreview) return;

    setPreviewing(true);
    setError("");

    try {
      const context = parseContext(form.contextText);

      const data = await readJsonResponse(
        await fetch(PREVIEW_URL, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            resolver_key: String(form.resolverKey).trim(),
            resource_type: String(form.resourceType).trim(),
            resource_id: Number(form.resourceId),
            context,
          }),
        })
      );

      setPreview(data.descriptor || null);
      setPreviewFingerprint(currentFingerprint);
    } catch (err) {
      setPreview(null);
      setPreviewFingerprint("");
      setError(err?.message || "Could not preview the REX destination.");
    } finally {
      setPreviewing(false);
    }
  }

  async function getToken() {
    if (!canCreate) return;

    setCreating(true);
    setError("");

    try {
      const context = parseContext(form.contextText);

      const createData = await readJsonResponse(
        await fetch(CREATE_URL, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            label: String(form.label).trim(),
            resolver_key: String(form.resolverKey).trim(),
            resource_type: String(form.resourceType).trim(),
            resource_id: Number(form.resourceId),
            context,
            admin_note: String(form.adminNote).trim() || null,
            reuse_existing: Boolean(request?.reuseExisting),
          }),
        })
      );

      const reservation = createData.item;

      if (!reservation?.id || !reservation?.token) {
        throw new Error("REX created a reservation but did not return its ID and token.");
      }

      let aliasItem = null;
      const alias = String(form.alias || "").trim();

      if (alias !== "" && !createData.reused) {
        const aliasData = await readJsonResponse(
          await fetch(CREATE_ALIAS_URL, {
            method: "POST",
            credentials: "include",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
              reservation_id: Number(reservation.id),
              alias,
            }),
          })
        );

        aliasItem = aliasData.item || null;
      }

      const result = {
        reservationId: Number(reservation.id),
        token: String(reservation.token),
        reservation,
        alias: aliasItem,
        reused: Boolean(createData.reused),
      };

      setCreated(result);
      onCreated?.(result);
    } catch (err) {
      setError(err?.message || "Could not create the REX reservation.");
    } finally {
      setCreating(false);
    }
  }

  return (
    <ModalDialog
      open={open}
      onClose={creating ? undefined : onClose}
      title="Fetch REX"
      subtitle="Review the reservation before creating a permanent token."
      width="760px"
    >
      <div className="rex-reservation-dialog">
        {error ? <div className="rex-reservation-dialog__error" role="alert">{error}</div> : null}

        <div className="rex-reservation-dialog__section">
          <div className="rex-reservation-dialog__section-title">Reservation</div>

          <div className="rex-reservation-dialog__grid">
            <label className="rex-reservation-dialog__wide">
              <span>Label</span>
              <input type="text" value={form.label} disabled={creating || Boolean(created)} onChange={(e) => updateField("label", e.target.value)} />
            </label>

            <label>
              <span>Alias</span>
              <input type="text" value={form.alias} disabled={creating || Boolean(created)} onChange={(e) => updateField("alias", e.target.value)} placeholder="optional" />
            </label>

            <label>
              <span>Resolver Key</span>
              <input type="text" value={form.resolverKey} disabled={creating || Boolean(created)} onChange={(e) => updateField("resolverKey", e.target.value)} />
            </label>

            <label>
              <span>Resource Type</span>
              <input type="text" value={form.resourceType} disabled={creating || Boolean(created)} onChange={(e) => updateField("resourceType", e.target.value)} />
            </label>

            <label>
              <span>Resource ID</span>
              <input type="number" min="1" step="1" value={form.resourceId} disabled={creating || Boolean(created)} onChange={(e) => updateField("resourceId", e.target.value)} />
            </label>

            <label className="rex-reservation-dialog__wide">
              <span>Context</span>
              <textarea rows={5} value={form.contextText} disabled={creating || Boolean(created)} onChange={(e) => updateField("contextText", e.target.value)} spellCheck="false" />
            </label>

            <label className="rex-reservation-dialog__wide">
              <span>Admin Note</span>
              <input type="text" maxLength={255} value={form.adminNote} disabled={creating || Boolean(created)} onChange={(e) => updateField("adminNote", e.target.value)} placeholder="Human note only; not used for routing" />
            </label>
          </div>
        </div>

        <div className="rex-reservation-dialog__section">
          <div className="rex-reservation-dialog__preview-head">
            <div>
              <div className="rex-reservation-dialog__section-title">Resolved Destination</div>
              <div className="rex-reservation-dialog__hint">REX asks the selected resolver to explain this destination before a token is created.</div>
            </div>

            {!created ? (
              <button type="button" className="rex-reservation-dialog__secondary" disabled={!canPreview} onClick={() => void previewDestination()}>
                {previewing ? "Checking…" : previewIsCurrent ? "Check Again" : "Preview"}
              </button>
            ) : null}
          </div>

          {previewIsCurrent ? (
            <div className="rex-reservation-dialog__descriptor">
              <strong>{preview?.title || "Destination confirmed"}</strong>
              {Array.isArray(preview?.fields) && preview.fields.length > 0 ? (
                <dl>
                  {preview.fields.map((field, index) => (
                    <div key={`${field?.label || "field"}-${index}`}>
                      <dt>{field?.label || "Field"}</dt>
                      <dd>{String(field?.value ?? "—")}</dd>
                    </div>
                  ))}
                </dl>
              ) : null}
            </div>
          ) : (
            <div className="rex-reservation-dialog__pending">Preview required before Get Token.</div>
          )}
        </div>

        {created ? (
          <div className="rex-reservation-dialog__created">
            <div className="rex-reservation-dialog__created-title">
              REX reservation #{created.reservationId} {created.reused ? "reused" : "created"}
            </div>
            <div className="rex-reservation-dialog__token">{created.token}</div>
            {created.alias?.alias ? <div className="rex-reservation-dialog__created-meta">Alias: {created.alias.alias}</div> : null}
          </div>
        ) : null}

        <div className="rex-reservation-dialog__actions">
          <button type="button" className="rex-reservation-dialog__secondary" disabled={creating} onClick={onClose}>
            {created ? "Done" : "Cancel"}
          </button>

          {!created ? (
            <button type="button" className="rex-reservation-dialog__primary" disabled={!canCreate} onClick={() => void getToken()}>
              {creating ? "Creating…" : "Get Token"}
            </button>
          ) : null}
        </div>
      </div>
    </ModalDialog>
  );
}
