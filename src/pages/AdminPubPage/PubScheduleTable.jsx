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

import PubScheduleDrawer
  from "./PubScheduleDrawer";

import PubScheduleControls
  from "./PubScheduleControls";


const SCHEDULE_URL =
  `${API_FOLDER}/v2/admin/pub/schedule.php`;


export default function PubScheduleTable() {
  const [
    controlsOpen,
    setControlsOpen,
  ] = useState(
    false
  );

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
    changingStage,
    setChangingStage,
  ] = useState(false);

  const [
    bulkEnqueueing,
    setBulkEnqueueing,
  ] = useState(false);

  const [
    sending,
    setSending,
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
   * SCHEDULE WORKBENCH
   *
   * Expected rows:
   *
   *   packed = sealed and held outside automatic scheduling
   *   queued = released into Schedule's automatic candidate pool
   *
   * Exact publication times are NOT assigned to rows here.
   * ScheduleManager chooses from QUEUED inventory when a channel is due.
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


      if (stageFilter) {
        params.set(
          "stage",
          stageFilter
        );
      }


      const res =
        await fetch(
          `${SCHEDULE_URL}?${params.toString()}`,
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
          "Failed to load Schedule workbench."
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
       * Keep the selected row current after Refresh or stage changes.
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

        } else {
          setSelectedAsset(
            null
          );


          if (drawerOpen) {
            setDrawerOpen(
              false
            );

            setDrawerAsset(
              null
            );
          }
        }
      }

    } catch (err) {
      setError(
        err?.message ||
        "Failed to load Schedule workbench."
      );

    } finally {
      setLoading(
        false
      );
    }
  }


  useEffect(() => {
    loadAssets();
  }, [
    channelFilter,
    typeFilter,
    stageFilter,
  ]);


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
          `${SCHEDULE_URL}?${params.toString()}`,
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
          "Could not load Schedule details."
        );
      }


      setDrawerAsset(
        data.asset ||
        null
      );

    } catch (err) {
      setDrawerError(
        err?.message ||
        "Could not load Schedule details."
      );

    } finally {
      setDrawerLoading(
        false
      );
    }
  }


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


  async function changeStage(
    action,
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


    setChangingStage(
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
                action,
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
          `Failed to ${action} asset #${pubAssetId}.`
        );
      }


      await loadAssets();


      if (drawerOpen) {
        await loadDrawerAsset(
          pubAssetId
        );
      }

    } catch (err) {
      const message =
        err?.message ||
        `Failed to update asset #${pubAssetId}.`;


      setDrawerError(
        message
      );

      setError(
        message
      );

    } finally {
      setChangingStage(
        false
      );
    }
  }


  /*
   * Enqueue every PACKED asset currently visible through the active
   * Channel / Type / Stage filters.
   *
   * This is the Schedule equivalent of Package's Pack All:
   *
   *   packed -> queued
   *
   * Each row still goes through the normal Schedule endpoint so the
   * same repository lifecycle checks apply.
   */
  async function enqueueAllPacked() {
    const packedAssets =
      assets.filter(
        (
          asset
        ) =>
          String(
            asset
              ?.pipeline_stage ||
            ""
          )
            .trim()
            .toLowerCase() ===
          "packed"
      );


    if (!packedAssets.length) {
      return;
    }


    const confirmed =
      window.confirm(
        `Enqueue ${packedAssets.length} packed asset${
          packedAssets.length === 1
            ? ""
            : "s"
        }?\n\nThey will become eligible for automatic Schedule.`
      );


    if (!confirmed) {
      return;
    }


    setBulkEnqueueing(
      true
    );

    setError(
      ""
    );


    const failures = [];


    try {
      for (
        const asset
        of packedAssets
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

        } catch (err) {
          failures.push(
            `#${pubAssetId}: ${
              err?.message ||
              "Enqueue failed."
            }`
          );
        }
      }


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


      if (failures.length) {
        setError(
          failures.join(
            "  "
          )
        );
      }

    } finally {
      setBulkEnqueueing(
        false
      );
    }
  }


  async function sendNow(
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
       * A successful Send Now leaves Schedule custody.
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


  const packedCount =
    useMemo(
      () =>
        assets.filter(
          (
            asset
          ) =>
            String(
              asset
                ?.pipeline_stage ||
              ""
            )
              .trim()
              .toLowerCase() ===
            "packed"
        ).length,
      [
        assets,
      ]
    );


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

          value:
            (asset) =>
              humanize(
                asset.channel
              ),
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
                String(
                  asset
                    .pipeline_stage ||
                  ""
                )
                  .trim()
                  .toLowerCase();


              return (
                <span
                  style={
                    stage ===
                      "queued"
                      ? queuedStageStyle
                      : packedStageStyle
                  }
                >
                  {
                    humanize(
                      stage
                    )
                  }
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


  if (controlsOpen) {
    return (
      <PubScheduleControls
        onBack={() =>
          setControlsOpen(
            false
          )
        }
      />
    );
  }


  if (
    loading
    &&
    !assets.length
  ) {
    return (
      <AdminEmptyState
        title="Schedule"

        message="Loading Schedule workbench..."
      />
    );
  }


  const drawerTitle =
    drawerAsset
      ?.pub_asset_id
      ? `Schedule · Asset #${drawerAsset.pub_asset_id}`
      : "Schedule";


  return (
    <div
      className="admin-detail-workarea"
    >
      <AdminWorkbench
        header={
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

                <option value="packed">
                  Packed
                </option>

                <option value="queued">
                  Queued
                </option>
              </select>
            </label>


            <button
              type="button"

              onClick={
                enqueueAllPacked
              }

              disabled={
                loading ||
                changingStage ||
                bulkEnqueueing ||
                sending ||
                packedCount === 0
              }

              style={
                enqueueAllButtonStyle
              }

              title={
                packedCount > 0
                  ? `Enqueue ${packedCount} packed asset${
                      packedCount === 1
                        ? ""
                        : "s"
                    } currently visible.`
                  : "No packed assets in the current filtered view."
              }
            >
              {
                bulkEnqueueing
                  ? "Enqueueing..."
                  : packedCount > 0
                    ? `Enqueue All (${packedCount})`
                    : "Enqueue All"
              }
            </button>


            <button
              type="button"

              onClick={() =>
                setControlsOpen(
                  true
                )
              }

              disabled={
                changingStage ||
                bulkEnqueueing ||
                sending
              }

              style={
                controlsButtonStyle
              }
            >
              Schedule Controls
            </button>


            <button
              type="button"

              onClick={
                loadAssets
              }

              disabled={
                loading ||
                changingStage ||
                bulkEnqueueing ||
                sending
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
              {assets.length} asset
              {
                assets.length === 1
                  ? ""
                  : "s"
              }
            </div>
          </div>
        }

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

              defaultSortKey="updated_at"
              defaultSortDirection="asc"

              ariaLabel="PUB Schedule workbench"
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
          <PubScheduleDrawer
            asset={
              drawerAsset
            }

            loading={
              drawerLoading
            }

            error={
              drawerError
            }

            changingStage={
              changingStage
            }

            sending={
              sending
            }

            onEnqueue={(
              pubAssetId
            ) =>
              changeStage(
                "enqueue",
                pubAssetId
              )
            }

            onDequeue={(
              pubAssetId
            ) =>
              changeStage(
                "dequeue",
                pubAssetId
              )
            }

            onSendNow={
              sendNow
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


const baseStageStyle = {
  display:
    "inline-block",

  padding:
    "3px 7px",

  borderRadius:
    999,

  fontSize:
    11,

  fontWeight:
    600,

  lineHeight:
    1.2,
};


const packedStageStyle = {
  ...baseStageStyle,

  background:
    "#eef1f4",

  color:
    "#465465",
};


const queuedStageStyle = {
  ...baseStageStyle,

  background:
    "#e8f1fb",

  color:
    "#245b88",
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


const enqueueAllButtonStyle = {
  marginLeft:
    "auto",

  marginBottom:
    1,
};


const controlsButtonStyle = {
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
