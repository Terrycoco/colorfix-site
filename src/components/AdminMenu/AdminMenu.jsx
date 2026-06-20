import { useEffect, useRef, useState } from "react";
import { isAdmin } from "@helpers/authHelper";
import { useAppState } from "@context/AppStateContext";
import { API_FOLDER } from "@helpers/config";
import { adminMenuItems } from "./adminMenuItems";
import "./adminmenu.css";

const CLIENT_INBOX_COUNT_URL = `${API_FOLDER}/v2/admin/clients/inbox-count.php`;
const INBOX_COUNT_CACHE_KEY = "colorfix.admin.inboxCount";
const INBOX_COUNT_CACHE_MS = 60_000;
const INBOX_COUNT_TIMEOUT_MS = 1500;

function normalizeHrefPath(href = "") {
  if (!href) return "";
  return String(href).split("?")[0].replace(/\/+$/, "") || "/";
}

function isPathMatch(currentPath, href) {
  const target = normalizeHrefPath(href);
  if (!target) return false;
  if (target === "/") return currentPath === "/";
  return currentPath === target || currentPath.startsWith(`${target}/`);
}

function shouldUseInboxBadge(currentPath) {
  return currentPath === "/admin/clients"
    || currentPath.startsWith("/admin/clients/")
    || currentPath.startsWith("/admin/asset-")
    || currentPath === "/admin/library"
    || currentPath === "/admin/publishing"
    || currentPath === "/admin/publisher"
    || currentPath === "/admin/pinterest-publisher";
}

