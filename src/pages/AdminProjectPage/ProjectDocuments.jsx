import {
  useEffect,
  useMemo,
  useState,
} from "react";

import {
  AdminButton,
  AdminDetailPane,
  AdminEmptyState,
  AdminNotice,
  AdminSmartGrid,
  AdminToolbar,
} from "@components/AdminLayout";

import FetchRexButton from "@components/REX/FetchRexButton";

import {
  API_FOLDER,
} from "@helpers/config";


const LIST_URL =
  `${API_FOLDER}/v2/admin/projects/documents/list.php`;

const GENERATE_URL =
  `${API_FOLDER}/v2/admin/projects/documents/generate.php`;

const TEMPLATE_LIST_URL =
  `${API_FOLDER}/v2/admin/documents/templates/list.php`;


function cleanText(value) {
  return String(value ?? "").trim();
}


async function readJson(response, fallbackMessage) {
  const text =
    await response.text();

  let data = {};

  try {
    data =
      text.trim()
        ? JSON.parse(text)
        : {};
  } catch {
    throw new Error(
      `${fallbackMessage}: invalid JSON response`
    );
  }

  if (
    !response.ok
    ||
    data?.ok === false
  ) {
    throw new Error(
      data?.error
      ||
      `HTTP ${response.status}`
    );
  }

  return data;
}


function formatDate(value) {
  if (!value) {
    return "—";
  }

  const normalized =
    String(value).includes("T")
      ? String(value)
      : String(value).replace(" ", "T");

  const date =
    new Date(normalized);

  if (
    Number.isNaN(
      date.getTime()
    )
  ) {
    return String(value);
  }

  return date.toLocaleDateString(
    undefined,
    {
      year: "numeric",
      month: "short",
      day: "numeric",
    }
  );
}


function templateLabel(template) {
  return (
    cleanText(
      template?.label
    )
    ||
    cleanText(
      template?.title
    )
    ||
    cleanText(
      template?.template_key
    )
    ||
    "Template"
  );
}


