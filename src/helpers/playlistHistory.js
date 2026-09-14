const STORAGE_KEY = "colorfix:lastPlaylistId";

function readStorage() {
  if (typeof window === "undefined") {
    return "";
  }

  return window.localStorage.getItem(STORAGE_KEY) || "";
}

export function recordLastPlaylistId(value) {
  if (
    typeof window === "undefined" ||
    value === null ||
    value === undefined ||
    value === ""
  ) {
    return;
  }

  window.localStorage.setItem(STORAGE_KEY, String(value));
}

export function getLastPlaylistId() {
  return readStorage();
}

export function clearLastPlaylistId() {
  if (typeof window === "undefined") {
    return;
  }

  window.localStorage.removeItem(STORAGE_KEY);
}
