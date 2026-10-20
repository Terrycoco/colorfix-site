import { useLayoutEffect, useRef } from "react";

// Lifecycle support for the existing shared components, not an overlay renderer.
// Keep one keyboard owner and one scroll lock across independently portaled roots.
const overlays = [];
let savedOverflow;
const focusableSelector = 'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

// Outside-click handlers must include portaled children of their own controls.
export function containsOverlayTarget(container, target) {
  if (!container) return false;
  if (container.contains(target)) return true;
  for (const entry of overlays) {
    if (!entry.root.contains(target)) continue;
    let child = entry;
    while (child) {
      if (container.contains(child.options.current.anchorRef?.current) || container.contains(child.root)) return true;
      child = child.owner;
    }
  }
  return false;
}

function topOverlay() {
  return overlays.filter((entry) => entry.root.isConnected).reduce((top, entry) => {
    const level = Number.parseFloat(getComputedStyle(entry.root).zIndex) || 0;
    const topLevel = top ? Number.parseFloat(getComputedStyle(top.root).zIndex) || 0 : -Infinity;
    return level >= topLevel ? entry : top;
  }, null);
}

function focusable(root) {
  return [...root.querySelectorAll(focusableSelector)].filter((node) =>
    node.getClientRects().length && !node.closest('[inert], [hidden], [aria-hidden="true"]')
  );
}

function focusEntry(entry) {
  const target = entry.options.current.initialFocus?.()
    || focusable(entry.root)[0]
    || entry.root;
  target.focus({ preventScroll: true });
}

function ownsFocus(entry, target) {
  if (entry.root.contains(target)) return true;
  for (const child of overlays) {
    let owner = child.owner;
    while (owner) {
      if (owner === entry && child.root.contains(target)) return true;
      owner = owner.owner;
    }
  }
  // Notifications stay reachable above modal surfaces.
  return Boolean(target?.closest?.('[data-overlay-notification]'));
}

function onFocus(event) {
  let top = topOverlay();
  while (top?.options.current.modal === false) top = top.owner;
  if (top?.options.current.modal !== false && top && !ownsFocus(top, event.target)) {
    focusEntry(top);
  }
}

function onKeyDown(event) {
  const top = topOverlay();
  if (!top) return;
  if (event.key === "Escape") {
    if (top.options.current.modal === false && !top.options.current.onEscape) return;
    // Even a busy/non-dismissible top surface shields its owner from Escape.
    event.preventDefault();
    event.stopImmediatePropagation();
    top.options.current.onEscape?.();
    return;
  }
  if (event.key !== "Tab") return;
  let scope = top;
  while (scope?.options.current.modal === false) scope = scope.owner;
  if (!scope) return;
  const nodes = focusable(scope.root);
  if (scope !== top) nodes.push(...focusable(top.root));
  const first = nodes[0];
  const last = nodes.at(-1);
  if (!first) {
    event.preventDefault();
    scope.root.focus({ preventScroll: true });
  } else if (!ownsFocus(scope, document.activeElement)
    || (event.shiftKey && document.activeElement === first)
    || (!event.shiftKey && document.activeElement === last)) {
    event.preventDefault();
    (event.shiftKey ? last : first).focus();
  }
}

function syncOverlays() {
  const top = topOverlay();
  for (const entry of overlays) {
    // Inline child overlays must not inherit inert from their owner.
    let interactiveOwner = top;
    while (interactiveOwner?.options.current.modal === false && interactiveOwner !== entry) {
      interactiveOwner = interactiveOwner.owner;
    }
    entry.root.inert = entry !== top && entry !== interactiveOwner && !entry.root.contains(top?.root);
  }
  const locked = overlays.some((entry) => entry.options.current.modal !== false);
  if (locked && savedOverflow === undefined) {
    savedOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
  } else if (!locked && savedOverflow !== undefined) {
    document.body.style.overflow = savedOverflow;
    savedOverflow = undefined;
  }
}

/**
 * Attach to the existing overlay root (the element with the semantic z-index).
 * Nested roots rise above their owner using the shared local step, capped below
 * notifications. Callbacks stay current without restarting focus/scroll effects.
 */
export default function useOverlayLifecycle({ open = true, layer, ...settings } = {}) {
  const rootRef = useRef(null);
  const options = useRef(settings);
  options.current = settings;

  useLayoutEffect(() => {
    const root = rootRef.current;
    if (!open || !root) return undefined;
    const previousFocus = document.activeElement;
    const previousZIndex = root.style.zIndex;
    const previousInert = root.inert;
    const previousTabIndex = root.getAttribute("tabindex");
    root.setAttribute("tabindex", "-1");

    const owner = topOverlay();
    if (layer) root.style.zIndex = `var(--z-${layer})`;
    if (layer && owner) {
      const ownerLevel = owner.root.style.zIndex || getComputedStyle(owner.root).zIndex;
      root.style.zIndex = `min(calc(var(--z-toast) - var(--z-above)), max(var(--z-${layer}), calc(${ownerLevel} + var(--z-above))))`;
    }
    const entry = { root, options, owner };
    overlays.push(entry);
    if (overlays.length === 1) {
      document.addEventListener("keydown", onKeyDown, true);
      document.addEventListener("focusin", onFocus, true);
    }
    syncOverlays();
    const frame = requestAnimationFrame(() => {
      if (topOverlay() === entry && options.current.autoFocus !== false) focusEntry(entry);
    });

    return () => {
      cancelAnimationFrame(frame);
      const wasTop = topOverlay() === entry || !root.isConnected;
      overlays.splice(overlays.indexOf(entry), 1);
      root.style.zIndex = previousZIndex;
      root.inert = previousInert;
      if (previousTabIndex === null) root.removeAttribute("tabindex");
      else root.setAttribute("tabindex", previousTabIndex);
      syncOverlays();
      if (!overlays.length) {
        document.removeEventListener("keydown", onKeyDown, true);
        document.removeEventListener("focusin", onFocus, true);
      }
      if (wasTop) {
        const top = topOverlay();
        if (previousFocus?.isConnected && !previousFocus.closest('[inert]')
          && (!top || top.options.current.modal === false || ownsFocus(top, previousFocus))) {
          previousFocus.focus({ preventScroll: true });
        } else if (top) focusEntry(top);
      }
    };
  }, [open, layer]);

  return rootRef;
}
