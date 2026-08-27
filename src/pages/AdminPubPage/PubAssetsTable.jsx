
import {
  useEffect,
  useMemo,
  useState,
} from "react";

import {
  createPortal,
} from "react-dom";

import {
  AdminDataGrid,
  AdminEmptyState,
} from "@components/AdminLayout";

import PubAssetCopyEditor
  from "./PubAssetCopyEditor";

import PubVideoAssetEditor
  from "./PubVideoAssetEditor";

import {
  API_FOLDER,
} from "@helpers/config";


const ASSETS_URL =
  `${API_FOLDER}/v2/admin/pub/assets.php`;

const CREATE_URL =
  `${API_FOLDER}/v2/admin/pub/create.php`;

const PACKAGE_URL =
  `${API_FOLDER}/v2/admin/pub/package.php`;


export default function PubAssetsTable({
  onOpenPackage,
}) {
  const [
    assets,
    setAssets,
  ] = useState([]);

  const [
    channels,
    setChannels,
  ] = useState([]);

  const [
    assetTypes,
    setAssetTypes,
  ] = useState([]);

  const [
    channelFilter,
    setChannelFilter,
  ] = useState("");

  const [
    typeFilter,
    setTypeFilter,
  ] = useState("");

  const [
    loading,
    setLoading,
  ] = useState(true);

  const [
    error,
    setError,
  ] = useState("");

  const [
    previewAsset,
    setPreviewAsset,
  ] = useState(null);

  const [
    editAsset,
    setEditAsset,
  ] = useState(null);

  const [
    editIngredientValues,
    setEditIngredientValues,
  ] = useState({});

  const [
    editIngredientBindings,
    setEditIngredientBindings,
  ] = useState([]);

  const [
    savingCopy,
    setSavingCopy,
  ] = useState(false);

  const [
    recreating,
    setRecreating,
  ] = useState(false);

  const [
    sendingToPackaging,
    setSendingToPackaging,
  ] = useState(false);

  const [
    previewVersion,
    setPreviewVersion,
  ] = useState(0);


  /*
   * LOAD ASSETS
   */
  async function loadAssets() {
    setLoading(true);
    setError("");

    try {
      const params =
        new URLSearchParams({
          _:
            String(
              Date.now()
            ),
        });

      if (channelFilter) {
        params.set(
          "channel",
          channelFilter
        );
      }

      if (typeFilter) {
        params.set(
          "asset_type",
          typeFilter
        );
      }

      const res =
        await fetch(
          `${ASSETS_URL}?${params.toString()}`,
          {
            credentials:
              "include",
          }
        );

      const data =
        await res.json();

      if (
        !res.ok ||
        !data?.ok
      ) {
        throw new Error(
          data?.error ||
          "Failed to load PUB assets."
        );
      }

      setAssets(
        Array.isArray(
          data.assets
        )
          ? data.assets
          : []
      );

      setChannels(
        Array.isArray(
          data?.filters?.channels
        )
          ? data.filters.channels
          : []
      );

      setAssetTypes(
        Array.isArray(
          data?.filters?.asset_types
        )
          ? data.filters.asset_types
          : []
      );

    } catch (err) {
      setError(
        err?.message ||
        "Failed to load PUB assets."
      );

    } finally {
      setLoading(false);
    }
  }


  useEffect(() => {
    loadAssets();
  }, [
    channelFilter,
    typeFilter,
  ]);

  useEffect(() => {
  const hasCreating =
    assets.some(
      (asset) =>
        asset.pipeline_stage ===
        "creating"
    );

  if (!hasCreating) {
    return;
  }

  const timer =
    setInterval(
      () => {
        loadAssets();
      },
      12000
    );

  return () => {
    clearInterval(timer);
  };
}, [assets, loadAssets]);


  /*
   * OPEN THE CORRECT ASSET EDITOR.
   *
   * Static image assets already have everything their editor needs
   * in the grid row.
   *
   * Video assets make one small editor-detail request so we can load
   * only their explicitly editable inside production ingredients.
   */
  async function openAssetEditor(
    asset
  ) {
    if (
      !isVideoAsset(
        asset
      )
    ) {
      setEditIngredientValues(
        {}
      );

      setEditIngredientBindings(
        []
      );

      setEditAsset(
        asset
      );

      setPreviewVersion(
        Date.now()
      );

      return;
    }


    const id =
      Number(
        asset
          ?.pub_asset_id ||
        0
      );


    if (!id) {
      return;
    }


    setError(
      ""
    );


    try {
      const params =
        new URLSearchParams({
          pub_asset_id:
            String(
              id
            ),

          _:
            String(
              Date.now()
            ),
        });


      const res =
        await fetch(
          `${ASSETS_URL}?${params.toString()}`,
          {
            credentials:
              "include",
          }
        );


      const data =
        await res.json();


      if (
        !res.ok ||
        !data?.ok
      ) {
        throw new Error(
          data?.error ||
          "Could not load asset editor."
        );
      }


      setEditIngredientValues(
        data
          .ingredient_values &&
        typeof data
          .ingredient_values ===
          "object"
          ? data
              .ingredient_values
          : {}
      );


      setEditIngredientBindings(
        Array.isArray(
          data
            .ingredient_bindings
        )
          ? data
              .ingredient_bindings
          : []
      );


      setEditAsset(
        data.asset ||
        asset
      );


      setPreviewVersion(
        Date.now()
      );

    } catch (err) {
      setError(
        err?.message ||
        "Could not load asset editor."
      );
    }
  }


  /*
   * DELETE
   */
  async function deleteAsset(
    asset
  ) {
    const id =
      Number(
        asset?.pub_asset_id ||
        0
      );

    if (!id) {
      return;
    }

    const stage =
      String(
        asset?.pipeline_stage ||
        ""
      ).toLowerCase();

    if (
      stage === "dispatched" ||
      stage === "published"
    ) {
      return;
    }

    const confirmed =
      window.confirm(
        `Delete PUB asset #${id}?`
      );

    if (!confirmed) {
      return;
    }

    setError("");

    try {
      const res =
        await fetch(
          ASSETS_URL,
          {
            method:
              "DELETE",

            credentials:
              "include",

            headers: {
              "Content-Type":
                "application/json",
            },

            body:
              JSON.stringify({
                pub_asset_id:
                  id,
              }),
          }
        );

      const data =
        await res.json();

      if (
        !res.ok ||
        !data?.ok
      ) {
        throw new Error(
          data?.error ||
          "Could not delete PUB asset."
        );
      }

      setAssets(
        (current) =>
          current.filter(
            (item) =>
              Number(
                item.pub_asset_id
              ) !== id
          )
      );

      if (
        Number(
          previewAsset
            ?.pub_asset_id ||
          0
        ) === id
      ) {
        setPreviewAsset(
          null
        );
      }

      if (
        Number(
          editAsset
            ?.pub_asset_id ||
          0
        ) === id
      ) {
        setEditAsset(
          null
        );

        setEditIngredientValues(
          {}
        );

        setEditIngredientBindings(
          []
        );
      }

    } catch (err) {
      setError(
        err?.message ||
        "Could not delete PUB asset."
      );
    }
  }


  /*
   * SAVE COPY.
   *
   * Updates:
   *
   *   pub_assets
   *   pub_asset_orders.box_json
   *
   * Does NOT recreate the physical asset.
   */
  async function saveAssetCopy(
    changes
  ) {
    const id =
      Number(
        editAsset
          ?.pub_asset_id ||
        0
      );

    if (!id) {
      return false;
    }

    setSavingCopy(true);
    setError("");

    try {
      const res =
        await fetch(
          ASSETS_URL,
          {
            method:
              "PATCH",

            credentials:
              "include",

            headers: {
              "Content-Type":
                "application/json",
            },

            body:
              JSON.stringify({
                pub_asset_id:
                  id,

                search_title:
                  changes
                    .search_title,

                description:
                  changes
                    .description,

                ...(
                  changes
                    .ingredient_changes &&
                  typeof changes
                    .ingredient_changes ===
                    "object"
                    ? {
                        ingredient_changes:
                          changes
                            .ingredient_changes,
                      }
                    : {}
                ),
              }),
          }
        );

      const data =
        await res.json();

      if (
        !res.ok ||
        !data?.ok
      ) {
        throw new Error(
          data?.error ||
          "Could not save asset copy."
        );
      }

      const updatedAsset =
        data.asset || {
          ...editAsset,

          search_title:
            changes
              .search_title,

          description:
            changes
              .description,
        };


      /*
       * Update grid immediately.
       */
      setAssets(
        (current) =>
          current.map(
            (asset) =>
              Number(
                asset
                  .pub_asset_id
              ) === id
                ? {
                    ...asset,
                    ...updatedAsset,
                  }
                : asset
          )
      );


      /*
       * Keep editor open and current.
       *
       * Save does not mean Close.
       */
      setEditAsset(
        (current) =>
          current
            ? {
                ...current,
                ...updatedAsset,
              }
            : current
      );


      if (
        data
          .ingredient_values &&
        typeof data
          .ingredient_values ===
          "object"
      ) {
        setEditIngredientValues(
          data
            .ingredient_values
        );
      }


      return true;

    } catch (err) {
      setError(
        err?.message ||
        "Could not save asset copy."
      );

      return false;

    } finally {
      setSavingCopy(
        false
      );
    }
  }


  /*
   * REDO ASSET.
   *
   * PubAssetCopyEditor saves first.
   *
   * This call sends ONLY the asset ID.
   * Coordinator fetches the current filed order.
   */
  async function recreateAsset(
    pubAssetId
  ) {
    const id =
      Number(
        pubAssetId ||
        0
      );

    if (!id) {
      return false;
    }

    setRecreating(true);
    setError("");

    try {
      const res =
        await fetch(
          CREATE_URL,
          {
            method:
              "POST",

            credentials:
              "include",

            headers: {
              "Content-Type":
                "application/json",
            },

            body:
            JSON.stringify({
              orders: [
                {
                  pub_asset_id:
                    id,
                },
              ],
            }),
          }
        );

      const data =
        await res.json();

      if (
        !res.ok ||
        !data?.ok
      ) {
        throw new Error(
          data?.error ||
          "Could not recreate PUB asset."
        );
      }

      const updatedAsset =
        data.asset || null;


      /*
       * Refresh grid row.
       */
      if (updatedAsset) {
        setAssets(
          (current) =>
            current.map(
              (asset) =>
                Number(
                  asset
                    .pub_asset_id
                ) === id
                  ? {
                      ...asset,
                      ...updatedAsset,
                    }
                  : asset
            )
        );

        setEditAsset(
          (current) =>
            current
              ? {
                  ...current,
                  ...updatedAsset,
                }
              : current
        );
      }


      /*
       * Force browser to fetch the newly
       * overwritten physical asset.
       */
      setPreviewVersion(
        Number(
          data.preview_version ||
          Date.now()
        )
      );

      return true;

    } catch (err) {
      setError(
        err?.message ||
        "Could not recreate PUB asset."
      );

      return false;

    } finally {
      setRecreating(
        false
      );
    }
  }


  /*
   * SEND ONE REVIEWED ASSET TO PACKAGE.
   *
   * The editor saves its current values first.
   * This method then rings the Package doorbell with ONE asset ID.
   *
   * On PACKED or PENDING:
   *   close the editor
   *   refresh Assets
   *   move to the Package workbench
   *
   * On failure:
   *   leave the editor open and return the actual reason.
   */
  async function sendAssetToPackaging(
    pubAssetId
  ) {
    const id =
      Number(
        pubAssetId ||
        0
      );


    if (!id) {
      return {
        ok:
          false,

        error:
          "Valid PUB asset ID required.",
      };
    }


    setSendingToPackaging(
      true
    );

    setError(
      ""
    );


    try {
      const res =
        await fetch(
          PACKAGE_URL,
          {
            method:
              "POST",

            credentials:
              "include",

            headers: {
              "Content-Type":
                "application/json",
            },

            body:
              JSON.stringify({
                pub_asset_id:
                  id,
              }),
          }
        );


      const data =
        await res.json();


      if (
        !res.ok
        ||
        !data?.ok
      ) {
        throw new Error(
          data?.error ||
          "Could not send asset to Packaging."
        );
      }


      const packed =
        Array.isArray(
          data.packed
        )
          ? data.packed
          : [];

      const pending =
        Array.isArray(
          data.pending
        )
          ? data.pending
          : [];

      const failed =
        Array.isArray(
          data.failed
        )
          ? data.failed
          : [];


      if (failed.length) {
        throw new Error(
          failed[0]
            ?.error ||
          "Packaging failed."
        );
      }


      if (
        packed.length === 0
        &&
        pending.length === 0
      ) {
        throw new Error(
          "Packaging did not accept this asset."
        );
      }


      /*
       * Successful department handoff.
       *
       * PENDING counts as successful intake: Package owns the asset now,
       * and its stage_note explains what the specialist is waiting for.
       */
      setEditAsset(
        null
      );

      setEditIngredientValues(
        {}
      );

      setEditIngredientBindings(
        []
      );


      await loadAssets();


      onOpenPackage?.();


      return {
        ok:
          true,

        status:
          packed.length
            ? "packed"
            : "pending",

        data:
          data,
      };

    } catch (err) {
      const message =
        err?.message ||
        "Could not send asset to Packaging.";


      setError(
        message
      );


      return {
        ok:
          false,

        error:
          message,
      };

    } finally {
      setSendingToPackaging(
        false
      );
    }
  }


  /*
   * GRID COLUMNS
   */
  const columns =
    useMemo(
      () => [

        /*
         * COPY EDITOR
         */
        {
          key:
            "edit",

          label:
            "",

          sortable:
            false,

          render:
            (asset) => {
              const stage =
                String(
                  asset
                    .pipeline_stage ||
                  ""
                ).toLowerCase();

              const locked =
                stage ===
                  "dispatched" ||
                stage ===
                  "published";

              if (locked) {
                return "—";
              }

              return (
                <button
                  type="button"

                  title="Edit asset"

                  style={
                    pencilButtonStyle
                  }

                  onClick={(
                    event
                  ) => {
                    event
                      .stopPropagation();

                    openAssetEditor(
                      asset
                    );
                  }}
                >
                  ✎
                </button>
              );
            },
        },
      


        {
          key:
            "pub_asset_id",

          label:
            "ID",

          sortValue:
            (asset) =>
              Number(
                asset
                  .pub_asset_id ||
                0
              ),
        },


        {
          key:
            "pub_run_id",

          label:
            "Job ID",

          value:
            (asset) =>
              Number(
                asset
                  .pub_run_id ||
                0
              ) || "—",

          sortValue:
            (asset) =>
              Number(
                asset
                  .pub_run_id ||
                0
              ),
        },


        {
          key:
            "channel",

          label:
            "Channel",
        },


        {
          key:
            "asset_type",

          label:
            "Type",
        },


        {
          key:
            "source",

          label:
            "Source",

          value:
            (asset) => {
              const type =
                asset
                  .source_type ||
                "";

              const id =
                asset
                  .source_id ||
                "";

              if (
                !type &&
                !id
              ) {
                return "—";
              }

              return `${type} #${id}`;
            },

          sortValue:
            (asset) =>
              `${asset.source_type || ""} ${asset.source_id || ""}`,
        },


        {
          key:
            "pipeline_stage",

          label:
            "Stage",

          render:
            (asset) => {
              const stage =
                String(
                  asset
                    .pipeline_stage ||
                  ""
                )
                  .trim()
                  .toLowerCase();

              if (!stage) {
                return "—";
              }

              return (
                <span
                  style={
                    stage ===
                      "redo_required"
                      ? redoRequiredStageStyle
                      : stage ===
                          "creating"
                        ? creatingStageStyle
                        : stageBadgeStyle
                  }
                >
                  {humanize(
                    stage
                  )}
                </span>
              );
            },

          sortValue:
            (asset) =>
              String(
                asset
                  .pipeline_stage ||
                ""
              ),
        },


        {
          key:
            "search_title",

          label:
            "Title",

          value:
            (asset) =>
              asset
                .search_title ||
              "—",
        },

        {
          key:
            "updated_at",

          label:
            "Last Updated",

          value:
            (asset) =>
              asset
                .updated_at ||
              "—",
        },


        {
          key:
            "delete",

          label:
            "",

          sortable:
            false,

          render:
            (asset) => {
              const stage =
                String(
                  asset
                    .pipeline_stage ||
                  ""
                ).toLowerCase();

              const locked =
                stage ===
                  "dispatched" ||
                stage ===
                  "published";

              return (
                <button
                  type="button"

                  style={
                    rowActionButtonStyle
                  }

                  disabled={
                    locked
                  }

                  onClick={(
                    event
                  ) => {
                    event
                      .stopPropagation();

                    deleteAsset(
                      asset
                    );
                  }}
                >
                  Delete
                </button>
              );
            },
        },
      ],
      []
    );


  /*
   * PAGE
   */
  if (
    loading &&
    !assets.length
  ) {
    return (
      <AdminEmptyState
        title="Assets"

        message="Loading PUB assets..."
      />
    );
  }


  return (
    <>
      <div
        className="admin-detail-workarea"
      >
        <div
          style={
            filterBarStyle
          }
        >
          <label
            className="admin-field"
          >
            <span
              className="admin-field__label"
            >
              Channel
            </span>

            <select
              className="admin-field__control"

              value={
                channelFilter
              }

              onChange={(
                event
              ) =>
                setChannelFilter(
                  event
                    .target
                    .value
                )
              }
            >
              <option value="">
                All
              </option>

              {channels.map(
                (channel) => (
                  <option
                    key={
                      channel
                    }

                    value={
                      channel
                    }
                  >
                    {humanize(
                      channel
                    )}
                  </option>
                )
              )}
            </select>
          </label>


          <label
            className="admin-field"
          >
            <span
              className="admin-field__label"
            >
              Type
            </span>

            <select
              className="admin-field__control"

              value={
                typeFilter
              }

              onChange={(
                event
              ) =>
                setTypeFilter(
                  event
                    .target
                    .value
                )
              }
            >
              <option value="">
                All
              </option>

              {assetTypes.map(
                (assetType) => (
                  <option
                    key={
                      assetType
                    }

                    value={
                      assetType
                    }
                  >
                    {humanize(
                      assetType
                    )}
                  </option>
                )
              )}
            </select>
          </label>


          <button
            type="button"

            onClick={
              loadAssets
            }

            disabled={
              loading
            }

            style={
              refreshButtonStyle
            }
          >
            {loading
              ? "Refreshing..."
              : "Refresh"}
          </button>


          <div
            style={
              countStyle
            }
          >
            {assets.length} asset
            {assets.length === 1
              ? ""
              : "s"}
          </div>
        </div>


        {error ? (
          <div
            style={
              errorStyle
            }
          >
            {error}
          </div>
        ) : null}


        <AdminDataGrid
          items={
            assets
          }

          columns={
            columns
          }

          getRowKey={(
            asset
          ) =>
            asset
              .pub_asset_id
          }

          defaultSortKey="pub_asset_id"

          defaultSortDirection="desc"

          ariaLabel="PUB assets"
        />
      </div>


      {/* FULL-SCREEN VIEW */}
      {previewAsset
        ?.url
        ? createPortal(
            <div
              style={
                previewOverlayStyle
              }

              onClick={() =>
                setPreviewAsset(
                  null
                )
              }
            >
              <img
                src={
                  withVersion(
                    previewAsset
                      .url,

                    previewVersion
                  )
                }

                alt=""

                style={
                  previewImageStyle
                }
              />
            </div>,

            document.body
          )
        : null}


      {/* ASSET EDITOR */}
      {editAsset ? (
        isVideoAsset(
          editAsset
        ) ? (
          <PubVideoAssetEditor
            ingredientValues={
              editIngredientValues
            }

            ingredientBindings={
              editIngredientBindings
            }

            asset={{
              ...editAsset,

              /*
               * Cache-bust video after REDO.
               */
              url:
                withVersion(
                  editAsset.url,
                  previewVersion
                ),
            }}

            saving={
              savingCopy
            }

            recreating={
              recreating
            }

            sendingToPackaging={
              sendingToPackaging
            }

            onSave={
              saveAssetCopy
            }

            onRecreate={
              recreateAsset
            }

            onSendToPackaging={
              sendAssetToPackaging
            }

            onRefreshAssets={
              loadAssets
            }

            onClose={() => {
              setEditAsset(
                null
              );

              setEditIngredientValues(
                {}
              );

              setEditIngredientBindings(
                []
              );
            }}
          />
        ) : (
          <PubAssetCopyEditor
            asset={{
              ...editAsset,

              /*
               * Cache-bust preview after REDO.
               */
              url:
                withVersion(
                  editAsset.url,
                  previewVersion
                ),
            }}

            saving={
              savingCopy
            }

            recreating={
              recreating
            }

            sendingToPackaging={
              sendingToPackaging
            }

            onSave={
              saveAssetCopy
            }

            onRecreate={
              recreateAsset
            }

            onSendToPackaging={
              sendAssetToPackaging
            }

            onClose={() => {
              setEditAsset(
                null
              );

              setEditIngredientValues(
                {}
              );
            }}
          />
        )
      ) : null}
    </>
  );
}


