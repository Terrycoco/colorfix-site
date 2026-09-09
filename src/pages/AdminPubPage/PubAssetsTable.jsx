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
  const [assets, setAssets] =
    useState([]);

  const [channels, setChannels] =
    useState([]);

  const [assetTypes, setAssetTypes] =
    useState([]);

  const [stages, setStages] =
    useState([]);

  const [
    sourcePlaylists,
    setSourcePlaylists,
  ] = useState([]);

  const [
    sourcePlaylistFilter,
    setSourcePlaylistFilter,
  ] = useState("");

  const [channelFilter, setChannelFilter] =
    useState("");

  const [typeFilter, setTypeFilter] =
    useState("");

  const [stageFilter, setStageFilter] =
    useState("");

  const [loading, setLoading] =
    useState(true);

  const [error, setError] =
    useState("");

  const [previewAsset, setPreviewAsset] =
    useState(null);

  const [editAsset, setEditAsset] =
    useState(null);

  const [
    editIngredientValues,
    setEditIngredientValues,
  ] = useState({});

  const [
    editIngredientBindings,
    setEditIngredientBindings,
  ] = useState([]);

  const [savingCopy, setSavingCopy] =
    useState(false);

  const [recreating, setRecreating] =
    useState(false);

  const [
    sendingToPackaging,
    setSendingToPackaging,
  ] = useState(false);

  const [
    approvingAssetId,
    setApprovingAssetId,
  ] = useState(0);

  const [
    previewVersion,
    setPreviewVersion,
  ] = useState(0);

  /*
   * Historical-delete confirmation.
   *
   * A shipped record is intentionally difficult to erase:
   * open dedicated warning -> check explicit authorization ->
   * Delete Forever.
   */
  const [
    deleteForeverAsset,
    setDeleteForeverAsset,
  ] = useState(null);

  const [
    deleteForeverAuthorized,
    setDeleteForeverAuthorized,
  ] = useState(false);

  const [
    deletingAssetId,
    setDeletingAssetId,
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

      if (sourcePlaylistFilter) {
        params.set(
          "source_type",
          "playlist"
        );

        params.set(
          "source_id",
          sourcePlaylistFilter
        );
      }

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

      if (stageFilter) {
        params.set(
          "stage",
          stageFilter
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

      setStages(
        Array.isArray(
          data?.filters?.stages
        )
          ? data.filters.stages
          : []
      );

      setSourcePlaylists(
        Array.isArray(
          data?.filters?.source_playlists
        )
          ? data.filters.source_playlists
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
    sourcePlaylistFilter,
    channelFilter,
    typeFilter,
    stageFilter,
  ]);


  useEffect(() => {
    const hasCreating =
      assets.some(
        (asset) =>
          asset.pipeline_stage ===
          "creating"
      );

    /*
     * Never let background polling disturb an active asset editor.
     *
     * Opening an editor immediately clears the current timer because
     * editAsset is a dependency of this effect. Polling resumes only
     * after the editor closes.
     */
    if (
      !hasCreating ||
      editAsset
    ) {
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
  }, [
    assets,
    editAsset,
  ]);


  /*
   * OPEN THE CORRECT ASSET EDITOR.
   */
  async function openAssetEditor(
    asset
  ) {
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
      const detail =
        await fetchAssetEditorDetail(
          id
        );


      setEditIngredientValues(
        detail
          .ingredient_values &&
        typeof detail
          .ingredient_values ===
          "object"
          ? detail
              .ingredient_values
          : {}
      );


      setEditIngredientBindings(
        Array.isArray(
          detail
            .ingredient_bindings
        )
          ? detail
              .ingredient_bindings
          : []
      );


      setEditAsset(
        detail.asset ||
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


  async function fetchAssetEditorDetail(
    pubAssetId
  ) {
    const id =
      Number(
        pubAssetId ||
        0
      );


    if (!id) {
      throw new Error(
        "Valid PUB asset ID required."
      );
    }


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


    return data;
  }


  async function refreshOpenAsset(
    pubAssetId
  ) {
    const id =
      Number(
        pubAssetId ||
        editAsset
          ?.pub_asset_id ||
        0
      );


    if (!id) {
      return false;
    }


    try {
      const detail =
        await fetchAssetEditorDetail(
          id
        );


      setEditAsset(
        detail.asset ||
        null
      );


      setEditIngredientValues(
        detail
          .ingredient_values &&
        typeof detail
          .ingredient_values ===
          "object"
          ? detail
              .ingredient_values
          : {}
      );


      setEditIngredientBindings(
        Array.isArray(
          detail
            .ingredient_bindings
        )
          ? detail
              .ingredient_bindings
          : []
      );


      setPreviewVersion(
        Date.now()
      );


      await loadAssets();

      return true;

    } catch (err) {
      setError(
        err?.message ||
        "Could not refresh asset editor."
      );

      return false;
    }
  }


  /*
   * APPROVE / UNAPPROVE.
   *
   * This is an immediate human checkpoint, not an Editor Save.
   */
  async function setAssetApproval(
    asset,
    approved
  ) {
    const id =
      Number(
        asset?.pub_asset_id ||
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


    setApprovingAssetId(
      id
    );

    setError(
      ""
    );


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

                approved:
                  Boolean(
                    approved
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
          "Could not change asset approval."
        );
      }


      const updatedAsset =
        data.asset || {
          ...asset,
          approved:
            approved
              ? 1
              : 0,
          display_stage:
            approved
              ? "approved"
              : "created",
        };


      setAssets(
        (current) =>
          current.map(
            (item) =>
              Number(
                item.pub_asset_id
              ) === id
                ? {
                    ...item,
                    ...updatedAsset,
                  }
                : item
          )
      );


      setEditAsset(
        (current) =>
          Number(
            current?.pub_asset_id ||
            0
          ) === id
            ? {
                ...current,
                ...updatedAsset,
              }
            : current
      );


      /*
       * If CREATED or APPROVED itself is the active filter, the row may
       * have just moved out of that result set. Reload immediately so the
       * grid remains truthful.
       */
      if (
        stageFilter === "created" ||
        stageFilter === "approved"
      ) {
        await loadAssets();
      }


      return {
        ok:
          true,

        asset:
          updatedAsset,
      };

    } catch (err) {
      const message =
        err?.message ||
        "Could not change asset approval.";


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
      setApprovingAssetId(
        0
      );
    }
  }


  /*
   * DELETE
   */
  function requestDeleteAsset(
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
      normalizedStage(
        asset
      );


    /*
     * Dispatch currently owns this asset. Do not provide an override
     * until the shipping operation has actually finished.
     */
    if (
      stage === "shipping"
    ) {
      setError(
        "This asset is currently shipping and cannot be deleted."
      );

      return;
    }


    if (
      isHistoricalStage(
        stage
      )
    ) {
      setDeleteForeverAuthorized(
        false
      );

      setDeleteForeverAsset(
        asset
      );

      return;
    }


    const confirmed =
      window.confirm(
        `Delete PUB asset #${id}?`
      );


    if (!confirmed) {
      return;
    }


    performDeleteAsset(
      asset,
      false
    );
  }


  async function performDeleteAsset(
    asset,
    forceDeleteShipped
  ) {
    const id =
      Number(
        asset?.pub_asset_id ||
        0
      );


    if (!id) {
      return false;
    }


    setDeletingAssetId(
      id
    );

    setError(
      ""
    );


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

                ...(
                  forceDeleteShipped
                    ? {
                        force_delete_shipped:
                          true,
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


      if (
        Number(
          deleteForeverAsset
            ?.pub_asset_id ||
          0
        ) === id
      ) {
        setDeleteForeverAsset(
          null
        );

        setDeleteForeverAuthorized(
          false
        );
      }


      return true;

    } catch (err) {
      setError(
        err?.message ||
        "Could not delete PUB asset."
      );

      return false;

    } finally {
      setDeletingAssetId(
        0
      );
    }
  }


  /*
   * SAVE COPY.
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
        failed[0]
          ?.message ||
        "CREATE rejected the REDO."
      );
    }


    const queued =
      Array.isArray(
        data.queued
      )
        ? data.queued
        : [];

    const created =
      Array.isArray(
        data.created
      )
        ? data.created
        : [];


    if (
      queued.length === 0 &&
      created.length === 0
    ) {
      throw new Error(
        "CREATE did not queue the REDO."
      );
    }


    setPreviewVersion(
      Date.now()
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
                normalizedStage(
                  asset
                );

              const locked =
                stage ===
                  "shipping" ||
                isHistoricalStage(
                  stage
                );

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

              const title =
                asset
                  .source_title ||
                "";

              if (
                !type &&
                !id
              ) {
                return "—";
              }

              if (
                type ===
                  "playlist" &&
                title
              ) {
                return title;
              }

              return `${type} #${id}`;
            },

          sortValue:
            (asset) =>
              asset
                .source_title ||
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
                displayStage(
                  asset
                );

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
              displayStage(
                asset
              ),
        },


        {
          key:
            "approved",

          label:
            "Approved",

          sortable:
            false,

          render:
            (asset) => {
              const stage =
                normalizedStage(
                  asset
                );

              const id =
                Number(
                  asset
                    .pub_asset_id ||
                  0
                );

              const checked =
                Number(
                  asset.approved ||
                  0
                ) === 1;

              const editable =
                stage ===
                  "created";

              const saving =
                Number(
                  approvingAssetId
                ) === id;


              return (
                <input
                  type="checkbox"

                  checked={
                    checked
                  }

                  disabled={
                    !editable ||
                    saving
                  }

                  aria-label={
                    checked
                      ? `Unapprove asset #${id}`
                      : `Approve asset #${id}`
                  }

                  title={
                    editable
                      ? checked
                        ? "Unapprove this asset"
                        : "Approve this asset"
                      : "Approval is locked after the asset leaves CREATED."
                  }

                  style={
                    approvalCheckboxStyle
                  }

                  onClick={(
                    event
                  ) => {
                    event
                      .stopPropagation();
                  }}

                  onChange={(
                    event
                  ) => {
                    setAssetApproval(
                      asset,
                      event
                        .target
                        .checked
                    );
                  }}
                />
              );
            },
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
                normalizedStage(
                  asset
                );

              const shipping =
                stage ===
                  "shipping";

              const deleting =
                Number(
                  deletingAssetId
                ) ===
                Number(
                  asset
                    .pub_asset_id ||
                  0
                );

              return (
                <button
                  type="button"

                  style={
                    isHistoricalStage(
                      stage
                    )
                      ? historicalDeleteButtonStyle
                      : rowActionButtonStyle
                  }

                  disabled={
                    shipping ||
                    deleting
                  }

                  title={
                    shipping
                      ? "Cannot delete while Dispatch is running."
                      : isHistoricalStage(
                          stage
                        )
                        ? "Permanent historical delete requires confirmation."
                        : "Delete asset"
                  }

                  onClick={(
                    event
                  ) => {
                    event
                      .stopPropagation();

                    requestDeleteAsset(
                      asset
                    );
                  }}
                >
                  {
                    deleting
                      ? "Deleting..."
                      : "Delete"
                  }
                </button>
              );
            },
        },
      ],
      [
        deletingAssetId,
        approvingAssetId,
        stageFilter,
      ]
    );


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
              Source Playlist
            </span>

            <select
              className="admin-field__control"

              value={
                sourcePlaylistFilter
              }

              onChange={(
                event
              ) =>
                setSourcePlaylistFilter(
                  event
                    .target
                    .value
                )
              }
            >
              <option value="">
                All
              </option>

              {sourcePlaylists.map(
                (playlist) => (
                  <option
                    key={
                      playlist
                        .playlist_id
                    }

                    value={
                      String(
                        playlist
                          .playlist_id
                      )
                    }
                  >
                    {
                      playlist
                        .title ||
                      `Playlist #${playlist.playlist_id}`
                    }
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


          <label
            className="admin-field"
          >
            <span
              className="admin-field__label"
            >
              Stage
            </span>

            <select
              className="admin-field__control"

              value={
                stageFilter
              }

              onChange={(
                event
              ) =>
                setStageFilter(
                  event
                    .target
                    .value
                )
              }
            >
              <option value="">
                All
              </option>

              {stages.map(
                (stage) => (
                  <option
                    key={
                      stage
                    }

                    value={
                      stage
                    }
                  >
                    {humanize(
                      stage
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

            approvalSaving={
              Number(
                approvingAssetId
              ) ===
              Number(
                editAsset
                  ?.pub_asset_id ||
                0
              )
            }

            onSetApproval={
              setAssetApproval
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

              loadAssets();
            }}
          />
        ) : (
          <PubAssetCopyEditor
            asset={{
              ...editAsset,

              url:
                withVersion(
                  editAsset.url,
                  previewVersion
                ),
            }}

            ingredientBindings={
              editIngredientBindings
            }

            saving={
              savingCopy
            }

            recreating={
              recreating
            }

            sendingToPackaging={
              sendingToPackaging
            }

            approvalSaving={
              Number(
                approvingAssetId
              ) ===
              Number(
                editAsset
                  ?.pub_asset_id ||
                0
              )
            }

            onSetApproval={
              setAssetApproval
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

            onRefreshAsset={
              refreshOpenAsset
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

              loadAssets();
            }}
          />
        )
      ) : null}


      {deleteForeverAsset
        ? createPortal(
            <div
              style={
                deleteOverlayStyle
              }

              onMouseDown={(
                event
              ) => {
                if (
                  event.target ===
                    event.currentTarget
                  &&
                  !deletingAssetId
                ) {
                  setDeleteForeverAsset(
                    null
                  );

                  setDeleteForeverAuthorized(
                    false
                  );
                }
              }}
            >
              <div
                role="dialog"

                aria-modal="true"

                aria-label="Delete shipped asset forever"

                style={
                  deleteDialogStyle
                }
              >
                <div
                  style={
                    deleteHeaderStyle
                  }
                >
                  <strong>
                    Delete shipped asset forever?
                  </strong>
                </div>


                <div
                  style={
                    deleteBodyStyle
                  }
                >
                  <div
                    style={
                      deleteWarningStyle
                    }
                  >
                    PUB has already recorded this asset as{" "}
                    <strong>
                      {humanize(
                        normalizedStage(
                          deleteForeverAsset
                        )
                      )}
                    </strong>.
                  </div>


                  <div>
                    Asset #
                    {
                      deleteForeverAsset
                        .pub_asset_id
                    }
                    {" · "}
                    {
                      deleteForeverAsset
                        .search_title ||
                      "Untitled"
                    }
                  </div>


                  <div>
                    This deletes the permanent PUB record and its local
                    creative files. It does <strong>not</strong> delete
                    anything from YouTube, Pinterest, or another channel.
                    Only continue when the channel item has already been
                    removed, or when you deliberately want PUB to forget
                    this historical shipment.
                  </div>


                  <label
                    style={
                      deleteCheckboxStyle
                    }
                  >
                    <input
                      type="checkbox"

                      checked={
                        deleteForeverAuthorized
                      }

                      disabled={
                        Boolean(
                          deletingAssetId
                        )
                      }

                      onChange={(
                        event
                      ) => {
                        setDeleteForeverAuthorized(
                          event
                            .target
                            .checked
                        );
                      }}
                    />

                    <span>
                      I understand this permanently deletes this shipped
                      PUB asset record.
                    </span>
                  </label>
                </div>


                <div
                  style={
                    deleteFooterStyle
                  }
                >
                  <button
                    type="button"

                    style={
                      quietButtonStyle
                    }

                    disabled={
                      Boolean(
                        deletingAssetId
                      )
                    }

                    onClick={() => {
                      setDeleteForeverAsset(
                        null
                      );

                      setDeleteForeverAuthorized(
                        false
                      );
                    }}
                  >
                    Cancel
                  </button>


                  <button
                    type="button"

                    style={
                      deleteForeverButtonStyle
                    }

                    disabled={
                      !deleteForeverAuthorized
                      ||
                      Boolean(
                        deletingAssetId
                      )
                    }

                    onClick={() => {
                      performDeleteAsset(
                        deleteForeverAsset,
                        true
                      );
                    }}
                  >
                    {
                      deletingAssetId
                        ? "Deleting..."
                        : "Delete Forever"
                    }
                  </button>
                </div>
              </div>
            </div>,

            document.body
          )
        : null}
    </>
  );
}


function normalizedStage(
  asset
) {
  return String(
    asset?.pipeline_stage ||
    ""
  )
    .trim()
    .toLowerCase();
}


function displayStage(
  asset
) {
  const explicit =
    String(
      asset?.display_stage ||
      ""
    )
      .trim()
      .toLowerCase();


  if (explicit) {
    return explicit;
  }


  const stage =
    normalizedStage(
      asset
    );


  if (
    stage === "created" &&
    Number(
      asset?.approved ||
      0
    ) === 1
  ) {
    return "approved";
  }


  return stage;
}


function isHistoricalStage(
  stage
) {
  return [
    "shipped",
    "dispatched",
    "published",
  ].includes(
    String(
      stage ||
      ""
    )
      .trim()
      .toLowerCase()
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


const approvalCheckboxStyle = {
  width:
    17,

  height:
    17,

  margin:
    0,

  cursor:
    "pointer",
};


const historicalDeleteButtonStyle = {
  ...rowActionButtonStyle,

  border:
    "1px solid #d4a4a4",

  color:
    "#7d2e2e",
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


const deleteOverlayStyle = {
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
    "rgba(0, 0, 0, 0.60)",
};


const deleteDialogStyle = {
  width:
    "min(560px, 94vw)",

  background:
    "#ffffff",

  color:
    "#1f2937",

  border:
    "1px solid #cfd5dc",

  borderRadius:
    5,

  boxShadow:
    "0 18px 50px rgba(0,0,0,0.30)",
};


const deleteHeaderStyle = {
  padding:
    "13px 15px",

  borderBottom:
    "1px solid #d8dde3",

  fontSize:
    17,
};


const deleteBodyStyle = {
  display:
    "flex",

  flexDirection:
    "column",

  gap:
    14,

  padding:
    16,

  fontSize:
    13,

  lineHeight:
    1.5,
};


const deleteWarningStyle = {
  padding:
    "10px 12px",

  border:
    "1px solid #e2baba",

  background:
    "#fff7f7",

  color:
    "#7d2e2e",
};


const deleteCheckboxStyle = {
  display:
    "flex",

  alignItems:
    "flex-start",

  gap:
    9,

  padding:
    "11px 12px",

  border:
    "1px solid #d8dde3",

  background:
    "#f8fafc",

  cursor:
    "pointer",
};


const deleteFooterStyle = {
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


const quietButtonStyle = {
  padding:
    "5px 9px",

  border:
    "1px solid #cfd5dc",

  borderRadius:
    3,

  background:
    "#ffffff",

  color:
    "#334155",

  cursor:
    "pointer",
};


const deleteForeverButtonStyle = {
  padding:
    "5px 10px",

  border:
    "1px solid #9e2f2f",

  borderRadius:
    3,

  background:
    "#b93636",

  color:
    "#ffffff",

  fontWeight:
    700,

  cursor:
    "pointer",
};