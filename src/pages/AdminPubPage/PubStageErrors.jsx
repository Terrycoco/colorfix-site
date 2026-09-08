export default function PubStageErrors({
  errors = [],
}) {
  if (!errors.length) {
    return null;
  }

  return (
    <div style={containerStyle}>
      <div style={titleStyle}>
        PUB needs attention
      </div>

      {errors.map(
        (error, index) => {
          const details =
            Array.isArray(
              error?.details
            )
              ? error.details
              : [];


          return (
            <div
              key={
                error.code
                  ? `${error.code}-${index}`
                  : index
              }
              style={errorRowStyle}
            >
              <div style={messageStyle}>
                {error.message ||
                  "An unknown PUB error occurred."}
              </div>

              {details.length ? (
                <div style={detailsListStyle}>
                  {details.map(
                    (
                      detail,
                      detailIndex
                    ) => (
                      <FailureDetail
                        key={
                          detail
                            ?.pub_asset_id ||
                          `${index}-${detailIndex}`
                        }
                        detail={
                          detail
                        }
                      />
                    )
                  )}
                </div>
              ) : null}
            </div>
          );
        }
      )}
    </div>
  );
}


function FailureDetail({
  detail,
}) {
  const outputLabel =
    String(
      detail?.output_label ||
      humanize(
        detail?.asset_type
      ) ||
      "Asset"
    ).trim();

  const searchTitle =
    String(
      detail?.search_title ||
      ""
    ).trim();

  const errorMessage =
    String(
      detail?.error ||
      detail?.message ||
      "CREATE failed."
    ).trim();

  const meta = [];


  if (
    detail?.pub_asset_id
  ) {
    meta.push(
      `Asset #${detail.pub_asset_id}`
    );
  }


  if (
    detail?.batch_index !==
      null &&
    detail?.batch_index !==
      undefined
  ) {
    meta.push(
      `Batch item ${Number(detail.batch_index) + 1}`
    );
  }


  if (
    detail?.sort_order !==
      null &&
    detail?.sort_order !==
      undefined
  ) {
    meta.push(
      `Order ${detail.sort_order}`
    );
  }


  if (
    detail?.source_type &&
    detail?.source_id
  ) {
    meta.push(
      `${humanize(detail.source_type)} #${detail.source_id}`
    );
  }


  return (
    <div style={failureCardStyle}>
      <div style={failureTitleStyle}>
        {outputLabel}
        {searchTitle
          ? ` — “${searchTitle}”`
          : ""}
      </div>

      {meta.length ? (
        <div style={failureMetaStyle}>
          {meta.join(
            " · "
          )}
        </div>
      ) : null}

      <div style={failureMessageStyle}>
        {errorMessage}
      </div>
    </div>
  );
}


function humanize(
  value
) {
  const raw =
    String(
      value ||
      ""
    ).trim();


  if (!raw) {
    return "";
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


const containerStyle = {
  margin: "10px 0",
  border:
    "1px solid #040404",
  background: "#d98383",
};

const titleStyle = {
  padding: "7px 9px",
  borderBottom:
    "1px solid #e6c3c3",
  fontSize: 12,
  fontWeight: 700,
  color: "#8a1c1c",
};

const errorRowStyle = {
  padding: "7px 9px",
  borderBottom:
    "1px solid #f0dcdc",
  fontSize: 12,
  lineHeight: 1.4,
  color: "#7a1b1b",
};

const messageStyle = {
  fontWeight: 700,
};

const detailsListStyle = {
  display: "grid",
  gap: 7,
  marginTop: 8,
};

const failureCardStyle = {
  padding: "8px 9px",
  border:
    "1px solid rgba(122, 27, 27, 0.28)",
  background:
    "rgba(255, 255, 255, 0.35)",
};

const failureTitleStyle = {
  fontWeight: 800,
};

const failureMetaStyle = {
  marginTop: 2,
  fontSize: 11,
  opacity: 0.82,
};

const failureMessageStyle = {
  marginTop: 5,
  fontWeight: 600,
};
