import {
  useState,
} from "react";

import {
  AdminWorkbenchDrawer,
} from "@components/AdminLayout";


export default function PubDispatchDrawer({
  open,
  asset,
  loading = false,
  error = "",
  retrying = false,
  onRetry,
  onClose,
}) {
  const [
    copiedUrl,
    setCopiedUrl,
  ] = useState(
    false
  );


  const packageValue =
    normalizeJson(
      asset?.package
    );

  const receiptValue =
    normalizeJson(
      asset
        ?.shipping_receipt
    );

  const externalId =
    receiptValue
      && typeof receiptValue ===
        "object"
      ? receiptValue
          .external_id ||
        "—"
      : "—";

  const externalUrl =
    receiptValue
      && typeof receiptValue ===
        "object"
      ? receiptValue
          .external_url ||
        ""
      : "";

  const title =
    asset
      ?.pub_asset_id
      ? `Dispatch · Asset #${asset.pub_asset_id}`
      : "Dispatch";


  const canRetryShipping =
    String(
      asset?.pipeline_stage ||
      ""
    )
      .trim()
      .toLowerCase() ===
      "error"
    &&
    String(
      asset?.error_stage ||
      ""
    )
      .trim()
      .toLowerCase() ===
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


  return (
    <AdminWorkbenchDrawer
      open={
        open
      }

      width={
        500
      }

      title={
        title
      }

      onClose={
        onClose
      }
    >
      <div
        style={
          bodyStyle
        }
      >
        {loading ? (
          <div
            style={
              mutedStyle
            }
          >
            Loading Dispatch details...
          </div>
        ) : error ? (
          <div
            style={
              errorStyle
            }
          >
            {error}
          </div>
        ) : asset ? (
          <>
            <Section
              title="Asset"
            >
              <DetailRow
                label="Channel"
                value={
                  humanize(
                    asset.channel
                  )
                }
              />

              <DetailRow
                label="Type"
                value={
                  humanize(
                    asset.asset_type
                  )
                }
              />

              <DetailRow
                label="Source"
                value={
                  formatSource(
                    asset
                  )
                }
              />

              <DetailRow
                label="Title"
                value={
                  asset.search_title ||
                  "—"
                }
              />
            </Section>


            <Section
              title="Status"
            >
              <DetailRow
                label="Stage"
                value={
                  humanize(
                    asset
                      .pipeline_stage
                  )
                }
              />

              <DetailRow
                label="Dispatched"
                value={
                  asset
                    .dispatched_at ||
                  "—"
                }
              />

              <DetailRow
                label="Stage Note"
                value={
                  asset
                    .stage_note ||
                  "—"
                }
              />

              <DetailRow
                label="Updated"
                value={
                  asset
                    .updated_at ||
                  "—"
                }
              />
            </Section>


            <Section
              title="Result"
            >
              <DetailRow
                label="External ID"
                value={
                  externalId
                }
              />

              <DetailRow
                label="Live URL"
                value={
                  externalUrl
                    ? (
                        <div
                          style={
                            liveUrlStyle
                          }
                        >
                          <a
                            href={
                              externalUrl
                            }

                            target="_blank"

                            rel="noreferrer"

                            style={
                              liveUrlLinkStyle
                            }
                          >
                            {
                              externalUrl
                            }
                          </a>

                          <button
                            type="button"

                            onClick={
                              copyLiveUrl
                            }

                            title={
                              copiedUrl
                                ? "Copied"
                                : "Copy live URL"
                            }

                            aria-label="Copy live URL"

                            style={
                              copyButtonStyle
                            }
                          >
                            {
                              copiedUrl
                                ? "✓"
                                : "⧉"
                            }
                          </button>
                        </div>
                      )
                    : "—"
                }
              />
            </Section>


            {
              asset.error_stage
              ||
              asset.error_code
              ||
              asset.error_message
                ? (
                    <Section
                      title="Dispatch Error"
                    >
                      <DetailRow
                        label="Stage"
                        value={
                          asset.error_stage ||
                          "—"
                        }
                      />

                      <DetailRow
                        label="Code"
                        value={
                          asset.error_code ||
                          "—"
                        }
                      />

                      <DetailRow
                        label="Message"
                        value={
                          asset.error_message ||
                          "—"
                        }
                      />

                      <DetailRow
                        label="At"
                        value={
                          asset.errored_at ||
                          "—"
                        }
                      />

                      {canRetryShipping ? (
                        <div
                          style={
                            actionStyle
                          }
                        >
                          <button
                            type="button"

                            disabled={
                              retrying
                            }

                            onClick={() => {
                              onRetry?.(
                                asset.pub_asset_id
                              );
                            }}
                          >
                            {
                              retrying
                                ? "Retrying..."
                                : "Retry Shipping"
                            }
                          </button>
                        </div>
                      ) : null}
                    </Section>
                  )
                : null
            }


            <JsonSection
              title="Outbound Package"

              emptyMessage="No outbound package is stored."

              value={
                packageValue
              }
            />


            <JsonSection
              title="Returned Shipping Receipt"

              emptyMessage="No shipping receipt has been returned."

              value={
                receiptValue
              }
            />
          </>
        ) : (
          <div
            style={
              mutedStyle
            }
          >
            Select a Dispatch row.
          </div>
        )}
      </div>
    </AdminWorkbenchDrawer>
  );
}