function isVideoAsset(
  asset
) {
  const assetType =
    String(
      asset?.asset_type ||
      ""
    )
      .trim()
      .toLowerCase();


  return (
    assetType ===
      "pin_before_after_video" ||
    assetType ===
      "youtube_video"
  );
}


function withVersion(
  url,
  version
) {
  const raw =
    String(
      url || ""
    ).trim();

  if (!raw) {
    return "";
  }

  if (!version) {
    return raw;
  }

  return (
    raw +
    (
      raw.includes("?")
        ? "&"
        : "?"
    ) +
    "v=" +
    encodeURIComponent(
      String(
        version
      )
    )
  );
}


function humanize(
  value
) {
  return String(
    value || ""
  )
    .replace(
      /^pin_/,
      ""
    )
    .replace(
      /[_-]+/g,
      " "
    )
    .replace(
      /\b\w/g,
      (character) =>
        character.toUpperCase()
    );
}


const stageBadgeStyle = {
  display:
    "inline-block",

  padding:
    "3px 7px",

  borderRadius:
    999,

  background:
    "#eef1f4",

  color:
    "#465465",

  fontSize:
    11,

  fontWeight:
    600,

  lineHeight:
    1.2,
};


const creatingStageStyle = {
  ...stageBadgeStyle,

  background:
    "#e8f1fb",

  color:
    "#245b88",
};


