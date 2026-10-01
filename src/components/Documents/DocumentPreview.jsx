import {
  useEffect,
} from "react";

import {
  createPortal,
} from "react-dom";

import DocumentBody from "./DocumentBody";


function escapeHtml(value) {
  return String(value || "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}


export default function DocumentPreview({
  open = false,
  document: documentRecord = null,
  onClose,
}) {
  const previewTitle =
    String(
      documentRecord?.title
      ||
      "Document Preview"
    );

  const previewHtml =
    String(
      documentRecord?.content_html
      ||
      ""
    );

  const renderedHtml =
    `<h1>${escapeHtml(previewTitle)}</h1>`
    +
    (
      previewHtml
        ? previewHtml
        : "<p>Nothing to preview.</p>"
    );


  useEffect(
    () => {
      if (
        !open
        ||
        typeof window === "undefined"
        ||
        typeof document === "undefined"
      ) {
        return undefined;
      }

      const previousOverflow =
        document.body.style.overflow;

      document.body.style.overflow =
        "hidden";

      function handleKeyDown(event) {
        if (event.key === "Escape") {
          onClose?.();
        }
      }

      window.addEventListener(
        "keydown",
        handleKeyDown
      );

      return () => {
        window.removeEventListener(
          "keydown",
          handleKeyDown
        );

        document.body.style.overflow =
          previousOverflow;
      };
    },
    [
      open,
      onClose,
    ]
  );


  if (
    !open
    ||
    typeof document === "undefined"
  ) {
    return null;
  }


  return createPortal(
    <div
      className="document-review-overlay"
      role="dialog"
      aria-modal="true"
      aria-label={previewTitle}
      title="Click to close"
      onClick={onClose}
    >
      <div className="document-review-overlay__stage">
        <DocumentBody
          html={renderedHtml}
          className="document-sheet--review"
        />
      </div>
    </div>,
    document.body
  );
}