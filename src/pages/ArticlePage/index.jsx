import { useEffect, useMemo, useState } from "react";
import { useParams, useSearchParams } from "react-router-dom";
import { API_FOLDER } from "@helpers/config";
import ArticleContainer from "./ArticleContainer";
import ArticleHero from "./ArticleHero";
import ArticleInspiration from "./ArticleInspiration";
import ArticlePalette from "./ArticlePalette";
import ArticleRoomStudy from "./ArticleRoomStudy";

const GET_URL = `${API_FOLDER}/v2/articles/get.php`;
const photoId = (photo) => Number(photo?.photo_library_id ?? photo?.id ?? 0);

/** ArticlePage coordinates fetching and presentation. Other article sections are
 * intentionally excluded until their new presentation components are built. */
export default function ArticlePage() {
  const { id } = useParams();
  const [searchParams] = useSearchParams();
  const adminPreview = searchParams.get("admin") === "1";
  const [item, setItem] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    let active = true;
    const controller = new AbortController();

    async function load() {
      setLoading(true);
      setError("");
      setItem(null);
      try {
        if (!id) throw new Error("Missing article ID.");
        const qs = new URLSearchParams({ id: String(id), _: String(Date.now()) });
        if (adminPreview) qs.set("admin", "1");
        const response = await fetch(`${GET_URL}?${qs}`, {
          credentials: "include",
          signal: controller.signal,
        });
        if (!response.ok) throw new Error(`Article request failed (HTTP ${response.status}).`);
        const result = await response.json();
        if (!result?.ok) throw new Error(result?.error || "Article could not be loaded.");
        if (active) setItem(result.item || null);
      } catch (err) {
        if (active && err?.name !== "AbortError") setError(err?.message || "Article could not be loaded.");
      } finally {
        if (active) setLoading(false);
      }
    }

    load();
    return () => { active = false; controller.abort(); };
  }, [id, adminPreview]);

  const hero = useMemo(() => {
    const article = item?.article || {};
    const sections = Array.isArray(item?.sections) ? item.sections : [];
    const images = sections.filter((s) => s?.asset?.rel_path);
    const sourcePainting = images.find((s) =>
      /^(the\s+)?inspiration$/i.test(String(s?.heading || "").trim())
    )?.asset || null;
    const firstRoom = images.find((s) => s.asset !== sourcePainting &&
      (s.kind === "image_text" || s.kind === "image"))?.asset || null;

    const findPhoto = (id) => {
      const wanted = Number(id);
      if (!wanted) return null;
      const candidates = [item?.hero, item?.hero_mobile, item?.hero_inset, ...images.map((s) => s.asset)];
      return candidates.find((p) => p?.rel_path && photoId(p) === wanted) || null;
    };

    // Saved article-level selections win. Don't silently substitute an unrelated
    // photograph if the API has not enriched the selected Photo Library ID yet.
    const mainId = Number(article.hero_asset_id || 0);
    const insetId = Number(article.hero_inset_asset_id || 0);
    return {
      room: mainId ? (findPhoto(mainId) || item?.hero || null) : (firstRoom || item?.hero || null),
      painting: insetId ? findPhoto(insetId) : (Object.prototype.hasOwnProperty.call(article, "hero_inset_asset_id") ? null : sourcePainting),
      positions: article.hero_inset_positions,
    };
  }, [item]);

  // The first unheaded text section is the recipe's authored introduction.
  // Only the opening section is eligible; later unheaded text is not pulled forward.
  const introduction = useMemo(() => {
    const first = Array.isArray(item?.sections) ? item.sections[0] : null;
    return first?.kind === "text" && !String(first.heading ?? "").trim()
      ? String(first.body ?? "").trim()
      : "";
  }, [item]);

  // The recipe's first named Inspiration section supplies the original artwork,
  // notes and caption. Other sections remain hidden until their components exist.
  const inspiration = useMemo(() => {
    const sections = Array.isArray(item?.sections) ? item.sections : [];
    return sections.find((section) =>
      section?.kind === "image_text" &&
      /^(the\s+)?inspiration$/i.test(String(section?.heading || "").trim())
    ) || null;
  }, [item]);

  // Palette data is assembled by PALETTES through the ARTICLES public response.
  const paletteSection = useMemo(() => {
    const sections = Array.isArray(item?.sections) ? item.sections : [];
    return sections.find((section) => section?.kind === "palette_link" && Number(section?.palette_id) > 0) || null;
  }, [item]);

  // Room studies remain in article section order. Alternate the same editorial
  // component rather than introducing a separate layout for each room.
  const roomSections = useMemo(() => {
    const sections = Array.isArray(item?.sections) ? item.sections : [];
    const paletteIndex = sections.findIndex((section) => section?.kind === "palette_link");
    if (paletteIndex < 0) return [];
    return sections.filter((section, index) =>
      index > paletteIndex && section?.kind === "image_text" &&
      Number(section?.asset_id) > 0 &&
      !/^(the\s+)?inspiration$/i.test(String(section.heading || "").trim())
    );
  }, [item]);

  return (
    <ArticleContainer>
      {loading ? (
        <p className="cf-article-status" role="status">Loading article…</p>
      ) : error ? (
        <p className="cf-article-status" role="alert">{error}</p>
      ) : !item?.article ? (
        <p className="cf-article-status">Article not found.</p>
      ) : (
        <>
          <ArticleHero
            title={item.article.title}
            kicker={item.article.kicker}
            dek={item.article.dek}
            room={hero.room}
            painting={hero.painting}
            insetPositions={hero.positions}
          />
          {(introduction || inspiration) ? <ArticleInspiration introduction={introduction} section={inspiration} /> : null}
          {paletteSection ? <ArticlePalette section={paletteSection} /> : null}
          {roomSections.length > 0 ? (
            <>
              <header className="cf-article-room-collection">
                <h2 className="cf-article-room-collection__heading">
                  One Palette, {roomSections.length === 1 ? "One Interpretation" : `${roomSections.length} Interpretations`}
                </h2>
                <p className="cf-article-room-collection__dek">
                  The same colors take on different personalities depending on their proportions, placement, and which one commands attention.
                </p>
              </header>
              {roomSections.map((section, index) => (
                <ArticleRoomStudy
                  key={section.id ?? `${section.asset_id}-${index}`}
                  section={section}
                  reverse={index % 2 === 1}
                />
              ))}
            </>
          ) : null}
        </>
      )}
    </ArticleContainer>
  );
}
