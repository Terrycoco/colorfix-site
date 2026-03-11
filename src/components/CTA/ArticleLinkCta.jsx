import "./cta.css";

export default function ArticleLinkCta({ cta, onClick, disabled = false }) {
  if (!cta) return null;
  if (cta.enabled === false) return null;

  const params = cta.params || {};
  const articleId = params.article_id || params.articleId;
  const href = params.url || (articleId ? `/articles/${articleId}` : "#");
  const title = params.title || params.label || cta.label || "Read article";
  const dek = params.dek || params.subtitle || "";
  const prefix = params.prefix ?? "";
  const normalizedPrefix = String(prefix || "").trim();
  const normalizedTitle = String(title || "").trim();
  const startsWithPrefix = normalizedPrefix
    ? normalizedTitle.toLowerCase().startsWith(normalizedPrefix.toLowerCase())
    : false;
  const startsWithRead = normalizedTitle.toLowerCase().startsWith("read");
  const shouldShowPrefix = Boolean(normalizedPrefix) && !startsWithPrefix && !(startsWithRead && normalizedPrefix.toLowerCase().startsWith("read"));

  return (
    <a
      href={href}
      className={`cta-article-link${disabled ? " is-disabled" : ""}`}
      onClick={(event) => {
        if (onClick) {
          event.preventDefault();
          if (!disabled) onClick(cta);
        }
      }}
      aria-disabled={disabled ? "true" : undefined}
    >
      <div className="cta-article-link__title">
        {shouldShowPrefix && (
          <span className="cta-article-link__prefix">{normalizedPrefix}</span>
        )}{" "}
        {title}
      </div>
      {dek && <div className="cta-article-link__dek">{dek}</div>}
    </a>
  );
}
