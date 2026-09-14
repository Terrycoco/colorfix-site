import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from "react";

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

function asKeySet(value) {
  if (value instanceof Set) {
    return new Set(value);
  }

  if (Array.isArray(value)) {
    return new Set(value);
  }

  return new Set();
}

function SelectAllCheckbox({
  checked,
  indeterminate,
  disabled,
  onChange,
}) {
  const ref = useRef(null);

  useEffect(() => {
    if (ref.current) {
      ref.current.indeterminate = Boolean(indeterminate);
    }
  }, [indeterminate]);

  return (
    <input
      ref={ref}
      type="checkbox"
      checked={checked}
      disabled={disabled}
      aria-label="Select all rows"
      title="Select all rows"
      onClick={(event) => event.stopPropagation()}
      onDoubleClick={(event) => event.stopPropagation()}
      onChange={(event) => onChange(event.target.checked)}
    />
  );
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

  multiSelect = false,
  canSelect = () => true,
  batchSelectedKeys,
  defaultBatchSelectedKeys = [],
  onBatchSelectionChange,
  selectionColumnPosition = "start",
  selectionColumnLabel = "",

  items = null,
  columns = null,
  getRowKey = (item) => item.id,

  drawer = null,

  ...dataGridProps
}) {
  const [editorSourceItem, setEditorSourceItem] = useState(null);
  const [editorData, setEditorData] = useState(null);
  const [editorLoading, setEditorLoading] = useState(false);
  const [editorError, setEditorError] = useState("");

  const [internalBatchSelectedKeys, setInternalBatchSelectedKeys] =
    useState(() => asKeySet(defaultBatchSelectedKeys));

  const batchSelectionControlled =
    batchSelectedKeys !== undefined;

  const resolvedBatchSelectedKeys =
    useMemo(
      () =>
        batchSelectionControlled
          ? asKeySet(batchSelectedKeys)
          : internalBatchSelectedKeys,
      [
        batchSelectionControlled,
        batchSelectedKeys,
        internalBatchSelectedKeys,
      ]
    );

  const rowItems =
    Array.isArray(items)
      ? items
      : [];

  const selectableItems =
    useMemo(
      () =>
        multiSelect
          ? rowItems.filter((item) => !canSelect || canSelect(item))
          : [],
      [multiSelect, rowItems, canSelect]
    );

  const selectableKeys =
    useMemo(
      () =>
        selectableItems.map((item) => getRowKey(item)),
      [selectableItems, getRowKey]
    );

  const selectedSelectableCount =
    useMemo(
      () =>
        selectableKeys.reduce(
          (count, key) =>
            count + (resolvedBatchSelectedKeys.has(key) ? 1 : 0),
          0
        ),
      [selectableKeys, resolvedBatchSelectedKeys]
    );

  const allSelectableSelected =
    selectableKeys.length > 0 &&
    selectedSelectableCount === selectableKeys.length;

  const someSelectableSelected =
    selectedSelectableCount > 0 &&
    !allSelectableSelected;

  const emitBatchSelection = useCallback(
    (nextSet) => {
      const nextKeys = Array.from(nextSet);

      if (!batchSelectionControlled) {
        setInternalBatchSelectedKeys(nextSet);
      }

      const nextItems = rowItems.filter((item) =>
        nextSet.has(getRowKey(item))
      );

      onBatchSelectionChange?.(nextKeys, nextItems);
    },
    [
      batchSelectionControlled,
      rowItems,
      getRowKey,
      onBatchSelectionChange,
    ]
  );

  const toggleBatchItem = useCallback(
    (item, checked) => {
      if (!multiSelect || !item) return;
      if (canSelect && !canSelect(item)) return;

      const key = getRowKey(item);
      const next = new Set(resolvedBatchSelectedKeys);

      if (checked) {
        next.add(key);
      } else {
        next.delete(key);
      }

      emitBatchSelection(next);
    },
    [
      multiSelect,
      canSelect,
      getRowKey,
      resolvedBatchSelectedKeys,
      emitBatchSelection,
    ]
  );

  const toggleAllSelectable = useCallback(
    (checked) => {
      if (!multiSelect) return;

      const next = new Set(resolvedBatchSelectedKeys);

      for (const key of selectableKeys) {
        if (checked) {
          next.add(key);
        } else {
          next.delete(key);
        }
      }

      emitBatchSelection(next);
    },
    [
      multiSelect,
      selectableKeys,
      resolvedBatchSelectedKeys,
      emitBatchSelection,
    ]
  );

  useEffect(() => {
    if (!multiSelect || batchSelectionControlled) return;

    const validKeys = new Set(rowItems.map((item) => getRowKey(item)));
    const next = new Set(
      Array.from(internalBatchSelectedKeys).filter((key) =>
        validKeys.has(key)
      )
    );

    if (next.size !== internalBatchSelectedKeys.size) {
      setInternalBatchSelectedKeys(next);
      onBatchSelectionChange?.(
        Array.from(next),
        rowItems.filter((item) => next.has(getRowKey(item)))
      );
    }
  }, [
    multiSelect,
    batchSelectionControlled,
    rowItems,
    getRowKey,
    internalBatchSelectedKeys,
    onBatchSelectionChange,
  ]);

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
      const loaded =
        typeof editor?.load === "function"
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

  /*
   * Let a drawer offer another way into the SAME SmartGrid editor.
   * This exposes openEditor() to drawer.render(); it does not create
   * a second editor implementation.
   */
  const enhancedDrawer = useMemo(() => {
    if (
      !drawer ||
      typeof drawer !== "object" ||
      typeof drawer.render !== "function"
    ) {
      return drawer;
    }

    return {
      ...drawer,
      render: (context) =>
        drawer.render({
          ...context,
          openEditor: (item = context?.item) =>
            openEditor(item),
        }),
    };
  }, [drawer, openEditor]);

  const enhancedColumns = useMemo(() => {
    if (!Array.isArray(columns)) return columns;

    const startColumns = [];
    const endColumns = [];

    if (multiSelect) {
      const selectColumn = {
        key: "__admin_smart_grid_select__",
        label: (
          <SelectAllCheckbox
            checked={allSelectableSelected}
            indeterminate={someSelectableSelected}
            disabled={selectableKeys.length === 0}
            onChange={toggleAllSelectable}
          />
        ),
        sortable: false,
        render: (item) => {
          const allowed = !canSelect || canSelect(item);
          const key = getRowKey(item);

          return (
            <input
              type="checkbox"
              checked={resolvedBatchSelectedKeys.has(key)}
              disabled={!allowed}
              aria-label={
                selectionColumnLabel
                  ? `${selectionColumnLabel} ${key}`
                  : `Select row ${key}`
              }
              title="Select row"
              onClick={(event) => event.stopPropagation()}
              onDoubleClick={(event) => event.stopPropagation()}
              onChange={(event) =>
                toggleBatchItem(item, event.target.checked)
              }
            />
          );
        },
      };

      if (selectionColumnPosition === "end") {
        endColumns.push(selectColumn);
      } else {
        startColumns.push(selectColumn);
      }
    }

    if (editable) {
      const editColumn = {
        key: "__admin_smart_grid_edit__",
        label: editColumnLabel,
        sortable: false,
        render: (item) => {
          const allowed =
            hasEditor &&
            (!canEdit || canEdit(item));

          if (!allowed) {
            return (
              <span
                className="admin-smart-grid__edit-unavailable"
                aria-hidden="true"
              >
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

      if (editColumnPosition === "end") {
        endColumns.push(editColumn);
      } else {
        startColumns.push(editColumn);
      }
    }

    return [
      ...startColumns,
      ...columns,
      ...endColumns,
    ];
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
    multiSelect,
    canSelect,
    getRowKey,
    resolvedBatchSelectedKeys,
    selectionColumnPosition,
    selectionColumnLabel,
    allSelectableSelected,
    someSelectableSelected,
    selectableKeys.length,
    toggleAllSelectable,
    toggleBatchItem,
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
        drawer={enhancedDrawer}
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
