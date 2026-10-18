import { useEffect, useState } from "react";
import "./MobileDetailPane.css";

// Mobile counterpart of AdminDetailPane: same public props and callbacks.
export default function MobileDetailPane({
  children,
  className = "",
  ariaLabel = "Detail",
  title = "",
  meta = null,
  actions = null,
  subActions = null,
  jumpToId = null,
}) {
  const [menuOpen, setMenuOpen] = useState(false);
  const [jumpValue, setJumpValue] = useState(String(jumpToId?.initialId ?? ""));
  const [jumpBusy, setJumpBusy] = useState(false);
  const [jumpError, setJumpError] = useState("");

  useEffect(() => {
    setJumpValue(String(jumpToId?.initialId ?? ""));
    setJumpError("");
  }, [jumpToId?.initialId]);

  const hasTitle = typeof title === "string" ? title.trim() !== "" : Boolean(title);
  const canJump = Boolean(jumpToId && typeof jumpToId.onJump === "function");
  const hasActions = Boolean(canJump || actions);

  async function submitJump(event) {
    event.preventDefault();
    if (!canJump || jumpBusy) return;
    const id = jumpValue.trim();
    if (!/^[1-9]\d*$/.test(id)) {
      setJumpError("Enter a valid ID.");
      return;
    }
    setJumpBusy(true);
    setJumpError("");
    try {
      const result = await jumpToId.onJump(id);
      if (result === false) {
        setJumpError(`ID #${id} was not found.`);
      }
    } catch (failure) {
      setJumpError(failure?.message || `Could not find ID #${id}.`);
    } finally {
      setJumpBusy(false);
    }
  }

  function clearJump() {
    setJumpValue("");
    setJumpError("");
    jumpToId?.onClear?.();
  }

  function handleActionClick(event) {
    // Close after a normal action, but not after toggling an embedded input.
    const control = event.target.closest("button, a[href]");
    if (control && !control.disabled) setMenuOpen(false);
  }

  return (
    <section className={`admin-detail-pane mobile-detail-pane ${className}`.trim()} aria-label={ariaLabel}>
      <div className="admin-detail-pane__chrome">
        {hasActions ? (
          <div className="mobile-detail-pane__toolbar">
            <div className="mobile-detail-pane__top-row">
              {actions ? (
                <button
                  type="button"
                  className="mobile-detail-pane__bar"
                  aria-label={menuOpen ? "Collapse toolbar" : "Expand toolbar"}
                  aria-expanded={menuOpen}
                  aria-controls="mobile-detail-pane-actions"
                  onClick={() => setMenuOpen((current) => !current)}
                >
                  <span className="mobile-detail-pane__hamburger" aria-hidden="true">☰</span>
                </button>
              ) : null}
              {canJump ? (
                <form className="admin-detail-pane__jump mobile-detail-pane__jump" onSubmit={submitJump}>
                  <label htmlFor={`mobile-admin-jump-${String(ariaLabel).replace(/[^a-z0-9]/gi, "-")}`}>
                    {jumpToId.label || "ID"}
                  </label>
                  <input
                    id={`mobile-admin-jump-${String(ariaLabel).replace(/[^a-z0-9]/gi, "-")}`}
                    className="admin-detail-pane__jump-input"
                    type="text" inputMode="numeric" pattern="[0-9]*" autoComplete="off"
                    value={jumpValue} placeholder={jumpToId.placeholder || "#"}
                    aria-label={`Jump to ${jumpToId.label || "ID"}`}
                    disabled={jumpBusy}
                    onChange={(event) => { setJumpValue(event.target.value); setJumpError(""); }}
                  />
                  <button className="admin-button" type="submit" disabled={jumpBusy}>
                    {jumpBusy ? "Finding…" : "Go"}
                  </button>
                  {jumpValue ? (
                    <button className="admin-button admin-button--secondary" type="button"
                      disabled={jumpBusy} onClick={clearJump}>Clear</button>
                  ) : null}
                </form>
              ) : null}
            </div>
            {jumpError ? <span className="mobile-detail-pane__jump-error" role="alert">{jumpError}</span> : null}
            {menuOpen && actions ? (
              <div className="mobile-detail-pane__dropdown" id="mobile-detail-pane-actions"
                aria-label={`${ariaLabel} actions`}>
                {actions ? (
                  <div className="mobile-detail-pane__actions" onClick={handleActionClick}>
                    {actions}
                  </div>
                ) : null}
              </div>
            ) : null}
          </div>
        ) : null}
        {hasTitle ? (
          <div className="admin-detail-pane__titlebar">
            <div className="admin-detail-pane__title-row">
              <h1 className="admin-detail-pane__title">{title}</h1>
              {meta ? <div className="admin-detail-pane__meta">{meta}</div> : null}
            </div>
          </div>
        ) : null}
        {subActions ? (
          <div className="admin-detail-pane__subactions" aria-label={`${ariaLabel} secondary actions`}>
            {subActions}
          </div>
        ) : null}
      </div>
      <div className="admin-detail-pane__body">{children}</div>
    </section>
  );
}
