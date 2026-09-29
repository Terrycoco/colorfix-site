import {
  useEffect,
  useMemo,
  useState,
} from "react";

import {
  AdminButton,
  AdminDetailPane,
  AdminEmptyState,
  AdminField,
  AdminListPane,
  AdminMasterDetail,
  AdminNotice,
  AdminObjectList,
  AdminObjectListItem,
  AdminStack,
} from "@components/AdminLayout";

import {
  API_FOLDER,
} from "@helpers/config";

import { REX_OPTIONS } from "@components/REX/rexOptions";

const LIST_URL =
  `${API_FOLDER}/v2/admin/rex/list.php`;

const CREATE_URL =
  `${API_FOLDER}/v2/admin/rex/create.php`;

const SAVE_URL =
  `${API_FOLDER}/v2/admin/rex/qr-save.php`;

function emptyForm() {
  return {
    id: null,
    token: "",
    label: "",
    qr_key: "",
    resolver_key: "",
    experience_key: "",
    resource_type: "",
    resource_id: "",
    context_text: "{}",
    public_url: "",
  };
}

function formFromReservation(item) {
  if (!item) {
    return emptyForm();
  }

  return {
    id: Number(item.id || 0) || null,
    token: String(item.token || ""),
    label: String(item.label || ""),
    qr_key: String(item.qr_key || ""),
    resolver_key: String(item.resolver_key || ""),
    experience_key: String(item.experience_key || ""),
    resource_type: String(item.resource_type || ""),
    resource_id:
      item.resource_id === null ||
      item.resource_id === undefined
        ? ""
        : String(item.resource_id),
    context_text: JSON.stringify(
      item.context && typeof item.context === "object"
        ? item.context
        : {},
      null,
      2
    ),
    public_url: String(
      item.public_url ||
      (item.token ? `/t/${item.token}` : "")
    ),
  };
}

function absolutePublicUrl(value) {
  const raw = String(value || "").trim();

  if (!raw) {
    return "";
  }

  if (/^https?:\/\//i.test(raw)) {
    return raw;
  }

  if (typeof window === "undefined") {
    return raw;
  }

  return new URL(
    raw.startsWith("/") ? raw : `/${raw}`,
    window.location.origin
  ).toString();
}

function parseContext(value) {
  const raw = String(value || "").trim();

  if (!raw) {
    return {};
  }

  const parsed = JSON.parse(raw);

  if (
    !parsed ||
    Array.isArray(parsed) ||
    typeof parsed !== "object"
  ) {
    throw new Error(
      "Context must be a JSON object."
    );
  }

  return parsed;
}

function buildQrUrl(value, size = 1800) {
  return (
    "https://api.qrserver.com/v1/create-qr-code/" +
    `?size=${size}x${size}` +
    `&data=${encodeURIComponent(value)}`
  );
}

function safeFilename(value) {
  return (
    String(value || "qr-code")
      .trim()
      .toLowerCase()
      .replace(/[^a-z0-9_-]+/g, "-")
      .replace(/^-+|-+$/g, "") ||
    "qr-code"
  );
}

async function readJsonResponse(response) {
  const text = await response.text();

  let data = null;

  try {
    data = JSON.parse(text);
  } catch {
    throw new Error(
      `HTTP ${response.status}: ${text.slice(0, 250)}`
    );
  }

  if (!response.ok || !data?.ok) {
    throw new Error(
      data?.error ||
      `Request failed (${response.status}).`
    );
  }

  return data;
}

