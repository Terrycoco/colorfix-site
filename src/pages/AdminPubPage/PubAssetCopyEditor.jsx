import { useEffect, useState } from "react";

import {
  AdminButton,
  AdminDialog,
  AdminEditorBody,
  AdminEditorFooter,
  AdminEditorForm,
  AdminEditorImage,
  AdminEditorPane,
  AdminField,
  AdminNotice,
} from "@components/AdminLayout";

export default function PubAssetCopyEditor({
  asset,
  ingredientBindings = [],
  saving = false,
  recreating = false,
  sendingToPackaging = false,
  approvalSaving = false,
  onSetApproval,
  onSave,
  onRecreate,
  onSendToPackaging,
  onPackagingComplete,
  onRefreshAsset,
  onClose,
}) {
  const [
    title,
    setTitle,
  ] = useState("");

  const [
    description,
    setDescription,
  ] = useState("");

  const [
    successMessage,
    setSuccessMessage,
  ] = useState("");

  const [
    actionError,
    setActionError,
  ] = useState("");

  const [
    redoPromptOpen,
    setRedoPromptOpen,
  ] = useState(false);


  const [
    previewVersion,
    setPreviewVersion,
  ] = useState(
    () => Date.now()
  );


  useEffect(() => {
    setTitle(
      asset?.search_title ||
      ""
    );

    setDescription(
      asset?.description ||
      ""
    );

    setSuccessMessage(
      ""
    );

    setActionError(
      ""
    );

    setRedoPromptOpen(
      false
    );
  }, [
    asset,
  ]);


  if (!asset) {
    return null;
  }


  const busy =
    saving ||
    recreating ||
    sendingToPackaging;


  /*
   * SHIPPED assets are historical records.
   * They remain viewable, but they are no longer editable/re-creatable.
   */
  const pipelineStage =
    String(
      asset?.pipeline_stage ||
      ""
    )
      .trim()
      .toLowerCase();

  const isShipping =
    pipelineStage ===
      "shipping";

  const isShipped =
    pipelineStage ===
      "shipped";

  const isDispatchLocked =
    isShipping ||
    isShipped;

  const editorLocked =
    busy ||
    isDispatchLocked;


  const isApproved =
    Number(
      asset?.approved ||
      0
    ) === 1;


  const approvalEditable =
    pipelineStage ===
      "created" &&
    !isDispatchLocked;


  function currentChanges() {
    return {
      search_title:
        title,

      description:
        description,
    };
  }


  function changedMetadataFields() {
    const changed = [];

    if (
      String(title || "") !==
      String(asset?.search_title || "")
    ) {
      changed.push(
        "search_title"
      );
    }

    if (
      String(description || "") !==
      String(asset?.description || "")
    ) {
      changed.push(
        "description"
      );
    }

    return changed;
  }


  function saveRequiresRedo() {
    const changed =
      new Set(
        changedMetadataFields()
      );

    if (changed.size === 0) {
      return false;
    }

    return (
      Array.isArray(
        ingredientBindings
      )
        ? ingredientBindings
        : []
    ).some(
      (binding) =>
        changed.has(
          String(
            binding?.boxField ||
            ""
          ).trim()
        )
    );
  }


  async function handleSave(
    event
  ) {
    event.preventDefault();

    if (isDispatchLocked) {
      return;
    }

    setSuccessMessage(
      ""
    );

    setActionError(
      ""
    );

    if (saveRequiresRedo()) {
      setRedoPromptOpen(
        true
      );

      return;
    }


    const result =
      await onSave?.(
        currentChanges()
      );

    if (
      result !== false
    ) {
      setSuccessMessage(
        "Changes saved."
      );
    }
  }


  async function saveAndRedo() {
    if (isDispatchLocked) {
      return false;
    }

    setRedoPromptOpen(
      false
    );

    setSuccessMessage(
      ""
    );

    setActionError(
      ""
    );

    /*
     * Save the new outside copy first. The repository repairs any
     * contract-bound Creator ingredients and marks REDO_REQUIRED.
     * CREATE then receives only the durable asset ID and rebuilds from
     * that freshly filed order.
     */
    const saved =
      await onSave?.(
        currentChanges()
      );

    if (
      saved === false
    ) {
      return false;
    }

    const recreated =
      await onRecreate?.(
        asset.pub_asset_id
      );

    if (
      recreated === false
    ) {
      return false;
    }

    await onRefreshAsset?.(
      asset.pub_asset_id
    );

    setPreviewVersion(
      Date.now()
    );

    setSuccessMessage(
      `Asset #${asset.pub_asset_id} recreated successfully.`
    );

    return true;
  }


  async function handleRecreate() {
    await saveAndRedo();
  }


  async function handleApproveAndClose() {
    if (
      isDispatchLocked ||
      !approvalEditable
    ) {
      return;
    }


    setSuccessMessage(
      ""
    );

    setActionError(
      ""
    );


    /*
     * Approval is the final review action for this editor:
     *
     *   save current copy
     *   redo first when a changed field is baked into the asset
     *   approve the finished asset
     *   close the editor
     *
     * Never approve a stale physical render.
     */
    if (saveRequiresRedo()) {
      const recreated =
        await saveAndRedo();


      if (!recreated) {
        return;
      }

    } else {
      const saved =
        await onSave?.(
          currentChanges()
        );


      if (saved === false) {
        setActionError(
          "Could not save this asset before approval."
        );

        return;
      }
    }


    const result =
      await onSetApproval?.(
        asset,
        true
      );


    if (
      result === false
      ||
      result?.ok === false
    ) {
      setActionError(
        result?.error ||
        "Could not approve this asset."
      );

      return;
    }


    onClose?.();
  }


  async function handleSendToPackaging() {
    if (isDispatchLocked) {
      return;
    }


    if (!isApproved) {
      setActionError(
        "Approve this asset before sending it to Packaging."
      );

      return;
    }

    setSuccessMessage(
      ""
    );

    setActionError(
      ""
    );


    /*
     * The Package handed off must reflect the latest editor values.
     * Save first; PackageManager then receives only the durable asset ID.
     */
    const saved =
      await onSave?.(
        currentChanges()
      );


    if (saved === false) {
      setActionError(
        "Could not save this asset before Packaging."
      );

      return;
    }


    const result =
      await onSendToPackaging?.(
        asset.pub_asset_id
      );


    if (
      result === false
      ||
      result?.ok === false
    ) {
      setActionError(
        result?.error ||
        "Could not send this asset to Packaging."
      );

      return;
    }

    onClose?.();
    onPackagingComplete?.(result);
  }


  return (
    <AdminEditorForm onSubmit={handleSave}>
      <AdminEditorBody layout="split">
        <AdminEditorPane kind="preview">
          <AdminEditorImage
            src={
              asset.url
                ? versionedPreviewUrl(asset.url, previewVersion)
                : ""
            }
            alt=""
            placeholder="No preview"
            variant="preview"
          />
        </AdminEditorPane>

        <AdminEditorPane kind="fields">
          <AdminField label="Title">
            <input
              className="admin-field__control admin-field__control--full"
              type="text"
              value={title}
              disabled={editorLocked}
              onChange={(event) => {
                setTitle(event.target.value);
                setSuccessMessage("");
                setActionError("");
              }}
            />
          </AdminField>

          <AdminField label="Description">
            <textarea
              className="admin-field__control admin-field__control--full"
              rows={9}
              value={description}
              disabled={editorLocked}
              onChange={(event) => {
                setDescription(event.target.value);
                setSuccessMessage("");
                setActionError("");
              }}
            />
          </AdminField>

          {successMessage ? (
            <AdminNotice variant="success">
              {successMessage}
            </AdminNotice>
          ) : null}

          {actionError ? (
            <AdminNotice variant="danger">
              {actionError}
            </AdminNotice>
          ) : null}
        </AdminEditorPane>
      </AdminEditorBody>

      <AdminEditorFooter
        leading={
          !isDispatchLocked ? (
            <AdminButton
              type="button"
              disabled={!approvalEditable || approvalSaving || busy}
              title={
                approvalEditable
                  ? "Save, approve, and close this asset."
                  : "Approval is locked after the asset leaves CREATED."
              }
              onClick={handleApproveAndClose}
            >
              {
                approvalSaving
                  ? "Approving..."
                  : saving
                    ? "Saving..."
                    : recreating
                      ? "Recreating..."
                      : "Approve & Close"
              }
            </AdminButton>
          ) : null
        }
      >
        <AdminButton
          type="button"
          variant="secondary"
          disabled={busy}
          onClick={onClose}
        >
          Close
        </AdminButton>

        {!isDispatchLocked ? (
          <>
            <AdminButton
              type="submit"
              variant="secondary"
              disabled={busy}
            >
              {saving ? "Saving..." : "Save"}
            </AdminButton>

            <AdminButton
              type="button"
              disabled={busy}
              onClick={handleRecreate}
            >
              {recreating ? "Recreating..." : "Redo Asset"}
            </AdminButton>

            <AdminButton
              type="button"
              disabled={busy || approvalSaving || !isApproved}
              title={
                isApproved
                  ? "Send this approved asset to Packaging."
                  : "Approve this asset before sending it to Packaging."
              }
              onClick={handleSendToPackaging}
            >
              {sendingToPackaging ? "Sending..." : "Send to Packaging"}
            </AdminButton>
          </>
        ) : null}
      </AdminEditorFooter>

      <AdminDialog
        open={redoPromptOpen}
        title="This change requires a new render"
        message="One or more changed fields are baked into the physical asset. Save the new values and redo this asset now?"
        confirmLabel={
          saving || recreating
            ? "Saving & Redoing..."
            : "Save & Redo"
        }
        cancelLabel="Cancel"
        onConfirm={saveAndRedo}
        onCancel={() => setRedoPromptOpen(false)}
        dismissOnBackdrop={!busy}
      />
    </AdminEditorForm>
  );
}


/**
 * Append a client-local version token to a durable preview URL.
 *
 * This guarantees a fresh browser request even when the backend payload
 * does not expose updated_at/checksum and REDO keeps the same asset URL.
 */
function versionedPreviewUrl(
  url,
  version
) {
  const value =
    String(
      url ||
      ""
    )
      .trim();


  if (!value) {
    return "";
  }


  const separator =
    value.includes(
      "?"
    )
      ? "&"
      : "?";


  return `${value}${separator}v=${encodeURIComponent(
    String(
      version
    )
  )}`;
}

