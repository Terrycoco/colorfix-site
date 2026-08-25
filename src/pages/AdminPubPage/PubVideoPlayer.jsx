export default function PubVideoPlayer({
  src,
  poster = "",
  title = "Video preview",
}) {
  if (!src) {
    return (
      <div style={noPreviewStyle}>
        No video preview
      </div>
    );
  }

  return (
    <div style={shellStyle}>
      <video
        src={src}
        poster={poster || undefined}
        controls
        playsInline
        preload="metadata"
        aria-label={title}
        style={videoStyle}
      />
    </div>
  );
}


const shellStyle = {
  width: "100%",
  minWidth: 0,
  display: "grid",
  placeItems: "center",
  background: "#111111",
  border: "1px solid #d8dde3",
};


const videoStyle = {
  display: "block",
  width: "100%",
  maxHeight: "560px",
  objectFit: "contain",
  background: "#111111",
};


const noPreviewStyle = {
  minHeight: 360,
  display: "grid",
  placeItems: "center",
  background: "#111111",
  border: "1px solid #d8dde3",
  color: "#cbd5e1",
  fontSize: 13,
};