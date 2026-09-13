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


export default function PubScheduleDrawer({
  asset,
  loadAsset,
  changingStage = false,
  sending = false,
  onEnqueue,
  onDequeue,
  onSendNow,
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
        "Could not load Schedule details."
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


  const currentAsset =
    detailAsset ||
    asset;

  const stage =
    String(
      currentAsset
        ?.pipeline_stage ||
      ""
    )
      .trim()
      .toLowerCase();

  const canEnqueue =
    stage ===
      "packed";

  const canDequeue =
    stage ===
      "queued";

  const canSendNow =
    stage ===
      "packed"
    ||
    stage ===
      "queued";


  async function handleStageChange(
    action
  ) {
    if (!currentAsset) {
      return;
    }

    setError(
      ""
    );

    const callback =
      action ===
        "enqueue"
        ? onEnqueue
        : onDequeue;

    const result =
      await callback?.(
        currentAsset
          .pub_asset_id
      );

    if (
      result?.ok ===
      false
    ) {
      setError(
        result.error ||
        "Could not change Schedule stage."
      );

      return;
    }

    await refreshDetail();
  }


  async function handleSendNow() {
    if (!currentAsset) {
      return;
    }

    setError(
      ""
    );

    const result =
      await onSendNow?.(
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
        Loading Schedule details...
      </AdminMetaText>
    );
  }


  if (!currentAsset) {
    return (
      <AdminMetaText as="div">
        Select a Schedule row.
      </AdminMetaText>
    );
  }


  return (
    <AdminStack gap="lg">
      {loading ? (
        <AdminMetaText as="div">
          Refreshing Schedule details...
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
            </div>
          </AdminField>

          <DetailField
            label="Updated"
            value={
              currentAsset
                .updated_at ||
              "—"
            }
          />


          <AdminToolbar compact>
            {canEnqueue ? (
              <AdminButton
                type="button"

                disabled={
                  changingStage
                  ||
                  sending
                }

                onClick={() =>
                  handleStageChange(
                    "enqueue"
                  )
                }
              >
                {
                  changingStage
                    ? "Queueing..."
                    : "Queue"
                }
              </AdminButton>
            ) : null}


            {canDequeue ? (
              <AdminButton
                type="button"

                variant="secondary"

                disabled={
                  changingStage
                  ||
                  sending
                }

                onClick={() =>
                  handleStageChange(
                    "dequeue"
                  )
                }
              >
                {
                  changingStage
                    ? "Removing..."
                    : "Remove from Queue"
                }
              </AdminButton>
            ) : null}


            {canSendNow ? (
              <AdminButton
                type="button"

                disabled={
                  sending
                  ||
                  changingStage
                }

                onClick={
                  handleSendNow
                }
              >
                {
                  sending
                    ? "Sending..."
                    : "Send Now"
                }
              </AdminButton>
            ) : null}
          </AdminToolbar>
        </AdminStack>
      </AdminPanel>


      <AdminPanel
        title="Schedule Meaning"
        compact
      >
        <AdminStack gap="sm">
          <DetailField
            label="Packed"
            value="Sealed and held outside automatic scheduling."
          />

          <DetailField
            label="Queued"
            value="Released into Schedule's automatic candidate pool."
          />
        </AdminStack>
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
