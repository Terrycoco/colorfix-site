import {
  useEffect,
  useMemo,
  useState,
} from "react";

import {
  AdminDataGrid,
  AdminEmptyState,
  AdminWorkbench,
} from "@components/AdminLayout";

import {
  API_FOLDER,
} from "@helpers/config";

import PubPackageDrawer
  from "./PubPackageDrawer";


const PACKAGE_URL =
  `${API_FOLDER}/v2/admin/pub/package.php`;

const SCHEDULE_URL =
  `${API_FOLDER}/v2/admin/pub/schedule.php`;


export default function PubPackageTable({
  onOpenDispatch,
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
    stageFilter,
    setStageFilter,
  ] = useState("");

  const [
    loading,
    setLoading,
  ] = useState(true);

  const [
    processing,
    setProcessing,
  ] = useState(false);

  const [
    sending,
    setSending,
  ] = useState(false);

  const [
    enqueueing,
    setEnqueueing,
  ] = useState(false);

  const [
    savingPingback,
    setSavingPingback,
  ] = useState(false);

  const [
    error,
    setError,
  ] = useState("");

  const [
    selectedAsset,
    setSelectedAsset,
  ] = useState(null);

  const [
    drawerOpen,
    setDrawerOpen,
  ] = useState(false);

  const [
    drawerAsset,
    setDrawerAsset,
  ] = useState(null);

  const [
    drawerLoading,
    setDrawerLoading,
  ] = useState(false);

  const [
    drawerError,
    setDrawerError,
  ] = useState("");


  /*
   * PACKAGE WORKBENCH
   *
   * Expected rows:
   *
   *   approved CREATED assets waiting to enter Package
   *   packing
   *   packed
   *   error where error_stage = package
   *
   * The summary request deliberately does NOT need the actual
   * package JSON. It only needs has_package for the green checkmark.
   *
   * Package JSON is fetched only when the drawer is open.
   */
  async function loadAssets() {
    setLoading(
      true
    );

    setError(
      ""
    );


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
          `${PACKAGE_URL}?${params.toString()}`,
          {
            credentials:
              "include",
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
          "Failed to load Package workbench."
        );
      }


      const nextAssets =
        Array.isArray(
          data.assets
        )
          ? data.assets
          : [];


      setAssets(
        nextAssets
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


      /*
       * Keep the selected row current after Refresh.
       */
      if (
        selectedAsset
          ?.pub_asset_id
      ) {
        const refreshed =
          nextAssets.find(
            (
              asset
            ) =>
              Number(
                asset
                  .pub_asset_id
              ) ===
              Number(
                selectedAsset
                  .pub_asset_id
              )
          );


        if (refreshed) {
          setSelectedAsset(
            refreshed
          );


          if (drawerOpen) {
            await loadDrawerAsset(
              refreshed
                .pub_asset_id
            );
          }
        }
      }

    } catch (err) {
      setError(
        err?.message ||
        "Failed to load Package workbench."
      );

    } finally {
      setLoading(
        false
      );
    }
  }


  /*
   * Pack exactly the selected asset.
   *
   * PackageEndpoint already supports the single-asset path:
   *
   *   POST { "pub_asset_id": 123 }
   *
   * That calls PackageManager::sendToPacking() and does NOT
   * sweep sibling rows already waiting at Packing.
   */
  async function packAsset(
    pubAssetId
  ) {
    pubAssetId =
      Number(
        pubAssetId ||
        0
      );


    if (!pubAssetId) {
      return;
    }


    setProcessing(
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
                  pubAssetId,
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
          `Failed to pack asset #${pubAssetId}.`
        );
      }


      const packedCount =
        Number(
          data.packed_count ||
          0
        );


      await loadAssets();


      /*
       * PENDING is a normal Package result. Keep the operator here so
       * the drawer can show what the asset is waiting for. Only move
       * onward when this exact asset actually reached PACKED.
       */
      if (packedCount > 0) {
        onOpenDispatch?.();
      }

    } catch (err) {
      const message =
        err?.message ||
        `Failed to pack asset #${pubAssetId}.`;


      /*
       * PackageManager may already have persisted error/package before
       * the HTTP request returns failure. Re-read durable state so the
       * workbench and open drawer never remain visually stuck at
       * "Packing" after the process has actually failed.
       */
      await loadAssets();


      if (drawerOpen) {
        await loadDrawerAsset(
          pubAssetId
        );
      }


      setError(
        message
      );

    } finally {
      setProcessing(
        false
      );
    }
  }


  /*
   * Pack every APPROVED asset currently visible through the active
   * Channel / Type / Stage filters.
   *
   * Batch packing deliberately stays on the Package workbench so the
   * operator can inspect the resulting rows.
   */
  async function packAllAssets() {
    const eligible =
      filteredAssets.filter(
        isApprovedCreatedAsset
      );


    if (!eligible.length) {
      return;
    }


    setProcessing(
      true
    );

    setError(
      ""
    );


    const failures = [];


    try {
      for (
        const asset
        of eligible
      ) {
        const pubAssetId =
          Number(
            asset
              ?.pub_asset_id ||
            0
          );


        if (!pubAssetId) {
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
                      pubAssetId,
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
              `Failed to pack asset #${pubAssetId}.`
            );
          }

        } catch (err) {
          failures.push(
            `#${pubAssetId}: ${
              err?.message ||
              "Packaging failed."
            }`
          );
        }
      }


      await loadAssets();


      if (failures.length) {
        setError(
          failures.join(
            "  "
          )
        );
      }

    } finally {
      setProcessing(
        false
      );
    }
  }


  /*
   * Edit outside-of-box destination metadata while the asset is still
   * under Package control. The endpoint/repository invalidates any
   * already-built package if the destination changes.
   */
  async function savePingback(
    pubAssetId,
    pingback
  ) {
    pubAssetId =
      Number(
        pubAssetId ||
        0
      );


    if (!pubAssetId) {
      return;
    }


    setSavingPingback(
      true
    );

    setError(
      ""
    );

    setDrawerError(
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
                action:
                  "update_pingback",
                pub_asset_id:
                  pubAssetId,
                pingback:
                  String(
                    pingback ||
                    ""
                  ).trim(),
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
          `Failed to save destination for asset #${pubAssetId}.`
        );
      }


      if (data?.asset) {
        setDrawerAsset(
          data.asset
        );
      }


      await loadAssets();

    } catch (err) {
      const message =
        err?.message ||
        `Failed to save destination for asset #${pubAssetId}.`;


      setDrawerError(
        message
      );

      setError(
        message
      );

    } finally {
      setSavingPingback(
        false
      );
    }
  }


  /*
   * Release exactly one PACKED asset into Schedule's active queue.
   *
   *   packed -> queued
   *
   * Once queued, the asset has left Package custody, so close the
   * Package drawer and refresh this workbench.
   */
  async function enqueueAsset(
    pubAssetId
  ) {
    pubAssetId =
      Number(
        pubAssetId ||
        0
      );


    if (!pubAssetId) {
      return;
    }


    setEnqueueing(
      true
    );

    setError(
      ""
    );

    setDrawerError(
      ""
    );


    try {
      const res =
        await fetch(
          SCHEDULE_URL,
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
                action:
                  "enqueue",

                pub_asset_id:
                  pubAssetId,
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
          `Failed to enqueue asset #${pubAssetId}.`
        );
      }


      /*
       * QUEUED belongs to Schedule, not Package.
       */
      setDrawerOpen(
        false
      );

      setDrawerAsset(
        null
      );

      setSelectedAsset(
        null
      );


      await loadAssets();

    } catch (err) {
      const message =
        err?.message ||
        `Failed to enqueue asset #${pubAssetId}.`;


      setDrawerError(
        message
      );

      setError(
        message
      );

    } finally {
      setEnqueueing(
        false
      );
    }
  }


  /*
   * Release every PACKED asset currently visible through the active
   * Channel / Type / Stage filters into Schedule's active queue.
   *
   *   packed -> queued
   *
   * This is the Package department's normal batch handoff to the
   * loading dock. It uses the same Schedule enqueue action as the
   * single-asset drawer control.
   */
  async function enqueueAllPackedAssets() {
    const eligible =
      filteredAssets.filter(
        isPackedAsset
      );


    if (!eligible.length) {
      return;
    }


    setEnqueueing(
      true
    );

    setError(
      ""
    );

    setDrawerError(
      ""
    );


    const failures = [];
    let enqueuedCount = 0;


    try {
      for (
        const asset
        of eligible
      ) {
        const pubAssetId =
          Number(
            asset
              ?.pub_asset_id ||
            0
          );


        if (!pubAssetId) {
          continue;
        }


        try {
          const res =
            await fetch(
              SCHEDULE_URL,
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
                    action:
                      "enqueue",

                    pub_asset_id:
                      pubAssetId,
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
              `Failed to enqueue asset #${pubAssetId}.`
            );
          }


          enqueuedCount +=
            1;

        } catch (err) {
          failures.push(
            `#${pubAssetId}: ${
              err?.message ||
              "Queue handoff failed."
            }`
          );
        }
      }


      /*
       * Any successfully queued asset has left Package custody.
       * Close stale selection/drawer state before re-reading the
       * Package workbench.
       */
      if (enqueuedCount > 0) {
        setDrawerOpen(
          false
        );

        setDrawerAsset(
          null
        );

        setSelectedAsset(
          null
        );
      }


      await loadAssets();


      if (failures.length) {
        setError(
          failures.join(
            "  "
          )
        );
      }

    } finally {
      setEnqueueing(
        false
      );
    }
  }


  /*
   * Manual Send Now.
   *
   * This deliberately goes through ScheduleManager's explicit override
   * path rather than calling Dispatch directly:
   *
   *   packed -> queued -> shipping -> shipped
   *
   * Automatic Schedule timing/ranking is bypassed.
   */
  async function sendAsset(
    pubAssetId
  ) {
    pubAssetId =
      Number(
        pubAssetId ||
        0
      );


    if (!pubAssetId) {
      return;
    }


    const confirmed =
      window.confirm(
        `Send asset #${pubAssetId} now?\n\nThis bypasses Schedule and sends it directly to Dispatch.`
      );


    if (!confirmed) {
      return;
    }


    setSending(
      true
    );

    setError(
      ""
    );

    setDrawerError(
      ""
    );


    try {
      const res =
        await fetch(
          SCHEDULE_URL,
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
                action:
                  "send_now",

                pub_asset_id:
                  pubAssetId,
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
          data?.result
            ?.failed
            ?.error ||
          `Failed to send asset #${pubAssetId}.`
        );
      }


      /*
       * The asset has left Package custody. Close the Package drawer
       * instead of trying to reload a row that no longer belongs here.
       */
      setDrawerOpen(
        false
      );

      setDrawerAsset(
        null
      );

      setSelectedAsset(
        null
      );


      await loadAssets();

    } catch (err) {
      const message =
        err?.message ||
        `Failed to send asset #${pubAssetId}.`;


      setDrawerError(
        message
      );

      setError(
        message
      );

    } finally {
      setSending(
        false
      );
    }
  }


  useEffect(() => {
    loadAssets();
  }, [
    channelFilter,
    typeFilter,
  ]);


  /*
   * DETAIL DRAWER
   *
   * The actual package object is loaded only when somebody
   * wants to inspect the selected row.
   */
  async function loadDrawerAsset(
    pubAssetId
  ) {
    const id =
      Number(
        pubAssetId ||
        0
      );


    if (!id) {
      return;
    }


    setDrawerLoading(
      true
    );

    setDrawerError(
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
          `${PACKAGE_URL}?${params.toString()}`,
          {
            credentials:
              "include",
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
          "Could not load Package details."
        );
      }


      setDrawerAsset(
        data.asset ||
        null
      );

    } catch (err) {
      setDrawerError(
        err?.message ||
        "Could not load Package details."
      );

    } finally {
      setDrawerLoading(
        false
      );
    }
  }


  /*
   * One click:
   *   select/highlight the row.
   *
   * If the drawer is already open, selecting another row
   * immediately changes the drawer to that asset.
   */
  function selectAsset(
    asset
  ) {
    setSelectedAsset(
      asset
    );


    if (drawerOpen) {
      loadDrawerAsset(
        asset.pub_asset_id
      );
    }
  }


  /*
   * Double click:
   *   select the row
   *   open the Package drawer
   */
  function openDrawer(
    asset
  ) {
    setSelectedAsset(
      asset
    );

    setDrawerOpen(
      true
    );

    loadDrawerAsset(
      asset.pub_asset_id
    );
  }


  const filteredAssets =
    useMemo(
      () => {
        if (!stageFilter) {
          return assets;
        }


        return assets.filter(
          (asset) =>
            displayStage(
              asset
            ) ===
            stageFilter
        );
      },
      [
        assets,
        stageFilter,
      ]
    );


  const approvedReadyCount =
    useMemo(
      () =>
        filteredAssets.filter(
          isApprovedCreatedAsset
        ).length,
      [
        filteredAssets,
      ]
    );


  const packedReadyCount =
    useMemo(
      () =>
        filteredAssets.filter(
          isPackedAsset
        ).length,
      [
        filteredAssets,
      ]
    );


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
            "channel",

          label:
            "Channel",
        },


        {
          key:
            "asset_type",

          label:
            "Type",

          value:
            (asset) =>
              humanize(
                asset
                  .asset_type
              ),
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
                !type
                &&
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


              const waiting =
                stage ===
                  "packing"
                &&
                Boolean(
                  String(
                    asset
                      .stage_note ||
                    ""
                  ).trim()
                );


              return (
                <span
                  style={
                    stage ===
                      "error"
                      ? errorStageStyle
                      : stage ===
                          "packing"
                        ? packingStageStyle
                        : stageBadgeStyle
                  }
                >
                  {
                    (
                      String(
                        asset
                          .error_stage ||
                        ""
                      )
                        .trim()
                        .toLowerCase() ===
                      "package"
                      ||
                      (
                        stage ===
                          "error"
                        &&
                        Boolean(
                          asset
                            .error_message
                        )
                      )
                    )
                      ? "Package Error"
                      : waiting
                        ? "Waiting"
                        : humanize(
                            stage
                          )
                  }
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
            "stage_note",

          label:
            "Note",

          value:
            (asset) =>
              asset
                .stage_note ||
              (
                asset
                  .pipeline_stage ===
                "error"
                  ? asset
                      .error_message ||
                    "Package error"
                  : "—"
              ),
        },


        {
          key:
            "has_package",

          label:
            "Package",

          render:
            (asset) =>
              hasPackage(
                asset
              )
                ? (
                    <span
                      title="Package complete"
                      aria-label="Package complete"

                      style={
                        packageCheckStyle
                      }
                    >
                      ✓
                    </span>
                  )
                : "",

          sortValue:
            (asset) =>
              hasPackage(
                asset
              )
                ? 1
                : 0,
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
      ],
      []
    );


  if (
    loading
    &&
    !assets.length
  ) {
    return (
      <AdminEmptyState
        title="Package"

        message="Loading Package workbench..."
      />
    );
  }


  const drawerTitle =
    drawerAsset
      ?.pub_asset_id
      ? `Package · Asset #${drawerAsset.pub_asset_id}`
      : "Package";


  return (
    <div
      className="admin-detail-workarea"
    >
      <AdminWorkbench
        header={
          <>
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
                    (
                      channel
                    ) => (
                      <option
                        key={
                          channel
                        }
            
                        value={
                          channel
                        }
                      >
                        {
                          humanize(
                            channel
                          )
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
                    (
                      assetType
                    ) => (
                      <option
                        key={
                          assetType
                        }
            
                        value={
                          assetType
                        }
                      >
                        {
                          humanize(
                            assetType
                          )
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

                  <option value="approved">
                    Approved
                  </option>

                  <option value="packing">
                    Packing
                  </option>

                  <option value="packed">
                    Packed
                  </option>

                  <option value="error">
                    Package Error
                  </option>
                </select>
              </label>


              <button
                type="button"

                onClick={
                  packAllAssets
                }

                disabled={
                  loading ||
                  processing ||
                  approvedReadyCount === 0
                }

                style={
                  packAllButtonStyle
                }

                title={
                  approvedReadyCount > 0
                    ? `Pack ${approvedReadyCount} approved asset${
                        approvedReadyCount === 1
                          ? ""
                          : "s"
                      } currently visible.`
                    : "No approved assets in the current filtered view."
                }
              >
                {
                  processing
                    ? "Packing..."
                    : approvedReadyCount > 0
                      ? `Pack All (${approvedReadyCount})`
                      : "Pack All"
                }
              </button>


              <button
                type="button"

                onClick={
                  enqueueAllPackedAssets
                }

                disabled={
                  loading ||
                  processing ||
                  enqueueing ||
                  packedReadyCount === 0
                }

                style={
                  sendToQueueButtonStyle
                }

                title={
                  packedReadyCount > 0
                    ? `Send ${packedReadyCount} packed asset${
                        packedReadyCount === 1
                          ? ""
                          : "s"
                      } currently visible to the Scheduler queue.`
                    : "No packed assets in the current filtered view."
                }
              >
                {
                  enqueueing
                    ? "Sending to Queue..."
                    : packedReadyCount > 0
                      ? `Send To Queue (${packedReadyCount})`
                      : "Send To Queue"
                }
              </button>


              <button
                type="button"
            
                onClick={
                  loadAssets
                }
            
                disabled={
                  loading ||
                  processing ||
                  enqueueing
                }
            
                style={
                  refreshButtonStyle
                }
              >
                {
                  loading
                    ? "Refreshing..."
                    : "Refresh"
                }
              </button>
            
            
              <div
                style={
                  countStyle
                }
              >
                {filteredAssets.length} asset
                {
                  filteredAssets.length === 1
                    ? ""
                    : "s"
                }
              </div>
            </div>
          </>
        }

        /*
         * PACKAGE is a single-grid workbench.
         * Give AdminWorkbench a zero-height upper section and use the
         * full lower workspace for the Package grid. AdminWorkbench,
         * not this table and not PubPackageDrawer, owns drawer geometry.
         */
        upperLeft={null}
        upperRight={null}
        upperHeight="0px"

        lower={
          <div
            className="admin-detail-workarea"

            style={{
              height:
                "100%",
            }}
          >
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
                filteredAssets
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
            
              selectedKey={
                selectedAsset
                  ?.pub_asset_id ??
                null
              }
            
              onSelectionChange={
                selectAsset
              }
            
              onRowDoubleClick={
                openDrawer
              }
            
              defaultSortKey="pub_asset_id"
            
              defaultSortDirection="desc"
            
              ariaLabel="PUB Package workbench"
            />
          </div>
        }

        drawerOpen={
          drawerOpen
        }

        drawerWidth={
          440
        }

        drawerTitle={
          drawerTitle
        }

        drawerContent={
          <PubPackageDrawer
            asset={
              drawerAsset
            }

            loading={
              drawerLoading
            }

            error={
              drawerError
            }

            packing={
              processing
            }

            savingPingback={
              savingPingback
            }

            sending={
              sending
            }

            enqueueing={
              enqueueing
            }

            onPack={
              packAsset
            }

            onSavePingback={
              savePingback
            }

            onEnqueue={
              enqueueAsset
            }

            onSend={
              sendAsset
            }
          />
        }

        onCloseDrawer={() => {
          setDrawerOpen(
            false
          );
        }}
      />
    </div>
  );
}


function displayStage(
  asset
) {
  const explicit =
    String(
      asset
        ?.display_stage ||
      ""
    )
      .trim()
      .toLowerCase();


  if (explicit) {
    return explicit;
  }


  const pipelineStage =
    String(
      asset
        ?.pipeline_stage ||
      ""
    )
      .trim()
      .toLowerCase();


  if (
    pipelineStage ===
      "created"
    &&
    Number(
      asset
        ?.approved ||
      0
    ) === 1
  ) {
    return "approved";
  }


  return pipelineStage;
}


function isApprovedCreatedAsset(
  asset
) {
  return (
    String(
      asset
        ?.pipeline_stage ||
      ""
    )
      .trim()
      .toLowerCase() ===
      "created"
    &&
    Number(
      asset
        ?.approved ||
      0
    ) === 1
  );
}


function isPackedAsset(
  asset
) {
  return (
    String(
      asset
        ?.pipeline_stage ||
      ""
    )
      .trim()
      .toLowerCase() ===
    "packed"
  );
}


function hasPackage(
  asset
) {
  return (
    asset
      ?.has_package ===
      true
    ||
    Number(
      asset
        ?.has_package ||
      0
    ) === 1
    ||
    (
      asset
        ?.package
      &&
      typeof asset
        .package ===
        "object"
    )
  );
}


function humanize(
  value
) {
  return String(
    value ||
    ""
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
      (
        character
      ) =>
        character
          .toUpperCase()
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


const packingStageStyle = {
  ...stageBadgeStyle,

  background:
    "#e8f1fb",

  color:
    "#245b88",
};


const errorStageStyle = {
  ...stageBadgeStyle,

  background:
    "#fff1f1",

  color:
    "#8a3131",

  border:
    "1px solid #e2baba",
};


const packageCheckStyle = {
  display:
    "inline-block",

  minWidth:
    18,

  color:
    "#248451",

  fontSize:
    18,

  fontWeight:
    800,

  lineHeight:
    1,

  textAlign:
    "center",
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


const selectedPackButtonStyle = {
  marginLeft:
    "auto",

  marginBottom:
    1,
};


const packAllButtonStyle = {
  marginBottom:
    1,
};


const sendToQueueButtonStyle = {
  marginBottom:
    1,
};


const refreshButtonStyle = {
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
