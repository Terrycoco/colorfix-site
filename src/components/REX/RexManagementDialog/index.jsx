import { useEffect, useState } from "react";
import ModalDialog from "@components/ModalDialog";
import { API_FOLDER } from "@helpers/config";
import "./RexManagementDialog.css";

const LIST_URL = `${API_FOLDER}/v2/admin/rex/list.php`;

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

export default function RexManagementDialog({
  open = false,
  reservationIds = [],
  title = "",
  onClose,
}) {
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    if (!open) {
      setItems([]);
      setError("");
      return;
    }

    const ids = Array.isArray(reservationIds)
      ? reservationIds
          .map((id) => Number(id))
          .filter((id) => Number.isInteger(id) && id > 0)
      : [];

    if (!ids.length) {
      setItems([]);
      return;
    }

    let cancelled = false;

    async function loadReservations() {
      setLoading(true);
      setError("");

      try {
        const params = new URLSearchParams();
        params.set("ids", ids.join(","));
        params.set("_", String(Date.now()));

        const data = await readJsonResponse(
          await fetch(`${LIST_URL}?${params.toString()}`, {
            credentials: "include",
          })
        );

        if (!cancelled) {
          setItems(Array.isArray(data.items) ? data.items : []);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err?.message || "Failed to load REX reservations");
        }
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    }

    void loadReservations();

    return () => {
      cancelled = true;
    };
  }, [open, reservationIds]);

  return (
    <ModalDialog
      open={open}
      onClose={onClose}
      title="REX Reservations"
      subtitle={title}
      width="720px"
    >
      <div className="rex-management">
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

        {!loading && !error && items.length === 0 ? (
          <div className="rex-management__empty">
            No REX reservations.
          </div>
        ) : null}

        {!loading && items.length > 0 ? (
          <div
            className="rex-management__list"
            aria-label="REX reservations"
          >
            {items.map((item) => {
              const descriptor = item.descriptor || {};
              const fields = Array.isArray(descriptor.fields)
                ? descriptor.fields
                : [];

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
                        Source: {item.source_key || "—"}
                      </span>
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
