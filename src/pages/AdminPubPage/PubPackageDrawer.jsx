import {
  useEffect,
  useState,
} from "react";

import {
  AdminBadge,
  AdminButton,
  AdminField,
  AdminMetaText,
  AdminNotice,
  AdminPanel,
  AdminStack,
  AdminToolbar,
} from "@components/AdminLayout";


export default function PubPackageDrawer({
  asset,
  loadAsset,
  packing = false,
  savingPingback = false,
  sending = false,
  enqueueing = false,
  onPack,
  onSavePingback,
  onEnqueue,
  onSend,
}) {
  const [
    detailAsset,
    setDetailAsset,
  ] = useState(
    asset ||
    null
  );

  const [
    loading,
    setLoading,
  ] = useState(
    false
  );

  const [
    error,
    setError,
  ] = useState(
    ""
  );

  const [
    pingback,
    setPingback,
  ] = useState(
    ""
  );


  async function refreshDetail() {
    const id =
      Number(
        asset
          ?.pub_asset_id ||
        0
      );

    if (!id) {
      setDetailAsset(
        null
      );

      return;
    }

    if (
      typeof loadAsset !==
      "function"
    ) {
      setDetailAsset(
        asset
      );

      return;
    }

    setLoading(
      true
    );

    setError(
      ""
    );

    try {
      const loaded =
        await loadAsset(
          id
        );

      setDetailAsset(
        loaded ||
        asset
      );

    } catch (err) {
      setError(
        err?.message ||
        "Could not load Package details."
      );

      setDetailAsset(
        asset
      );

    } finally {
      setLoading(
        false
      );
    }
  }


  useEffect(() => {
    setDetailAsset(
      asset ||
      null
    );

    refreshDetail();
  }, [
    asset?.pub_asset_id,
    asset?.updated_at,
  ]);


  useEffect(() => {
    setPingback(
      String(
        detailAsset
          ?.pingback ||
        ""
      )
    );
  }, [
    detailAsset
      ?.pub_asset_id,
    detailAsset
      ?.pingback,
  ]);


  const currentAsset =
    detailAsset ||
    asset;

  const packageValue =
    normalizePackage(
      currentAsset
        ?.package
    );

  const stage =
    String(
      currentAsset
        ?.pipeline_stage ||
      ""
    )
      .trim()
      .toLowerCase();

  const errorStage =
    String(
      currentAsset
        ?.error_stage ||
      ""
    )
      .trim()
      .toLowerCase();

  const isApprovedCreated =
    stage ===
      "created"
    &&
    Number(
      currentAsset
        ?.approved ||
      0
    ) === 1;

  const isPackageError =
    stage ===
      "error"
    &&
    errorStage ===
      "package";

  const canPack =
    isApprovedCreated
    ||
    stage ===
      "packing"
    ||
    stage ===
      "pending"
    ||
    isPackageError;

  const canSendNow =
    stage ===
      "packed"
    &&
    packageValue !==
      null;

  const showDestination =
    String(
      currentAsset
        ?.channel ||
      ""
    )
      .trim()
      .toLowerCase() ===
      "pinterest";

  const savedPingback =
    String(
      currentAsset
        ?.pingback ||
      ""
    ).trim();

  const editedPingback =
    String(
      pingback ||
      ""
    ).trim();

  const pingbackChanged =
    editedPingback !==
    savedPingback;


  async function handlePack() {
    if (!currentAsset) {
      return;
    }

    setError(
      ""
    );

    const result =
      await onPack?.(
        currentAsset
          .pub_asset_id
      );

    if (
      result?.ok ===
      false
    ) {
      setError(
        result.error ||
        "Packaging failed."
      );

      return;
    }

    await refreshDetail();
  }


  async function handleSavePingback() {
    if (!currentAsset) {
      return;
    }

    setError(
      ""
    );

    const result =
      await onSavePingback?.(
        currentAsset
          .pub_asset_id,
        editedPingback
      );

    if (
      result?.ok ===
      false
    ) {
      setError(
        result.error ||
        "Could not save destination."
      );

      return;
    }

    if (
      result?.asset
    ) {
      setDetailAsset(
        result.asset
      );
    } else {
      await refreshDetail();
    }
  }


  async function handleEnqueue() {
    if (!currentAsset) {
      return;
    }

    setError(
      ""
    );

    const result =
      await onEnqueue?.(
        currentAsset
          .pub_asset_id
      );

    if (
      result?.ok ===
      false
    ) {
      setError(
        result.error ||
        "Could not enqueue asset."
      );
    }
  }


  async function handleSend() {
    if (!currentAsset) {
      return;
    }

    setError(
      ""
    );

    const result =
      await onSend?.(
        currentAsset
          .pub_asset_id
      );

    if (
      result?.cancelled
    ) {
      return;
    }

    if (
      result?.ok ===
      false
    ) {
      setError(
        result.error ||
        "Could not send asset."
      );
    }
  }


  if (
    loading
    &&
    !currentAsset
  ) {
    return (
      <AdminMetaText as="div">
        Loading Package details...
      </AdminMetaText>
    );
  }


  if (!currentAsset) {
    return (
      <AdminMetaText as="div">
        Select a Package row.
      </AdminMetaText>
    );
  }


  const displayedStage =
    stage ===
      "packing"
    &&
    String(
      currentAsset
        .stage_note ||
      ""
    ).trim()
      ? "Waiting"
      : humanize(
          currentAsset
            .display_stage ||
          currentAsset
            .pipeline_stage
        );


  return (
    <AdminStack gap="lg">
      {loading ? (
        <AdminMetaText as="div">
          Refreshing Package details...
        </AdminMetaText>
      ) : null}


      {error ? (
        <AdminNotice variant="danger">
          {error}
        </AdminNotice>
      ) : null}


      <AdminPanel
        title="Asset"
        compact
      >
        <AdminStack gap="sm">
          <DetailField
            label="Channel"
            value={
              humanize(
                currentAsset
                  .channel
              )
            }
          />

          <DetailField
            label="Type"
            value={
              humanize(
                currentAsset
                  .asset_type
              )
            }
          />

          <DetailField
            label="Source"
            value={
              formatSource(
                currentAsset
              )
            }
          />

          <DetailField
            label="Title"
            value={
              currentAsset
                .search_title ||
              "—"
            }
          />
        </AdminStack>
      </AdminPanel>


      <AdminPanel
        title="Status"
        compact
      >
        <AdminStack gap="sm">
          <AdminField label="Stage">
            <div>
              <AdminBadge
                variant={
                  stageVariant(
                    stage,
                    displayedStage
                  )
                }
              >
                {displayedStage}
              </AdminBadge>
            </div>
          </AdminField>

          <DetailField
            label="Stage Note"
            value={
              currentAsset
                .stage_note ||
              "—"
            }
          />

          <DetailField
            label="Updated"
            value={
              currentAsset
                .updated_at ||
              "—"
            }
          />


          {canPack ? (
            <AdminToolbar compact>
              <AdminButton
                type="button"

                disabled={
                  packing
                }

                onClick={
                  handlePack
                }
              >
                {
                  packing
                    ? "Packing..."
                    : stage ===
                        "packing"
                      ||
                      stage ===
                        "pending"
                      ||
                      isPackageError
                        ? "Retry Packaging"
                        : "Pack"
                }
              </AdminButton>
            </AdminToolbar>
          ) : null}


          {canSendNow ? (
            <AdminToolbar compact>
              <AdminButton
                type="button"

                disabled={
                  enqueueing
                  ||
                  sending
                }

                onClick={
                  handleEnqueue
                }
              >
                {
                  enqueueing
                    ? "Enqueueing..."
                    : "Enqueue"
                }
              </AdminButton>

              <AdminButton
                type="button"

                disabled={
                  sending
                  ||
                  enqueueing
                }

                onClick={
                  handleSend
                }
              >
                {
                  sending
                    ? "Sending..."
                    : "Send Now"
                }
              </AdminButton>
            </AdminToolbar>
          ) : null}
        </AdminStack>
      </AdminPanel>


      {showDestination ? (
        <AdminPanel
          title="Destination"
          compact
        >
          <AdminStack gap="sm">
            <AdminField label="Pingback">
              <input
                className="admin-field__control admin-field__control--full"

                type="text"

                value={
                  pingback
                }

                placeholder="Destination URL"

                onChange={(
                  event
                ) =>
                  setPingback(
                    event
                      .target
                      .value
                  )
                }
              />
            </AdminField>

            <AdminToolbar compact>
              <AdminButton
                type="button"

                variant="secondary"

                disabled={
                  savingPingback
                  ||
                  !pingbackChanged
                }

                onClick={
                  handleSavePingback
                }
              >
                {
                  savingPingback
                    ? "Saving..."
                    : "Save Pingback"
                }
              </AdminButton>
            </AdminToolbar>
          </AdminStack>
        </AdminPanel>
      ) : null}


      {
        currentAsset
          .error_stage
        ||
        currentAsset
          .error_code
        ||
        currentAsset
          .error_message
          ? (
              <AdminPanel
                title="Error"
                compact
              >
                <AdminStack gap="sm">
                  <DetailField
                    label="Stage"
                    value={
                      currentAsset
                        .error_stage ||
                      "—"
                    }
                  />

                  <DetailField
                    label="Code"
                    value={
                      currentAsset
                        .error_code ||
                      "—"
                    }
                  />

                  <DetailField
                    label="Message"
                    value={
                      currentAsset
                        .error_message ||
                      "—"
                    }
                  />

                  <DetailField
                    label="At"
                    value={
                      currentAsset
                        .errored_at ||
                      "—"
                    }
                  />
                </AdminStack>
              </AdminPanel>
            )
          : null
      }


      <AdminPanel
        title="Package"
        compact
      >
        {
          packageValue ===
          null
            ? (
                <AdminMetaText as="div">
                  No package has been built.
                </AdminMetaText>
              )
            : (
                <pre className="admin-code-block">
                  {
                    JSON.stringify(
                      packageValue,
                      null,
                      2
                    )
                  }
                </pre>
              )
        }
      </AdminPanel>
    </AdminStack>
  );
}


function DetailField({
  label,
  value,
}) {
  return (
    <AdminField
      label={
        label
      }
    >
      <div>
        {
          value ||
          "—"
        }
      </div>
    </AdminField>
  );
}


function normalizePackage(
  value
) {
  if (
    value === null
    ||
    value ===
      undefined
    ||
    value === ""
  ) {
    return null;
  }

  if (
    typeof value ===
    "object"
  ) {
    return value;
  }

  if (
    typeof value ===
    "string"
  ) {
    try {
      return JSON.parse(
        value
      );
    } catch {
      return value;
    }
  }

  return value;
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


function stageVariant(
  stage,
  displayedStage
) {
  if (
    String(
      displayedStage ||
      ""
    )
      .trim()
      .toLowerCase() ===
    "waiting"
  ) {
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
