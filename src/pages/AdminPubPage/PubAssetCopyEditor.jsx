import {
  useEffect,
  useState,
} from "react";

import {
  createPortal,
} from "react-dom";


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


  async function handleApprovalChange(
    event
  ) {
    const checked =
      event
        .target
        .checked;


    setSuccessMessage(
      ""
    );

    setActionError(
      ""
    );


    const result =
      await onSetApproval?.(
        asset,
        checked
      );


    if (
      result === false
      ||
      result?.ok === false
    ) {
      setActionError(
        result?.error ||
        "Could not change asset approval."
      );
    }
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
    }
  }


  return createPortal(
    <div
      style={
        overlayStyle
      }

      onMouseDown={(
        event
      ) => {
        if (
          event.target ===
          event.currentTarget
          &&
          !busy
        ) {
          onClose?.();
        }
      }}
    >
      <form
        style={
          dialogStyle
        }

        onSubmit={
          handleSave
        }
      >
        <div
          style={
            headerStyle
          }
        >
          <strong>
            Edit Asset Copy
          </strong>

          <div
            style={
              headerRightStyle
            }
          >
            {isDispatchLocked ? (
              <div
                style={
                  shippedBadgeStyle
                }
              >
                {
                  isShipping
                    ? "SHIPPING"
                    : "SHIPPED"
                }
              </div>
            ) : null}

            <div
              style={
                assetIdStyle
              }
            >
              Asset #
              {
                asset
                  .pub_asset_id
              }
            </div>

            <button
              type="button"

              style={
                quietButtonStyle
              }

              disabled={
                busy
              }

              onClick={
                onClose
              }
            >
              ×
            </button>
          </div>
        </div>


        <div
          style={
            bodyStyle
          }
        >
          <div
            style={
              previewColumnStyle
            }
          >
            {asset.url ? (
              <img
                src={
                  versionedPreviewUrl(
                    asset.url,
                    previewVersion
                  )
                }

                alt=""

                style={
                  previewStyle
                }
              />
            ) : (
              <div
                style={
                  noPreviewStyle
                }
              >
                No preview
              </div>
            )}
          </div>


          <div
            style={
              fieldsStyle
            }
          >
            <label
              className="admin-field"
            >
              <span
                className="admin-field__label"
              >
                Title
              </span>

              <input
                className="admin-field__control"

                type="text"

                value={
                  title
                }

                disabled={
                  editorLocked
                }

                onChange={(
                  event
                ) => {
                  setTitle(
                    event
                      .target
                      .value
                  );

                  setSuccessMessage(
                    ""
                  );

                  setActionError(
                    ""
                  );
                }}

                style={{
                  width:
                    "100%",
                }}
              />
            </label>


            <label
              className="admin-field"
            >
              <span
                className="admin-field__label"
              >
                Description
              </span>

              <textarea
                className="admin-field__control"

                rows={9}

                value={
                  description
                }

                disabled={
                  editorLocked
                }

                onChange={(
                  event
                ) => {
                  setDescription(
                    event
                      .target
                      .value
                  );

                  setSuccessMessage(
                    ""
                  );

                  setActionError(
                    ""
                  );
                }}

                style={{
                  width:
                    "100%",

                  resize:
                    "vertical",

                  boxSizing:
                    "border-box",
                }}
              />
            </label>


            {successMessage ? (
              <div
                style={
                  successStyle
                }
              >
                {
                  successMessage
                }
              </div>
            ) : null}


            {actionError ? (
              <div
                style={
                  errorStyle
                }
              >
                {
                  actionError
                }
              </div>
            ) : null}
          </div>
        </div>


        <div
          style={
            footerStyle
          }
        >
          <label
            style={
              approvalControlStyle
            }
            title={
              approvalEditable
                ? isApproved
                  ? "Uncheck to revoke approval."
                  : "Approve this asset for Packaging."
                : "Approval is locked after the asset leaves CREATED."
            }
          >
            <input
              type="checkbox"

              checked={
                isApproved
              }

              disabled={
                !approvalEditable ||
                approvalSaving ||
                busy
              }

              onChange={
                handleApprovalChange
              }
            />

            <span>
              Approved
            </span>
          </label>


          <button
            type="button"

            style={
              quietButtonStyle
            }

            disabled={
              busy
            }

            onClick={
              onClose
            }
          >
            Close
          </button>


          {!isDispatchLocked ? (
            <>
              <button
                type="submit"

                style={
                  quietButtonStyle
                }

                disabled={
                  busy
                }
              >
                {saving
                  ? "Saving..."
                  : "Save"}
              </button>


              <button
                type="button"

                disabled={
                  busy
                }

                onClick={
                  handleRecreate
                }
              >
                {recreating
                  ? "Recreating..."
                  : "Redo Asset"}
              </button>


              <button
                type="button"

                disabled={
                  busy ||
                  approvalSaving ||
                  !isApproved
                }

                title={
                  isApproved
                    ? "Send this approved asset to Packaging."
                    : "Approve this asset before sending it to Packaging."
                }

                onClick={
                  handleSendToPackaging
                }
              >
                {
                  sendingToPackaging
                    ? "Sending..."
                    : "Send to Packaging"
                }
              </button>
            </>
          ) : null}
        </div>
      </form>


      {redoPromptOpen ? (
        <div
          style={
            redoConfirmOverlayStyle
          }
        >
          <div
            role="dialog"
            aria-modal="true"
            aria-label="Save changes and redo asset"
            style={
              redoConfirmDialogStyle
            }
          >
            <div
              style={
                redoConfirmTitleStyle
              }
            >
              This change requires a new render
            </div>

            <div
              style={
                redoConfirmBodyStyle
              }
            >
              One or more changed fields are baked into the physical asset.
              Save the new values and redo this asset now?
            </div>

            <div
              style={
                redoConfirmActionsStyle
              }
            >
              <button
                type="button"
                style={
                  quietButtonStyle
                }
                disabled={
                  busy
                }
                onClick={() =>
                  setRedoPromptOpen(
                    false
                  )
                }
              >
                Cancel
              </button>

              <button
                type="button"
                disabled={
                  busy
                }
                onClick={
                  saveAndRedo
                }
              >
                {
                  saving ||
                  recreating
                    ? "Saving & Redoing..."
                    : "Save & Redo"
                }
              </button>
            </div>
          </div>
        </div>
      ) : null}
    </div>,

    document.body
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


const redoConfirmOverlayStyle = {
  position:
    "fixed",

  inset:
    0,

  zIndex:
    2147483648,

  display:
    "grid",

  placeItems:
    "center",

  padding:
    30,

  background:
    "rgba(0, 0, 0, 0.48)",
};


const redoConfirmDialogStyle = {
  width:
    "min(460px, 92vw)",

  background:
    "#ffffff",

  border:
    "1px solid #cfd5dc",

  borderRadius:
    5,

  boxShadow:
    "0 18px 50px rgba(0,0,0,0.30)",
};


const redoConfirmTitleStyle = {
  padding:
    "13px 15px",

  borderBottom:
    "1px solid #d8dde3",

  fontSize:
    17,

  fontWeight:
    700,
};


const redoConfirmBodyStyle = {
  padding:
    16,

  color:
    "#334155",

  fontSize:
    13,

  lineHeight:
    1.5,
};


const redoConfirmActionsStyle = {
  display:
    "flex",

  justifyContent:
    "flex-end",

  gap:
    8,

  padding:
    "11px 12px",

  borderTop:
    "1px solid #d8dde3",
};


const overlayStyle = {
  position:
    "fixed",

  inset:
    0,

  zIndex:
    2147483647,

  display:
    "grid",

  placeItems:
    "center",

  padding:
    30,

  background:
    "rgba(0, 0, 0, 0.55)",
};


const dialogStyle = {
  width:
    "min(760px, 94vw)",

  maxHeight:
    "92vh",

  display:
    "flex",

  flexDirection:
    "column",

  background:
    "#ffffff",

  border:
    "1px solid #cfd5dc",

  borderRadius:
    5,

  boxShadow:
    "0 18px 50px rgba(0,0,0,0.28)",
};


const headerStyle = {
  display:
    "flex",

  alignItems:
    "center",

  justifyContent:
    "space-between",

  padding:
    "10px 12px",

  borderBottom:
    "1px solid #d8dde3",
};


const headerRightStyle = {
  display:
    "flex",

  alignItems:
    "center",

  gap:
    10,
};


const bodyStyle = {
  display:
    "grid",

  gridTemplateColumns:
    "220px minmax(0, 1fr)",

  gap:
    18,

  padding:
    16,

  overflow:
    "auto",
};


const previewColumnStyle = {
  minWidth:
    0,
};


const previewStyle = {
  display:
    "block",

  width:
    "100%",

  maxHeight:
    340,

  objectFit:
    "contain",

  background:
    "#f4f5f6",

  border:
    "1px solid #d8dde3",
};


const noPreviewStyle = {
  height:
    260,

  display:
    "grid",

  placeItems:
    "center",

  background:
    "#f4f5f6",

  border:
    "1px solid #d8dde3",

  color:
    "#6b7280",
};


const shippedBadgeStyle = {
  padding:
    "3px 7px",

  border:
    "1px solid #9bbda7",

  borderRadius:
    999,

  background:
    "#eef8f1",

  color:
    "#2f6b43",

  fontSize:
    11,

  fontWeight:
    800,

  letterSpacing:
    "0.04em",

  whiteSpace:
    "nowrap",
};


const assetIdStyle = {
  color:
    "#586675",

  fontSize:
    12,

  fontWeight:
    600,

  whiteSpace:
    "nowrap",
};


const fieldsStyle = {
  display:
    "flex",

  flexDirection:
    "column",

  gap:
    14,

  minWidth:
    0,
};


const approvalControlStyle = {
  display:
    "flex",

  alignItems:
    "center",

  gap:
    7,

  marginRight:
    "auto",

  color:
    "#334155",

  fontSize:
    12,

  fontWeight:
    700,

  cursor:
    "pointer",
};


const footerStyle = {
  display:
    "flex",

  justifyContent:
    "flex-end",

  gap:
    8,

  padding:
    "10px 12px",

  borderTop:
    "1px solid #d8dde3",
};


const successStyle = {
  padding:
    "8px 10px",

  border:
    "1px solid #cfd5dc",

  background:
    "#f6f8f9",

  fontSize:
    12,
};


const errorStyle = {
  padding:
    "8px 10px",

  border:
    "1px solid #e2baba",

  background:
    "#fff7f7",

  color:
    "#7d2e2e",

  fontSize:
    12,
};


const quietButtonStyle = {
  padding:
    "4px 8px",

  minHeight:
    0,

  border:
    "1px solid #cfd5dc",

  borderRadius:
    3,

  background:
    "#ffffff",

  color:
    "#334155",

  fontSize:
    12,

  lineHeight:
    1.2,

  fontWeight:
    500,

  cursor:
    "pointer",
};
