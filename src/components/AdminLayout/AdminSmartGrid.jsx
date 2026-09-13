import { useCallback, useMemo, useState } from "react";

import AdminDataGrid from "./AdminDataGrid";
import AdminEditor from "./AdminEditor";
import AdminEditorBody from "./AdminEditorBody";
import AdminEmptyState from "./AdminEmptyState";

function resolveOption(option, item, fallback = null) {
  if (typeof option === "function") {
    return option(item);
  }

  return option ?? fallback;
}

export default function AdminSmartGrid({
  editable = false,
  canEdit = () => true,
  editor = null,
  editButtonLabel = "✎",
  editButtonTitle = "Edit",
  editColumnPosition = "start",
  editColumnLabel = "",
  onEditOpen,
  onEditClose,
  items = null,
  columns = null,
  getRowKey = (item) => item.id,
  ...dataGridProps
}) {
  const [editorSourceItem, setEditorSourceItem] = useState(null);
  const [editorData, setEditorData] = useState(null);
  const [editorLoading, setEditorLoading] = useState(false);
  const [editorError, setEditorError] = useState("");

  const hasEditor = Boolean(
    editable &&
    editor &&
    typeof editor.render === "function"
  );

  const closeEditor = useCallback(() => {
    const item = editorSourceItem;

    setEditorSourceItem(null);
    setEditorData(null);
    setEditorLoading(false);
    setEditorError("");

    onEditClose?.(item);
    editor?.onClose?.(item);
  }, [editorSourceItem, editor, onEditClose]);

  const loadEditorData = useCallback(async (item) => {
    if (!item) return null;

    setEditorLoading(true);
    setEditorError("");

    try {
      const loaded = typeof editor?.load === "function"
        ? await editor.load(item)
        : item;

      setEditorData(loaded);
      return loaded;
    } catch (error) {
      setEditorError(
        error?.message ||
        "Could not load editor."
      );
      return null;
    } finally {
      setEditorLoading(false);
    }
  }, [editor]);

  const openEditor = useCallback(async (item) => {
    if (!hasEditor || !item) return;
    if (canEdit && !canEdit(item)) return;

    setEditorSourceItem(item);
    setEditorData(null);
    setEditorError("");

    onEditOpen?.(item);
    editor?.onOpen?.(item);

    await loadEditorData(item);
  }, [hasEditor, canEdit, editor, loadEditorData, onEditOpen]);

  const reloadEditor = useCallback(async () => {
    if (!editorSourceItem) return null;
    return loadEditorData(editorSourceItem);
  }, [editorSourceItem, loadEditorData]);

  const enhancedColumns = useMemo(() => {
    if (!Array.isArray(columns)) return columns;
    if (!editable) return columns;

    const editColumn = {
      key: "__admin_smart_grid_edit__",
      label: editColumnLabel,
      sortable: false,
      render: (item) => {
        const allowed = hasEditor && (!canEdit || canEdit(item));

        if (!allowed) {
          return (
            <span className="admin-smart-grid__edit-unavailable" aria-hidden="true">
              —
            </span>
          );
        }

        return (
          <button
            type="button"
            className="admin-smart-grid__edit-button"
            title={
              typeof editButtonTitle === "function"
                ? editButtonTitle(item)
                : editButtonTitle
            }
            aria-label={
              typeof editButtonTitle === "function"
                ? editButtonTitle(item)
                : editButtonTitle
            }
            onClick={(event) => {
              event.stopPropagation();
              openEditor(item);
            }}
          >
            {typeof editButtonLabel === "function"
              ? editButtonLabel(item)
              : editButtonLabel}
          </button>
        );
      },
    };

    return editColumnPosition === "end"
      ? [...columns, editColumn]
      : [editColumn, ...columns];
  }, [
    columns,
    editable,
    hasEditor,
    canEdit,
    editColumnLabel,
    editColumnPosition,
    editButtonLabel,
    editButtonTitle,
    openEditor,
  ]);

  const editorBusy = editorSourceItem
    ? Boolean(resolveOption(editor?.busy, editorSourceItem, false))
    : false;

  const editorTitle = editorSourceItem
    ? resolveOption(editor?.title, editorSourceItem, "Edit")
    : "Edit";

  const editorMeta = editorSourceItem
    ? resolveOption(editor?.meta, editorSourceItem, null)
    : null;

  const editorStatus = editorSourceItem
    ? resolveOption(editor?.status, editorSourceItem, null)
    : null;

  const editorStatusVariant = editorSourceItem
    ? resolveOption(editor?.statusVariant, editorSourceItem, "neutral")
    : "neutral";

  const editorSize = editorSourceItem
    ? resolveOption(editor?.size, editorSourceItem, "lg")
    : "lg";

  const editorWidth = editorSourceItem
    ? resolveOption(editor?.width, editorSourceItem, undefined)
    : undefined;

  return (
    <>
      <AdminDataGrid
        {...dataGridProps}
        items={items}
        columns={enhancedColumns}
        getRowKey={getRowKey}
      />

      <AdminEditor
        open={Boolean(editorSourceItem)}
        title={editorTitle}
        meta={editorMeta}
        status={editorStatus}
        statusVariant={editorStatusVariant}
        size={editorSize}
        width={editorWidth}
        busy={editorBusy}
        dismissOnBackdrop={editor?.dismissOnBackdrop !== false}
        dismissOnEscape={editor?.dismissOnEscape !== false}
        onClose={closeEditor}
        className={editor?.className || ""}
      >
        {editorLoading ? (
          <AdminEditorBody>
            <AdminEmptyState
              title="Loading editor"
              message="Fetching the current record..."
            />
          </AdminEditorBody>
        ) : editorError ? (
          <AdminEditorBody>
            <div className="admin-smart-grid__editor-error">
              <AdminEmptyState
                title="Editor could not load"
                message={editorError}
              />

              <div className="admin-smart-grid__editor-error-actions">
                <button
                  type="button"
                  className="admin-button admin-button--secondary"
                  onClick={reloadEditor}
                >
                  Retry
                </button>
              </div>
            </div>
          </AdminEditorBody>
        ) : editorSourceItem ? (
          editor.render({
            item: editorSourceItem,
            data: editorData ?? editorSourceItem,
            close: closeEditor,
            reload: reloadEditor,
          })
        ) : null}
      </AdminEditor>
    </>
  );
}
