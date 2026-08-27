import {
  useEffect,
  useState,
} from "react";

import {
  createPortal,
} from "react-dom";


export default function PubAssetCopyEditor({
  asset,
  saving = false,
  recreating = false,
  sendingToPackaging = false,
  onSave,
  onRecreate,
  onSendToPackaging,
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


  function currentChanges() {
    return {
      search_title:
        title,

      description:
        description,
    };
  }


  async function handleSave(
    event
  ) {
    event.preventDefault();

    setSuccessMessage(
      ""
    );

    setActionError(
      ""
    );

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


  async function handleRecreate() {
    setSuccessMessage(
      ""
    );

    setActionError(
      ""
    );

    /*
     * Redo always saves the current
     * editor values first.
     *
     * Copy Editing updates the filed
     * order. CREATE then receives only
     * the asset number and fetches that
     * latest order itself.
     */
    const saved =
      await onSave?.(
        currentChanges()
      );

    if (
      saved === false
    ) {
      return;
    }

    const recreated =
      await onRecreate?.(
        asset.pub_asset_id
      );

    if (
      recreated !== false
    ) {
      setSuccessMessage(
        `Asset #${asset.pub_asset_id} recreated successfully.`
      );
    }
  }


  async function handleSendToPackaging() {
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
                  asset.url
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
                  busy
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
                  busy
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
              busy
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
        </div>
      </form>
    </div>,

    document.body
  );
}


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
