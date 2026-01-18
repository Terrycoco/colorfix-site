import { useEffect, useRef, useState } from "react";
import { isAdmin } from "@helpers/authHelper";
import { adminMenuItems } from "./adminMenuItems";
import "./adminmenu.css";

export default function AdminMenu() {
  const admin = isAdmin();
  const [open, setOpen] = useState(false);
  const [hovered, setHovered] = useState(null);
  const menuRef = useRef(null);
  const hoverTimerRef = useRef(null);

  useEffect(() => {
    function handleClickOutside(e) {
      if (menuRef.current && !menuRef.current.contains(e.target)) {
        setOpen(false);
        setHovered(null);
      }
    }
    if (open) document.addEventListener("mousedown", handleClickOutside);
    return () => document.removeEventListener("mousedown", handleClickOutside);
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

  if (!admin) return null;

  return (
    <div className="admin-menu" ref={menuRef}>
      <button
        type="button"
        className="admin-menu__trigger"
        onClick={() => setOpen((v) => !v)}
        aria-haspopup="menu"
        aria-expanded={open}
      >
        Admin ▾
      </button>
      {open && (
        <div className="admin-menu__panel" role="menu">
          {adminMenuItems.map((group, idx) => (
            <div
              key={group.label}
              className="admin-menu__group"
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
                      className="admin-menu__item"
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
          ))}
        </div>
      )}
    </div>
  );
}
