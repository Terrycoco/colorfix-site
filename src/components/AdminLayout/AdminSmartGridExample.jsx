import {
  AdminButton,
  AdminEditorBody,
  AdminEditorFooter,
  AdminEditorForm,
  AdminEditorPane,
  AdminSmartGrid,
} from "@components/AdminLayout";

/*
 * Reference only. This demonstrates the v1 SmartGrid editor contract.
 *
 * - Single/double click behavior remains AdminDataGrid behavior.
 * - editable adds the standard Edit button.
 * - canEdit is the page's business rule.
 * - editor.load may fetch richer editor data.
 * - editor.render supplies only the specialized inner editor content.
 */
export default function AdminSmartGridExample({ items, loadDetail, saveItem }) {
  const columns = [
    { key: "id", label: "ID" },
    { key: "title", label: "Title" },
  ];

  return (
    <AdminSmartGrid
      items={items}
      columns={columns}
      getRowKey={(item) => item.id}
      editable
      canEdit={(item) => !item.locked}
      onRowDoubleClick={(item) => {
        /* Open the normal inspection drawer here. */
        console.log("inspect", item);
      }}
      editor={{
        title: (item) => `Edit #${item.id}`,
        size: "md",
        load: loadDetail,
        render: ({ item, data, close, reload }) => (
          <AdminEditorForm
            onSubmit={async (event) => {
              event.preventDefault();
              await saveItem(data);
              await reload();
            }}
          >
            <AdminEditorBody layout="split">
              <AdminEditorPane kind="preview">
                {/* Page-specific preview. */}
              </AdminEditorPane>

              <AdminEditorPane kind="fields">
                {/* Page-specific fields. */}
              </AdminEditorPane>
            </AdminEditorBody>

            <AdminEditorFooter
              leading={
                <AdminButton variant="secondary" type="button" onClick={close}>
                  Close
                </AdminButton>
              }
            >
              <AdminButton type="submit">Save</AdminButton>
            </AdminEditorFooter>
          </AdminEditorForm>
        ),
      }}
    />
  );
}
