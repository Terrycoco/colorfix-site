export function canUseNativeShare() {
  if (typeof window === "undefined" || typeof navigator === "undefined") {
    return false;
  }

  if (typeof navigator.share !== "function") {
    return false;
  }

  if (window.matchMedia?.("(hover: none) and (pointer: coarse)").matches) {
    return true;
  }

  return /Android|iPhone|iPad|iPod/i.test(navigator.userAgent || "");
}

export function buildSmsShareUrl(body, phone = "") {
  const recipient = String(phone || "").replace(/[^\d+]/g, "");
  const encodedBody = encodeURIComponent(String(body || ""));
  const userAgent = typeof navigator === "undefined" ? "" : navigator.userAgent || "";

  if (/Macintosh/i.test(userAgent)) {
    if (recipient) {
      return `sms:?addresses=${encodeURIComponent(recipient)}&body=${encodedBody}`;
    }
    return `sms:/open?&body=${encodedBody}`;
  }

  return `sms:${recipient}?body=${encodedBody}`;
}

export async function copyShareText(value) {
  if (!navigator.clipboard?.writeText) {
    return false;
  }

  try {
    await navigator.clipboard.writeText(String(value || ""));
    return true;
  } catch {
    return false;
  }
}

export function composeShareMessage({ text = "", url = "" } = {}) {
  return [text, url].map((part) => String(part || "").trim()).filter(Boolean).join("\n");
}

export async function openNativeShare({ title = "", text = "", url = "" } = {}) {
  if (!canUseNativeShare()) {
    return false;
  }

  try {
    await navigator.share({ title, text, url });
    return true;
  } catch {
    return false;
  }
}

export async function openTextShare({ text = "", url = "", phone = "" } = {}) {
  const body = composeShareMessage({ text, url });
  await copyShareText(body);
  window.location.href = buildSmsShareUrl(body, phone);
}

export function buildMailtoUrl({ subject = "", body = "", to = "" } = {}) {
  const recipient = String(to || "").trim();
  const params = new URLSearchParams();
  if (subject) params.set("subject", subject);
  if (body) params.set("body", body);
  const query = params.toString();
  return `mailto:${recipient}${query ? `?${query}` : ""}`;
}

export async function shareOrText({ title = "", text = "", url = "", phone = "" } = {}) {
  if (await openNativeShare({ title, text, url })) {
    return "native";
  }

  await openTextShare({ text, url, phone });
  return "text";
}
