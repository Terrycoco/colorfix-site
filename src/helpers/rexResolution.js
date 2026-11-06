export const REX_UNAVAILABLE = "Unable to load this ColorFix link right now. Please try again.";

export class RexNotFoundError extends Error {
  constructor() { super("Link not found."); }
}

export function readRexBootstrap(token, src) {
  try {
    const bootstrap = JSON.parse(document.getElementById("colorfix-rex-bootstrap")?.textContent || "null");
    if (bootstrap?.token === token && bootstrap.payload?.ok && bootstrap.payload?.data) {
      // Referrer/session attribution is established in the browser after PHP.
      const plan = bootstrap.payload.data.destination?.playback_plan;
      if (plan) plan.src = src || null;
      return bootstrap.payload;
    }
  } catch { /* A missing/invalid bootstrap uses the normal resolver API. */ }
  return null;
}

export async function readRexResponse(response) {
  const payload = await response.json().catch(() => null);
  // An HTML challenge (even with a 404 status) is not a missing-resource verdict.
  if (response.status === 404 && payload?.ok === false && payload?.error === "Link not found.") {
    throw new RexNotFoundError();
  }
  if (!response.ok || payload?.ok !== true || !payload?.data) {
    throw new Error(REX_UNAVAILABLE);
  }
  return payload;
}
