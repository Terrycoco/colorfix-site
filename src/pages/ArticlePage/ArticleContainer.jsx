import "./ArticleContainer.css";

/** Shared responsive canvas for all future ArticlePage editorial components. */
export default function ArticleContainer({ children }) {
  return (
    <main className="cf-article-canvas">
      <div className="cf-article-container">{children}</div>
    </main>
  );
}