const redoRequiredStageStyle = {
  ...stageBadgeStyle,

  background:
    "#fff1bf",

  color:
    "#7a5600",

  border:
    "1px solid #e1c15e",
};


const filterBarStyle = {
  display:
    "flex",

  alignItems:
    "flex-end",

  gap:
    12,

  padding:
    "14px 0",
};


const refreshButtonStyle = {
  marginLeft:
    "auto",

  marginBottom:
    1,
};


const countStyle = {
  paddingBottom:
    7,

  color:
    "#586675",

  fontSize:
    13,
};


const errorStyle = {
  marginBottom:
    12,

  padding:
    "8px 10px",

  border:
    "1px solid #d8dde3",

  background:
    "#fff7f7",
};


const previewOverlayStyle = {
  position:
    "fixed",

  inset:
    0,

  zIndex:
    2147483647,

  display:
    "flex",

  alignItems:
    "center",

  justifyContent:
    "center",

  padding:
    30,

  background:
    "rgba(0, 0, 0, 0.88)",

  cursor:
    "pointer",
};


const previewImageStyle = {
  display:
    "block",

  maxWidth:
    "95vw",

  maxHeight:
    "95vh",

  width:
    "auto",

  height:
    "auto",

  objectFit:
    "contain",
};


const rowActionButtonStyle = {
  padding:
    "3px 7px",

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
    11,

  lineHeight:
    1.2,

  fontWeight:
    500,

  cursor:
    "pointer",
};


const pencilButtonStyle = {
  padding:
    "2px 5px",

  minHeight:
    0,

  border:
    "1px solid #cfd5dc",

  borderRadius:
    3,

  background:
    "#ffffff",

  color:
    "#526273",

  fontSize:
    14,

  lineHeight:
    1,

  fontWeight:
    400,

  cursor:
    "pointer",
};