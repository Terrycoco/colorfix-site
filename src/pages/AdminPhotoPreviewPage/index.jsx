import "./photopreview.css";

export default function AdminPhotoPreviewPage() {
  return (
    <div className="admin-photo-preview-placeholder">
      <div className="admin-photo-preview-card">
        <h1>Photo Preview (deprecated)</h1>
        <p>
          This tool has been replaced by the new Mask Tester experience.
        </p>
        <p>
          Please head over to <code>/admin/mask-tester</code> to work with photos and masks.
        </p>
      </div>
    </div>
  );
}
