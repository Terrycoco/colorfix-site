import "./ArticleGuide.css";
import ArticleByline from "./ArticleByline";

export function editorialParagraphs(value) {
  return String(value ?? "").split(/\n\s*\n/).map((s) => s.trim()).filter(Boolean);
}

export function editorialInline(value) {
  const source = String(value ?? "");
  const parts = source.split(/(<\/?(?:em|i|strong|b)>)/i);
  const nodes = [];
  let emphasis = null;
  let content = [];
  let key = 0;
  const append = (node) => {
    if (emphasis) content.push(node);
    else nodes.push(node);
  };
  for (const part of parts) {
    const match = part.match(/^<(\/)?(em|i|strong|b)>$/i);
    if (!match) {
      if (part) append(part);
      continue;
    }
    if (!match[1] && !emphasis) {
      emphasis = /^(em|i)$/i.test(match[2]) ? "em" : "strong";
      content = [];
    } else if (match[1] && emphasis) {
      nodes.push(emphasis === "em"
        ? <em key={key++}>{content}</em>
        : <strong key={key++}>{content}</strong>);
      emphasis = null;
      content = [];
    }
  }
  if (emphasis) nodes.push(...content);
  return nodes;
}

export default function ArticleText({ section, concluding = false, publishedAt }) {
  if (!section) return null;
  const body = editorialParagraphs(section.body);
  if (!section.heading && body.length === 0) return null;
  return (
    <section className={`cf-article-guide-text${concluding ? " cf-article-guide-text--closing" : ""}`}>
      <div className="cf-article-guide-text__inner">
        {publishedAt ? <ArticleByline publishedAt={publishedAt} /> : null}
        {section.heading ? <h2 className="cf-article-guide-text__heading">{section.heading}</h2> : null}
        {body.map((p, i) => <p className="cf-article-guide-text__paragraph" key={i}>{editorialInline(p)}</p>)}
      </div>
    </section>
  );
}
