const STORAGE_KEY = "colorfix:lastPlaylistInstanceId";

function readStorage() {
  if (typeof window === "undefined") {
    return "";
  }
  return window.localStorage.getItem(STORAGE_KEY) || "";
}

export function recordLastPlaylistInstanceId(value) {
  if (typeof window === "undefined" || value === null || value === undefined || value === "") {
    return;
  }
  window.localStorage.setItem(STORAGE_KEY, String(value));
}

export function getLastPlaylistInstanceId() {
  return readStorage();
}

export function clearLastPlaylistInstanceId() {
  if (typeof window === "undefined") {
    return;
  }
  window.localStorage.removeItem(STORAGE_KEY);
}