export default function AdminQrSheetsPage() {
  const [
    reservations,
    setReservations,
  ] = useState([]);

  const [
    selectedId,
    setSelectedId,
  ] = useState(null);

  const [
    form,
    setForm,
  ] = useState(emptyForm);

  const [
    loading,
    setLoading,
  ] = useState(true);

  const [
    saving,
    setSaving,
  ] = useState(false);

  const [
    downloading,
    setDownloading,
  ] = useState(false);

  const [
    error,
    setError,
  ] = useState("");

  const [
    statusMessage,
    setStatusMessage,
  ] = useState("");

  const isNew =
    selectedId === "new";

  const selectedReservation =
    useMemo(
      () =>
        reservations.find(
          (item) =>
            Number(item.id) ===
            Number(selectedId)
        ) || null,
      [
        reservations,
        selectedId,
      ]
    );

  useEffect(() => {
    loadReservations();
  }, []);

  useEffect(() => {
    if (isNew) {
      setForm(emptyForm());
      setError("");
      setStatusMessage("");
      return;
    }

    if (selectedReservation) {
      setForm(
        formFromReservation(
          selectedReservation
        )
      );
      setError("");
      setStatusMessage("");
    }
  }, [
    isNew,
    selectedReservation,
  ]);

  async function loadReservations(
    preferredId = null
  ) {
    setLoading(true);
    setError("");

    try {
      const res =
        await fetch(
          `${LIST_URL}?qr_only=1&_=${Date.now()}`,
          {
            credentials:
              "include",
          }
        );

      const data =
        await readJsonResponse(res);

      const items =
        Array.isArray(data.items)
          ? data.items
          : [];

      setReservations(items);

      const nextId =
        preferredId ||
        (
          items.some(
            (item) =>
              Number(item.id) ===
              Number(selectedId)
          )
            ? selectedId
            : items[0]?.id || null
        );

      setSelectedId(
        nextId
          ? Number(nextId)
          : null
      );

      if (!nextId) {
        setForm(emptyForm());
      }
    } catch (err) {
      setReservations([]);
      setSelectedId(null);
      setForm(emptyForm());
      setError(
        err?.message ||
        "Failed to load QR reservations."
      );
    } finally {
      setLoading(false);
    }
  }

  function setField(
    field,
    value
  ) {
    setForm(
      (current) => ({
        ...current,
        [field]: value,
      })
    );

    setError("");
    setStatusMessage("");
  }

  function beginNew() {
    setSelectedId("new");
    setForm(emptyForm());
    setError("");
    setStatusMessage("");
  }

  async function saveReservation(
    event
  ) {
    event.preventDefault();

    setSaving(true);
    setError("");
    setStatusMessage("");

    try {
      const context =
        parseContext(
          form.context_text
        );

      const payload = {
        label:
          String(
            form.label || ""
          ).trim(),

        qr_key:
          String(
            form.qr_key || ""
          ).trim(),

        resolver_key:
          String(
            form.resolver_key || ""
          ).trim(),

        experience_key:
          String(
            form.experience_key || ""
          ).trim() || null,

        resource_type:
          String(
            form.resource_type || ""
          ).trim(),

        resource_id:
          Number(
            form.resource_id || 0
          ),

        context,
      };

      if (
        !payload.label ||
        !payload.qr_key ||
        !payload.resolver_key ||
        !payload.resource_type ||
        payload.resource_id <= 0
      ) {
        throw new Error(
          "Name, QR Key, Resolver Key, Resource Type, and Resource ID are required."
        );
      }

      const url =
        isNew
          ? CREATE_URL
          : SAVE_URL;

      if (!isNew) {
        payload.reservation_id =
          Number(form.id);
      }

      const res =
        await fetch(
          url,
          {
            method: "POST",
            credentials:
              "include",
            headers: {
              "Content-Type":
                "application/json",
            },
            body:
              JSON.stringify(
                payload
              ),
          }
        );

      const data =
        await readJsonResponse(res);

      const saved =
        data.item || null;

      if (!saved?.id) {
        throw new Error(
          "REX reservation saved, but no reservation was returned."
        );
      }

      setStatusMessage(
        isNew
          ? "QR reservation created."
          : "QR reservation saved."
      );

      await loadReservations(
        Number(saved.id)
      );
    } catch (err) {
      setError(
        err?.message ||
        "Failed to save QR reservation."
      );
    } finally {
      setSaving(false);
    }
  }

  async function downloadQr() {
    const publicUrl =
      absolutePublicUrl(
        form.public_url ||
        (
          form.token
            ? `/t/${form.token}`
            : ""
        )
      );

    if (!publicUrl) {
      setError(
        "Save this QR reservation before downloading its QR code."
      );
      return;
    }

    setDownloading(true);
    setError("");

    try {
      const size = 1800;
      const trackedUrl =
        new URL(publicUrl);

      if (form.qr_key) {
        trackedUrl.searchParams.set(
          "src",
          String(form.qr_key).trim()
        );
      }

      const qrUrl =
        buildQrUrl(
          trackedUrl.toString(),
          size
        );

      const qrImg =
        new Image();

      qrImg.crossOrigin =
        "anonymous";

      qrImg.src =
        qrUrl;

      await new Promise(
        (resolve, reject) => {
          qrImg.onload =
            resolve;
          qrImg.onerror =
            reject;
        }
      );

      const canvas =
        document.createElement(
          "canvas"
        );

      canvas.width =
        size;

      canvas.height =
        size;

      const ctx =
        canvas.getContext(
          "2d"
        );

      if (!ctx) {
        throw new Error(
          "Could not create QR image."
        );
      }

      ctx.fillStyle =
        "#ffffff";

      ctx.fillRect(
        0,
        0,
        size,
        size
      );

      ctx.drawImage(
        qrImg,
        0,
        0,
        size,
        size
      );

      const link =
        document.createElement(
          "a"
        );

      link.download =
        `${safeFilename(
          form.qr_key ||
          form.label
        )}-qr.png`;

      link.href =
        canvas.toDataURL(
          "image/png"
        );

      link.click();
    } catch (err) {
      setError(
        err?.message ||
        "Failed to download QR code."
      );
    } finally {
      setDownloading(false);
    }
  }

  const list =
    (
      <AdminListPane
        title="QR Codes"
      >
        <AdminObjectList
          ariaLabel="QR Codes"
        >
          {
            reservations.map(
              (item) => (
                <AdminObjectListItem
                  key={item.id}
                  id={String(item.id)}
                  title={
                    item.label ||
                    item.qr_key ||
                    `QR #${item.id}`
                  }
                  meta={[
                    item.qr_key
                      ? String(
                          item.qr_key
                        )
                      : "",
                    item.descriptor?.title
                      ? String(
                          item.descriptor.title
                        )
                      : (
                          item.resource_type &&
                          item.resource_id
                            ? `${item.resource_type} #${item.resource_id}`
                            : ""
                        ),
                  ].filter(Boolean)}
                  selected={
                    Number(
                      selectedId
                    ) ===
                    Number(
                      item.id
                    )
                  }
                  onSelect={() =>
                    setSelectedId(
                      Number(
                        item.id
                      )
                    )
                  }
                />
              )
            )
          }
        </AdminObjectList>
      </AdminListPane>
    );

  let detail = null;

  if (loading) {
    detail =
      (
        <AdminDetailPane
          ariaLabel="QR Code"
          title="QR Code"
        >
          <AdminEmptyState
            title="QR Codes"
            message="Loading QR reservations..."
          />
        </AdminDetailPane>
      );
  } else if (
    !isNew &&
    !selectedReservation
  ) {
    detail =
      (
        <AdminDetailPane
          ariaLabel="QR Code"
          title="QR Codes"
          actions={
            <AdminButton
              type="button"
              onClick={beginNew}
            >
              New
            </AdminButton>
          }
        >
          {
            error
              ? (
                  <AdminNotice
                    variant="danger"
                  >
                    {error}
                  </AdminNotice>
                )
              : (
                  <AdminEmptyState
                    title="No QR codes yet"
                    message="Create a permanent QR reservation."
                  />
                )
          }
        </AdminDetailPane>
      );
  } else {
    const title =
      isNew
        ? "New QR Code"
        : (
            form.label ||
            form.qr_key ||
            "QR Code"
          );

    const publicUrl =
      absolutePublicUrl(
        form.public_url ||
        (
          form.token
            ? `/t/${form.token}`
            : ""
        )
      );

    const permanentQrUrl =
      publicUrl
        ? (() => {
            const url =
              new URL(publicUrl);

            if (form.qr_key) {
              url.searchParams.set(
                "src",
                String(form.qr_key).trim()
              );
            }

            return url.toString();
          })()
        : "";

    detail =
      (
        <AdminDetailPane
          ariaLabel="QR Code"
          title={title}
          actions={
            <>
              <AdminButton
                type="button"
                onClick={beginNew}
                disabled={
                  saving ||
                  downloading
                }
              >
                New
              </AdminButton>

              <AdminButton
                type="submit"
                form="admin-qr-reservation-form"
                disabled={
                  saving ||
                  downloading
                }
              >
                {
                  saving
                    ? "Saving..."
                    : "Save"
                }
              </AdminButton>

              <AdminButton
                type="button"
                onClick={
                  downloadQr
                }
                disabled={
                  isNew ||
                  saving ||
                  downloading ||
                  !publicUrl
                }
              >
                {
                  downloading
                    ? "Downloading..."
                    : "Download QR"
                }
              </AdminButton>
            </>
          }
        >
          <form
            id="admin-qr-reservation-form"
            onSubmit={
              saveReservation
            }
          >
            <AdminStack>
              {
                error
                  ? (
                      <AdminNotice
                        variant="danger"
                      >
                        {error}
                      </AdminNotice>
                    )
                  : null
              }

              {
                statusMessage
                  ? (
                      <AdminNotice
                        variant="success"
                      >
                        {statusMessage}
                      </AdminNotice>
                    )
                  : null
              }

              <AdminField
                label="Name"
              >
                <input
                  className="admin-field__control"
                  type="text"
                  value={
                    form.label
                  }
                  disabled={
                    saving
                  }
                  onChange={
                    (event) =>
                      setField(
                        "label",
                        event.target.value
                      )
                  }
                />
              </AdminField>

              <AdminField
                label="QR Key"
              >
                <input
                  className="admin-field__control"
                  type="text"
                  value={
                    form.qr_key
                  }
                  disabled={
                    saving
                  }
                  placeholder="sign1"
                  onChange={
                    (event) =>
                      setField(
                        "qr_key",
                        event.target.value
                      )
                  }
                />
              </AdminField>

              {
                !isNew
                  ? (
                      <>
                        <AdminField
                          label="Permanent REX URL"
                        >
                          <input
                            className="admin-field__control"
                            type="text"
                            value={
                              permanentQrUrl
                            }
                            readOnly
                          />
                        </AdminField>

                        <AdminField
                          label="REX Token"
                        >
                          <input
                            className="admin-field__control"
                            type="text"
                            value={
                              form.token
                            }
                            readOnly
                          />
                        </AdminField>
                      </>
                    )
                  : null
              }

              <AdminField
                label="Resolver Key"
              >
                <select
                  className="admin-field__control"
                  value={
                    form.resolver_key
                  }
                  disabled={
                    saving
                  }
                  onChange={
                    (event) =>
                      setField(
                        "resolver_key",
                        event.target.value
                      )
                  }
                >
                  <option value="">Choose...</option>
                  {REX_OPTIONS.resolvers.map((option) => (
                    <option
                      key={option.value}
                      value={option.value}
                    >
                      {option.label}
                    </option>
                  ))}
                </select>
              </AdminField>

              <AdminField
                label="Resource Type"
              >
                <select
                  className="admin-field__control"
                  value={
                    form.resource_type
                  }
                  disabled={
                    saving
                  }
                  onChange={
                    (event) =>
                      setField(
                        "resource_type",
                        event.target.value
                      )
                  }
                >
                  <option value="">Choose...</option>
                  {REX_OPTIONS.resourceTypes.map((option) => (
                    <option
                      key={option.value}
                      value={option.value}
                    >
                      {option.label}
                    </option>
                  ))}
                </select>
              </AdminField>

              <AdminField
                label="Resource ID"
              >
                <input
                  className="admin-field__control"
                  type="number"
                  min="1"
                  step="1"
                  value={
                    form.resource_id
                  }
                  disabled={
                    saving
                  }
                  onChange={
                    (event) =>
                      setField(
                        "resource_id",
                        event.target.value
                      )
                  }
                />
              </AdminField>

              <AdminField
                label="Experience"
              >
                <select
                  className="admin-field__control"
                  value={
                    form.experience_key
                  }
                  disabled={
                    saving
                  }
                  onChange={
                    (event) =>
                      setField(
                        "experience_key",
                        event.target.value
                      )
                  }
                >
                  {REX_OPTIONS.experiences.map((option) => (
                    <option
                      key={option.value || "none"}
                      value={option.value}
                    >
                      {option.label}
                    </option>
                  ))}
                </select>
              </AdminField>

              <AdminField
                label="Context"
              >
                <textarea
                  className="admin-field__control"
                  rows={6}
                  value={
                    form.context_text
                  }
                  disabled={
                    saving
                  }
                  onChange={
                    (event) =>
                      setField(
                        "context_text",
                        event.target.value
                      )
                  }
                />
              </AdminField>
            </AdminStack>
          </form>
        </AdminDetailPane>
      );
  }

  return (
    <AdminMasterDetail
      storageKey="admin-qr-list-width"
      defaultListWidth={240}
      list={list}
      detail={detail}
    />
  );
}
