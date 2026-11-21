export default function ArticleByline({ publishedAt }) {
  if (!publishedAt) return null;
  const iso = publishedAt.replace(" ", "T") + (/[zZ]|[+-]\d{2}:\d{2}$/.test(publishedAt) ? "" : "Z");
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return null;
  return <p className="cf-article-byline">By Terry Marr <span aria-hidden="true">·</span> <time dateTime={iso}>{date.toLocaleDateString("en-US", { year: "numeric", month: "long", day: "numeric", timeZone: "UTC" })}</time></p>;
}