export default function AdminMenu() {
  const { user, setAdminExitPath } = useAppState();
  const admin = Boolean(user?.is_admin) || isAdmin();
  const currentPath = normalizeHrefPath(window.location.pathname);
  const isAdminEntry = currentPath === "/admin" || currentPath.startsWith("/admin/");
  const useInboxBadge = shouldUseInboxBadge(currentPath);
  const [open, setOpen] = useState(false);
  const [hovered, setHovered] = useState(null);
  const [unreadSiteNotes, setUnreadSiteNotes] = useState(0);
  const menuRef = useRef(null);
  const hoverTimerRef = useRef(null);
  const touchHandledRef = useRef(false);

  useEffect(() => {
    function handleClickOutside(e) {
      if (menuRef.current && !menuRef.current.contains(e.target)) {
        setOpen(false);
        setHovered(null);
      }
    }
    if (open) document.addEventListener("pointerdown", handleClickOutside);
    return () => document.removeEventListener("pointerdown", handleClickOutside);
  }, [open]);

  useEffect(() => {
    return () => {
      if (hoverTimerRef.current) {
        clearTimeout(hoverTimerRef.current);
        hoverTimerRef.current = null;
      }
    };
  }, []);

  useEffect(() => {
    if (!admin || !isAdminEntry || !useInboxBadge) return undefined;
    let ignore = false;
    let timeoutId = null;

    function readCachedInboxCount() {
      try {
        const cached = JSON.parse(window.sessionStorage.getItem(INBOX_COUNT_CACHE_KEY) || "null");
        if (!cached || Date.now() - Number(cached.savedAt || 0) > INBOX_COUNT_CACHE_MS) return null;
        return Number(cached.count || 0);
      } catch {
        return null;
      }
    }

    function writeCachedInboxCount(count) {
      try {
        window.sessionStorage.setItem(
          INBOX_COUNT_CACHE_KEY,
          JSON.stringify({ count: Number(count || 0), savedAt: Date.now() })
        );
      } catch {
        // Ignore storage failures; the badge is non-critical.
      }
    }

    async function loadInboxCount() {
      const controller = new AbortController();
      const abortId = window.setTimeout(() => controller.abort(), INBOX_COUNT_TIMEOUT_MS);
      try {
        const res = await fetch(`${CLIENT_INBOX_COUNT_URL}?_=${Date.now()}`, {
          credentials: "include",
          signal: controller.signal,
        });
        const data = await res.json();
        if (!ignore && res.ok && data?.ok) {
          const count = Number(data.unread_site_note_count || 0);
          setUnreadSiteNotes(count);
          writeCachedInboxCount(count);
        }
      } catch {
        const cached = readCachedInboxCount();
        if (!ignore && cached !== null) setUnreadSiteNotes(cached);
      } finally {
        window.clearTimeout(abortId);
      }
    }

    const cached = readCachedInboxCount();
    if (cached !== null) setUnreadSiteNotes(cached);
    timeoutId = window.setTimeout(loadInboxCount, 750);
    const intervalId = window.setInterval(loadInboxCount, 60000);
    return () => {
      ignore = true;
      if (timeoutId) window.clearTimeout(timeoutId);
      window.clearInterval(intervalId);
    };
  }, [admin, isAdminEntry, useInboxBadge]);

  function scheduleHoverClose() {
    if (hoverTimerRef.current) clearTimeout(hoverTimerRef.current);
    hoverTimerRef.current = setTimeout(() => {
      setHovered(null);
    }, 250);
  }

  function cancelHoverClose() {
    if (hoverTimerRef.current) {
      clearTimeout(hoverTimerRef.current);
      hoverTimerRef.current = null;
    }
  }

  if (!admin || !isAdminEntry) return null;

  const activeGroupIndex = adminMenuItems.findIndex((group) =>
    group.items.some((item) => isPathMatch(currentPath, item.href))
  );

  function toggleMenu(e) {
    e?.preventDefault?.();
    e?.stopPropagation?.();
    setOpen((v) => {
      const next = !v;
      if (!next) {
        setHovered(null);
      } else {
        setHovered(null);
      }
      return next;
    });
  }

  function handleTouchEnd(e) {
    touchHandledRef.current = true;
    toggleMenu(e);
    window.setTimeout(() => {
      touchHandledRef.current = false;
    }, 250);
  }

  function handleClick(e) {
    if (touchHandledRef.current) {
      e?.preventDefault?.();
      e?.stopPropagation?.();
      return;
    }
    toggleMenu(e);
  }

  return (
    <div className="admin-menu" ref={menuRef}>
      <button
        type="button"
        className="admin-menu__trigger"
        onClick={handleClick}
        onTouchEnd={handleTouchEnd}
        aria-haspopup="menu"
        aria-expanded={open}
      >
        Admin ▾
      </button>
      {open && (
        <div className="admin-menu__panel" role="menu">
          <button
            type="button"
            className="admin-menu__item admin-menu__back"
            onClick={() => {
              setOpen(false);
              setHovered(null);
              if (window.history.length > 1) {
                window.history.back();
              } else {
                window.location.href = "/";
              }
            }}
          >
            ← Back
          </button>
          {adminMenuItems.map((group, idx) => {
            const groupIsActive = idx === activeGroupIndex;
            return (
              <div
                key={group.label}
                className={`admin-menu__group${groupIsActive ? " is-active" : ""}`}
                onMouseEnter={() => {
                  cancelHoverClose();
                  setHovered(idx);
                }}
                onMouseLeave={scheduleHoverClose}
                onClick={() => {
                  cancelHoverClose();
                  setHovered((prev) => (prev === idx ? null : idx));
                }}
              >
                <div className="admin-menu__group-label">
                  {group.label}
                  <span className="admin-menu__chev">›</span>
                </div>
                {(hovered === idx) && (
                  <div
                    className="admin-menu__submenu"
                    role="menu"
                    onMouseEnter={cancelHoverClose}
                    onMouseLeave={scheduleHoverClose}
                  >
                    {group.items.map((item) => (
                      <a
                        key={item.href}
                        href={item.href}
                        className={`admin-menu__item${isPathMatch(currentPath, item.href) ? " is-active" : ""}`}
                        onClick={() => {
                          if (item.adminExitPath) {
                            setAdminExitPath(item.adminExitPath);
                          }
                          setOpen(false);
                          setHovered(null);
                        }}
                      >
                        <span>{item.label}</span>
                        {useInboxBadge && item.href === "/admin/clients" && unreadSiteNotes > 0 ? (
                          <span className="admin-menu__badge">{unreadSiteNotes}</span>
                        ) : null}
                      </a>
                    ))}
                  </div>
                )}
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
