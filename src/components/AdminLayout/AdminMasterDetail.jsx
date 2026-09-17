import {
  cloneElement,
  isValidElement,
  useEffect,
  useMemo,
  useRef,
  useState,
} from "react";

function clamp(value, min, max) {
  return Math.min(Math.max(value, min), max);
}

function normalizeItemId(value) {
  if (value === null || value === undefined || value === "") {
    return null;
  }

  return String(value);
}

export default function AdminMasterDetail({
  list,
  detail,
  drawer = null,
  selectedId = null,
  storageKey = "admin-master-detail-width",
  defaultListWidth = 320,
  minListWidth = 50,
  maxListWidth = 520,
  className = "",
}) {
  const shellRef = useRef(null);

  const initialWidth = useMemo(() => {
    if (typeof window === "undefined") return defaultListWidth;
    const stored = Number(window.localStorage.getItem(storageKey));
    return Number.isFinite(stored) && stored > 0 ? stored : defaultListWidth;
  }, [defaultListWidth, storageKey]);

  const [listWidth, setListWidth] = useState(initialWidth);
  const [dragging, setDragging] = useState(false);

  const [drawerOpen, setDrawerOpen] = useState(false);
  const [drawerItemId, setDrawerItemId] = useState(null);
  const [lastListItemId, setLastListItemId] = useState(null);

  const hasDrawer = Boolean(drawer);
  const currentItemId =
    normalizeItemId(selectedId)
    ?? normalizeItemId(lastListItemId);

  useEffect(() => {
    if (typeof window !== "undefined") {
      window.localStorage.setItem(storageKey, String(listWidth));
    }
  }, [listWidth, storageKey]);

  useEffect(() => {
    if (!dragging) return;

    function onPointerMove(event) {
      const shell = shellRef.current;
      if (!shell) return;

      const rect = shell.getBoundingClientRect();
      const availableMax = Math.max(
        minListWidth,
        Math.min(maxListWidth, rect.width - 320)
      );

      const nextWidth = clamp(
        event.clientX - rect.left,
        minListWidth,
        availableMax
      );

      setListWidth(nextWidth);
    }

    function onPointerUp() {
      setDragging(false);
    }

    window.addEventListener("pointermove", onPointerMove);
    window.addEventListener("pointerup", onPointerUp, { once: true });

    return () => {
      window.removeEventListener("pointermove", onPointerMove);
      window.removeEventListener("pointerup", onPointerUp);
    };
  }, [dragging, minListWidth, maxListWidth]);

  useEffect(() => {
    if (!drawerOpen || currentItemId === null) {
      return;
    }

    setDrawerItemId(currentItemId);
  }, [currentItemId, drawerOpen]);

  function onSplitterKeyDown(event) {
    const step = event.shiftKey ? 40 : 12;

    if (event.key === "ArrowLeft") {
      event.preventDefault();
      setListWidth((value) =>
        clamp(value - step, minListWidth, maxListWidth)
      );
    }

    if (event.key === "ArrowRight") {
      event.preventDefault();
      setListWidth((value) =>
        clamp(value + step, minListWidth, maxListWidth)
      );
    }

    if (event.key === "Home") {
      event.preventDefault();
      setListWidth(minListWidth);
    }

    if (event.key === "End") {
      event.preventDefault();
      setListWidth(maxListWidth);
    }
  }

  function findListItemId(event) {
    const item = event.target.closest?.("[data-admin-object-id]");

    if (!item || !event.currentTarget.contains(item)) {
      return null;
    }

    return normalizeItemId(item.getAttribute("data-admin-object-id"));
  }

  function handleListClick(event) {
    const itemId = findListItemId(event);

    if (itemId !== null) {
      setLastListItemId(itemId);
    }
  }

  function toggleDrawerFor(itemId) {
    if (!hasDrawer) return;

    const normalizedId = normalizeItemId(itemId);
    if (normalizedId === null) return;

    if (drawerOpen && drawerItemId === normalizedId) {
      setDrawerOpen(false);
      return;
    }

    setDrawerItemId(normalizedId);
    setDrawerOpen(true);
  }

  function handleListDoubleClick(event) {
    const itemId = findListItemId(event);

    if (itemId === null) {
      return;
    }

    setLastListItemId(itemId);
    toggleDrawerFor(itemId);
  }

  function handleDetailDoubleClick() {
    if (!hasDrawer || currentItemId === null) {
      return;
    }

    toggleDrawerFor(currentItemId);
  }

  function closeDrawer() {
    setDrawerOpen(false);

    if (isValidElement(drawer)) {
      drawer.props.onClose?.();
    }
  }

  const renderedDrawer =
    hasDrawer && isValidElement(drawer)
      ? cloneElement(drawer, {
          open: drawerOpen,
          onClose: closeDrawer,
        })
      : drawer;

  return (
    <>
      <div
        ref={shellRef}
        className={`admin-master-detail ${dragging ? "is-resizing" : ""} ${className}`.trim()}
        style={{ "--admin-list-width": `${listWidth}px` }}
      >
        <div
          className="admin-master-detail__list"
          onClick={handleListClick}
          onDoubleClick={handleListDoubleClick}
        >
          {list}
        </div>

        <div
          className="admin-master-detail__splitter"
          role="separator"
          aria-orientation="vertical"
          aria-label="Resize list pane"
          aria-valuemin={minListWidth}
          aria-valuemax={maxListWidth}
          aria-valuenow={Math.round(listWidth)}
          tabIndex={0}
          onPointerDown={(event) => {
            event.preventDefault();
            event.currentTarget.setPointerCapture?.(event.pointerId);
            setDragging(true);
          }}
          onKeyDown={onSplitterKeyDown}
        />

        <div
          className="admin-master-detail__detail"
          onDoubleClick={handleDetailDoubleClick}
        >
          {detail}
        </div>
      </div>

      {renderedDrawer}
    </>
  );
}
