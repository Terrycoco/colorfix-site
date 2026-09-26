import "./Document.css";

export default function DocumentBody({
  html = "",
  className = "",
  preview = false,
}) {
  const classes = [
    "document-sheet",
    preview
      ? "document-sheet--preview"
      : "",
    className,
  ]
    .filter(Boolean)
    .join(" ");

  return (
    <div
      className={classes}
      dangerouslySetInnerHTML={{
        __html: html,
      }}
    />
  );
}
