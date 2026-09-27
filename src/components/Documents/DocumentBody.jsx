import BlackAnimated from "@components/Logos/BlackAnimated";
import "./Document.css";

export default function DocumentBody({
  html = "",
  className = "",
  preview = false,
  letterhead = false,
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
    <div className={classes}>
      {letterhead ? (
        <header className="document-sheet__letterhead">
          <BlackAnimated className="document-sheet__logo" />
        </header>
      ) : null}

      <div
        className="document-sheet__body"
        dangerouslySetInnerHTML={{
          __html: html,
        }}
      />
    </div>
  );
}
