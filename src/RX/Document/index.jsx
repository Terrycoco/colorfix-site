import { useEffect, useState } from "react";
import DocumentBody from "@components/Documents/DocumentBody";
import "./document.css";

const API_FOLDER = import.meta.env.VITE_API_FOLDER || "/api";

export default function Document({ document: documentRecord }) {
  const [document, setDocument] = useState(documentRecord);
  const [approving, setApproving] = useState(false);
  const [approvalError, setApprovalError] = useState("");

  useEffect(() => {
    setDocument(documentRecord);
    setApprovalError("");
    setApproving(false);
  }, [documentRecord]);

  if (!document) return null;

  const approvalRequired = Boolean(document.approval_required);
  const acceptedAt = document.accepted_at || null;
  const clientName = String(
    document.approved_by || document.client_name || ""
  ).trim();
  const documentTitle =
    String(document.title || "").trim() || "this agreement";

  async function handleApprove() {
    const documentId = Number(document.id || 0);

    if (!documentId || approving) return;

    setApproving(true);
    setApprovalError("");

    try {
      const response = await fetch(
        `${API_FOLDER}/v2/documents/approve.php`,
        {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
          },
          body: JSON.stringify({
            document_id: documentId,
          }),
        }
      );

      const payload = await response.json().catch(() => null);

      if (!response.ok || !payload?.ok) {
        throw new Error(
          payload?.error || "Unable to approve document."
        );
      }

      const approvedDocument = payload?.data?.document;

      if (!approvedDocument) {
        throw new Error(
          "Approval succeeded but no document was returned."
        );
      }

      setDocument((current) => ({
        ...current,
        ...approvedDocument,
        client_name:
          approvedDocument.client_name ||
          approvedDocument.approved_by ||
          current.client_name ||
          "",
      }));
    } catch (error) {
      setApprovalError(
        error instanceof Error
          ? error.message
          : "Unable to approve document."
      );
    } finally {
      setApproving(false);
    }
  }

  return (
    <main className="rex-document">
      <article className="rex-document__frame">
        <DocumentBody
          html={String(document.content_html || "")}
          letterhead
        />

        {approvalRequired ? (
          <footer className="rex-document__approval">
            {acceptedAt ? (
              <div className="rex-document__accepted">
                <div className="rex-document__approval-label">
                  Approved — {formatDateTime(acceptedAt)}
                </div>
                <div className="rex-document__approval-detail">
                  {clientName
                    ? `${clientName} approved the ${documentTitle}.`
                    : `The ${documentTitle} was approved.`}
                </div>
              </div>
            ) : (
              <>
                <div className="rex-document__approval-copy">
                  <div className="rex-document__approval-label">
                    {clientName
                      ? `I, ${clientName}, have reviewed and approve the ${documentTitle} above.`
                      : `I have reviewed and approve the ${documentTitle} above.`}
                  </div>
                  <div className="rex-document__approval-detail">
                    By clicking Approve Agreement, you confirm this approval.
                  </div>
                </div>

                <button
                  type="button"
                  className="rex-document__approve-button"
                  onClick={handleApprove}
                  disabled={approving}
                >
                  {approving ? "Approving…" : "Approve Agreement"}
                </button>

                {approvalError ? (
                  <div
                    className="rex-document__approval-error"
                    role="alert"
                  >
                    {approvalError}
                  </div>
                ) : null}
              </>
            )}
          </footer>
        ) : null}
      </article>
    </main>
  );
}

function formatDateTime(value) {
  if (!value) return "";

  const normalized = String(value).includes("T")
    ? String(value)
    : String(value).replace(" ", "T");

  const date = new Date(normalized);

  if (Number.isNaN(date.getTime())) {
    return String(value);
  }

  return date.toLocaleString(undefined, {
    year: "numeric",
    month: "long",
    day: "numeric",
    hour: "numeric",
    minute: "2-digit",
  });
}
