import { useEffect, useMemo, useState } from "react";

import {
  AdminBadge,
  AdminButton,
  AdminCheckboxRow,
  AdminDialog,
  AdminEmptyState,
  AdminField,
  AdminMetaText,
  AdminNotice,
  AdminSmartGrid,
  AdminStack,
  AdminToolbar,
  AdminToolbarSpacer,
  useAdminDialog,
} from "@components/AdminLayout";

import PubAssetCopyEditor from "./PubAssetCopyEditor";
import PubVideoAssetEditor from "./PubVideoAssetEditor";
import { API_FOLDER } from "@helpers/config";


const ASSETS_URL =
  `${API_FOLDER}/v2/admin/pub/assets.php`;

const CREATE_URL =
  `${API_FOLDER}/v2/admin/pub/create.php`;

const PACKAGE_URL =
  `${API_FOLDER}/v2/admin/pub/package.php`;


export default function PubAssetsTable({
  onOpenPackage,
}) {
  const dialog = useAdminDialog();

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

  const [editorOpen, setEditorOpen] = useState(false);

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
     * Opening a SmartGrid editor immediately clears the current timer.
     * Polling resumes only after the editor closes.
     */
    if (
      !hasCreating ||
      editorOpen
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
    editorOpen,
  ]);


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
  async function requestDeleteAsset(
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
      await dialog.confirm({
        title: "Delete PUB asset?",
        message: `Delete PUB asset #${id}?`,
        confirmLabel: "Delete",
        cancelLabel: "Cancel",
      });


    if (!confirmed) {
      return;
    }


    await performDeleteAsset(
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
    asset,
    changes
  ) {
    const id =
      Number(
        asset
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
          ...asset,

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


      await loadAssets();


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
   * SEND ALL CURRENTLY VISIBLE APPROVED ASSETS TO PACKAGE.
   *
   * The button acts on the current filtered Assets workbench only.
   * Approval remains the human gate. PACKAGE remains responsible for
   * deciding packed / pending / failed for each individual asset.
   */
  async function sendApprovedAssetsToPackaging() {
    const approvedAssets =
      assets.filter(
        (asset) =>
          Number(
            asset?.approved ||
            0
          ) === 1
          &&
          normalizedStage(
            asset
          ) === "created"
      );


    if (!approvedAssets.length) {
      return;
    }


    setSendingToPackaging(
      true
    );

    setError(
      ""
    );


    const failures = [];


    try {
      for (const asset of approvedAssets) {
        const id =
          Number(
            asset?.pub_asset_id ||
            0
          );


        if (!id) {
          continue;
        }


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
              "Packaging failed."
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


          if (
            packed.length === 0
            &&
            pending.length === 0
          ) {
            throw new Error(
              "Packaging did not accept this asset."
            );
          }

        } catch (err) {
          failures.push(
            `#${id}: ${
              err?.message ||
              "Packaging failed."
            }`
          );
        }
      }


      await loadAssets();


      if (failures.length) {
        setError(
          `Some approved assets could not be sent to Packaging:\n${failures.join("\n")}`
        );

        return;
      }


      onOpenPackage?.();

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
                <AdminBadge
                  variant={
                    stage === "redo_required"
                      ? "warning"
                      : stage === "creating"
                        ? "info"
                        : "neutral"
                  }
                >
                  {humanize(stage)}
                </AdminBadge>
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
                <AdminButton
                  type="button"
                  size="sm"
                  variant="secondary"
                 disabled={
                    shipping ||
                    isHistoricalStage(stage) ||
                    deleting
                  }
                  title={
                    shipping
                      ? "Cannot delete while Dispatch is running."
                      : isHistoricalStage(stage)
                        ? "Permanent historical delete requires confirmation."
                        : "Delete asset"
                  }
                  onClick={(event) => {
                    event.stopPropagation();
                    requestDeleteAsset(asset);
                  }}
                >
                  {deleting ? "Deleting..." : "Delete"}
                </AdminButton>
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


  const approvedReadyAssets =
    assets.filter(
      (asset) =>
        Number(
          asset?.approved ||
          0
        ) === 1
        &&
        normalizedStage(
          asset
        ) === "created"
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
      <div className="admin-detail-workarea">
        <AdminToolbar>
          <AdminField label="Source Playlist" compact>
            <select
              className="admin-field__control"
              value={sourcePlaylistFilter}
              onChange={(event) => setSourcePlaylistFilter(event.target.value)}
            >
              <option value="">All</option>

              {sourcePlaylists.map((playlist) => (
                <option
                  key={playlist.playlist_id}
                  value={String(playlist.playlist_id)}
                >
                  {playlist.title || `Playlist #${playlist.playlist_id}`}
                </option>
              ))}
            </select>
          </AdminField>

          <AdminField label="Channel" compact>
            <select
              className="admin-field__control"
              value={channelFilter}
              onChange={(event) => setChannelFilter(event.target.value)}
            >
              <option value="">All</option>
              {channels.map((channel) => (
                <option key={channel} value={channel}>
                  {humanize(channel)}
                </option>
              ))}
            </select>
          </AdminField>

          <AdminField label="Type" compact>
            <select
              className="admin-field__control"
              value={typeFilter}
              onChange={(event) => setTypeFilter(event.target.value)}
            >
              <option value="">All</option>
              {assetTypes.map((assetType) => (
                <option key={assetType} value={assetType}>
                  {humanize(assetType)}
                </option>
              ))}
            </select>
          </AdminField>

          <AdminField label="Stage" compact>
            <select
              className="admin-field__control"
              value={stageFilter}
              onChange={(event) => setStageFilter(event.target.value)}
            >
              <option value="">All</option>
              {stages.map((stage) => (
                <option key={stage} value={stage}>
                  {humanize(stage)}
                </option>
              ))}
            </select>
          </AdminField>

          <AdminButton
            type="button"
            onClick={sendApprovedAssetsToPackaging}
            disabled={
              sendingToPackaging ||
              approvedReadyAssets.length === 0
            }
          >
            {
              sendingToPackaging
                ? "Sending..."
                : `Send To Packaging (${approvedReadyAssets.length})`
            }
          </AdminButton>

          <AdminToolbarSpacer />

          <AdminButton
            type="button"
            variant="secondary"
            onClick={loadAssets}
            disabled={loading}
          >
            {loading ? "Refreshing..." : "Refresh"}
          </AdminButton>

          <AdminMetaText as="div">
            {assets.length} asset{assets.length === 1 ? "" : "s"}
          </AdminMetaText>
        </AdminToolbar>

        {error ? (
          <AdminNotice variant="danger">
            {error}
          </AdminNotice>
        ) : null}

        <AdminSmartGrid
          items={assets}
          columns={columns}
          getRowKey={(asset) => asset.pub_asset_id}
          defaultSortKey="pub_asset_id"
          defaultSortDirection="desc"
          ariaLabel="PUB assets"
          drawer={{
            title: (asset) => `Asset #${asset.pub_asset_id}`,
            width: 440,
            render: ({ item }) => (
              <PubAssetDetails asset={item} />
            ),
          }}
          editable
          canEdit={(asset) => {
            const stage = normalizedStage(asset);
            return stage !== "shipping" && !isHistoricalStage(stage);
          }}
          onEditOpen={() => setEditorOpen(true)}
          onEditClose={() => {
            setEditorOpen(false);
            loadAssets();
          }}
          editor={{
            title: (asset) =>
              isVideoAsset(asset)
                ? "Edit Video Asset"
                : "Edit Asset Copy",

            size: (asset) =>
              isVideoAsset(asset)
                ? "xl"
                : "md",

            meta: (asset) => {
              const values = [];

              if (
                isVideoAsset(asset) &&
                Number(asset?.duration_ms || 0) > 0
              ) {
                values.push(`Runtime ${formatDurationMs(asset.duration_ms)}`);
              }

              values.push(`Asset #${asset.pub_asset_id}`);
              return values;
            },

            busy: () =>
              savingCopy ||
              recreating ||
              sendingToPackaging,

            load: (asset) =>
              fetchAssetEditorDetail(asset.pub_asset_id),

            render: ({ item, data, close, reload }) => {
              const editorAsset = data?.asset || item;

              const ingredientValues =
                data?.ingredient_values &&
                typeof data.ingredient_values === "object"
                  ? data.ingredient_values
                  : {};

              const ingredientBindings =
                Array.isArray(data?.ingredient_bindings)
                  ? data.ingredient_bindings
                  : [];

              const approvalSaving =
                Number(approvingAssetId) ===
                Number(editorAsset?.pub_asset_id || 0);

              const sharedProps = {
                asset: editorAsset,
                ingredientBindings,
                saving: savingCopy,
                recreating,
                sendingToPackaging,
                approvalSaving,
                onSetApproval: setAssetApproval,
                onSave: (changes) =>
                  saveAssetCopy(editorAsset, changes),
                onRecreate: recreateAsset,
                onSendToPackaging: sendAssetToPackaging,
                onPackagingComplete: onOpenPackage,
                onClose: close,
              };

              if (isVideoAsset(editorAsset)) {
                return (
                  <PubVideoAssetEditor
                    {...sharedProps}
                    ingredientValues={ingredientValues}
                    onRefreshAssets={loadAssets}
                  />
                );
              }

              return (
                <PubAssetCopyEditor
                  {...sharedProps}
                  onRefreshAsset={async () => {
                    await reload();
                    await loadAssets();
                    return true;
                  }}
                />
              );
            },
          }}
        />
      </div>

      <AdminDialog
        open={Boolean(deleteForeverAsset)}
        mode="danger"
        title="Delete shipped asset forever?"
        width={560}
        dismissOnBackdrop={!deletingAssetId}
        onClose={() => {
          if (deletingAssetId) return;
          setDeleteForeverAsset(null);
          setDeleteForeverAuthorized(false);
        }}
        actions={[
          {
            key: "cancel",
            label: "Cancel",
            variant: "secondary",
            disabled: Boolean(deletingAssetId),
            onClick: () => {
              setDeleteForeverAsset(null);
              setDeleteForeverAuthorized(false);
            },
          },
          {
            key: "delete-forever",
            label: deletingAssetId ? "Deleting..." : "Delete Forever",
            variant: "danger",
            disabled:
              !deleteForeverAuthorized ||
              Boolean(deletingAssetId),
            onClick: () => {
              if (!deleteForeverAsset) return;
              performDeleteAsset(deleteForeverAsset, true);
            },
          },
        ]}
      >
        {deleteForeverAsset ? (
          <AdminStack gap="md">
            <AdminNotice variant="danger">
              PUB has already recorded this asset as{" "}
              <strong>
                {humanize(normalizedStage(deleteForeverAsset))}
              </strong>.
            </AdminNotice>

            <div>
              Asset #{deleteForeverAsset.pub_asset_id}
              {" · "}
              {deleteForeverAsset.search_title || "Untitled"}
            </div>

            <div>
              This deletes the permanent PUB record and its local creative
              files. It does <strong>not</strong> delete anything from YouTube,
              Pinterest, or another channel. Only continue when the channel
              item has already been removed, or when you deliberately want PUB
              to forget this historical shipment.
            </div>

            <AdminCheckboxRow
              checked={deleteForeverAuthorized}
              disabled={Boolean(deletingAssetId)}
              onChange={(event) =>
                setDeleteForeverAuthorized(event.target.checked)
              }
            >
              I understand this permanently deletes this shipped PUB asset
              record.
            </AdminCheckboxRow>
          </AdminStack>
        ) : null}
      </AdminDialog>
    </>
  );
}


function PubAssetDetails({
  asset,
}) {
  const sourceType =
    String(
      asset?.source_type ||
      ""
    ).trim();

  const sourceId =
    Number(
      asset?.source_id ||
      0
    );

  const sourceTitle =
    String(
      asset?.source_title ||
      ""
    ).trim();

  const sourceLabel =
    sourceTitle ||
    (
      sourceType &&
      sourceId
        ? `${humanize(sourceType)} #${sourceId}`
        : "—"
    );

  const stage =
    displayStage(
      asset
    );

  const approved =
    Number(
      asset?.approved ||
      0
    ) === 1;

  return (
    <AdminStack gap="md">
      <AdminField label="Asset ID">
        <div>
          #{asset?.pub_asset_id || "—"}
        </div>
      </AdminField>

      <AdminField label="Job ID">
        <div>
          {
            Number(
              asset?.pub_run_id ||
              0
            ) || "—"
          }
        </div>
      </AdminField>

      <AdminField label="Channel">
        <div>
          {asset?.channel || "—"}
        </div>
      </AdminField>

      <AdminField label="Type">
        <div>
          {
            asset?.asset_type
              ? humanize(
                  asset.asset_type
                )
              : "—"
          }
        </div>
      </AdminField>

      <AdminField label="Source">
        <div>
          {sourceLabel}
        </div>
      </AdminField>

      <AdminField label="Stage">
        <div>
          {stage ? (
            <AdminBadge
              variant={
                stage === "redo_required"
                  ? "warning"
                  : stage === "creating"
                    ? "info"
                    : "neutral"
              }
            >
              {humanize(stage)}
            </AdminBadge>
          ) : (
            "—"
          )}
        </div>
      </AdminField>

      <AdminField label="Approved">
        <div>
          {approved ? "Yes" : "No"}
        </div>
      </AdminField>

      <AdminField label="Title">
        <div>
          {asset?.search_title || "—"}
        </div>
      </AdminField>

      <AdminField label="Description">
        <div>
          {asset?.description || "—"}
        </div>
      </AdminField>

      {asset?.url ? (
        <AdminField label="Asset URL">
          <a
            href={asset.url}
            target="_blank"
            rel="noreferrer"
          >
            {asset.url}
          </a>
        </AdminField>
      ) : null}

      {asset?.thumbnail_url ? (
        <AdminField label="Thumbnail URL">
          <a
            href={asset.thumbnail_url}
            target="_blank"
            rel="noreferrer"
          >
            {asset.thumbnail_url}
          </a>
        </AdminField>
      ) : null}

      {asset?.created_at ? (
        <AdminField label="Created">
          <AdminMetaText>
            {asset.created_at}
          </AdminMetaText>
        </AdminField>
      ) : null}

      <AdminField label="Last Updated">
        <AdminMetaText>
          {asset?.updated_at || "—"}
        </AdminMetaText>
      </AdminField>
    </AdminStack>
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


function formatDurationMs(value) {
  const milliseconds = Number(value || 0);

  if (!Number.isFinite(milliseconds) || milliseconds <= 0) {
    return "—";
  }

  const totalSeconds = Math.round(milliseconds / 1000);
  const minutes = Math.floor(totalSeconds / 60);
  const seconds = totalSeconds % 60;

  return `${minutes}:${String(seconds).padStart(2, "0")}`;
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

