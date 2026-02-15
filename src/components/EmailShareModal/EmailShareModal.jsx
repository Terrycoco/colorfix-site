import { useEffect, useState } from "react";
import "./email-share-modal.css";

export default function EmailShareModal({
  open,
  title = "Send Link",
  templates = [],
  templateKey = "",
  onTemplateChange,
  toEmail = "",
  onToEmailChange,
  subject = "",
  onSubjectChange,
  htmlBody = "",
  onHtmlBodyChange,
  message = "",
  onMessageChange,
  sendFormat = "html",
  onSendFormatChange,
  shareLink = "",
  status = { loading: false, error: "", success: "" },
  onSend,
  onClose,
}) {
  const [showHtmlPreview, setShowHtmlPreview] = useState(false);

  useEffect(() => {
    if (open) setShowHtmlPreview(false);
  }, [open]);

  if (!open) return null;

  return (
    <div className="admin-email-modal" role="dialog" aria-modal="true">
      <div className="admin-email-panel">
        <div className="admin-email-header">
          <h3>{title}</h3>
          <button type="button" className="admin-email-close" onClick={onClose}>
            Close
          </button>
        </div>
        <div className="admin-email-body">
          <label>
            Template
            <select value={templateKey} onChange={(e) => onTemplateChange?.(e.target.value)}>
              {templates.length === 0 && <option value="">Default</option>}
              {templates.map((tpl) => (
                <option key={tpl.key} value={tpl.key}>
                  {tpl.label || tpl.key}
                </option>
              ))}
            </select>
          </label>
          <label>
            To
            <input
              type="email"
              value={toEmail}
              onChange={(e) => onToEmailChange?.(e.target.value)}
              placeholder="name@email.com"
            />
          </label>
          <label>
            Subject
            <input type="text" value={subject} onChange={(e) => onSubjectChange?.(e.target.value)} />
          </label>
          <label>
            HTML Body (optional)
            <div className="admin-email-choice">
              <input
                type="radio"
                name="email-format"
                checked={sendFormat === "html"}
                onChange={() => onSendFormatChange?.("html")}
              />
              <span>Send HTML</span>
            </div>
            <div className="admin-email-html-toggle">
              <button
                type="button"
                className={showHtmlPreview ? "" : "active"}
                onClick={() => setShowHtmlPreview(false)}
                disabled={sendFormat !== "html"}
              >
                Edit HTML
              </button>
              <button
                type="button"
                className={showHtmlPreview ? "active" : ""}
                onClick={() => setShowHtmlPreview(true)}
                disabled={sendFormat !== "html"}
              >
                Preview
              </button>
            </div>
            {showHtmlPreview ? (
              <div
                className="admin-email-html-preview"
                dangerouslySetInnerHTML={{ __html: htmlBody || "<em>No HTML body yet.</em>" }}
              />
            ) : (
              <textarea
                rows={12}
                value={htmlBody}
                onChange={(e) => onHtmlBodyChange?.(e.target.value)}
                disabled={sendFormat !== "html"}
              />
            )}
          </label>
          <label>
            Message (plain text fallback)
            <div className="admin-email-choice">
              <input
                type="radio"
                name="email-format"
                checked={sendFormat === "text"}
                onChange={() => onSendFormatChange?.("text")}
              />
              <span>Send plain text</span>
            </div>
            <textarea
              rows={4}
              value={message}
              onChange={(e) => onMessageChange?.(e.target.value)}
              disabled={sendFormat !== "text"}
            />
          </label>
          <label>
            Share Link
            <input type="text" readOnly value={shareLink} onFocus={(e) => e.target.select()} />
          </label>
          {status.error && <div className="admin-email-status error">{status.error}</div>}
          {status.success && <div className="admin-email-status success">{status.success}</div>}
        </div>
        <div className="admin-email-actions">
          <button type="button" className="primary-btn" onClick={onSend} disabled={status.loading}>
            {status.loading ? "Sending..." : "Send Email"}
          </button>
          <button type="button" onClick={onClose}>
            Close
          </button>
        </div>
      </div>
    </div>
  );
}
