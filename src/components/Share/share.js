

export function canUseNativeShare() {
  if (
    typeof navigator === "undefined" ||
    typeof navigator.share !== "function"
  ) {
    return false;
  }

  // Native Web Share is useful on mobile.
  // On desktop macOS/Chrome, Messages does not reliably
  // preserve the share when choosing an arbitrary recipient.
  return /Android|iPhone|iPad|iPod/i.test(
    navigator.userAgent || ""
  );
}

export function buildSmsShareUrl(body, phone = "") {
  const recipient = String(phone || "").replace(/[^\d+]/g, "");
  const encodedBody = encodeURIComponent(String(body || ""));
  const userAgent =
    typeof navigator === "undefined" ? "" : navigator.userAgent || "";

  if (/Macintosh/i.test(userAgent)) {
    if (recipient) {
      return `sms:?addresses=${encodeURIComponent(recipient)}&body=${encodedBody}`;
    }

    return `sms:/open?&body=${encodedBody}`;
  }

  return `sms:${recipient}?body=${encodedBody}`;
}

export async function copyShareText(value) {
  if (
    typeof navigator === "undefined" ||
    !navigator.clipboard?.writeText
  ) {
    return false;
  }

  try {
    await navigator.clipboard.writeText(String(value || ""));
    return true;
  } catch {
    return false;
  }
}

export function composeShareMessage({
  text = "",
  url = "",
} = {}) {
  return [text, url]
    .map((part) => String(part || "").trim())
    .filter(Boolean)
    .join("\n");
}

export async function openNativeShare({
  title = "",
  text = "",
  url = "",
} = {}) {
  if (!canUseNativeShare()) {
    return false;
  }

  try {
    await navigator.share({
      title,
      text,
      url,
    });

    return true;
  } catch {
    return false;
  }
}

export async function openTextShare({
  text = "",
  url = "",
  phone = "",
} = {}) {
  const body = composeShareMessage({
    text,
    url,
  });

  await copyShareText(body);

  if (typeof window === "undefined") {
    return true;
  }

  const userAgent = navigator?.userAgent || "";

  // macOS Messages loses a prefilled sms: body when the user
  // subsequently selects an existing recipient/thread.
  //
  // The complete share message is already on the clipboard,
  // so open Messages without attempting to prefill the draft.
  if (/Macintosh/i.test(userAgent)) {
    window.location.href = "sms:/open";
    return true;
  }

  window.location.href = buildSmsShareUrl(body, phone);

  return true;
}

export function buildMailtoUrl({
  subject = "",
  body = "",
  to = "",
} = {}) {
  const recipient = String(to || "").trim();
  const params = new URLSearchParams();

  if (subject) {
    params.set("subject", subject);
  }

  if (body) {
    params.set("body", body);
  }

  const query = params.toString();

  return `mailto:${recipient}${query ? `?${query}` : ""}`;
}

export async function shareUrl({
  title = "",
  text = "",
  url = "",
  phone = "",
} = {}) {
  const shareableUrl = String(url || "").trim();

  if (!shareableUrl) {
    return false;
  }

  const nativeResult = await openNativeShare({
    title,
    text,
    url: shareableUrl,
  });

  if (nativeResult) {
    return "native";
  }

  const body = composeShareMessage({
    text,
    url: shareableUrl,
  });

  // Desktop fallback: copy the complete share to the clipboard.
  // Do not launch Messages; macOS can discard the draft when
  // the user subsequently chooses a recipient.
  const copied = await copyShareText(body);

  if (copied) {
    return "copied";
  }

  // Last-resort fallback for environments without clipboard access.
  await openTextShare({
    text,
    url: shareableUrl,
    phone,
  });

  return "text";
}

// Temporary compatibility alias while old consumers are migrated.
export const shareOrText = shareUrl;