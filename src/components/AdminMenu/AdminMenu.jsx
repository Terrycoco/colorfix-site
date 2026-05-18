import { useEffect, useRef, useState } from "react";
import { isAdmin } from "@helpers/authHelper";
import { adminMenuItems } from "./adminMenuItems";
import "./adminmenu.css";

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

export default function AdminMenu() {
  const admin = isAdmin();
  const currentPath = normalizeHrefPath(window.location.pathname);
  const isAdminEntry = currentPath === "/admin" || currentPath.startsWith("/admin/");
  const [open, setOpen] = useState(false);
  const [hovered, setHovered] = useState(null);
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
                          setOpen(false);
                          setHovered(null);
                        }}
                      >
                        {item.label}
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
