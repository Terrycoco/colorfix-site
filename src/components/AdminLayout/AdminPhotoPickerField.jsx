import {
  useState,
} from "react";

import PhotoPickerModal
  from "@components/PhotoPickerModal";

import AdminButton
  from "./AdminButton.jsx";
import AdminMediaPreview
  from "./AdminMediaPreview.jsx";
import AdminPanel
  from "./AdminPanel.jsx";
import AdminToolbar
  from "./AdminToolbar.jsx";


export default function AdminPhotoPickerField({
  title = "Photo",
  meta = null,
  imageUrl = "",
  photoLibraryId = null,
  placeholder = "No image selected",
  pickerTitle = "Pick Photo",
  pickLabel = "Pick Photo",
  clearLabel = "Clear",
  onPick,
  onClear,
}) {
  const [
    open,
    setOpen,
  ] = useState(false);


  return (
    <>
      <AdminPanel
        title={
          title
        }
        meta={
          meta
        }
        actions={
          <AdminToolbar
            compact
          >
            {
              photoLibraryId
                ? (
                    <AdminButton
                      type="button"
                      variant="secondary"
                      size="sm"
                      onClick={
                        onClear
                      }
                    >
                      {clearLabel}
                    </AdminButton>
                  )
                : null
            }

            <AdminButton
              type="button"
              variant="secondary"
              size="sm"
              onClick={() =>
                setOpen(true)
              }
            >
              {pickLabel}
            </AdminButton>
          </AdminToolbar>
        }
      >
        <AdminMediaPreview
          imageUrl={
            imageUrl
          }
          label={
            photoLibraryId
              ? `Photo #${photoLibraryId}`
              : ""
          }
          placeholder={
            placeholder
          }
        />
      </AdminPanel>

      <PhotoPickerModal
        open={
          open
        }
        title={
          pickerTitle
        }
        onClose={() =>
          setOpen(false)
        }
        onPick={(photo) => {
          if (!photo) {
            return;
          }

          onPick?.(
            photo
          );

          setOpen(
            false
          );
        }}
      />
    </>
  );
}