export default function ProjectDocuments({
  projectId,
  onSelectedDocumentChange,
  onPreview,
}) {
  const [
    documents,
    setDocuments,
  ] = useState([]);

  const [
    templates,
    setTemplates,
  ] = useState([]);

  const [
    selectedDocumentId,
    setSelectedDocumentId,
  ] = useState(null);

  const [
    selectedTemplateKey,
    setSelectedTemplateKey,
  ] = useState("");

  const [
    loading,
    setLoading,
  ] = useState(true);

  const [
    generating,
    setGenerating,
  ] = useState(false);

  const [
    error,
    setError,
  ] = useState("");

  const [
    statusMessage,
    setStatusMessage,
  ] = useState("");


  async function loadDocuments({
    preserveSelection = true,
  } = {}) {
    const id =
      Number(
        projectId || 0
      );

    if (id <= 0) {
      setDocuments([]);
      setSelectedDocumentId(null);
      return [];
    }

    const data =
      await readJson(
        await fetch(
          `${LIST_URL}?project_id=${encodeURIComponent(
            id
          )}&_=${Date.now()}`,
          {
            credentials:
              "include",
            cache:
              "no-store",
          }
        ),
        "Failed to load Project Documents"
      );

    const rows =
      Array.isArray(
        data?.documents
      )
        ? data.documents
        : [];

    setDocuments(
      rows
    );

    setSelectedDocumentId(
      (current) => {
        if (
          preserveSelection
          &&
          current
          &&
          rows.some(
            (document) =>
              Number(
                document?.id || 0
              )
              ===
              Number(current)
          )
        ) {
          return current;
        }

        return rows[0]?.id
          ? Number(
              rows[0].id
            )
          : null;
      }
    );

    return rows;
  }


  useEffect(
    () => {
      let cancelled =
        false;

      const id =
        Number(
          projectId || 0
        );

      if (id <= 0) {
        setDocuments([]);
        setTemplates([]);
        setSelectedDocumentId(null);
        setSelectedTemplateKey("");
        setLoading(false);
        setError("");
        return undefined;
      }

      setLoading(true);
      setError("");
      setStatusMessage("");

      (
        async () => {
          try {
            const [
              documentData,
              templateData,
            ] =
              await Promise.all([
                readJson(
                  await fetch(
                    `${LIST_URL}?project_id=${encodeURIComponent(
                      id
                    )}&_=${Date.now()}`,
                    {
                      credentials:
                        "include",
                      cache:
                        "no-store",
                    }
                  ),
                  "Failed to load Project Documents"
                ),

                readJson(
                  await fetch(
                    `${TEMPLATE_LIST_URL}?_=${Date.now()}`,
                    {
                      credentials:
                        "include",
                      cache:
                        "no-store",
                    }
                  ),
                  "Failed to load document templates"
                ),
              ]);

            if (cancelled) {
              return;
            }

            const rows =
              Array.isArray(
                documentData?.documents
              )
                ? documentData.documents
                : [];

            const activeTemplates =
              (
                Array.isArray(
                  templateData?.templates
                )
                  ? templateData.templates
                  : []
              ).filter(
                (template) =>
                  Number(
                    template?.is_active
                    ?? 1
                  ) === 1
              );

            setDocuments(
              rows
            );

            setTemplates(
              activeTemplates
            );

            setSelectedDocumentId(
              rows[0]?.id
                ? Number(
                    rows[0].id
                  )
                : null
            );

            setSelectedTemplateKey(
              (current) => {
                if (
                  current
                  &&
                  activeTemplates.some(
                    (template) =>
                      cleanText(
                        template?.template_key
                      )
                      ===
                      current
                  )
                ) {
                  return current;
                }

                return cleanText(
                  activeTemplates[0]
                    ?.template_key
                );
              }
            );

          } catch (err) {
            if (!cancelled) {
              setDocuments([]);
              setTemplates([]);
              setSelectedDocumentId(null);
              setSelectedTemplateKey("");

              setError(
                err?.message
                ||
                "Failed to load Project Documents."
              );
            }

          } finally {
            if (!cancelled) {
              setLoading(
                false
              );
            }
          }
        }
      )();

      return () => {
        cancelled =
          true;
      };
    },
    [
      projectId,
    ]
  );


  const selectedDocument =
    useMemo(
      () =>
        documents.find(
          (document) =>
            Number(
              document?.id || 0
            )
            ===
            Number(
              selectedDocumentId || 0
            )
        )
        ||
        null,
      [
        documents,
        selectedDocumentId,
      ]
    );


  useEffect(
    () => {
      onSelectedDocumentChange?.(
        selectedDocument
      );
    },
    [
      onSelectedDocumentChange,
      selectedDocument,
    ]
  );


  useEffect(
    () =>
      () => {
        onSelectedDocumentChange?.(
          null
        );
      },
    [
      onSelectedDocumentChange,
    ]
  );


  const columns =
    useMemo(
      () => [
        {
          key:
            "name",
          label:
            "Name",
          sortable:
            true,
          value:
            (document) =>
              cleanText(
                document?.title
              )
              ||
              `Document #${document?.id || ""}`,
        },
        {
          key:
            "type",
          label:
            "Type",
          sortable:
            true,
          value:
            (document) =>
              cleanText(
                document?.document_type
              )
              ||
              "Document",
        },
        {
          key:
            "approval_required",
          label:
            "Approval Reqd",
          sortable:
            true,
          value:
            (document) =>
              Number(
                document?.approval_required
                ?? 0
              ) === 1
                ? "Yes"
                : "No",
        },
        {
          key:
            "sent",
          label:
            "Sent",
          sortable:
            true,
          sortValue:
            (document) =>
              document?.sent_at
              || "",
          value:
            (document) =>
              formatDate(
                document?.sent_at
              ),
        },
      ],
      []
    );


  async function generateDocument() {
    const id =
      Number(
        projectId || 0
      );

    const templateKey =
      cleanText(
        selectedTemplateKey
      );

    if (
      generating
      ||
      id <= 0
      ||
      !templateKey
    ) {
      return;
    }

    const existing =
      documents.find(
        (document) =>
          cleanText(
            document?.template_key
          )
          ===
          templateKey
          &&
          !document?.sent_at
          &&
          !document?.accepted_at
          &&
          !document?.locked_at
      )
      ||
      null;

    const selectedTemplate =
      templates.find(
        (template) =>
          cleanText(
            template?.template_key
          )
          ===
          templateKey
      )
      ||
      null;

    const label =
      templateLabel(
        selectedTemplate
      );

    const confirmed =
      window.confirm(
        existing
          ? `Generate ${label}?\n\nThis will overwrite the existing unlocked draft.`
          : `Generate ${label}?`
      );

    if (!confirmed) {
      return;
    }

    setGenerating(true);
    setError("");
    setStatusMessage("");

    try {
      const data =
        await readJson(
          await fetch(
            GENERATE_URL,
            {
              method:
                "POST",
              credentials:
                "include",
              headers: {
                "Content-Type":
                  "application/json",
              },
              body:
                JSON.stringify({
                  project_id:
                    id,
                  template_key:
                    templateKey,
                }),
            }
          ),
          "Failed to generate document"
        );

      const generated =
        data?.document
        ||
        null;

      if (
        !generated
        ||
        Number(
          generated?.id || 0
        ) <= 0
      ) {
        throw new Error(
          "Document generation did not return a valid document."
        );
      }

      setDocuments(
        (current) => [
          generated,
          ...current.filter(
            (document) =>
              Number(
                document?.id || 0
              )
              !==
              Number(
                generated.id
              )
          ),
        ]
      );

      setSelectedDocumentId(
        Number(
          generated.id
        )
      );

      setStatusMessage(
        "Document generated."
      );

    } catch (err) {
      setError(
        err?.message
        ||
        "Failed to generate document."
      );

    } finally {
      setGenerating(
        false
      );
    }
  }


  const detailActions = (
    <AdminToolbar compact>
      <AdminButton
        type="button"
        variant="secondary"
        disabled={
          !selectedDocument
        }
        onClick={() => {
          if (
            selectedDocument
          ) {
            onPreview?.(
              selectedDocument
            );
          }
        }}
      >
        Preview
      </AdminButton>

      <select
        value={
          selectedTemplateKey
        }
        onChange={(event) =>
          setSelectedTemplateKey(
            event.target.value
          )
        }
        disabled={
          loading
          ||
          generating
          ||
          templates.length === 0
        }
        aria-label="Document template"
      >
        {
          templates.length === 0
            ? (
                <option value="">
                  No templates
                </option>
              )
            : templates.map(
                (template) => (
                  <option
                    key={
                      cleanText(
                        template?.template_key
                      )
                    }
                    value={
                      cleanText(
                        template?.template_key
                      )
                    }
                  >
                    {
                      templateLabel(
                        template
                      )
                    }
                  </option>
                )
              )
        }
      </select>

      <AdminButton
        type="button"
        disabled={
          generating
          ||
          !selectedTemplateKey
        }
        onClick={() => {
          void generateDocument();
        }}
      >
        {
          generating
            ? "Generating…"
            : "Generate"
        }
      </AdminButton>

      <FetchRexButton
        buttonLabel="R↗"
        disabled={
          !selectedDocument
        }
        request={
          selectedDocument
            ? {
                label:
                  cleanText(
                    selectedDocument?.title
                  )
                  ||
                  `Document #${selectedDocument.id}`,
                resolverKey:
                  "document",
                resourceType:
                  "doc",
                resourceId:
                  Number(
                    selectedDocument.id
                  ),
                reuseExisting:
                  true,
              }
            : null
        }
        autoCreate
        onCreated={() => {
          void loadDocuments();
        }}
      />
    </AdminToolbar>
  );


  return (
    <AdminDetailPane
      ariaLabel="Project documents"
      title={`Project #${projectId} Documents`}
      actions={
        detailActions
      }
    >
      {
        error
          ? (
              <AdminNotice variant="danger">
                {error}
              </AdminNotice>
            )
          : null
      }

      {
        statusMessage
          ? (
              <AdminNotice variant="success">
                {statusMessage}
              </AdminNotice>
            )
          : null
      }

      {
        loading
          ? (
              <AdminNotice>
                Loading Project Documents...
              </AdminNotice>
            )
          : null
      }

      {
        !loading
        &&
        documents.length === 0
          ? (
              <AdminEmptyState
                title="No documents"
                message="Choose a template above and generate the first document."
              />
            )
          : null
      }

      {
        documents.length > 0
          ? (
              <AdminSmartGrid
                items={
                  documents
                }
                columns={
                  columns
                }
                getRowKey={(document) =>
                  Number(
                    document?.id || 0
                  )
                }
                selectedKey={
                  selectedDocumentId
                }
                onSelectionChange={(
                  document,
                  key
                ) =>
                  setSelectedDocumentId(
                    Number(
                      key
                      ??
                      document?.id
                      ??
                      0
                    )
                    ||
                    null
                  )
                }
                defaultSortKey="name"
                defaultSortDirection="asc"
                ariaLabel="Project documents"
                verticalAlign="middle"
              />
            )
          : null
      }
    </AdminDetailPane>
  );
}
