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
  AdminToolbar,
  AdminToolbarSpacer,
  useAdminDialog,
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
        "Failed to load Package workbench."
      );

    } finally {
      setLoading(
        false
      );
    }
  }


  async function packAsset(
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
          `Failed to pack asset #${id}.`
        );
      }

      const packedCount =
        Number(
          data.packed_count ||
          0
        );

      await loadAssets();

      if (packedCount > 0) {
        onOpenDispatch?.();
      }

      return {
        ok:
          true,
        data,
      };

    } catch (err) {
      const message =
        err?.message ||
        `Failed to pack asset #${id}.`;

      /*
       * Durable Package state may already have changed even if the
       * request returned an error. Re-read the workbench before
       * reporting the failure.
       */
      await loadAssets();

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
      setProcessing(
        false
      );
    }
  }


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


  async function savePingback(
    pubAssetId,
    pingback
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

    setSavingPingback(
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
                action:
                  "update_pingback",

                pub_asset_id:
                  id,

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
          `Failed to save destination for asset #${id}.`
        );
      }

      await loadAssets();

      return {
        ok:
          true,
        asset:
          data?.asset ||
          null,
      };

    } catch (err) {
      const message =
        err?.message ||
        `Failed to save destination for asset #${id}.`;

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
      setSavingPingback(
        false
      );
    }
  }


  async function enqueueAsset(
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

    setEnqueueing(
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
                  "enqueue",

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
          `Failed to enqueue asset #${id}.`
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
        `Failed to enqueue asset #${id}.`;

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
      setEnqueueing(
        false
      );
    }
  }


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
              "Queue handoff failed."
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
      setEnqueueing(
        false
      );
    }
  }


  async function sendAsset(
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
          `Send asset #${id} now? This bypasses Schedule timing and sends it to Dispatch immediately.`,

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


  useEffect(() => {
    loadAssets();
  }, [
    channelFilter,
    typeFilter,
  ]);


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

              const packageError =
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
                );

              const label =
                packageError
                  ? "Package Error"
                  : waiting
                    ? "Waiting"
                    : humanize(
                        stage
                      );

              return (
                <AdminBadge
                  variant={
                    packageError
                      ? "danger"
                      : stageBadgeVariant(
                          stage,
                          waiting
                        )
                  }
                >
                  {label}
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
                    <AdminBadge
                      variant="success"
                      title="Package complete"
                      aria-label="Package complete"
                    >
                      ✓
                    </AdminBadge>
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
        </AdminField>


        <AdminButton
          type="button"

          onClick={
            packAllAssets
          }

          disabled={
            loading
            ||
            processing
            ||
            approvedReadyCount ===
              0
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
        </AdminButton>


        <AdminButton
          type="button"

          onClick={
            enqueueAllPackedAssets
          }

          disabled={
            loading
            ||
            processing
            ||
            enqueueing
            ||
            packedReadyCount ===
              0
          }
        >
          {
            enqueueing
              ? "Sending to Queue..."
              : packedReadyCount > 0
                ? `Send To Queue (${packedReadyCount})`
                : "Send To Queue"
          }
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
            processing
            ||
            enqueueing
          }
        >
          {
            loading
              ? "Refreshing..."
              : "Refresh"
          }
        </AdminButton>


        <AdminMetaText as="div">
          {filteredAssets.length} asset
          {
            filteredAssets.length === 1
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

        defaultSortKey="pub_asset_id"

        defaultSortDirection="desc"

        ariaLabel="PUB Package workbench"

        drawer={{
          title:
            (asset) =>
              `Package · Asset #${asset.pub_asset_id}`,

          width:
            440,

          render:
            ({ item }) => (
              <PubPackageDrawer
                asset={
                  item
                }

                loadAsset={
                  fetchPackageAssetDetail
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
            ),
        }}
      />
    </div>
  );
}


async function fetchPackageAssetDetail(
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

  return (
    data.asset ||
    null
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
    asset?.source_type ||
    "";

  const id =
    asset?.source_id ||
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


function stageBadgeVariant(
  stage,
  waiting = false
) {
  if (waiting) {
    return "warning";
  }

  switch (
    String(
      stage ||
      ""
    )
      .trim()
      .toLowerCase()
  ) {
    case "packed":
      return "success";

    case "packing":
      return "info";

    case "pending":
      return "warning";

    case "error":
      return "danger";

    default:
      return "neutral";
  }
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
