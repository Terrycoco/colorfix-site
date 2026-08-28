import {
  useEffect,
  useMemo,
  useState,
} from "react";

import {
  AdminDataGrid,
  AdminEmptyState,
} from "@components/AdminLayout";

import {
  API_FOLDER,
} from "@helpers/config";

import PubDispatchDrawer
  from "./PubDispatchDrawer";


const DISPATCH_URL =
  `${API_FOLDER}/v2/admin/pub/dispatch.php`;

const CONNECTION_URL =
  `${API_FOLDER}/v2/admin/pub/channel-connection.php`;

/*
 * Preserve the already-working public Pinterest OAuth route.
 * Its implementation can now be only a thin door into app/PUB.
 */
const PINTEREST_CONNECT_URL =
  `${API_FOLDER}/v2/admin/pub/pinterest-oauth-start.php`;


export default function PubDispatchTable() {
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
    statusMessage,
    setStatusMessage,
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

  const [
    retryingAssetId,
    setRetryingAssetId,
  ] = useState(null);


  /*
   * CHANNEL CONNECTION
   *
   * This is deliberately separate from DispatchManager.
   * The UI talks to PUB's channel-connection endpoint.
   */
  const [
    pinterestConnection,
    setPinterestConnection,
  ] = useState(null);

  const [
    connectionLoading,
    setConnectionLoading,
  ] = useState(true);

  const [
    connectionAction,
    setConnectionAction,
  ] = useState("");


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
          `${DISPATCH_URL}?${params.toString()}`,
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
          "Failed to load Dispatch workbench."
        );
      }


      const nextAssets =
        Array.isArray(
          data.items
        )
          ? data.items
          : [];


      setAssets(
        nextAssets
      );


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
        }
      }

    } catch (err) {
      setError(
        err?.message ||
        "Failed to load Dispatch workbench."
      );

    } finally {
      setLoading(
        false
      );
    }
  }


  async function loadFilters() {
    try {
      const params =
        new URLSearchParams({
          meta:
            "filters",

          _:
            String(
              Date.now()
            ),
        });


      const res =
        await fetch(
          `${DISPATCH_URL}?${params.toString()}`,
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
          "Failed to load Dispatch filters."
        );
      }


      setChannels(
        Array.isArray(
          data.channels
        )
          ? data.channels
          : []
      );

      setAssetTypes(
        Array.isArray(
          data.asset_types
        )
          ? data.asset_types
          : []
      );

    } catch (err) {
      setError(
        err?.message ||
        "Failed to load Dispatch filters."
      );
    }
  }


  async function loadPinterestConnection() {
    setConnectionLoading(
      true
    );


    try {
      const params =
        new URLSearchParams({
          channel:
            "pinterest",

          _:
            String(
              Date.now()
            ),
        });


      const res =
        await fetch(
          `${CONNECTION_URL}?${params.toString()}`,
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
          "Failed to load Pinterest connection."
        );
      }


      setPinterestConnection(
        data.item ||
        null
      );

    } catch (err) {
      setError(
        err?.message ||
        "Failed to load Pinterest connection."
      );

    } finally {
      setConnectionLoading(
        false
      );
    }
  }


  async function runConnectionAction(
    action
  ) {
    setConnectionAction(
      action
    );

    setError(
      ""
    );

    setStatusMessage(
      ""
    );


    try {
      const res =
        await fetch(
          CONNECTION_URL,
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
                channel:
                  "pinterest",

                action,
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
          `Pinterest ${action} failed.`
        );
      }


      if (
        action ===
        "test"
      ) {
        setStatusMessage(
          data
            ?.item
            ?.message ||
          "Pinterest connection test passed."
        );

      } else if (
        action ===
        "sync"
      ) {
        setStatusMessage(
          "Pinterest boards synced."
        );

      } else if (
        action ===
        "disconnect"
      ) {
        setStatusMessage(
          "Pinterest disconnected."
        );
      }


      await loadPinterestConnection();

    } catch (err) {
      setError(
        err?.message ||
        `Pinterest ${action} failed.`
      );

    } finally {
      setConnectionAction(
        ""
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
   * ASYNC DISPATCH WATCH
   *
   * A driver may still be out on the road after this screen mounts.
   * While any visible asset is SHIPPING, refresh periodically so the
   * workbench naturally changes to SHIPPED or ERROR when the driver
   * reports back to DispatchDesk.
   */
  useEffect(() => {
    const hasShipping =
      assets.some(
        (asset) =>
          String(
            asset?.pipeline_stage ||
            ""
          )
            .trim()
            .toLowerCase() ===
          "shipping"
      );


    if (!hasShipping) {
      return undefined;
    }


    const timer =
      window.setInterval(
        () => {
          loadAssets();
        },
        3000
      );


    return () => {
      window.clearInterval(
        timer
      );
    };
  }, [
    assets,
    channelFilter,
    typeFilter,
    drawerOpen,
    selectedAsset?.pub_asset_id,
  ]);


  useEffect(() => {
    loadFilters();
    loadPinterestConnection();


    /*
     * OAuth returns to the admin app with the result in the query.
     * If the operator later clicks Dispatch, surface that result here.
     */
    const params =
      new URLSearchParams(
        window.location.search
      );

    const authStatus =
      params.get(
        "pinterest_auth"
      );

    const message =
      params.get(
        "message"
      );


    if (authStatus) {
      if (
        authStatus ===
        "connected"
      ) {
        setStatusMessage(
          message ||
          "Pinterest connected."
        );

      } else {
        setError(
          message ||
          `Pinterest OAuth ${authStatus}.`
        );
      }


      params.delete(
        "pinterest_auth"
      );

      params.delete(
        "message"
      );


      const query =
        params.toString();


      window.history.replaceState(
        {},
        "",
        `${window.location.pathname}${
          query
            ? `?${query}`
            : ""
        }`
      );
    }
  }, []);


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
          `${DISPATCH_URL}?${params.toString()}`,
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
          "Could not load Dispatch details."
        );
      }


      setDrawerAsset(
        data.item ||
        null
      );

    } catch (err) {
      setDrawerError(
        err?.message ||
        "Could not load Dispatch details."
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
        asset
          .pub_asset_id
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
      asset
        .pub_asset_id
    );
  }


  /*
   * RETRY ONE DISPATCH ERROR
   *
   * First attempt and explicit retry use the same one-box Dispatch
   * endpoint. DispatchManager owns:
   *
   *   error / dispatch -> shipping
   *
   * The UI never rewrites lifecycle state itself.
   */
  async function retryShipping(
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


    const asset =
      assets.find(
        (item) =>
          Number(
            item?.pub_asset_id ||
            0
          ) === id
      )
      ||
      (
        Number(
          drawerAsset?.pub_asset_id ||
          0
        ) === id
          ? drawerAsset
          : null
      );


    const stage =
      String(
        asset?.pipeline_stage ||
        ""
      )
        .trim()
        .toLowerCase();

    const errorStage =
      String(
        asset?.error_stage ||
        ""
      )
        .trim()
        .toLowerCase();


    if (
      stage !== "error"
      ||
      errorStage !== "dispatch"
    ) {
      setError(
        `Asset #${id} is not a retryable Dispatch error.`
      );

      return;
    }


    const ok =
      window.confirm(
        `Retry shipping asset #${id}?\n\n`
        + "If the external service accepted part of the previous attempt "
        + "before the failure was reported, a retry could create a duplicate."
      );


    if (!ok) {
      return;
    }


    setRetryingAssetId(
      id
    );

    setError(
      ""
    );

    setDrawerError(
      ""
    );

    setStatusMessage(
      ""
    );


    try {
      const res =
        await fetch(
          DISPATCH_URL,
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
        const failed =
          data
            ?.result
            ?.failed;

        throw new Error(
          failed
            ?.error ||
          data
            ?.error ||
          `Asset #${id} could not restart Dispatch.`
        );
      }


      if (
        data
          ?.result
          ?.in_progress
      ) {
        setStatusMessage(
          `Asset #${id} is shipping again.`
        );

      } else {
        setStatusMessage(
          `Asset #${id} shipped successfully.`
        );
      }


      await loadAssets();

      if (drawerOpen) {
        await loadDrawerAsset(
          id
        );
      }

    } catch (err) {
      setError(
        err?.message ||
        `Asset #${id} could not restart Dispatch.`
      );


      await loadAssets();

      if (drawerOpen) {
        await loadDrawerAsset(
          id
        );
      }

    } finally {
      setRetryingAssetId(
        null
      );
    }
  }


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


              if (!stage) {
                return "—";
              }


              return (
                <span
                  style={
                    stage ===
                      "error"
                      ? errorStageStyle
                      : stage ===
                          "shipping"
                        ? shippingStageStyle
                        : shippedStageStyle
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
            "sent",

          label:
            "Sent",

          render:
            (asset) =>
              isShipped(
                asset
              )
                ? (
                    <Check
                      label="Shipped"
                    />
                  )
                : "",

          sortValue:
            (asset) =>
              isShipped(
                asset
              )
                ? 1
                : 0,
        },


        {
          key:
            "has_receipt",

          label:
            "Receipt",

          render:
            (asset) =>
              hasReceipt(
                asset
              )
                ? (
                    <Check
                      label="Shipping receipt saved"
                    />
                  )
                : "",

          sortValue:
            (asset) =>
              hasReceipt(
                asset
              )
                ? 1
                : 0,
        },


        {
          key:
            "external_id",

          label:
            "External ID",

          value:
            (asset) =>
              asset
                .external_id ||
              "—",
        },


        {
          key:
            "external_url",

          label:
            "Live URL",

          render:
            (asset) =>
              asset
                .external_url
                ? (
                    <a
                      href={
                        asset
                          .external_url
                      }

                      target="_blank"

                      rel="noreferrer"

                      onClick={(
                        event
                      ) =>
                        event
                          .stopPropagation()
                      }
                    >
                      Open
                    </a>
                  )
                : "—",

          sortValue:
            (asset) =>
              asset
                .external_url
                ? 1
                : 0,
        },


        {
          key:
            "dispatched_at",

          label:
            "Dispatched At",

          value:
            (asset) =>
              asset
                .dispatched_at ||
              "—",
        },
      ],
      []
    );


  const pinterestStatus =
    String(
      pinterestConnection
        ?.channel
        ?.status ||
      pinterestConnection
        ?.auth
        ?.status ||
      ""
    )
      .trim()
      .toLowerCase();

  const pinterestConnected =
    pinterestStatus ===
      "connected";

  const productionBoard =
    pinterestConnection
      ?.destinations
      ?.production ||
    {};

  const canRetrySelected =
    Boolean(
      selectedAsset
        ?.pub_asset_id
    )
    &&
    String(
      selectedAsset
        ?.pipeline_stage ||
      ""
    )
      .trim()
      .toLowerCase() ===
      "error"
    &&
    String(
      selectedAsset
        ?.error_stage ||
      ""
    )
      .trim()
      .toLowerCase() ===
      "dispatch"
    &&
    Number(
      retryingAssetId ||
      0
    ) !==
    Number(
      selectedAsset
        ?.pub_asset_id ||
      0
    );


  if (
    loading
    &&
    !assets.length
  ) {
    return (
      <AdminEmptyState
        title="Dispatch"

        message="Loading Dispatch workbench..."
      />
    );
  }


  return (
    <div
      style={
        workbenchShellStyle
      }
    >
      <div
        className="admin-detail-workarea"

        style={
          workbenchMainStyle
        }
      >
        <section
          style={
            connectionPanelStyle
          }

          aria-label="Pinterest connection"
        >
          <div
            style={
              connectionHeadingStyle
            }
          >
            <div>
              <div
                style={
                  connectionTitleStyle
                }
              >
                Pinterest
              </div>

              <div
                style={
                  connectionMetaStyle
                }
              >
                Connection:{" "}
                <strong>
                  {
                    connectionLoading
                      ? "Checking..."
                      : pinterestConnected
                        ? "Connected ✓"
                        : "Not connected"
                  }
                </strong>

                {" · "}

                Production board:{" "}
                <strong>
                  {
                    productionBoard
                      ?.board_name ||
                    "ColorFix Makeovers"
                  }
                </strong>

                {
                  productionBoard
                    ?.board_id
                    ? ` · ${productionBoard.board_id}`
                    : ""
                }
              </div>
            </div>


            <div
              style={
                connectionActionsStyle
              }
            >
              <button
                type="button"

                onClick={() =>
                  runConnectionAction(
                    "test"
                  )
                }

                disabled={
                  connectionAction !==
                    ""
                  ||
                  !pinterestConnected
                }
              >
                {
                  connectionAction ===
                    "test"
                    ? "Testing..."
                    : "Test Connection"
                }
              </button>


              <a
                href={
                  `${PINTEREST_CONNECT_URL}?return=${encodeURIComponent("/admin/pub")}`
                }

                style={
                  linkButtonStyle
                }
              >
                {
                  pinterestConnected
                    ? "Reconnect"
                    : "Connect"
                }
              </a>


              <button
                type="button"

                onClick={() =>
                  runConnectionAction(
                    "sync"
                  )
                }

                disabled={
                  connectionAction !==
                    ""
                  ||
                  !pinterestConnected
                }
              >
                {
                  connectionAction ===
                    "sync"
                    ? "Syncing..."
                    : "Sync Boards"
                }
              </button>


              <button
                type="button"

                onClick={
                  loadPinterestConnection
                }

                disabled={
                  connectionLoading
                }
              >
                Refresh
              </button>
            </div>
          </div>


          {
            pinterestConnection
              ?.auth
              ?.last_auth_error
              ? (
                  <div
                    style={
                      connectionErrorStyle
                    }
                  >
                    {
                      pinterestConnection
                        .auth
                        .last_auth_error
                    }
                  </div>
                )
              : null
          }
        </section>


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


          <button
            type="button"

            onClick={() => {
              retryShipping(
                selectedAsset
                  ?.pub_asset_id
              );
            }}

            disabled={
              !canRetrySelected
            }
          >
            {
              Number(
                retryingAssetId ||
                0
              ) ===
              Number(
                selectedAsset
                  ?.pub_asset_id ||
                0
              )
                ? "Retrying..."
                : "Retry Shipping"
            }
          </button>


          <button
            type="button"

            onClick={() => {
              loadAssets();
              loadFilters();
              loadPinterestConnection();
            }}

            disabled={
              loading
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


        {error ? (
          <div
            style={
              errorStyle
            }
          >
            {error}
          </div>
        ) : null}


        {statusMessage ? (
          <div
            style={
              successStyle
            }
          >
            {statusMessage}
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

          defaultSortKey="pub_asset_id"

          defaultSortDirection="desc"

          ariaLabel="PUB Dispatch workbench"
        />
      </div>


      <PubDispatchDrawer
        open={
          drawerOpen
        }

        asset={
          drawerAsset
        }

        loading={
          drawerLoading
        }

        error={
          drawerError
        }

        retrying={
          Number(
            retryingAssetId ||
            0
          ) ===
          Number(
            drawerAsset
              ?.pub_asset_id ||
            0
          )
        }

        onRetry={
          retryShipping
        }

        onClose={() =>
          setDrawerOpen(
            false
          )
        }
      />
    </div>
  );
}


function Check({
  label,
}) {
  return (
    <span
      title={
        label
      }

      aria-label={
        label
      }

      style={
        checkStyle
      }
    >
      ✓
    </span>
  );
}


function isShipped(
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
    "shipped"
  );
}


function hasReceipt(
  asset
) {
  return (
    asset
      ?.has_receipt ===
      true
    ||
    Number(
      asset
        ?.has_receipt ||
      0
    ) === 1
    ||
    (
      asset
        ?.shipping_receipt
      &&
      typeof asset
        .shipping_receipt ===
        "object"
    )
  );
}


function humanize(
  value
) {
  const raw =
    String(
      value ||
      ""
    )
      .trim();


  if (!raw) {
    return "—";
  }


  return raw
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


const workbenchShellStyle = {
  display:
    "flex",

  width:
    "100%",

  minWidth:
    0,

  minHeight:
    0,
};


const workbenchMainStyle = {
  flex:
    1,

  minWidth:
    0,
};


const connectionPanelStyle = {
  marginTop:
    14,

  padding:
    12,

  border:
    "1px solid #d8dde3",

  borderRadius:
    4,

  background:
    "#f8fafb",
};


const connectionHeadingStyle = {
  display:
    "flex",

  alignItems:
    "center",

  justifyContent:
    "space-between",

  gap:
    16,
};


const connectionTitleStyle = {
  marginBottom:
    4,

  fontSize:
    14,

  fontWeight:
    800,
};


const connectionMetaStyle = {
  color:
    "#586675",

  fontSize:
    12,
};


const connectionActionsStyle = {
  display:
    "flex",

  alignItems:
    "center",

  flexWrap:
    "wrap",

  gap:
    7,
};


const linkButtonStyle = {
  display:
    "inline-flex",

  alignItems:
    "center",

  minHeight:
    28,

  padding:
    "2px 9px",

  border:
    "1px solid #aeb8c2",

  borderRadius:
    3,

  background:
    "#ffffff",

  color:
    "#273444",

  fontSize:
    12,

  textDecoration:
    "none",
};


const connectionErrorStyle = {
  marginTop:
    8,

  color:
    "#8a3131",

  fontSize:
    12,
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
    "#64748b",

  fontSize:
    12,
};


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


const shippingStageStyle = {
  ...stageBadgeStyle,

  background:
    "#fff6df",

  color:
    "#7a5b00",
};


const shippedStageStyle = {
  ...stageBadgeStyle,

  background:
    "#eaf7ef",

  color:
    "#246640",
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


const checkStyle = {
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


const errorStyle = {
  marginBottom:
    10,

  padding:
    "9px 10px",

  border:
    "1px solid #e2baba",

  background:
    "#fff7f7",

  color:
    "#7d2e2e",

  fontSize:
    12,
};


const successStyle = {
  marginBottom:
    10,

  padding:
    "9px 10px",

  border:
    "1px solid #b8d8c0",

  background:
    "#f3faf5",

  color:
    "#2c6540",

  fontSize:
    12,

  fontWeight:
    600,
};
