import {
  useEffect,
  useMemo,
  useState,
} from "react";

import {
  AdminButton,
  AdminDetailPane,
  AdminEmptyState,
  AdminField,
  AdminMetaText,
  AdminNotice,
  AdminSmartGrid,
  AdminStack,
  AdminToolbar,
  AdminToolbarSpacer,
  AdminWorkbenchDrawer,
} from "@components/AdminLayout";

import FetchRexButton from "@components/REX/FetchRexButton";

import {
  API_FOLDER,
} from "@helpers/config";


const LIST_URL =
  `${API_FOLDER}/v2/admin/projects/documents/list.php`;

const GENERATE_URL =
  `${API_FOLDER}/v2/admin/projects/documents/generate.php`;

const FREEZE_URL =
  `${API_FOLDER}/v2/admin/projects/documents/freeze.php`;

const SENT_URL =
  `${API_FOLDER}/v2/admin/projects/documents/sent.php`;

const ACTIVITY_SAVE_URL =
  `${API_FOLDER}/v2/admin/projects/activity/save.php`;

const TEMPLATE_LIST_URL =
  `${API_FOLDER}/v2/admin/documents/templates/list.php`;


function cleanText(value) {
  return String(value ?? "").trim();
}


async function readJson(
  response,
  fallbackMessage
) {
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


function yesNo(value) {
  return Number(
    value ?? 0
  ) === 1
    ? "Yes"
    : "No";
}


function checkmark(value) {
  return value
    ? "✓"
    : "—";
}


function dateInputValue(value) {
  const text = cleanText(value);

  if (!text) {
    return "";
  }

  return text.slice(0, 10);
}


export default function ProjectDocuments({
  projectId,
  projectName = "",
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
    drawerOpen,
    setDrawerOpen,
  ] = useState(false);

  const [
    drawerMode,
    setDrawerMode,
  ] = useState("new");

  const [
    drawerDocument,
    setDrawerDocument,
  ] = useState(null);

  const [
    loading,
    setLoading,
  ] = useState(true);

  const [
    generating,
    setGenerating,
  ] = useState(false);

  const [
    updatingState,
    setUpdatingState,
  ] = useState(false);

  const [
    sentDate,
    setSentDate,
  ] = useState("");

  const [
    error,
    setError,
  ] = useState("");

  const [
    statusMessage,
    setStatusMessage,
  ] = useState("");

  const [
    drawerError,
    setDrawerError,
  ] = useState("");

  const [
    drawerStatus,
    setDrawerStatus,
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
        setDrawerOpen(false);
        setDrawerDocument(null);
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
              cleanText(
                activeTemplates[0]
                  ?.template_key
              )
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
            "approval_required",
          label:
            "Appr Req",
          sortable:
            true,
          value:
            (document) =>
              yesNo(
                document?.approval_required
              ),
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
              checkmark(
                document?.sent_at
              ),
        },
        {
          key:
            "frozen",
          label:
            "Frozen",
          sortable:
            true,
          sortValue:
            (document) =>
              document?.locked_at
              || "",
          value:
            (document) =>
              checkmark(
                document?.locked_at
              ),
        },
        {
          key:
            "approved",
          label:
            "Approved",
          sortable:
            true,
          sortValue:
            (document) =>
              document?.accepted_at
              || "",
          value:
            (document) =>
              Number(
                document?.approval_required
                ?? 0
              ) === 1
                ? checkmark(
                    document?.accepted_at
                  )
                : "—",
        },
      ],
      []
    );



  function openNewDocument() {
    setDrawerMode(
      "new"
    );

    setDrawerDocument(
      null
    );

    setSelectedTemplateKey(
      (current) => {
        if (
          current
          &&
          templates.some(
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
          templates[0]
            ?.template_key
        );
      }
    );

    setDrawerError("");
    setDrawerStatus("");
    setDrawerOpen(true);
  }


  function openExistingDocument(
    document
  ) {
    const id =
      Number(
        document?.id || 0
      );

    if (!id) {
      return;
    }

    setSelectedDocumentId(
      id
    );

    setDrawerMode(
      "edit"
    );

    setDrawerDocument(
      document
    );

    setSentDate(
      dateInputValue(
        document?.sent_at
      )
    );

    setDrawerError("");
    setDrawerStatus("");
    setDrawerOpen(true);
  }


  function closeDrawer() {
    if (generating) {
      return;
    }

    setDrawerOpen(
      false
    );

    setDrawerDocument(
      null
    );

    setSentDate("");

    setDrawerError("");
    setDrawerStatus("");
  }


  async function generateDocument(
    templateKeyOverride = ""
  ) {
    const id =
      Number(
        projectId || 0
      );

    const templateKey =
      cleanText(
        templateKeyOverride
        ||
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

    setGenerating(true);
    setDrawerError("");
    setDrawerStatus("");

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

      setDrawerMode(
        "edit"
      );

      setDrawerDocument(
        generated
      );

      setDrawerStatus(
        "Document generated."
      );

      setStatusMessage(
        "Document generated."
      );

    } catch (err) {
      setDrawerError(
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


  async function logSend() {
    const documentId =
      Number(
        drawerDocument?.id || 0
      );

    const activityDate =
      cleanText(
        sentDate
      );

    if (
      updatingState
      || documentId <= 0
      || !activityDate
    ) {
      return;
    }

    setUpdatingState(true);
    setDrawerError("");
    setDrawerStatus("");

    try {
      const data =
        await readJson(
          await fetch(
            SENT_URL,
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
                  document_id:
                    documentId,
                  sent_date:
                    activityDate,
                }),
            }
          ),
          "Failed to update sent date"
        );

      const updated =
        data?.document
        || null;

      if (!updated) {
        throw new Error(
          "Sent date update did not return a document."
        );
      }

      await readJson(
        await fetch(
          ACTIVITY_SAVE_URL,
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
                  Number(
                    projectId || 0
                  ),
                activity_date:
                  activityDate,
                description:
                  `Sent ${cleanText(
                    updated?.title
                  ) || `Document #${documentId}`}`,
                hours:
                  null,
                miles:
                  null,
                amount:
                  null,
              }),
          }
        ),
        "Failed to log document send"
      );

      setDrawerDocument(
        updated
      );

      setSentDate(
        dateInputValue(
          updated?.sent_at
        )
      );

      setDocuments(
        (current) =>
          current.map(
            (document) =>
              Number(
                document?.id || 0
              ) === documentId
                ? updated
                : document
          )
      );

      setDrawerStatus(
        "Logged."
      );

    } catch (err) {
      setDrawerError(
        err?.message
        ||
        "Failed to log document send."
      );

    } finally {
      setUpdatingState(false);
    }
  }


  async function freezeDocument() {
    const documentId =
      Number(
        drawerDocument?.id || 0
      );

    if (
      updatingState
      || documentId <= 0
      || drawerDocument?.locked_at
    ) {
      return;
    }

    const confirmed =
      window.confirm(
        "Freeze this document? The frozen version cannot be regenerated or overwritten."
      );

    if (!confirmed) {
      return;
    }

    setUpdatingState(true);
    setDrawerError("");
    setDrawerStatus("");

    try {
      const data =
        await readJson(
          await fetch(
            FREEZE_URL,
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
                  document_id:
                    documentId,
                }),
            }
          ),
          "Failed to freeze document"
        );

      const updated =
        data?.document
        || null;

      if (!updated) {
        throw new Error(
          "Freeze did not return a document."
        );
      }

      setDrawerDocument(
        updated
      );

      setDocuments(
        (current) =>
          current.map(
            (document) =>
              Number(
                document?.id || 0
              ) === documentId
                ? updated
                : document
          )
      );

      setDrawerStatus(
        "Document frozen."
      );

    } catch (err) {
      setDrawerError(
        err?.message
        ||
        "Failed to freeze document."
      );

    } finally {
      setUpdatingState(false);
    }
  }


  const detailActions = (
    <AdminToolbar compact>
      <AdminButton
        type="button"
        onClick={
          openNewDocument
        }
      >
        New
      </AdminButton>

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
    <>
      <AdminDetailPane
        ariaLabel="Project documents"
        title={`${String(projectName || "").trim() || `Project #${projectId}`} Documents`}
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
                  message="Click New to generate the first document for this project."
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
                  onRowDoubleClick={
                    openExistingDocument
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


      <AdminWorkbenchDrawer
        open={
          drawerOpen
        }
        width={
          680
        }
        title={
          drawerMode === "new"
            ? "New Document"
            : (
                cleanText(
                  drawerDocument?.title
                )
                ||
                "Document"
              )
        }
        onClose={
          closeDrawer
        }
        portal
        padded
      >
        <AdminStack gap="md">
          {
            drawerError
              ? (
                  <AdminNotice variant="danger">
                    {drawerError}
                  </AdminNotice>
                )
              : null
          }

          {
            drawerStatus
              ? (
                  <AdminNotice variant="success">
                    {drawerStatus}
                  </AdminNotice>
                )
              : null
          }

          {
            drawerMode === "new"
              ? (
                  <>
                    <AdminField
                      label="Template"
                      compact
                    >
                      <select
                        className="admin-field__control"
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
                    </AdminField>

                    {
                      selectedTemplateKey
                        ? (
                            <AdminMetaText as="div">
                              {
                                cleanText(
                                  templates.find(
                                    (template) =>
                                      cleanText(
                                        template?.template_key
                                      )
                                      ===
                                      selectedTemplateKey
                                  )?.description
                                )
                              }
                            </AdminMetaText>
                          )
                        : null
                    }

                    <AdminToolbar>
                      <AdminToolbarSpacer />

                      <AdminButton
                        type="button"
                        variant="secondary"
                        disabled={
                          generating
                        }
                        onClick={
                          closeDrawer
                        }
                      >
                        Close
                      </AdminButton>

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
                    </AdminToolbar>
                  </>
                )
              : (
                  <>
                    <AdminField
                      label="Title"
                      compact
                    >
                      <input
                        className="admin-field__control"
                        type="text"
                        value={
                          cleanText(
                            drawerDocument?.title
                          )
                        }
                        readOnly
                      />
                    </AdminField>

                    <AdminField
                      label="Template"
                      compact
                    >
                      <input
                        className="admin-field__control"
                        type="text"
                        value={
                          cleanText(
                            drawerDocument?.template_key
                          )
                        }
                        readOnly
                      />
                    </AdminField>

                    <AdminField
                      label="Approval Required"
                      compact
                    >
                      <input
                        className="admin-field__control"
                        type="text"
                        value={
                          yesNo(
                            drawerDocument?.approval_required
                          )
                        }
                        readOnly
                      />
                    </AdminField>

                    <AdminField
                      label="Sent"
                      compact
                    >
                      <AdminToolbar compact>
                        <input
                          className="admin-field__control"
                          type="date"
                          style={{ width: 150 }}
                          value={
                            sentDate
                          }
                          onChange={(event) =>
                            setSentDate(
                              event.target.value
                            )
                          }
                          disabled={
                            updatingState
                          }
                        />

                        <AdminButton
                          type="button"
                          variant="secondary"
                          disabled={
                            updatingState
                            || !sentDate
                          }
                          onClick={() => {
                            void logSend();
                          }}
                        >
                          Log Send
                        </AdminButton>
                      </AdminToolbar>
                    </AdminField>

                    <AdminToolbar compact>
                      <AdminField
                        label="Frozen"
                        compact
                      >
                        <input
                          className="admin-field__control"
                          type="text"
                          style={{ width: 150 }}
                          value={
                            formatDate(
                              drawerDocument?.locked_at
                            )
                          }
                          readOnly
                        />
                      </AdminField>

                      <AdminField
                        label="Approved"
                        compact
                      >
                        <input
                          className="admin-field__control"
                          type="text"
                          style={{ width: 150 }}
                          value={
                            Number(
                              drawerDocument?.approval_required
                              ?? 0
                            ) === 1
                              ? formatDate(
                                  drawerDocument?.accepted_at
                                )
                              : "—"
                          }
                          readOnly
                        />
                      </AdminField>
                    </AdminToolbar>

                    <AdminToolbar>
                      <AdminButton
                        type="button"
                        variant="secondary"
                        disabled={
                          generating
                          ||
                          updatingState
                        }
                        onClick={
                          closeDrawer
                        }
                      >
                        Close
                      </AdminButton>

                      <AdminToolbarSpacer />

                      <AdminButton
                        type="button"
                        variant="secondary"
                        disabled={
                          generating
                          ||
                          updatingState
                          ||
                          Boolean(
                            drawerDocument?.locked_at
                          )
                        }
                        onClick={() => {
                          void freezeDocument();
                        }}
                      >
                        {
                          drawerDocument?.locked_at
                            ? "Frozen"
                            : updatingState
                              ? "Freezing…"
                              : "Freeze"
                        }
                      </AdminButton>

                      <AdminButton
                        type="button"
                        disabled={
                          generating
                          ||
                          updatingState
                          ||
                          Boolean(
                            drawerDocument?.locked_at
                          )
                          ||
                          !cleanText(
                            drawerDocument?.template_key
                          )
                        }
                        onClick={() => {
                          void generateDocument(
                            cleanText(
                              drawerDocument?.template_key
                            )
                          );
                        }}
                      >
                        {
                          generating
                            ? "Generating…"
                            : "Generate"
                        }
                      </AdminButton>
                    </AdminToolbar>
                  </>
                )
          }
        </AdminStack>
      </AdminWorkbenchDrawer>
    </>
  );
}
