import { useEffect, useState } from "react";
import "./styles/AdminDetailPane.css";

export default function AdminDetailPane({
  children,
  className = "",
  ariaLabel = "Detail",
  title = "",
  meta = null,
  actions = null,
  subActions = null,
  jumpToId = null,
}) {
  const [jumpValue, setJumpValue] = useState(String(jumpToId?.initialId ?? ""));
  const [jumpBusy, setJumpBusy] = useState(false);
  const [jumpError, setJumpError] = useState("");

  useEffect(() => {
    setJumpValue(String(jumpToId?.initialId ?? ""));
    setJumpError("");
  }, [jumpToId?.initialId]);

  const hasTitle =
    typeof title === "string"
      ? title.trim() !== ""
      : Boolean(title);

  const canJump = Boolean(jumpToId && typeof jumpToId.onJump === "function");

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
      if (result === false) setJumpError(`ID #${id} was not found.`);
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

  return (
    <section
      className={`admin-detail-pane ${className}`.trim()}
      aria-label={ariaLabel}
    >
      <div className="admin-detail-pane__chrome">
        <div
          className="admin-detail-pane__actions"
          aria-label={`${ariaLabel} actions`}
        >
          {canJump ? (
            <form className="admin-detail-pane__jump" onSubmit={submitJump}>
              <label htmlFor={`admin-jump-${String(ariaLabel).replace(/[^a-z0-9]/gi, "-")}`}>
                {jumpToId.label || "ID"}
              </label>
              <input
                id={`admin-jump-${String(ariaLabel).replace(/[^a-z0-9]/gi, "-")}`}
                className="admin-detail-pane__jump-input"
                type="text"
                inputMode="numeric"
                pattern="[0-9]*"
                autoComplete="off"
                value={jumpValue}
                placeholder={jumpToId.placeholder || "#"}
                aria-label={`Jump to ${jumpToId.label || "ID"}`}
                disabled={jumpBusy}
                onChange={(event) => {
                  setJumpValue(event.target.value);
                  setJumpError("");
                }}
              />
              <button className="admin-button" type="submit" disabled={jumpBusy}>
                {jumpBusy ? "Finding…" : "Go"}
              </button>
              {jumpValue ? (
                <button className="admin-button admin-button--secondary" type="button" onClick={clearJump} disabled={jumpBusy}>
                  Clear
                </button>
              ) : null}
              {jumpError ? <span className="admin-detail-pane__jump-error" role="alert">{jumpError}</span> : null}
            </form>
          ) : null}
          {actions}
        </div>

        {hasTitle ? (
          <div className="admin-detail-pane__titlebar">
            <div className="admin-detail-pane__title-row">
              <h1 className="admin-detail-pane__title">
                {title}
              </h1>
              {meta ? (
                <div className="admin-detail-pane__meta">
                  {meta}
                </div>
              ) : null}
            </div>
          </div>
        ) : null}

        {subActions ? (
          <div
            className="admin-detail-pane__subactions"
            aria-label={`${ariaLabel} secondary actions`}
          >
            {subActions}
          </div>
        ) : null}
      </div>

      <div className="admin-detail-pane__body">
        {children}
      </div>
    </section>
  );
}
