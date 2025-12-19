import { v4 as uuidv4 } from "uuid";

const KEY = "viewer_id";

export function ensureViewerId() {
  let id = localStorage.getItem(KEY);

  if (!id) {
    id = uuidv4();
    localStorage.setItem(KEY, id);
  }

  return id;
}


export function getViewerId() {
  return localStorage.getItem(KEY);
}