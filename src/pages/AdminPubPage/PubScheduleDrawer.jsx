export default function PubScheduleDrawer({
  asset,
  loading = false,
  error = "",
  changingStage = false,
  sending = false,
  onEnqueue,
  onDequeue,
  onSendNow,
}) {
  const stage =
    String(
      asset?.pipeline_stage ||
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


  return (
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
          Loading Schedule details...
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
              label="Updated"
              value={
                asset.updated_at ||
                "—"
              }
            />


            {canEnqueue ? (
              <div
                style={
                  actionStyle
                }
              >
                <button
                  type="button"

                  disabled={
                    changingStage ||
                    sending
                  }

                  onClick={() => {
                    onEnqueue?.(
                      asset.pub_asset_id
                    );
                  }}
                >
                  {
                    changingStage
                      ? "Queueing..."
                      : "Queue"
                  }
                </button>
              </div>
            ) : null}


            {canDequeue ? (
              <div
                style={
                  actionStyle
                }
              >
                <button
                  type="button"

                  disabled={
                    changingStage ||
                    sending
                  }

                  onClick={() => {
                    onDequeue?.(
                      asset.pub_asset_id
                    );
                  }}
                >
                  {
                    changingStage
                      ? "Removing..."
                      : "Remove from Queue"
                  }
                </button>
              </div>
            ) : null}


            {canSendNow ? (
              <div
                style={
                  sendActionStyle
                }
              >
                <button
                  type="button"

                  disabled={
                    sending ||
                    changingStage
                  }

                  onClick={() => {
                    onSendNow?.(
                      asset.pub_asset_id
                    );
                  }}
                >
                  {
                    sending
                      ? "Sending..."
                      : "Send Now"
                  }
                </button>
              </div>
            ) : null}
          </Section>


          <Section
            title="Schedule Meaning"
          >
            <DetailRow
              label="Packed"
              value="Sealed and held outside automatic scheduling."
            />

            <DetailRow
              label="Queued"
              value="Released into Schedule's automatic candidate pool."
            />
          </Section>
        </>
      ) : (
        <div
          style={
            mutedStyle
          }
        >
          Select a Schedule row.
        </div>
      )}
    </div>
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


const sendActionStyle = {
  ...actionStyle,

  background:
    "#f7f9fb",
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
