import "./ArticleIntroduction.css";
import ArticleByline from "./ArticleByline";

/** First authored text section, following the visual hero. */
export default function ArticleIntroduction({ body, publishedAt }) {
  const paragraphs = String(body ?? "")
    .split(/\n\s*\n/)
    .map((part) => part.trim())
    .filter(Boolean);

  if (!paragraphs.length) return null;

  return (
    <section className="cf-article-introduction" aria-label="Introduction">
      <div className="cf-article-introduction__inner">
        <ArticleByline publishedAt={publishedAt} />
        {paragraphs.map((paragraph, index) => (
          <p
            className={index === 0
              ? "cf-article-introduction__paragraph cf-article-introduction__paragraph--lead"
              : "cf-article-introduction__paragraph"}
            key={index}
          >
            {paragraph}
          </p>
        ))}
      </div>
    </section>
  );
}
