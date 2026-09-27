import { useState } from "react";
import RexReservationDialog from "@components/REX/RexReservationDialog";
import { API_FOLDER } from "@helpers/config";
import "./FetchRexButton.css";

const PREVIEW_URL = `${API_FOLDER}/v2/admin/rex/preview.php`;
const CREATE_URL = `${API_FOLDER}/v2/admin/rex/create.php`;

function absoluteUrl(value) {
  const url = String(value || "").trim();
  if (!url) return "";

  try {
    return new URL(url, window.location.origin).toString();
  } catch {
    return url;
  }
}

function adminRexUrl(value) {
  const url = absoluteUrl(value);
  if (!url) return "";

  try {
    const parsed = new URL(url, window.location.origin);
    parsed.searchParams.set("back", "1");
    return parsed.toString();
  } catch {
    return url;
  }
}

async function readJsonResponse(response) {
  const text = await response.text();

  let data = null;

  try {
    data = text ? JSON.parse(text) : null;
  } catch {
    throw new Error(
      `REX returned invalid JSON (HTTP ${response.status}).`
    );
  }

  if (!response.ok || !data?.ok) {
    throw new Error(
      data?.error ||
      `REX request failed (HTTP ${response.status}).`
    );
  }

  return data;
}

function createdPublicUrl(result) {
  const reservation = result?.reservation || result?.item || result || {};

  const direct =
    reservation?.public_url ||
    reservation?.url ||
    reservation?.href ||
    result?.public_url ||
    result?.url ||
    result?.href ||
    "";

  if (direct) {
    return absoluteUrl(direct);
  }

  const token = String(
    reservation?.token ||
    result?.token ||
    ""
  ).trim();

  return token
    ? absoluteUrl(`/t/${encodeURIComponent(token)}`)
    : "";
}

async function createReservation(request) {
  const label = String(request?.label || "").trim();
  const resolverKey = String(request?.resolverKey || "").trim();
  const resourceType = String(request?.resourceType || "").trim();
  const resourceId = Number(request?.resourceId || 0);

  if (!label) {
    throw new Error("REX label is required.");
  }

  if (!resolverKey || !resourceType || resourceId <= 0) {
    throw new Error("REX destination is incomplete.");
  }

  const context =
    request?.context &&
    typeof request.context === "object" &&
    !Array.isArray(request.context)
      ? request.context
      : {};

  // Match RexReservationDialog's working flow exactly:
  // resolve/preview the destination before creating the reservation.
  await readJsonResponse(
    await fetch(PREVIEW_URL, {
      method: "POST",
      credentials: "include",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        resolver_key: resolverKey,
        resource_type: resourceType,
        resource_id: resourceId,
        context,
      }),
    })
  );

  const data = await readJsonResponse(
    await fetch(CREATE_URL, {
      method: "POST",
      credentials: "include",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        label,
        resolver_key: resolverKey,
        resource_type: resourceType,
        resource_id: resourceId,
        context,
        admin_note:
          String(request?.adminNote || "").trim() || null,
        reuse_existing: Boolean(request?.reuseExisting),
      }),
    })
  );

  const reservation = data?.item;

  if (!reservation?.id || !reservation?.token) {
    throw new Error(
      "REX created a reservation but did not return its ID and token."
    );
  }

  return {
    reservationId: Number(reservation.id),
    token: String(reservation.token),
    reservation,
    reused: Boolean(data?.reused),
  };
}

export default function FetchRexButton({
  request,
  onCreated,
  buttonLabel = "Fetch REX",
  disabled = false,
  className = "",
  existingUrl = "",
  resolveExistingUrl = null,
  autoCreate = false,
}) {
  const [open, setOpen] = useState(false);
  const [checking, setChecking] = useState(false);

  async function handleClick() {
    if (disabled || checking) return;

    setChecking(true);

    try {
      let url = absoluteUrl(existingUrl);

      if (!url && typeof resolveExistingUrl === "function") {
        url = absoluteUrl(await resolveExistingUrl(request));
      }

      if (url) {
        window.location.href = adminRexUrl(url);
        return;
      }

      if (autoCreate) {
        const result = await createReservation(request);

        onCreated?.(result);

        const createdUrl = createdPublicUrl(result);

        if (!createdUrl) {
          throw new Error(
            "REX was created but no public URL could be determined."
          );
        }

        window.location.href = adminRexUrl(createdUrl);
        return;
      }

      setOpen(true);
    } catch (error) {
      window.alert(
        error instanceof Error
          ? error.message
          : "Could not fetch REX."
      );
    } finally {
      setChecking(false);
    }
  }

  return (
    <>
      <button
        type="button"
        className={["fetch-rex-button", className]
          .filter(Boolean)
          .join(" ")}
        disabled={disabled || checking}
        onClick={handleClick}
      >
        {checking ? "…" : buttonLabel}
      </button>

      {!autoCreate ? (
        <RexReservationDialog
          open={open}
          request={request}
          onClose={() => setOpen(false)}
          onCreated={(result) => onCreated?.(result)}
        />
      ) : null}
    </>
  );
}
