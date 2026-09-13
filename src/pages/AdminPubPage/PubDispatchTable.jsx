import {
  useEffect,
  useMemo,
  useState,
} from "react";

import {
  AdminBadge,
  AdminButton,
  AdminDataGrid,
  AdminEmptyState,
  AdminField,
  AdminMetaText,
  AdminNotice,
  AdminPanel,
  AdminStack,
  AdminToolbar,
  AdminToolbarSpacer,
  useAdminDialog,
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

const PINTEREST_CONNECT_URL =
  `${API_FOLDER}/v2/admin/pub/pinterest-oauth-start.php`;

const YOUTUBE_CONNECT_URL =
  `${API_FOLDER}/v2/admin/pub/youtube-oauth-start.php`;


export default function PubDispatchTable() {
  const dialog =
    useAdminDialog();

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
    retryingAssetId,
    setRetryingAssetId,
  ] = useState(null);


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

  const [
    youtubeConnection,
    setYoutubeConnection,
  ] = useState(null);

  const [
    youtubeConnectionLoading,
    setYoutubeConnectionLoading,
  ] = useState(true);

  const [
    youtubeConnectionAction,
    setYoutubeConnectionAction,
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

      setSelectedAsset(
        (current) => {
          if (
            !current
              ?.pub_asset_id
          ) {
            return current;
          }

          return (
            nextAssets.find(
              (asset) =>
                Number(
                  asset
                    .pub_asset_id
                ) ===
                Number(
                  current
                    .pub_asset_id
                )
            )
            ||
            null
          );
        }
      );

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


  async function loadYoutubeConnection() {
    setYoutubeConnectionLoading(
      true
    );

    try {
      const params =
        new URLSearchParams({
          channel:
            "youtube",

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
          "Failed to load YouTube connection."
        );
      }

      setYoutubeConnection(
        data.item ||
        null
      );

    } catch (err) {
      setError(
        err?.message ||
        "Failed to load YouTube connection."
      );

    } finally {
      setYoutubeConnectionLoading(
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


  async function runYoutubeConnectionAction(
    action
  ) {
    setYoutubeConnectionAction(
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
                  "youtube",

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
          `YouTube ${action} failed.`
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
          "YouTube connection test passed."
        );
      }

      await loadYoutubeConnection();

    } catch (err) {
      setError(
        err?.message ||
        `YouTube ${action} failed.`
      );

    } finally {
      setYoutubeConnectionAction(
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


  useEffect(() => {
    const hasShipping =
      assets.some(
        (asset) =>
          normalizedStage(
            asset
          ) ===
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
  ]);


  useEffect(() => {
    loadFilters();
    loadPinterestConnection();
    loadYoutubeConnection();

    const params =
      new URLSearchParams(
        window.location.search
      );

    const pinterestAuthStatus =
      params.get(
        "pinterest_auth"
      );

    const youtubeAuthStatus =
      params.get(
        "youtube_auth"
      );

    const message =
      params.get(
        "message"
      );

    if (pinterestAuthStatus) {
      if (
        pinterestAuthStatus ===
        "connected"
      ) {
        setStatusMessage(
          message ||
          "Pinterest connected."
        );

      } else {
        setError(
          message ||
          `Pinterest OAuth ${pinterestAuthStatus}.`
        );
      }
    }

    if (youtubeAuthStatus) {
      setChannelFilter(
        "youtube"
      );

      if (
        youtubeAuthStatus ===
        "connected"
      ) {
        setStatusMessage(
          message ||
          "YouTube connected."
        );

      } else {
        setError(
          message ||
          `YouTube OAuth ${youtubeAuthStatus}.`
        );
      }
    }

    if (
      pinterestAuthStatus
      ||
      youtubeAuthStatus
    ) {
      params.delete(
        "pinterest_auth"
      );

      params.delete(
        "youtube_auth"
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


  async function retryShipping(
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

    const asset =
      assets.find(
        (item) =>
          Number(
            item
              ?.pub_asset_id ||
            0
          ) === id
      )
      ||
      (
        Number(
          selectedAsset
            ?.pub_asset_id ||
          0
        ) === id
          ? selectedAsset
          : null
      );

    if (
      normalizedStage(
        asset
      ) !==
        "error"
      ||
      normalizedErrorStage(
        asset
      ) !==
        "dispatch"
    ) {
      const message =
        `Asset #${id} is not a retryable Dispatch error.`;

      setError(
        message
      );

      return {
        ok:
          false,
        error:
          message,
      };
    }

    const confirmed =
      await dialog.confirm({
        title:
          "Retry shipping?",

        message:
          `Retry shipping asset #${id}? If the external service accepted part of the previous attempt before the failure was reported, a retry could create a duplicate.`,

        confirmLabel:
          "Retry Shipping",

        cancelLabel:
          "Cancel",
      });

    if (!confirmed) {
      return {
        ok:
          false,
        cancelled:
          true,
      };
    }

    setRetryingAssetId(
      id
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

      return {
        ok:
          true,
      };

    } catch (err) {
      const message =
        err?.message ||
        `Asset #${id} could not restart Dispatch.`;

      setError(
        message
      );

      await loadAssets();

      return {
        ok:
          false,
        error:
          message,
      };

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
                normalizedStage(
                  asset
                );

              if (!stage) {
                return "—";
              }

              return (
                <AdminBadge
                  variant={
                    stage ===
                      "error"
                      ? "danger"
                      : stage ===
                          "shipping"
                        ? "warning"
                        : "success"
                  }
                >
                  {
                    humanize(
                      stage
                    )
                  }
                </AdminBadge>
              );
            },

          sortValue:
            (asset) =>
              normalizedStage(
                asset
              ),
        },

        {
          key:
            "dispatch_error",

          label:
            "Error",

          value:
            (asset) => {
              if (
                normalizedStage(
                  asset
                ) !==
                  "error"
                ||
                normalizedErrorStage(
                  asset
                ) !==
                  "dispatch"
              ) {
                return "—";
              }

              const text =
                [
                  String(
                    asset
                      ?.error_code ||
                    ""
                  ).trim(),

                  String(
                    asset
                      ?.error_message ||
                    ""
                  ).trim(),
                ]
                  .filter(
                    Boolean
                  )
                  .join(
                    ": "
                  );

              return (
                text ||
                "Dispatch error"
              );
            },
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
                    <AdminBadge
                      variant="success"
                      title="Shipped"
                      aria-label="Shipped"
                    >
                      ✓
                    </AdminBadge>
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
                    <AdminBadge
                      variant="success"
                      title="Shipping receipt saved"
                      aria-label="Shipping receipt saved"
                    >
                      ✓
                    </AdminBadge>
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

  const youtubeStatus =
    String(
      youtubeConnection
        ?.channel
        ?.status ||
      youtubeConnection
        ?.auth
        ?.status ||
      ""
    )
      .trim()
      .toLowerCase();

  const youtubeConnected =
    youtubeStatus ===
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
    normalizedStage(
      selectedAsset
    ) ===
      "error"
    &&
    normalizedErrorStage(
      selectedAsset
    ) ===
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


  const visibleChannels =
    [
      ...new Set(
        assets
          .map(
            (asset) =>
              String(
                asset
                  ?.channel ||
                ""
              )
                .trim()
                .toLowerCase()
          )
          .filter(
            Boolean
          )
      ),
    ];

  const connectionPanelChannel =
    String(
      channelFilter ||
      (
        visibleChannels.length ===
          1
          ? visibleChannels[0]
          : ""
      )
    )
      .trim()
      .toLowerCase();


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
    <div className="admin-detail-workarea">
      <AdminToolbar>
        <AdminField
          label="Channel"
          compact
        >
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
                  {
                    humanize(
                      channel
                    )
                  }
                </option>
              )
            )}
          </select>
        </AdminField>


        <AdminField
          label="Type"
          compact
        >
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
                  {
                    humanize(
                      assetType
                    )
                  }
                </option>
              )
            )}
          </select>
        </AdminField>


        <AdminButton
          type="button"

          variant="secondary"

          onClick={() =>
            retryShipping(
              selectedAsset
                ?.pub_asset_id
            )
          }

          disabled={
            !canRetrySelected
          }
        >
          {
            retryingAssetId
            &&
            Number(
              retryingAssetId
            ) ===
            Number(
              selectedAsset
                ?.pub_asset_id ||
              0
            )
              ? "Retrying..."
              : "Retry Shipping"
          }
        </AdminButton>


        <AdminToolbarSpacer />


        <AdminButton
          type="button"

          variant="secondary"

          onClick={() => {
            loadAssets();
            loadFilters();
          }}

          disabled={
            loading
          }
        >
          {
            loading
              ? "Refreshing Assets..."
              : "Refresh Assets"
          }
        </AdminButton>


        <AdminMetaText as="div">
          {assets.length} asset
          {
            assets.length === 1
              ? ""
              : "s"
          }
        </AdminMetaText>
      </AdminToolbar>


      {connectionPanelChannel ===
      "pinterest" ? (
        <AdminPanel
          title="Pinterest"
          compact
        >
          <AdminStack gap="sm">
            <AdminMetaText as="div">
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
            </AdminMetaText>


            <AdminToolbar compact>
              <AdminButton
                type="button"

                variant="secondary"

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
              </AdminButton>


              <AdminButton
                type="button"

                onClick={() =>
                  window.location.assign(
                    `${PINTEREST_CONNECT_URL}?return=${encodeURIComponent("/admin/pub")}`
                  )
                }
              >
                {
                  pinterestConnected
                    ? "Reconnect Pinterest"
                    : "Connect Pinterest"
                }
              </AdminButton>


              <AdminButton
                type="button"

                variant="secondary"

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
              </AdminButton>


              <AdminButton
                type="button"

                variant="secondary"

                onClick={
                  loadPinterestConnection
                }

                disabled={
                  connectionLoading
                }
              >
                Refresh
              </AdminButton>
            </AdminToolbar>


            {
              pinterestConnection
                ?.auth
                ?.last_auth_error
                ? (
                    <AdminNotice variant="danger">
                      {
                        pinterestConnection
                          .auth
                          .last_auth_error
                      }
                    </AdminNotice>
                  )
                : null
            }
          </AdminStack>
        </AdminPanel>
      ) : null}


      {connectionPanelChannel ===
      "youtube" ? (
        <AdminPanel
          title="YouTube"
          compact
        >
          <AdminStack gap="sm">
            <AdminMetaText as="div">
              Connection:{" "}
              <strong>
                {
                  youtubeConnectionLoading
                    ? "Checking..."
                    : youtubeConnected
                      ? "Connected ✓"
                      : "Not connected"
                }
              </strong>

              {
                Array.isArray(
                  youtubeConnection
                    ?.scopes_requested
                )
                &&
                youtubeConnection
                  .scopes_requested
                  .length
                  ? (
                      <>
                        {" · "}
                        Scope:{" "}
                        <strong>
                          {
                            youtubeConnection
                              .scopes_requested
                              .join(", ")
                          }
                        </strong>
                      </>
                    )
                  : null
              }
            </AdminMetaText>


            <AdminToolbar compact>
              <AdminButton
                type="button"

                variant="secondary"

                onClick={() =>
                  runYoutubeConnectionAction(
                    "test"
                  )
                }

                disabled={
                  youtubeConnectionAction !==
                    ""
                  ||
                  !youtubeConnected
                }
              >
                {
                  youtubeConnectionAction ===
                    "test"
                    ? "Testing..."
                    : "Test Connection"
                }
              </AdminButton>


              <AdminButton
                type="button"

                onClick={() =>
                  window.location.assign(
                    `${YOUTUBE_CONNECT_URL}?return=${encodeURIComponent("/admin/pub?stage=dispatch")}`
                  )
                }
              >
                {
                  youtubeConnected
                    ? "Reconnect YouTube"
                    : "Connect YouTube"
                }
              </AdminButton>


              <AdminButton
                type="button"

                variant="secondary"

                onClick={
                  loadYoutubeConnection
                }

                disabled={
                  youtubeConnectionLoading
                }
              >
                {
                  youtubeConnectionLoading
                    ? "Refreshing..."
                    : "Refresh"
                }
              </AdminButton>
            </AdminToolbar>


            {
              youtubeConnection
                ?.auth
                ?.last_auth_error
                ? (
                    <AdminNotice variant="danger">
                      {
                        youtubeConnection
                          .auth
                          .last_auth_error
                      }
                    </AdminNotice>
                  )
                : null
            }
          </AdminStack>
        </AdminPanel>
      ) : null}


      {error ? (
        <AdminNotice variant="danger">
          {error}
        </AdminNotice>
      ) : null}


      {statusMessage ? (
        <AdminNotice variant="success">
          {statusMessage}
        </AdminNotice>
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

        onSelectionChange={(
          asset
        ) =>
          setSelectedAsset(
            asset
          )
        }

        defaultSortKey="pub_asset_id"

        defaultSortDirection="desc"

        ariaLabel="PUB Dispatch workbench"

        drawer={{
          title:
            (asset) =>
              `Dispatch · Asset #${asset.pub_asset_id}`,

          width:
            500,

          render:
            ({ item }) => (
              <PubDispatchDrawer
                asset={
                  item
                }

                loadAsset={
                  fetchDispatchAssetDetail
                }

                retrying={
                  Number(
                    retryingAssetId ||
                    0
                  ) ===
                  Number(
                    item
                      ?.pub_asset_id ||
                    0
                  )
                }

                onRetry={
                  retryShipping
                }
              />
            ),
        }}
      />
    </div>
  );
}


async function fetchDispatchAssetDetail(
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

  return (
    data.item ||
    null
  );
}


function normalizedStage(
  asset
) {
  return String(
    asset
      ?.pipeline_stage ||
    ""
  )
    .trim()
    .toLowerCase();
}


function normalizedErrorStage(
  asset
) {
  return String(
    asset
      ?.error_stage ||
    ""
  )
    .trim()
    .toLowerCase();
}


function isShipped(
  asset
) {
  return (
    normalizedStage(
      asset
    ) ===
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
      (character) =>
        character
          .toUpperCase()
    );
}
