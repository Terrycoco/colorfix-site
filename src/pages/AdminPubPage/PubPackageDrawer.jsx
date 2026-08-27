import {
  AdminWorkbenchDrawer,
} from "@components/AdminLayout";


export default function PubPackageDrawer({
  open,
  asset,
  loading = false,
  error = "",
  onClose,
}) {
  const packageValue =
    normalizePackage(
      asset?.package
    );


  const title =
    asset
      ?.pub_asset_id
      ? `Package · Asset #${asset.pub_asset_id}`
      : "Package";


  return (
    <AdminWorkbenchDrawer
      open={
        open
      }

      width={
        440
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
            Loading Package details...
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
                    asset.pipeline_stage
                  )
                }
              />

              <DetailRow
                label="Stage Note"
                value={
                  asset.stage_note ||
                  "—"
                }
              />

              <DetailRow
                label="Updated"
                value={
                  asset.updated_at ||
                  "—"
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
                      title="Error"
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
                    </Section>
                  )
                : null
            }


            <Section
              title="Package"
            >
              {
                packageValue === null
                  ? (
                      <div
                        style={
                          noPackageStyle
                        }
                      >
                        No package has been built.
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
                            packageValue,
                            null,
                            2
                          )
                        }
                      </pre>
                    )
              }
            </Section>
          </>
        ) : (
          <div
            style={
              mutedStyle
            }
          >
            Select a Package row.
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


function normalizePackage(
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
    "105px minmax(0, 1fr)",

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


const noPackageStyle = {
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
