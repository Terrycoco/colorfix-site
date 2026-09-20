import {
  useEffect,
  useMemo,
  useState,
} from "react";

import {
  AdminDialog,
} from "@components/AdminLayout";

import {
  API_FOLDER,
} from "@helpers/config";

import "./styles/Dialogs.css";


const UPLOAD_URL =
  `${API_FOLDER}/v2/admin/photos/upload.php`;


function parseJsonResponse(text) {
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}


export default function UploadPhotoDialog({
  open,
  onClose,
  onUploaded,
  title = "Upload Photo",
}) {
  const [
    file,
    setFile,
  ] = useState(null);

  const [
    tags,
    setTags,
  ] = useState("");

  const [
    photoTitle,
    setPhotoTitle,
  ] = useState("");

  const [
    altText,
    setAltText,
  ] = useState("");

  const [
    uploading,
    setUploading,
  ] = useState(false);

  const [
    error,
    setError,
  ] = useState("");


  const previewUrl =
    useMemo(
      () =>
        file
          ? URL.createObjectURL(file)
          : "",
      [file]
    );


  useEffect(() => {
    return () => {
      if (previewUrl) {
        URL.revokeObjectURL(
          previewUrl
        );
      }
    };
  }, [previewUrl]);


  useEffect(() => {
    if (!open) {
      return;
    }

    setFile(null);
    setTags("");
    setPhotoTitle("");
    setAltText("");
    setUploading(false);
    setError("");
  }, [open]);


  function handleCancel() {
    if (uploading) {
      return;
    }

    onClose?.();
  }


  async function handleUpload() {
    if (uploading) {
      return;
    }

    if (!file) {
      setError(
        "Choose a photo."
      );
      return;
    }

    if (!tags.trim()) {
      setError(
        "Add at least one tag."
      );
      return;
    }

    setUploading(true);
    setError("");

    try {
      const formData =
        new FormData();

      formData.append(
        "photo",
        file
      );

      formData.append(
        "tags",
        tags.trim()
      );

      if (
        photoTitle.trim()
      ) {
        formData.append(
          "title",
          photoTitle.trim()
        );
      }

      if (
        altText.trim()
      ) {
        formData.append(
          "alt_text",
          altText.trim()
        );
      }

      const res =
        await fetch(
          UPLOAD_URL,
          {
            method:
              "POST",

            credentials:
              "include",

            body:
              formData,
          }
        );

      const text =
        await res.text();

      const data =
        parseJsonResponse(
          text
        );

      if (
        !res.ok
        ||
        !data?.ok
        ||
        !data?.photo
      ) {
        throw new Error(
          data?.error
          ||
          `Upload failed (HTTP ${res.status}).`
        );
      }

      onUploaded?.(
        data.photo
      );

      onClose?.();

    } catch (err) {
      setError(
        err?.message
        ||
        "Upload failed."
      );

    } finally {
      setUploading(false);
    }
  }


  const canUpload =
    Boolean(
      file
      &&
      tags.trim()
    )
    &&
    !uploading;


  const actions = [
    {
      key:
        "cancel",

      label:
        "Cancel",

      variant:
        "secondary",

      disabled:
        uploading,

      onClick:
        handleCancel,
    },

    {
      key:
        "upload",

      label:
        uploading
          ? "Uploading…"
          : "Upload",

      variant:
        "primary",

      disabled:
        !canUpload,

      onClick:
        handleUpload,
    },
  ];


  return (
    <AdminDialog
      open={open}
      title={title}
      width={560}
      actions={actions}
      onCancel={handleCancel}
      onClose={handleCancel}
      dismissOnBackdrop={!uploading}
    >
      <div className="dialog-layout dialog-layout--preview-form">
        <div className="dialog-preview">
          {previewUrl ? (
            <img
              src={previewUrl}
              alt=""
            />
          ) : (
            <div className="dialog-preview__placeholder">
              No photo selected
            </div>
          )}
        </div>

        <div className="dialog-fields">
          {error ? (
            <div
              className="dialog-error"
              role="alert"
            >
              {error}
            </div>
          ) : null}

          <label>
            Photo
            <input
              type="file"
              accept="image/jpeg,image/png,image/webp"
              disabled={uploading}
              onChange={
                (event) => {
                  const nextFile =
                    event.target.files?.[0]
                    ||
                    null;

                  setFile(
                    nextFile
                  );

                  setError("");
                }
              }
            />
          </label>

          <label>
            Tags *
            <input
              type="text"
              value={tags}
              disabled={uploading}
              placeholder="interior, wine-room, before"
              onChange={
                (event) =>
                  setTags(
                    event.target.value
                  )
              }
            />
          </label>

          <label>
            Title
            <input
              type="text"
              value={photoTitle}
              disabled={uploading}
              placeholder="Optional — filename used if blank"
              onChange={
                (event) =>
                  setPhotoTitle(
                    event.target.value
                  )
              }
            />
          </label>

          <label>
            Alt text
            <input
              type="text"
              value={altText}
              disabled={uploading}
              placeholder="Optional — AI can generate later"
              onChange={
                (event) =>
                  setAltText(
                    event.target.value
                  )
              }
            />
          </label>
        </div>
      </div>
    </AdminDialog>
  );
}
