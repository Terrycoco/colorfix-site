export default function AdminMetaText({
  children,
  tone = "muted",
  className = "",
  as: Tag = "span",
}) {
  return (
    <Tag
      className={[
        "admin-meta-text",
        tone ? `admin-meta-text--${tone}` : "",
        className,
      ].filter(Boolean).join(" ")}
    >
      {children}
    </Tag>
  );
}
