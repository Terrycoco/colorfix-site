import {
  useEffect,
  useMemo,
  useState,
} from "react";

import {
  AdminBadge,
  AdminButton,
  AdminDataGrid,
  AdminDialog,
  AdminEmptyState,
  AdminField,
  AdminMetaText,
  AdminNotice,
  AdminToolbar,
  AdminToolbarSpacer,
  useAdminDialog,
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
  const dialog =
    useAdminDialog();

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


  async function changeStage(
    action,
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

    setChangingStage(
      true
    );

    setError(
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
          `Failed to ${action} asset #${id}.`
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
        `Failed to update asset #${id}.`;

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
      setChangingStage(
        false
      );
    }
  }


  async function enqueueAllPacked() {
    const packedAssets =
      assets.filter(
        (asset) =>
          normalizedStage(
            asset
          ) ===
          "packed"
      );

    if (!packedAssets.length) {
      return;
    }

    const confirmed =
      await dialog.confirm({
        title:
          "Enqueue packed assets?",

        message:
          `Enqueue ${packedAssets.length} packed asset${
            packedAssets.length === 1
              ? ""
              : "s"
          }? They will become eligible for automatic Schedule.`,

        confirmLabel:
          "Enqueue",

        cancelLabel:
          "Cancel",
      });

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

    const confirmed =
      await dialog.confirm({
        title:
          "Send asset now?",

        message:
          `Send asset #${id} now? This bypasses Schedule timing and sends it directly to Dispatch.`,

        confirmLabel:
          "Send Now",

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

    setSending(
      true
    );

    setError(
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
          data?.result
            ?.failed
            ?.error ||
          `Failed to send asset #${id}.`
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
        `Failed to send asset #${id}.`;

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
      setSending(
        false
      );
    }
  }


  const packedCount =
    useMemo(
      () =>
        assets.filter(
          (asset) =>
            normalizedStage(
              asset
            ) ===
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
            (asset) =>
              formatSource(
                asset
              ),

          sortValue:
            (asset) =>
              asset
                .source_title ||
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
                normalizedStage(
                  asset
                );

              return (
                <AdminBadge
                  variant={
                    stage ===
                      "queued"
                      ? "info"
                      : "neutral"
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
        title="Schedule"

        message="Loading Schedule workbench..."
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
        </AdminField>


        <AdminField
          label="Stage"
          compact
        >
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
        </AdminField>


        <AdminButton
          type="button"

          onClick={
            enqueueAllPacked
          }

          disabled={
            loading
            ||
            changingStage
            ||
            bulkEnqueueing
            ||
            sending
            ||
            packedCount ===
              0
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
        </AdminButton>


        <AdminButton
          type="button"

          variant="secondary"

          onClick={() =>
            setControlsOpen(
              true
            )
          }

          disabled={
            changingStage
            ||
            bulkEnqueueing
            ||
            sending
          }
        >
          Schedule Controls
        </AdminButton>


        <AdminToolbarSpacer />


        <AdminButton
          type="button"

          variant="secondary"

          onClick={
            loadAssets
          }

          disabled={
            loading
            ||
            changingStage
            ||
            bulkEnqueueing
            ||
            sending
          }
        >
          {
            loading
              ? "Refreshing..."
              : "Refresh"
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


      {error ? (
        <AdminNotice variant="danger">
          {error}
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

        defaultSortKey="updated_at"

        defaultSortDirection="asc"

        ariaLabel="PUB Schedule workbench"

        drawer={{
          title:
            (asset) =>
              `Schedule · Asset #${asset.pub_asset_id}`,

          width:
            440,

          render:
            ({ item }) => (
              <PubScheduleDrawer
                asset={
                  item
                }

                loadAsset={
                  fetchScheduleAssetDetail
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
            ),
        }}
      />


      <AdminDialog
        open={
          controlsOpen
        }

        title="Schedule Controls"

        message="Automatic publishing rules. These settings do not affect Manual Send Now."

        width={
          780
        }

        onClose={() =>
          setControlsOpen(
            false
          )
        }

        actions={[
          {
            key:
              "close",

            label:
              "Close",

            variant:
              "secondary",

            onClick: () =>
              setControlsOpen(
                false
              ),
          },
        ]}
      >
        <PubScheduleControls />
      </AdminDialog>
    </div>
  );
}


async function fetchScheduleAssetDetail(
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

  return (
    data.asset ||
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


function formatSource(
  asset
) {
  const title =
    String(
      asset
        ?.source_title ||
      ""
    ).trim();

  if (title) {
    return title;
  }

  const type =
    asset
      ?.source_type ||
    "";

  const id =
    asset
      ?.source_id ||
    "";

  if (
    !type
    &&
    !id
  ) {
    return "—";
  }

  return `${type} #${id}`;
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
