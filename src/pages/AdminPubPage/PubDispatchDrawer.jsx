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


export default function PubDispatchDrawer({
  asset,
  loadAsset,
  retrying = false,
  onRetry,
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
    copiedUrl,
    setCopiedUrl,
  ] = useState(
    false
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
        "Could not load Dispatch details."
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

    setCopiedUrl(
      false
    );

    refreshDetail();
  }, [
    asset?.pub_asset_id,
    asset?.updated_at,
  ]);


  const currentAsset =
    detailAsset ||
    asset;

  const packageValue =
    normalizeJson(
      currentAsset
        ?.package
    );

  const receiptValue =
    normalizeJson(
      currentAsset
        ?.shipping_receipt
    );

  const externalId =
    receiptValue
    &&
    typeof receiptValue ===
      "object"
      ? receiptValue
          .external_id ||
        currentAsset
          ?.external_id ||
        "—"
      : currentAsset
          ?.external_id ||
        "—";

  const externalUrl =
    receiptValue
    &&
    typeof receiptValue ===
      "object"
      ? receiptValue
          .external_url ||
        currentAsset
          ?.external_url ||
        ""
      : currentAsset
          ?.external_url ||
        "";

  const stage =
    normalizedStage(
      currentAsset
    );

  const canRetryShipping =
    stage ===
      "error"
    &&
    normalizedErrorStage(
      currentAsset
    ) ===
      "dispatch";


  async function copyLiveUrl() {
    if (!externalUrl) {
      return;
    }

    try {
      await navigator
        .clipboard
        .writeText(
          externalUrl
        );

      setCopiedUrl(
        true
      );

      window.setTimeout(
        () => {
          setCopiedUrl(
            false
          );
        },
        1400
      );

    } catch {
      setCopiedUrl(
        false
      );
    }
  }


  async function handleRetry() {
    if (!currentAsset) {
      return;
    }

    setError(
      ""
    );

    const result =
      await onRetry?.(
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
        "Dispatch retry failed."
      );

      return;
    }

    await refreshDetail();
  }


  if (
    loading
    &&
    !currentAsset
  ) {
    return (
      <AdminMetaText as="div">
        Loading Dispatch details...
      </AdminMetaText>
    );
  }


  if (!currentAsset) {
    return (
      <AdminMetaText as="div">
        Select a Dispatch row.
      </AdminMetaText>
    );
  }


  return (
    <AdminStack gap="lg">
      {loading ? (
        <AdminMetaText as="div">
          Refreshing Dispatch details...
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
            </div>
          </AdminField>

          <DetailField
            label="Dispatched"
            value={
              currentAsset
                .dispatched_at ||
              "—"
            }
          />

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
        </AdminStack>
      </AdminPanel>


      <AdminPanel
        title="Result"
        compact
      >
        <AdminStack gap="sm">
          <DetailField
            label="External ID"
            value={
              externalId
            }
          />

          <AdminField label="Live URL">
            {
              externalUrl
                ? (
                    <AdminToolbar compact>
                      <a
                        href={
                          externalUrl
                        }

                        target="_blank"

                        rel="noreferrer"
                      >
                        {
                          externalUrl
                        }
                      </a>

                      <AdminButton
                        type="button"

                        size="sm"

                        variant="secondary"

                        onClick={
                          copyLiveUrl
                        }

                        title={
                          copiedUrl
                            ? "Copied"
                            : "Copy live URL"
                        }

                        aria-label="Copy live URL"
                      >
                        {
                          copiedUrl
                            ? "✓"
                            : "⧉"
                        }
                      </AdminButton>
                    </AdminToolbar>
                  )
                : (
                    <div>
                      —
                    </div>
                  )
            }
          </AdminField>
        </AdminStack>
      </AdminPanel>


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
                title="Dispatch Error"
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


                  {canRetryShipping ? (
                    <AdminToolbar compact>
                      <AdminButton
                        type="button"

                        disabled={
                          retrying
                        }

                        onClick={
                          handleRetry
                        }
                      >
                        {
                          retrying
                            ? "Retrying..."
                            : "Retry Shipping"
                        }
                      </AdminButton>
                    </AdminToolbar>
                  ) : null}
                </AdminStack>
              </AdminPanel>
            )
          : null
      }


      <JsonPanel
        title="Outbound Package"

        emptyMessage="No outbound package is stored."

        value={
          packageValue
        }
      />


      <JsonPanel
        title="Returned Shipping Receipt"

        emptyMessage="No shipping receipt has been returned."

        value={
          receiptValue
        }
      />
    </AdminStack>
  );
}


function JsonPanel({
  title,
  value,
  emptyMessage,
}) {
  return (
    <AdminPanel
      title={
        title
      }

      compact
    >
      {
        value ===
        null
          ? (
              <AdminMetaText as="div">
                {emptyMessage}
              </AdminMetaText>
            )
          : (
              <pre className="admin-code-block">
                {
                  JSON.stringify(
                    value,
                    null,
                    2
                  )
                }
              </pre>
            )
      }
    </AdminPanel>
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


function normalizeJson(
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
      (character) =>
        character
          .toUpperCase()
    );
}
