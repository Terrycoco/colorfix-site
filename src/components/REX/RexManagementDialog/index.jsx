import { useEffect, useMemo, useState } from "react";
import ModalDialog from "@components/ModalDialog";
import { API_FOLDER } from "@helpers/config";
import "./RexManagementDialog.css";
import "../FetchRexButton/FetchRexButton.css";

const LIST_URL = `${API_FOLDER}/v2/admin/rex/list.php`;
const PLAYLIST_EXPERIENCES_URL = `${API_FOLDER}/v2/admin/rex/playlist-experiences.php`;

const PLAYLIST_EXPERIENCES = [
  { key: "public", label: "Public" },
  { key: "concept", label: "Concept" },
  { key: "client", label: "Client" },
];

async function readJsonResponse(res) {
  const text = await res.text();

  if (!res.ok) {
    throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
  }

  const data = JSON.parse(text);

  if (!data?.ok) {
    throw new Error(data?.error || "REX request failed");
  }

  return data;
}

function statusLabel(status) {
  const value = String(status || "").trim().toLowerCase();

  if (value === "active") return "Active";
  if (value === "revoked") return "Revoked";

  return status || "Unknown";
}

function absoluteRexUrl(url) {
  const value = String(url || "").trim();

  if (!value) {
    return "";
  }

  try {
    return new URL(value, window.location.origin).toString();
  } catch {
    return value;
  }
}

async function copyText(value) {
  const text = String(value || "").trim();

  if (!text || !navigator.clipboard?.writeText) {
    return;
  }

  await navigator.clipboard.writeText(text);
}

