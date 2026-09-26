import {
  useEffect,
  useMemo,
  useRef,
  useState,
} from "react";

import {
  AdminButton,
  AdminCheckboxRow,
  AdminDetailPane,
  AdminField,
  AdminFieldRow,
  AdminListPane,
  AdminMasterDetail,
  AdminMetaText,
  AdminNotice,
  AdminObjectList,
  AdminObjectListItem,
  AdminPanel,
  AdminStack,
  AdminToolbar,
  AdminToolbarSpacer,
} from "@components/AdminLayout";

import DocumentPreview from "@components/Documents/DocumentPreview";

import {
  API_FOLDER,
} from "@helpers/config";


const DOCUMENT_TEMPLATES_LIST_URL =
  `${API_FOLDER}/v2/admin/documents/templates/list.php`;

const DOCUMENT_TEMPLATES_SAVE_URL =
  `${API_FOLDER}/v2/admin/documents/templates/save.php`;

const FORM_ID =
  "admin-document-template-form";


const DOCUMENT_FIELDS = [
  {
    token: "{{client_name}}",
    label: "Client Name",
    description:
      "Client name from the Project.",
  },
  {
    token: "{{project_name}}",
    label: "Project Name",
    description:
      "Project name from PROJECTS.",
  },
  {
    token: "{{property_address}}",
    label: "Property Address",
    description:
      "Street address from the Project's linked Property.",
  },
  {
    token: "{{project_goal}}",
    label: "Project Goal",
    description:
      "Goal saved on the Project Scope.",
  },
  {
    token: "{{areas_covered}}",
    label: "Areas Covered",
    description:
      "Areas saved on the Project Scope.",
  },
  {
    token: "{{scope_fee}}",
    label: "Scope Fee",
    description:
      "Scope fee formatted as currency.",
  },
];


function emptyTemplate() {
  return {
    id: null,
    template_key: "",
    template_type: "document",
    label: "",
    description: "",
    title_template: "",
    text_template: "",
    html_template: "",
    is_active: true,
  };
}


function generatedKey(label) {
  return String(label || "")
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, "_")
    .replace(/^_+|_+$/g, "");
}


function normalizeTemplate(
  template
) {
  return {
    id:
      Number(
        template?.id
        || 0
      ) || null,

    template_key:
      String(
        template?.template_key
        ?? ""
      ),

    template_type:
      String(
        template?.template_type
        ?? "document"
      ),

    label:
      String(
        template?.label
        ?? ""
      ),

    description:
      String(
        template?.description
        ?? ""
      ),

    title_template:
      String(
        template?.title_template
        ?? ""
      ),

    text_template:
      String(
        template?.text_template
        ?? ""
      ),

    html_template:
      String(
        template?.html_template
        ?? ""
      ),

    is_active:
      template?.is_active !== false
      && Number(
        template?.is_active
        ?? 1
      ) !== 0,
  };
}


function htmlToPlainText(html) {
  const normalized =
    String(html || "")
      .replace(/<br\s*\/?>/gi, "\n")
      .replace(/<\/p>/gi, "\n\n")
      .replace(/<\/div>/gi, "\n")
      .replace(/<\/li>/gi, "\n")
      .replace(/<li\b[^>]*>/gi, "* ");

  if (
    typeof window === "undefined"
    || !window.document
  ) {
    return normalized
      .replace(/<[^>]+>/g, "")
      .trim();
  }

  const container =
    window.document.createElement(
      "div"
    );

  container.innerHTML =
    normalized;

  return String(
    container.textContent
    || container.innerText
    || ""
  )
    .replace(/\n{3,}/g, "\n\n")
    .trim();
}


