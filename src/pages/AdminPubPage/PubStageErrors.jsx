export default function PubStageErrors({
  errors = [],
}) {
  if (!errors.length) {
    return null;
  }

  return (
    <div style={containerStyle}>
      <div style={titleStyle}>
        PUB cannot continue
      </div>

      {errors.map(
        (error, index) => (
          <div
            key={
              error.code
                ? `${error.code}-${index}`
                : index
            }
            style={errorRowStyle}
          >
            {error.message ||
              "An unknown PUB error occurred."}
          </div>
        )
      )}
    </div>
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