export default function RexManagementDialog({
  open = false,
  reservationIds = [],
  playlistId = null,
  title = "",
  onClose,
  onFetchMissing = null,
}) {
  const [items, setItems] = useState([]);
  const [playlistExperiences, setPlaylistExperiences] = useState({});
  const [loading, setLoading] = useState(false);
  const [fetchingMissing, setFetchingMissing] = useState(false);
  const [error, setError] = useState("");

  const normalizedPlaylistId = Number(playlistId || 0);
  const isPlaylistMode = normalizedPlaylistId > 0;

  const reservationIdKey = Array.isArray(reservationIds)
    ? reservationIds
        .map((id) => Number(id))
        .filter((id) => Number.isInteger(id) && id > 0)
        .join(",")
    : "";

  const experienceRows = useMemo(
    () =>
      PLAYLIST_EXPERIENCES.map((definition) => ({
        ...definition,
        experience:
          playlistExperiences?.[definition.key] || null,
      })),
    [playlistExperiences]
  );

  const missingExperienceRows = useMemo(
    () =>
      experienceRows.filter(({ experience }) => {
        const slideCount = Number(experience?.slide_count || 0);
        const rex = experience?.playlist_rex || null;

        return slideCount > 0 && !rex;
      }),
    [experienceRows]
  );

  async function loadReservations() {
    if (!open) {
      return;
    }

    setLoading(true);
    setError("");

    try {
      if (isPlaylistMode) {
        const params = new URLSearchParams();
        params.set("playlist_id", String(normalizedPlaylistId));
        params.set("_", String(Date.now()));

        const data = await readJsonResponse(
          await fetch(
            `${PLAYLIST_EXPERIENCES_URL}?${params.toString()}`,
            {
              credentials: "include",
            }
          )
        );

        setPlaylistExperiences(
          data?.data?.experiences &&
          typeof data.data.experiences === "object"
            ? data.data.experiences
            : {}
        );
        setItems([]);
        return;
      }

      const ids = reservationIdKey
        ? reservationIdKey
            .split(",")
            .map((id) => Number(id))
        : [];

      if (!ids.length) {
        setItems([]);
        setPlaylistExperiences({});
        return;
      }

      const params = new URLSearchParams();
      params.set("ids", ids.join(","));
      params.set("_", String(Date.now()));

      const data = await readJsonResponse(
        await fetch(`${LIST_URL}?${params.toString()}`, {
          credentials: "include",
        })
      );

      setItems(Array.isArray(data.items) ? data.items : []);
      setPlaylistExperiences({});
    } catch (err) {
      setError(err?.message || "Failed to load REX reservations");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    if (!open) {
      setItems([]);
      setPlaylistExperiences({});
      setError("");
      setFetchingMissing(false);
      return;
    }

    void loadReservations();
  }, [open, normalizedPlaylistId, reservationIdKey]);

  async function fetchMissing() {
    if (
      fetchingMissing ||
      missingExperienceRows.length === 0 ||
      typeof onFetchMissing !== "function"
    ) {
      return;
    }

    setFetchingMissing(true);
    setError("");

    try {
      const result = await onFetchMissing();

      if (result === false) {
        return;
      }

      await loadReservations();
    } catch (err) {
      setError(err?.message || "Failed to fetch missing REX reservations");
    } finally {
      setFetchingMissing(false);
    }
  }

  return (
    <ModalDialog
      open={open}
      onClose={fetchingMissing ? undefined : onClose}
      title="REX Reservations"
      subtitle={title}
      width="720px"
    >
      <div className="rex-management">
        {isPlaylistMode ? (
          <div className="rex-management__toolbar">
            <strong>Playlist #{normalizedPlaylistId}</strong>

            {missingExperienceRows.length > 0 ? (
              <button
                type="button"
                className="fetch-rex-button"
                disabled={
                  fetchingMissing ||
                  typeof onFetchMissing !== "function"
                }
                onClick={() => void fetchMissing()}
              >
                {fetchingMissing ? "Fetching…" : "Fetch Missing"}
              </button>
            ) : null}
          </div>
        ) : null}

        {loading ? (
          <div className="rex-management__empty">
            Loading REX reservations…
          </div>
        ) : null}

        {error ? (
          <div className="rex-management__error">
            {error}
          </div>
        ) : null}

        {isPlaylistMode && !loading ? (
          <div
            className="rex-management__list"
            aria-label="Playlist REX reservations"
          >
            {experienceRows.map(({ key, label, experience }) => {
              const slideCount = Number(experience?.slide_count || 0);
              const rex = experience?.playlist_rex || null;
              const rexUrl = absoluteRexUrl(rex?.url);
              const rexStatus = String(rex?.status || "").trim().toLowerCase();
              const hasRex = Boolean(rex?.id);
              const notNeeded = slideCount === 0 && !hasRex;

              return (
                <section
                  className="rex-management__card"
                  key={key}
                >
                  <div className="rex-management__card-main">
                    <div className="rex-management__card-head">
                      <strong>{label}</strong>

                      <span
                        className={`rex-management__status ${
                          rexStatus
                            ? `is-${rexStatus}`
                            : ""
                        }`}
                      >
                        {hasRex
                          ? statusLabel(rex?.status)
                          : notNeeded
                            ? "Not needed"
                            : "Missing"}
                      </span>
                    </div>

                    <div className="rex-management__card-meta">
                      <span>{slideCount} slide{slideCount === 1 ? "" : "s"}</span>
                      <span>
                        {hasRex
                          ? `Reservation #${rex.id}`
                          : "No reservation"}
                      </span>
                    </div>

                    {rexUrl ? (
                      <div className="rex-management__destination">
                        <a
                          href={rexUrl}
                          target="_blank"
                          rel="noreferrer"
                        >
                          {rexUrl}
                        </a>

                        <button
                          type="button"
                          onClick={() => void copyText(rexUrl)}
                        >
                          Copy URL
                        </button>
                      </div>
                    ) : null}
                  </div>
                </section>
              );
            })}
          </div>
        ) : null}

        {!isPlaylistMode && !loading && !error && items.length === 0 ? (
          <div className="rex-management__empty">
            No REX reservations.
          </div>
        ) : null}

        {!isPlaylistMode && !loading && items.length > 0 ? (
          <div
            className="rex-management__list"
            aria-label="REX reservations"
          >
            {items.map((item) => {
              const descriptor = item.descriptor || {};
              const fields = Array.isArray(descriptor.fields)
                ? descriptor.fields
                : [];
              const publicUrl = absoluteRexUrl(
                item.public_url ||
                (item.token ? `/t/${item.token}` : "")
              );

              return (
                <section
                  className="rex-management__card"
                  key={item.id}
                >
                  <div className="rex-management__card-main">
                    <div className="rex-management__card-head">
                      <strong>{descriptor.title || "Unknown destination"}</strong>
                      <span className={`rex-management__status is-${String(item.status || "").toLowerCase()}`}>
                        {statusLabel(item.status)}
                      </span>
                    </div>

                    <div className="rex-management__card-meta">
                      <span>Reservation #{item.id}</span>
                      <span>
                        Resolver: {item.resolver_key || "—"}
                      </span>
                    </div>

                    {fields.length > 0 ? (
                      <div className="rex-management__destination">
                        {fields.map((field, index) => (
                          <span key={`${item.id}-${index}`}>
                            <strong>{field.label || "Field"}:</strong>{" "}
                            {field.value ?? "—"}
                            {field.id ? ` (#${field.id})` : ""}
                          </span>
                        ))}
                      </div>
                    ) : null}

                    {publicUrl ? (
                      <div className="rex-management__destination">
                        <a
                          href={publicUrl}
                          target="_blank"
                          rel="noreferrer"
                        >
                          {publicUrl}
                        </a>

                        <button
                          type="button"
                          onClick={() => void copyText(publicUrl)}
                        >
                          Copy URL
                        </button>
                      </div>
                    ) : null}
                  </div>
                </section>
              );
            })}
          </div>
        ) : null}
      </div>
    </ModalDialog>
  );
}
