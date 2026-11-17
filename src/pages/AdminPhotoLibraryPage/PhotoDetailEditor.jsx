import { AdminEditorForm } from "@components/AdminLayout";

export default function PhotoDetailEditor({ children, saving, onSave }) {
  return (
    <AdminEditorForm onSubmit={(event) => { event.preventDefault(); onSave(); }}>
      <fieldset className="photo-detail-editor" disabled={saving}>
        {children}
      </fieldset>
    </AdminEditorForm>
  );
}