function escapeHtml(value) {
  return String(value || "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}


function plainTextToHtml(text) {
  const normalized =
    String(text || "")
      .replace(/\r\n/g, "\n")
      .trim();

  if (!normalized) {
    return "";
  }

  const lines =
    normalized.split("\n");

  const html = [];
  let group = [];
  let previousWasHeading = false;
  let headingCount = 0;

  const isHeadingLine =
    (line) => {
      const value =
        String(line || "").trim();

      if (
        !value
        || value.length > 120
        || !/[A-Za-z]/.test(value)
      ) {
        return false;
      }

      return (
        value === value.toUpperCase()
        && value !== value.toLowerCase()
      );
    };

  const isMetadataLine =
    (line) =>
      /^[A-Za-z][^:]{0,32}:\s*/.test(
        String(line || "").trim()
      );

  const isBulletLine =
    (line) =>
      /^[-*]\s+/.test(
        String(line || "").trim()
      );

  const stripBullet =
    (line) =>
      String(line || "")
        .trim()
        .replace(/^[-*]\s+/, "");

  const looksLikeShortList =
    (items) =>
      previousWasHeading
      && items.length >= 2
      && items.every(
        (line) => {
          const value =
            String(line || "").trim();

          return (
            value.length > 0
            && value.length <= 90
            && !isMetadataLine(value)
            && !/[.!?;:]$/.test(value)
          );
        }
      );

  const renderMetadataLine =
    (line) => {
      const value =
        String(line || "").trim();

      const match =
        value.match(
          /^([^:]+):\s*(.*)$/
        );

      if (!match) {
        return escapeHtml(value);
      }

      return (
        `<strong>${escapeHtml(match[1])}:</strong>`
        + (
          match[2]
            ? ` ${escapeHtml(match[2])}`
            : ""
        )
      );
    };

  const flushGroup =
    () => {
      if (group.length === 0) {
        return;
      }

      const items =
        group
          .map(
            (line) =>
              String(line || "").trim()
          )
          .filter(Boolean);

      group = [];

      if (items.length === 0) {
        return;
      }

      if (
        items.every(
          isMetadataLine
        )
      ) {
        html.push(
          `<p>${items.map(renderMetadataLine).join("<br />")}</p>`
        );

        previousWasHeading =
          false;

        return;
      }

      if (
        items.every(
          isBulletLine
        )
        || looksLikeShortList(
          items
        )
      ) {
        html.push(
          "<ul>"
          + items
              .map(
                (line) =>
                  `<li>${escapeHtml(stripBullet(line))}</li>`
              )
              .join("")
          + "</ul>"
        );

        previousWasHeading =
          false;

        return;
      }

      html.push(
        `<p>${items.map(escapeHtml).join("<br />")}</p>`
      );

      previousWasHeading =
        false;
    };

  for (const rawLine of lines) {
    const line =
      String(rawLine || "").trim();

    if (!line) {
      flushGroup();
      continue;
    }

    if (
      isHeadingLine(
        line
      )
    ) {
      flushGroup();

      headingCount += 1;

      html.push(
        headingCount === 1
          ? `<h1>${escapeHtml(line)}</h1>`
          : `<h2>${escapeHtml(line)}</h2>`
      );

      previousWasHeading =
        true;

      continue;
    }

    group.push(
      line
    );
  }

  flushGroup();

  return html.join("\n");
}


async function readJson(
  response,
  fallback
) {
  const text =
    await response.text();

  let data = null;

  try {
    data =
      JSON.parse(text);
  } catch {
    throw new Error(
      `${fallback}: HTTP ${response.status}: ${text.slice(0, 250)}`
    );
  }

  if (
    !response.ok
    || !data?.ok
  ) {
    throw new Error(
      data?.error
      || fallback
    );
  }

  return data;
}


export default function AdminTemplatesPage() {
  const [
    items,
    setItems,
  ] = useState([]);

  const [
    selectedId,
    setSelectedId,
  ] = useState(null);

  const [
    form,
    setForm,
  ] = useState(
    emptyTemplate
  );

  const [
    insertToken,
    setInsertToken,
  ] = useState(
    DOCUMENT_FIELDS[0]?.token
    || ""
  );

  const [
    insertTarget,
    setInsertTarget,
  ] = useState(
    "text_template"
  );

  const [
    query,
    setQuery,
  ] = useState("");

  const [
    loading,
    setLoading,
  ] = useState(
    true
  );

  const [
    saving,
    setSaving,
  ] = useState(
    false
  );

  const [
    error,
    setError,
  ] = useState("");

  const [
    notice,
    setNotice,
  ] = useState("");

  const [
    previewOpen,
    setPreviewOpen,
  ] = useState(false);

  const titleInputRef =
    useRef(null);

  const textTextareaRef =
    useRef(null);

  const selectionRef =
    useRef({
      title_template: {
        start: 0,
        end: 0,
      },
      text_template: {
        start: 0,
        end: 0,
      },
    });


  function getFieldRef(field) {
    if (
      field ===
      "title_template"
    ) {
      return titleInputRef;
    }

    return textTextareaRef;
  }


  function syncSelection(field) {
    const element =
      getFieldRef(
        field
      ).current;

    if (!element) {
      return;
    }

    selectionRef.current[field] = {
      start:
        element.selectionStart
        ?? 0,

      end:
        element.selectionEnd
        ?? element.selectionStart
        ?? 0,
    };

    setInsertTarget(
      field
    );
  }


  function selectTemplate(
    template
  ) {
    const next =
      normalizeTemplate(
        template
      );

    setSelectedId(
      next.id
    );

    setForm({
      ...next,
      html_template:
        plainTextToHtml(
          next.text_template
        ),
    });

    setInsertToken(
      DOCUMENT_FIELDS[0]?.token
      || ""
    );

    setError("");
    setNotice("");
  }


  function startNew() {
    setSelectedId(
      null
    );

    setForm(
      emptyTemplate()
    );

    setInsertToken(
      DOCUMENT_FIELDS[0]?.token
      || ""
    );

    setInsertTarget(
      "text_template"
    );

    setError("");
    setNotice("");
  }


  async function loadTemplates(
    preferredId = null
  ) {
    setLoading(
      true
    );

    setError("");

    try {
      const response =
        await fetch(
          `${DOCUMENT_TEMPLATES_LIST_URL}?_=${Date.now()}`,
          {
            credentials:
              "include",
            cache:
              "no-store",
          }
        );

      const data =
        await readJson(
          response,
          "Failed to load document templates"
        );

      const nextItems =
        (
          Array.isArray(
            data.templates
          )
            ? data.templates
            : []
        )
          .map(
            normalizeTemplate
          );

      setItems(
        nextItems
      );

      const targetId =
        Number(
          preferredId
          || selectedId
          || 0
        );

      const target =
        nextItems.find(
          (item) =>
            Number(item.id)
            === targetId
        );

      if (target) {
        selectTemplate(
          target
        );
      } else if (
        !targetId
        && nextItems[0]
      ) {
        selectTemplate(
          nextItems[0]
        );
      } else if (
        targetId
      ) {
        startNew();
      }

    } catch (err) {
      setError(
        err?.message
        || "Failed to load document templates"
      );

    } finally {
      setLoading(
        false
      );
    }
  }


  useEffect(
    () => {
      void loadTemplates();
    },
    []
  );


  function handleInsertField() {
    if (
      !insertToken
      || !insertTarget
    ) {
      return;
    }

    const targetField =
      insertTarget;

    const targetRef =
      getFieldRef(
        targetField
      );

    const {
      start,
      end,
    } =
      selectionRef.current[
        targetField
      ]
      || {
        start: 0,
        end: 0,
      };

    setForm(
      (prev) => {
        const currentValue =
          String(
            prev[targetField]
            || ""
          );

        const safeStart =
          Math.max(
            0,
            Math.min(
              start,
              currentValue.length
            )
          );

        const safeEnd =
          Math.max(
            safeStart,
            Math.min(
              end,
              currentValue.length
            )
          );

        const nextValue =
          `${currentValue.slice(0, safeStart)}`
          + `${insertToken}`
          + `${currentValue.slice(safeEnd)}`;

        const next = {
          ...prev,
          [targetField]:
            nextValue,
        };

        if (
          targetField === "text_template"
        ) {
          next.html_template =
            plainTextToHtml(
              nextValue
            );
        }

        return next;
      }
    );

    const nextCaret =
      start
      + insertToken.length;

    selectionRef.current[
      targetField
    ] = {
      start:
        nextCaret,
      end:
        nextCaret,
    };

    window.requestAnimationFrame(
      () => {
        const element =
          targetRef.current;

        if (!element) {
          return;
        }

        element.focus();

        element.setSelectionRange(
          nextCaret,
          nextCaret
        );
      }
    );
  }


  async function handleSave(event) {
    event.preventDefault();

    setSaving(
      true
    );

    setError("");
    setNotice("");

    try {
      const key =
        String(
          form.template_key
          || ""
        ).trim()
        || generatedKey(
          form.label
        );

      if (!key) {
        throw new Error(
          "Template key or label required."
        );
      }

      if (
        !String(
          form.label
          || ""
        ).trim()
      ) {
        throw new Error(
          "Template label required."
        );
      }

      const htmlToSave =
        plainTextToHtml(
          form.text_template
        );

      const response =
        await fetch(
          DOCUMENT_TEMPLATES_SAVE_URL,
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
                id:
                  form.id,

                template_key:
                  key,

                template_type:
                  form.template_type
                  || "document",

                label:
                  form.label,

                description:
                  form.description,

                title_template:
                  form.title_template,

                text_template:
                  form.text_template,

                html_template:
                  htmlToSave,

                is_active:
                  form.is_active,
              }),
          }
        );

      const data =
        await readJson(
          response,
          "Failed to save document template"
        );

      const savedId =
        Number(
          data?.template?.id
          || form.id
          || 0
        );

      setNotice(
        selectedId
          ? "Template updated."
          : "Template created."
      );

      await loadTemplates(
        savedId
      );

    } catch (err) {
      setError(
        err?.message
        || "Failed to save document template"
      );

    } finally {
      setSaving(
        false
      );
    }
  }


  const filteredItems =
    useMemo(
      () => {
        const needle =
          query
            .trim()
            .toLowerCase();

        if (!needle) {
          return items;
        }

        return items.filter(
          (item) =>
            item.label
              .toLowerCase()
              .includes(
                needle
              )
            || item.template_key
              .toLowerCase()
              .includes(
                needle
              )
            || item.template_type
              .toLowerCase()
              .includes(
                needle
              )
            || item.description
              .toLowerCase()
              .includes(
                needle
              )
        );
      },
      [
        items,
        query,
      ]
    );


  const list =
    (
      <AdminListPane
        title="Templates"
        actions={
          <AdminButton
            type="button"
            onClick={
              startNew
            }
          >
            New Template
          </AdminButton>
        }
        searchValue={
          query
        }
        onSearchChange={
          setQuery
        }
        searchPlaceholder="Search templates..."
      >
        {
          loading
            ? (
                <AdminMetaText as="div">
                  Loading templates...
                </AdminMetaText>
              )
            : filteredItems.length === 0
              ? (
                  <AdminMetaText as="div">
                    No templates found.
                  </AdminMetaText>
                )
              : (
                  <AdminObjectList
                    ariaLabel="Document templates"
                  >
                    {
                      filteredItems.map(
                        (item) => (
                          <AdminObjectListItem
                            key={
                              item.id
                              || item.template_key
                            }
                            id={
                              item.id
                              || item.template_key
                            }
                            title={
                              item.label
                              || item.template_key
                            }
                            meta={[
                              item.template_type,
                              item.template_key,
                              item.description
                                || null,
                            ]}
                            selected={
                              Number(
                                selectedId
                                || 0
                              )
                              === Number(
                                item.id
                                || 0
                              )
                            }
                            onSelect={() =>
                              selectTemplate(
                                item
                              )
                            }
                          />
                        )
                      )
                    }
                  </AdminObjectList>
                )
        }
      </AdminListPane>
    );


  const detailTitle =
    selectedId
      ? (
          form.label
          || form.template_key
          || "Template"
        )
      : "New Template";


  const detail =
    (
      <AdminDetailPane
        ariaLabel="Document template detail"
        title={
          detailTitle
        }
        actions={
          <AdminToolbar compact>
            <AdminButton
              type="button"
              variant="secondary"
              onClick={() =>
                setPreviewOpen(
                  true
                )
              }
            >
              Preview
            </AdminButton>

            <AdminButton
              type="submit"
              form={
                FORM_ID
              }
              disabled={
                saving
              }
            >
              {
                saving
                  ? "Saving..."
                  : selectedId
                    ? "Save"
                    : "Create"
              }
            </AdminButton>
          </AdminToolbar>
        }
      >
        <form
          id={
            FORM_ID
          }
          onSubmit={
            handleSave
          }
        >
          <AdminStack
            gap="lg"
          >
            {
              error
                ? (
                    <AdminNotice
                      variant="danger"
                    >
                      {error}
                    </AdminNotice>
                  )
                : null
            }

            {
              notice
                ? (
                    <AdminNotice
                      variant="success"
                    >
                      {notice}
                    </AdminNotice>
                  )
                : null
            }

            <AdminPanel
              title="Template"
            >
              <AdminStack
                gap="md"
              >
                <AdminFieldRow>
                  <AdminField
                    label="Document Type"
                  >
                    <input
                      className="admin-field__control"
                      type="text"
                      value={
                        form.template_type
                      }
                      placeholder="agreement, process, document..."
                      onChange={
                        (event) =>
                          setForm(
                            (prev) => ({
                              ...prev,
                              template_type:
                                event.target.value,
                            })
                          )
                      }
                    />
                  </AdminField>
                </AdminFieldRow>

                <AdminField
                  label="Label"
                >
                  <input
                    className="admin-field__control"
                    type="text"
                    value={
                      form.label
                    }
                    onChange={
                      (event) =>
                        setForm(
                          (prev) => ({
                            ...prev,
                            label:
                              event.target.value,
                          })
                        )
                    }
                  />
                </AdminField>

                <AdminField
                  label="Key"
                >
                  <input
                    className="admin-field__control"
                    type="text"
                    value={
                      form.template_key
                    }
                    placeholder="Auto-generated from label if left blank"
                    onChange={
                      (event) =>
                        setForm(
                          (prev) => ({
                            ...prev,
                            template_key:
                              event.target.value,
                          })
                        )
                    }
                  />
                </AdminField>

                <AdminField
                  label="Description"
                >
                  <input
                    className="admin-field__control"
                    type="text"
                    value={
                      form.description
                    }
                    onChange={
                      (event) =>
                        setForm(
                          (prev) => ({
                            ...prev,
                            description:
                              event.target.value,
                          })
                        )
                    }
                  />
                </AdminField>

                <AdminCheckboxRow
                  checked={
                    form.is_active
                  }
                  onChange={
                    (event) =>
                      setForm(
                        (prev) => ({
                          ...prev,
                          is_active:
                            event.target.checked,
                        })
                      )
                  }
                >
                  Active
                </AdminCheckboxRow>
              </AdminStack>
            </AdminPanel>

            <AdminPanel
              title="Insertion Fields"
            >
              <AdminStack
                gap="sm"
              >
                <AdminMetaText
                  as="div"
                >
                  Document merge fields use double braces.
                </AdminMetaText>

                <AdminFieldRow>
                  <AdminField
                    label="Field"
                    grow
                  >
                    <select
                      className="admin-field__control"
                      value={
                        insertToken
                      }
                      onChange={
                        (event) =>
                          setInsertToken(
                            event.target.value
                          )
                      }
                    >
                      {
                        DOCUMENT_FIELDS.map(
                          (field) => (
                            <option
                              key={
                                field.token
                              }
                              value={
                                field.token
                              }
                            >
                              {
                                `${field.label} · ${field.token}`
                              }
                            </option>
                          )
                        )
                      }
                    </select>
                  </AdminField>

                  <AdminField
                    label="Insert into"
                  >
                    <select
                      className="admin-field__control"
                      value={
                        insertTarget
                      }
                      onChange={
                        (event) =>
                          setInsertTarget(
                            event.target.value
                          )
                      }
                    >
                      <option value="title_template">
                        Title
                      </option>

                      <option value="text_template">
                        Plain Text
                      </option>

                    </select>
                  </AdminField>
                </AdminFieldRow>

                <AdminToolbar
                  compact
                >
                  <AdminMetaText
                    as="div"
                  >
                    {
                      DOCUMENT_FIELDS.find(
                        (field) =>
                          field.token
                          === insertToken
                      )
                        ?.description
                      || ""
                    }
                  </AdminMetaText>

                  <AdminToolbarSpacer />

                  <AdminButton
                    type="button"
                    variant="secondary"
                    onClick={
                      handleInsertField
                    }
                  >
                    Insert Field
                  </AdminButton>
                </AdminToolbar>
              </AdminStack>
            </AdminPanel>

            <AdminPanel
              title="Content"
            >
              <AdminStack
                gap="md"
              >
                <AdminField
                  label="Title template"
                >
                  <input
                    ref={
                      titleInputRef
                    }
                    className="admin-field__control"
                    type="text"
                    value={
                      form.title_template
                    }
                    onChange={
                      (event) =>
                        setForm(
                          (prev) => ({
                            ...prev,
                            title_template:
                              event.target.value,
                          })
                        )
                    }
                    onFocus={() =>
                      syncSelection(
                        "title_template"
                      )
                    }
                    onClick={() =>
                      syncSelection(
                        "title_template"
                      )
                    }
                    onKeyUp={() =>
                      syncSelection(
                        "title_template"
                      )
                    }
                    onSelect={() =>
                      syncSelection(
                        "title_template"
                      )
                    }
                  />
                </AdminField>

                <AdminField
                  label="Text template"
                >
                  <textarea
                    ref={
                      textTextareaRef
                    }
                    className="admin-field__control"
                    rows={10}
                    value={
                      form.text_template
                    }
                    onChange={
                      (event) =>
                        setForm(
                          (prev) => ({
                            ...prev,
                            text_template:
                              event.target.value,

                            html_template:
                              plainTextToHtml(
                                event.target.value
                              ),
                          })
                        )
                    }
                    onFocus={() =>
                      syncSelection(
                        "text_template"
                      )
                    }
                    onClick={() =>
                      syncSelection(
                        "text_template"
                      )
                    }
                    onKeyUp={() =>
                      syncSelection(
                        "text_template"
                      )
                    }
                    onSelect={() =>
                      syncSelection(
                        "text_template"
                      )
                    }
                  />
                </AdminField>

                <AdminMetaText
                  as="div"
                >
                  HTML is generated automatically from Plain Text when you save.
                </AdminMetaText>

                <AdminField
                  label="Generated HTML"
                >
                  <textarea
                    className="admin-field__control"
                    rows={14}
                    value={
                      form.html_template
                    }
                    readOnly
                  />
                </AdminField>
              </AdminStack>
            </AdminPanel>
          </AdminStack>
        </form>
      </AdminDetailPane>
    );


  return (
    <>
      <AdminMasterDetail
        storageKey="admin-document-templates-list-width"
        defaultListWidth={320}
        list={
          list
        }
        detail={
          detail
        }
      />

      <DocumentPreview
        open={
          previewOpen
        }
        title={
          form.title_template
          || form.label
          || "Template Preview"
        }
        html={
          plainTextToHtml(
            form.text_template
          )
        }
        onClose={() =>
          setPreviewOpen(
            false
          )
        }
      />
    </>
  );
}
