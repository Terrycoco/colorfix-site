import fs from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import * as d3 from "d3";
import wheel300 from "../src/components/ColorWheel/wheel-300-svg.js";
import fetchCategories from "../src/data/fetchCategories.js";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const rootDir = path.resolve(__dirname, "..");
const outDir = path.join(rootDir, "public", "wheels");

const WHEEL_SIZE = 300;
const PADDING = 60;
const OUTER = WHEEL_SIZE + PADDING * 2;
const CENTER = WHEEL_SIZE / 2;

const slugify = (value) =>
  String(value || "")
    .toLowerCase()
    .replace(/\s+/g, "-")
    .replace(/[^a-z0-9\-]/g, "")
    .replace(/\-+/g, "-")
    .replace(/^\-+|\-+$/g, "") || "cat";

const isEven = (n) => n % 2 === 0;

const buildLabelArcs = (categories) => {
  const arcs = categories.map((cat, i) => {
  const startAngle = (Number(cat.hue_min) * Math.PI) / 180;
    const endAngle = (Number(cat.hue_max) * Math.PI) / 180;
    const innerRadius = !isEven(i) ? WHEEL_SIZE * 0.3 : WHEEL_SIZE * 0.388;
    const outerRadius = !isEven(i) ? WHEEL_SIZE * 0.388 : WHEEL_SIZE * 0.476;
    const fontSize = `${WHEEL_SIZE * 0.036}px`;
    const arcGen = d3
      .arc()
      .innerRadius(innerRadius)
      .outerRadius(outerRadius)
      .startAngle(startAngle)
      .endAngle(endAngle);

    const pathId = `label-${i}-${slugify(cat.name)}`;
    const label = String(cat.name || "").trim();
    const fill = cat.wheel_text_color || "#222";

    return [
      `<path class="label-path" id="${pathId}" d="${arcGen()}" fill="none" stroke="#000"/>`,
      `<text class="cat-text" dy="17">` +
        `<textPath xlink:href="#${pathId}" startOffset="2%" style="fill:${fill};font-size:${fontSize}">` +
        `${label}` +
        `</textPath>` +
      `</text>`
    ].join("");
  });

  return `<g class="label-group" transform="translate(${CENTER}, ${CENTER})">${arcs.join("")}</g>`;
};

const buildDegreeTicks = () => {
  const majorDegrees = [0, 90, 180, 270];
  const minorDegrees = [45, 135, 225, 315];
  const baseRadius = 150;
  const majorLen = 14;
  const minorLen = 10;
  const labelRadius = baseRadius + 32;
  const ticks = [];

  const lineFor = (deg, len, isMajor) => {
    const angle = ((deg - 90) * Math.PI) / 180;
    const cos = Math.cos(angle);
    const sin = Math.sin(angle);
    const x1 = (baseRadius - len) * cos;
    const y1 = (baseRadius - len) * sin;
    const x2 = (baseRadius + len) * cos;
    const y2 = (baseRadius + len) * sin;
    const dash = isMajor ? ' stroke-dasharray="4,4"' : '';
    const strokeW = isMajor ? 1.8 : 1;
    return `<line x1="${x1.toFixed(2)}" y1="${y1.toFixed(2)}" x2="${x2.toFixed(2)}" y2="${y2.toFixed(2)}" stroke="#222" stroke-width="${strokeW}"${dash} />`;
  };

  const labelFor = (deg, isMajor) => {
    const angle = ((deg - 90) * Math.PI) / 180;
    const cos = Math.cos(angle);
    const sin = Math.sin(angle);
    const x = labelRadius * cos;
    const y = labelRadius * sin;
    const weight = isMajor ? 700 : 400;
    return `<text x="${x.toFixed(2)}" y="${y.toFixed(2)}" text-anchor="middle" dominant-baseline="middle" font-size="10" font-family="Lato, Open Sans, sans-serif" font-weight="${weight}" fill="#222">${deg}\u00B0</text>`;
  };

  majorDegrees.forEach((deg) => {
    // major: dashed line from center to tick
    const angle = ((deg - 90) * Math.PI) / 180;
    const cos = Math.cos(angle);
    const sin = Math.sin(angle);
    const x2 = (baseRadius + majorLen) * cos;
    const y2 = (baseRadius + majorLen) * sin;
    ticks.push(
      `<line x1="0" y1="0" x2="${x2.toFixed(2)}" y2="${y2.toFixed(2)}" stroke="#222" stroke-width="1.6" stroke-dasharray="4,4" />`
    );
    ticks.push(lineFor(deg, majorLen, true));
    ticks.push(labelFor(deg, true));
  });
  minorDegrees.forEach((deg) => {
    ticks.push(lineFor(deg, minorLen, false));
    ticks.push(labelFor(deg, false));
  });

  return `<g class="degree-layer" transform="translate(${CENTER}, ${CENTER})">${ticks.join("")}</g>`;
};

const buildSvg = ({ categories, includeDegrees }) => {
  const labels = buildLabelArcs(categories);
  const degrees = includeDegrees ? buildDegreeTicks() : "";
  return [
    `<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="${OUTER}" height="${OUTER}" viewBox="0 0 ${OUTER} ${OUTER}">`,
    `<g transform="translate(${PADDING}, ${PADDING})">`,
    wheel300,
    labels,
    degrees,
    `</g>`,
    `</svg>`,
  ].join("");
};

const loadCategories = async (filePath) => {
  if (!filePath) {
    const cats = await fetchCategories();
    return cats.filter((c) => c.type === "hue" && c.hue_min != null && c.hue_max != null);
  }
  const raw = await fs.readFile(path.resolve(rootDir, filePath), "utf8");
  const json = JSON.parse(raw);
  const rows = Array.isArray(json) ? json : json.data || [];
  return rows.filter((c) => String(c.type || "").toLowerCase() === "hue" && c.hue_min != null && c.hue_max != null);
};

const run = async () => {
  const arg = process.argv.find((v) => v.startsWith("--categories="));
  const filePath = arg ? arg.split("=").slice(1).join("=") : null;

  const categories = await loadCategories(filePath);
  if (!categories.length) {
    throw new Error("No hue categories found. Provide --categories=path/to/categories.json");
  }

  await fs.mkdir(outDir, { recursive: true });

  const labelsSvg = buildSvg({ categories, includeDegrees: false });
  const degreesSvg = buildSvg({ categories, includeDegrees: true });

  await fs.writeFile(path.join(outDir, "wheel-300-labels.svg"), labelsSvg, "utf8");
  await fs.writeFile(path.join(outDir, "wheel-300-labels-degrees.svg"), degreesSvg, "utf8");

  console.log("Wrote:");
  console.log(`- ${path.join(outDir, "wheel-300-labels.svg")}`);
  console.log(`- ${path.join(outDir, "wheel-300-labels-degrees.svg")}`);
};

run().catch((err) => {
  console.error(err?.message || err);
  process.exit(1);
});