function Section({
  title,
  children,
}) {
  return (
    <section
      style={
        sectionStyle
      }
    >
      <div
        style={
          sectionTitleStyle
        }
      >
        {title}
      </div>

      <div
        style={
          sectionBodyStyle
        }
      >
        {children}
      </div>
    </section>
  );
}


function JsonSection({
  title,
  value,
  emptyMessage,
}) {
  return (
    <Section
      title={
        title
      }
    >
      {
        value === null
          ? (
              <div
                style={
                  noJsonStyle
                }
              >
                {emptyMessage}
              </div>
            )
          : (
              <pre
                style={
                  jsonStyle
                }
              >
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
    </Section>
  );
}


function DetailRow({
  label,
  value,
}) {
  return (
    <div
      style={
        detailRowStyle
      }
    >
      <div
        style={
          detailLabelStyle
        }
      >
        {label}
      </div>

      <div
        style={
          detailValueStyle
        }
      >
        {value || "—"}
      </div>
    </div>
  );
}


function normalizeJson(
  value
) {
  if (
    value === null
    ||
    value === undefined
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


const bodyStyle = {
  padding:
    14,
};


const sectionStyle = {
  marginBottom:
    18,
};


const sectionTitleStyle = {
  marginBottom:
    7,

  color:
    "#526273",

  fontSize:
    11,

  fontWeight:
    800,

  letterSpacing:
    "0.05em",

  textTransform:
    "uppercase",
};


const sectionBodyStyle = {
  border:
    "1px solid #d8dde3",

  borderRadius:
    4,

  background:
    "#ffffff",
};


const detailRowStyle = {
  display:
    "grid",

  gridTemplateColumns:
    "110px minmax(0, 1fr)",

  gap:
    10,

  padding:
    "8px 10px",

  borderBottom:
    "1px solid #edf0f2",
};


const detailLabelStyle = {
  color:
    "#64748b",

  fontSize:
    11,

  fontWeight:
    700,
};


const detailValueStyle = {
  minWidth:
    0,

  overflowWrap:
    "anywhere",

  color:
    "#273444",

  fontSize:
    12,

  lineHeight:
    1.4,
};


const liveUrlStyle = {
  display:
    "flex",

  alignItems:
    "center",

  gap:
    7,

  minWidth:
    0,
};


const liveUrlLinkStyle = {
  minWidth:
    0,

  overflowWrap:
    "anywhere",
};


const copyButtonStyle = {
  flex:
    "0 0 auto",

  minWidth:
    28,

  padding:
    "2px 6px",

  border:
    "1px solid #d8dde3",

  borderRadius:
    4,

  background:
    "#ffffff",

  color:
    "#526273",

  fontSize:
    14,

  lineHeight:
    1.2,

  cursor:
    "pointer",
};


const actionStyle = {
  display:
    "flex",

  justifyContent:
    "flex-end",

  padding:
    "10px",

  borderTop:
    "1px solid #edf0f2",

  background:
    "#fafbfc",
};


const jsonStyle = {
  margin:
    0,

  padding:
    12,

  overflow:
    "auto",

  whiteSpace:
    "pre-wrap",

  overflowWrap:
    "anywhere",

  background:
    "#f7f9fa",

  color:
    "#263443",

  fontFamily:
    "ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace",

  fontSize:
    11,

  lineHeight:
    1.5,
};


const noJsonStyle = {
  padding:
    12,

  color:
    "#64748b",

  fontSize:
    12,
};


const errorStyle = {
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


const mutedStyle = {
  color:
    "#64748b",

  fontSize:
    12,
};
