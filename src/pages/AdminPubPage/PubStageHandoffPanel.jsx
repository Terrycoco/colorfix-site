export default function PubStageHandoffPanel({
  stage,
  nextStage,

  contract,

  values = {},
  onChangeValue,

  requirements = [],

  onHelper,

  canSend = false,
  sending = false,

  onSend,
}) {
  if (!contract) {
    return null;
  }

  return (
    <div style={panelStyle}>
      <div style={headerStyle}>
        Send to {String(nextStage || "").toUpperCase()}
      </div>

      <div style={bodyStyle}>
        {(contract.sharedFields || []).length ? (
          <section style={sectionStyle}>
            <div style={sectionHeaderStyle}>
              Shared Fields
            </div>

            {(contract.sharedFields || []).map(
              (field) => {
                const value =
                  values[field.key] || "";

                return (
                  <div
                    key={field.key}
                    style={fieldRowStyle}
                  >
                    <div style={fieldLabelStyle}>
                      {field.label}

                      {field.requiredAtHandoff ? (
                        <span style={requiredStyle}>
                          *
                        </span>
                      ) : null}
                    </div>

                    <div style={fieldControlStyle}>
                      <input
                        type={
                          field.type === "url"
                            ? "url"
                            : "text"
                        }
                        value={value}
                        onChange={(event) =>
                          onChangeValue?.(
                            field.key,
                            event.target.value
                          )
                        }
                        style={inputStyle}
                      />

                      {field.helper ? (
                        <button
                          type="button"
                          onClick={() =>
                            onHelper?.(
                              field.helper.key,
                              field
                            )
                          }
                        >
                          {field.helper.label}
                        </button>
                      ) : null}
                    </div>
                  </div>
                );
              }
            )}
          </section>
        ) : null}

        <section style={sectionStyle}>
          <div style={sectionHeaderStyle}>
            Requirements
          </div>

          {requirements.length ? (
            requirements.map((item) => (
              <div
                key={item.key}
                style={requirementRowStyle}
              >
                <span>
                  {item.label}
                </span>

                <span
                  style={{
                    ...requirementStatusStyle,
                    color: item.ok
                      ? "#2f6f44"
                      : "#9b1c1c",
                  }}
                >
                  {item.ok
                    ? "✓"
                    : "Missing"}
                </span>
              </div>
            ))
          ) : (
            <div style={emptyStyle}>
              No requirements defined.
            </div>
          )}
        </section>
      </div>

      <div style={footerStyle}>
        <button
          type="button"
          onClick={onSend}
          disabled={
            !canSend ||
            sending
          }
        >
          {sending
            ? "Sending..."
            : `Send to ${String(
                nextStage || ""
              ).toUpperCase()}`}
        </button>
      </div>
    </div>
  );
}

const panelStyle = {
  height: "100%",
  minHeight: 0,

  display: "flex",
  flexDirection: "column",
};

const headerStyle = {
  flexShrink: 0,

  padding: "10px 12px",

  borderBottom:
    "1px solid var(--admin-layout-border, #d8dde3)",

  fontSize: 14,
  fontWeight: 700,
};

const bodyStyle = {
  flex: 1,
  minHeight: 0,

  overflow: "auto",
};

const sectionStyle = {
  padding: 10,

  borderBottom:
    "1px solid var(--admin-layout-border, #d8dde3)",
};

const sectionHeaderStyle = {
  marginBottom: 8,

  color: "#4b6b8a",

  fontSize: 10,
  fontWeight: 700,

  letterSpacing: ".06em",
  textTransform: "uppercase",
};

const fieldRowStyle = {
  marginBottom: 10,
};

const fieldLabelStyle = {
  marginBottom: 4,

  fontSize: 12,
  fontWeight: 600,
};

const requiredStyle = {
  marginLeft: 3,

  color: "#9b1c1c",
};

const fieldControlStyle = {
  display: "flex",
  gap: 6,
};

const inputStyle = {
  flex: 1,
  minWidth: 0,

  boxSizing: "border-box",

  padding: "5px 6px",
};

const requirementRowStyle = {
  display: "flex",
  alignItems: "center",
  justifyContent: "space-between",

  gap: 10,

  padding: "5px 0",

  borderBottom:
    "1px solid #eceff1",

  fontSize: 12,
};

const requirementStatusStyle = {
  flexShrink: 0,

  fontSize: 11,
  fontWeight: 700,
};

const emptyStyle = {
  color: "#6b7280",
  fontSize: 12,
};

const footerStyle = {
  flexShrink: 0,

  display: "flex",
  justifyContent: "flex-end",

  padding: 10,

  borderTop:
    "1px solid var(--admin-layout-border, #d8dde3)",

  background: "#f7f8fa",
};