import React from "react";
import PaletteRow from "./PaletteRow";
import "./browse-palettes.css";

export default function AccordionSection({
  size,
  count,
  items,
  open,
  loading,          // <-- add this
  onToggle,
  loadMore,
  nextOffset,
  setInspected,
  accHeaderRef,
}) {
  const panelId = `bpv1-sec-${size}`;
  const fmt = (n) => (n ?? 0).toLocaleString();
  const isLoading = !!loading;

  return (
    <section className={`bpv1-acc ${open ? "is-open" : ""}`}>
      <button
        ref={accHeaderRef}
        className="bpv1-acc-header"
        onMouseDown={(e) => e.preventDefault()}
        onClick={onToggle}
        aria-expanded={open}
        aria-controls={panelId}
        type="button"
      >
        <span className="bpv1-caret" aria-hidden>
          {open ? "▾" : "▸"}
        </span>
        <span className="bpv1-group-title">
          {size}-color palettes <span className="bpv1-count">({fmt(count)})</span>
        </span>
      </button>

      <div id={panelId} className="bpv1-acc-panel" hidden={!open}>
        <div className="bpv1-group-list">
          {(items || []).map((p, idx) => (
            <PaletteRow
              key={p.palette_id ?? idx}
              palette={p}
              onClick={setInspected}
            />
          ))}
        </div>

        {nextOffset != null && (
          <div className="bpv1-acc-footer">
            <button
              type="button"
              className={`bpv1-load-more${isLoading ? " is-loading" : ""}`}
              onClick={() => loadMore(size)}
              disabled={isLoading}
            >
              {isLoading ? "Loading…" : "Load more"}
            </button>
          </div>
        )}
      </div>
    </section>
  );
}
