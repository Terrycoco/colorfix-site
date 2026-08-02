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
  return `sms:${recipient}?body=${encodedBody}`;
}
