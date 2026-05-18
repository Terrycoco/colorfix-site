import { useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import colorfixByTerryLightBgUrl from "../../assets/brand/colorfix_byterry_lightbg.png";
import "./admin-qr-sheets.css";

const INSTANCES_URL = `${API_FOLDER}/v2/admin/playlist-instances/list.php`;
const WATCH_GET_URL = `${API_FOLDER}/v2/admin/watch-config/get.php`;
const WATCH_SAVE_URL = `${API_FOLDER}/v2/admin/watch-config/save.php`;


function handlePrintSheet() {
  const sheet = document.querySelector(".admin-qr-sheets__sheet");
  if (!sheet) return;

  const printWindow = window.open("", "_blank", "width=1000,height=1400");
  if (!printWindow) return;

  printWindow.document.open();
  printWindow.document.write(`
    <!doctype html>
    <html>
      <head>
        <title>Print QR Sheet</title>
        <style>
          @page {
            size: 8.5in 11in;
            margin: 0;
          }

          html, body {
            margin: 0;
            padding: 0;
            width: 8.5in;
            height: 11in;
            background: #fff;
            overflow: hidden;
            font-family: Arial, Helvetica, sans-serif;
          }

          .admin-qr-sheets__sheet {
            width: 8.5in;
            height: 11in;
            background: #fff;
            box-sizing: border-box;
            display: grid;
            grid-template-columns: repeat(3, 30.3921569%);
            grid-template-rows: repeat(10, 9.0909091%);
            column-gap: 1.4705882%;
            row-gap: 0;
            padding: 4.5454545% 2.9411765%;
            border: none;
            border-radius: 0;
            transform: translate(-0.5in, -0.5in);
            transform-origin: top left;
          }

          .admin-qr-sheets__sheet-label {
            min-width: 0;
            min-height: 0;
            outline: 1px dashed #d8d8d8;
            outline-offset: -1px;
            overflow: hidden;
            background: #fff;
          }

          .admin-qr-sheets__sticker.admin-qr-sheets__sticker--compact {
            width: 100%;
            height: 100%;
            box-sizing: border-box;
            display: grid;
            grid-template-columns: 34% 1fr;
            gap: 6%;
            align-items: center;
            padding: 6% 6.5%;
            border: none;
            border-radius: 0;
            background: #fff;
          }

          .admin-qr-sheets__sticker-qr-wrap {
            display: flex;
            align-items: center;
            justify-content: center;
          }

          .admin-qr-sheets__sticker-qr {
            width: 100%;
            height: auto;
            aspect-ratio: 1 / 1;
            display: block;
          }

          .admin-qr-sheets__sticker-copy {
            min-width: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-self: center;
          }

          .admin-qr-sheets__sticker-eyebrow {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10pt;
            font-weight: 800;
            line-height: 1.16;
            letter-spacing: 0;
            margin: 0 0 10pt 0;
            color: #111827;
          }

          .admin-qr-sheets__sticker-brand {
            margin: -1pt 0 0 0;
          }

          .admin-qr-sheets__sticker-brand-image {
            width: 100%;
            max-width: 90pt;
            height: auto;
            display: block;
          }
        </style>
      </head>
      <body>
        ${sheet.outerHTML}
      </body>
    </html>
  `);
  printWindow.document.close();

  printWindow.focus();
  printWindow.onload = () => {
    printWindow.print();
    printWindow.close();
  };
}




const qrOptions = [
  {
    id: "watch-sticker",
    label: "Watch Sticker",
    headline: "Watch Real Transformations",
    url: "https://colorfix.terrymarr.com/watch",
    subline: "ColorFix",
    type: "sticker",
  },
  {
    id: "watch",
    label: "Watch Playlist",
    headline: "Watch ColorFix",
    url: "https://colorfix.terrymarr.com/watch",
    subline: "Scan to start the current featured playlist",
  },
  {
    id: "appt",
    label: "Appointments",
    headline: "Reserve Time with Terry",
    url: "https://outlook.office365.com/book/DunnEdwardsPaintsRanchoCucamonga@dunnedwards.com/?ismsaljsauthenabled=true",
    subline: "Scan to Book Your Color Consultation",
  },
  {
    id: "hoa",
    label: "HOA Landing Page",
    headline: "HOA Approved Colors System",
    url: "https://colorfix.terrymarr.com/hoa",
    subline: "Scan to learn about HOA playlists",
  },
  {
    id: "colorfix",
    label: "ColorFix",
    headline: "ColorFix",
    url: "https://colorfix.terrymarr.com",
    subline: "See Terry's Color Design Toolkit",
  },
  {
    id: "hue-wheel",
    label: "Hue Wheel (Print)",
    headline: "HCL Hue Wheel",
    url: "",
    subline: "Reference wheel with degree markers",
    type: "wheel",
    asset: "/wheels/wheel-300-labels-degrees.svg",
  },
];

function buildQrUrl(value, size = 600) {
  const encoded = encodeURIComponent(value.trim());
  return `https://api.qrserver.com/v1/create-qr-code/?size=${size}x${size}&data=${encoded}`;
}


function Avery15660SheetPreview({ url }) {
  const qrSrc = buildQrUrl(url || "https://colorfix.terrymarr.com/watch", 420);

  return (
    <div className="admin-qr-sheets__sheet-block">
      <div className="admin-qr-sheets__sheet-title">Sticker Preview</div>
      <div className="admin-qr-sheets__sheet-note">
        Single 1&quot; x 2.625&quot; label preview. Download PNG for the full print sheet.
      </div>

      <div className="admin-qr-sheets__single-preview">
        <div className="admin-qr-sheets__single-preview-label">
          <div className="admin-qr-sheets__sticker admin-qr-sheets__sticker--compact">
            <div className="admin-qr-sheets__sticker-qr-wrap">
              <img
                className="admin-qr-sheets__sticker-qr"
                src={qrSrc}
                alt=""
              />
            </div>

            <div className="admin-qr-sheets__sticker-copy">
              <div className="admin-qr-sheets__sticker-eyebrow">
                Watch Color<br />
                Before &amp; Afters
              </div>

              <div className="admin-qr-sheets__sticker-brand">
                <img
                  src={colorfixByTerryLightBgUrl}
                  alt="ColorFix by Terry"
                  className="admin-qr-sheets__sticker-brand-image"
                />
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

export default function AdminQrSheetsPage() {
  const [selectedId, setSelectedId] = useState(qrOptions[0].id);
  const [instances, setInstances] = useState([]);
  const [watchForm, setWatchForm] = useState({ playlist_instance_id: "" });
  const [watchLoading, setWatchLoading] = useState(true);
  const [watchSaving, setWatchSaving] = useState(false);
  const [watchStatus, setWatchStatus] = useState("");
  const [watchError, setWatchError] = useState("");
  const wheelVersion = useMemo(() => Date.now(), []);

  const selected = useMemo(
    () => qrOptions.find((option) => option.id === selectedId) || qrOptions[0],
    [selectedId]
  );
  const selectedWatchTarget = useMemo(
    () => instances.find((item) => String(item.playlist_instance_id) === String(watchForm.playlist_instance_id)) || null,
    [instances, watchForm.playlist_instance_id]
  );
  const watchSlugUrl = useMemo(() => {
    const playerUrl = selectedWatchTarget?.player_url || "";
    if (!playerUrl) return "https://colorfix.terrymarr.com/watch";
    if (/^https?:\/\//i.test(playerUrl)) return playerUrl;
    return `https://colorfix.terrymarr.com${playerUrl.startsWith("/") ? "" : "/"}${playerUrl}`;
  }, [selectedWatchTarget]);
  const selectedUrl = selected?.id === "watch" || selected?.id === "watch-sticker"
    ? watchSlugUrl
    : selected?.url;

  const wheelSrc =
    selected?.type === "wheel" ? `${selected.asset}?v=${wheelVersion}` : "";
 

  useEffect(() => {
    loadWatchControls();
  }, []);

  async function loadWatchControls() {
    setWatchLoading(true);
    setWatchError("");
    try {
      const [instancesRes, watchRes] = await Promise.all([
        fetch(`${INSTANCES_URL}?_=${Date.now()}`, { credentials: "include" }),
        fetch(`${WATCH_GET_URL}?_=${Date.now()}`, { credentials: "include" }),
      ]);
      const [instancesData, watchData] = await Promise.all([instancesRes.json(), watchRes.json()]);
      if (!instancesRes.ok || !instancesData?.ok) {
        throw new Error(instancesData?.error || "Failed to load playlist instances");
      }
      if (!watchRes.ok || !watchData?.ok) {
        throw new Error(watchData?.error || "Failed to load watch config");
      }

      const nextInstances = Array.isArray(instancesData.items) ? instancesData.items : [];
      nextInstances.sort((a, b) => {
        const aLabel = String(a?.instance_name || a?.display_title || "").trim();
        const bLabel = String(b?.instance_name || b?.display_title || "").trim();
        const byLabel = aLabel.localeCompare(bLabel, undefined, { numeric: true, sensitivity: "base" });
        if (byLabel !== 0) return byLabel;
        return Number(a?.playlist_instance_id || 0) - Number(b?.playlist_instance_id || 0);
      });
      setInstances(nextInstances);
      setWatchForm({
        playlist_instance_id: String(watchData?.item?.playlist_instance_id || ""),
      });
    } catch (err) {
      setWatchError(err?.message || "Failed to load watch controls");
    } finally {
      setWatchLoading(false);
    }
  }

  async function handleSaveWatchConfig() {
    setWatchStatus("");
    setWatchError("");
    try {
      setWatchSaving(true);
      const playlistInstanceId = Number(watchForm.playlist_instance_id || 0);
      if (!playlistInstanceId) {
        throw new Error("Pick a playlist instance");
      }

      const res = await fetch(WATCH_SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          playlist_instance_id: playlistInstanceId,
        }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || "Failed to save watch config");
      }
      setWatchStatus("Watch link updated");
    } catch (err) {
      setWatchError(err?.message || "Failed to save watch config");
    } finally {
      setWatchSaving(false);
    }
  }

async function handleDownloadPng() {
  function crc32(bytes) {
    let crc = 0xffffffff;
    for (let i = 0; i < bytes.length; i += 1) {
      crc ^= bytes[i];
      for (let j = 0; j < 8; j += 1) {
        const mask = -(crc & 1);
        crc = (crc >>> 1) ^ (0xedb88320 & mask);
      }
    }
    return (crc ^ 0xffffffff) >>> 0;
  }

  function writeUint32(arr, offset, value) {
    arr[offset] = (value >>> 24) & 255;
    arr[offset + 1] = (value >>> 16) & 255;
    arr[offset + 2] = (value >>> 8) & 255;
    arr[offset + 3] = value & 255;
  }

  function addPngDpi(dataUrl, dpi) {
    const base64 = dataUrl.split(",")[1];
    const binary = atob(base64);
    const original = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i += 1) {
      original[i] = binary.charCodeAt(i);
    }

    const ppm = Math.round(dpi / 0.0254);

    const chunkData = new Uint8Array(9);
    writeUint32(chunkData, 0, ppm);
    writeUint32(chunkData, 4, ppm);
    chunkData[8] = 1;

    const chunkType = new Uint8Array([112, 72, 89, 115]); // pHYs
    const crcInput = new Uint8Array(chunkType.length + chunkData.length);
    crcInput.set(chunkType, 0);
    crcInput.set(chunkData, chunkType.length);
    const crc = crc32(crcInput);

    const chunk = new Uint8Array(4 + 4 + 9 + 4);
    writeUint32(chunk, 0, 9);
    chunk.set(chunkType, 4);
    chunk.set(chunkData, 8);
    writeUint32(chunk, 17, crc);

    const insertAt = 33; // after PNG signature + IHDR chunk
    const out = new Uint8Array(original.length + chunk.length);
    out.set(original.slice(0, insertAt), 0);
    out.set(chunk, insertAt);
    out.set(original.slice(insertAt), insertAt + chunk.length);

    let result = "";
    for (let i = 0; i < out.length; i += 1) {
      result += String.fromCharCode(out[i]);
    }
    return `data:image/png;base64,${btoa(result)}`;
  }

  if (selected?.type === "sticker") {
    const dpi = 300;
    const pageWidth = Math.round(8.5 * dpi);
    const pageHeight = Math.round(11 * dpi);
    const printerMarginComp = Math.round(0.5 * dpi);
    const leftMargin = Math.round(0.25 * dpi);
    const rightMargin = Math.round(0.25 * dpi) - printerMarginComp;
    const topMargin = Math.round(0.5 * dpi) - printerMarginComp;
    const bottomMargin = Math.round(0.5 * dpi) - printerMarginComp;
    const columnGap = Math.round(0.125 * dpi);
    const labelWidth = Math.round((pageWidth - leftMargin - rightMargin - (columnGap * 2)) / 3);
    const labelHeight = Math.round(1 * dpi);
    const rowGap = Math.max(
      0,
      Math.round((pageHeight - topMargin - bottomMargin - (labelHeight * 10)) / 9) - 2
    );

    const canvas = document.createElement("canvas");
    canvas.width = pageWidth;
    canvas.height = pageHeight;
    const ctx = canvas.getContext("2d");
    if (!ctx) return;

    ctx.fillStyle = "#ffffff";
    ctx.fillRect(0, 0, pageWidth, pageHeight);

    const qrUrl = buildQrUrl(selectedUrl, 900);
    const qrImg = new Image();
    qrImg.crossOrigin = "anonymous";
    qrImg.src = qrUrl;
    const brandImg = new Image();
    brandImg.src = colorfixByTerryLightBgUrl;
    await new Promise((resolve, reject) => {
      qrImg.onload = resolve;
      qrImg.onerror = reject;
    });
    await new Promise((resolve, reject) => {
      brandImg.onload = resolve;
      brandImg.onerror = reject;
    });

    const innerPadX = Math.round(labelWidth * 0.065);
    const innerPadY = Math.round(labelHeight * 0.06);
    const qrColWidth = Math.round(labelWidth * 0.34);
    const contentGap = Math.round(labelWidth * 0.06);

    const qrBoxX = innerPadX;
    const qrBoxY = innerPadY;
    const qrBoxW = qrColWidth;
    const qrBoxH = labelHeight - innerPadY * 2;

    const qrSize = Math.min(qrBoxW, qrBoxH);
    const qrOffsetX = qrBoxX + Math.round((qrBoxW - qrSize) / 2);
    const qrOffsetY = qrBoxY + Math.round((qrBoxH - qrSize) / 2);

    const textXOffset = qrBoxX + qrColWidth + contentGap;
    const textWidth = labelWidth - textXOffset - innerPadX;

    const headlineFontPx = 40;
    const headlineLineGap = 42;
    const headlineTopOffset = 104;
    const brandBottomInset = 52;

    ctx.textAlign = "left";
    ctx.textBaseline = "alphabetic";

    for (let row = 0; row < 10; row += 1) {
      for (let col = 0; col < 3; col += 1) {
        const labelX = leftMargin + col * (labelWidth + columnGap);
        const labelY = topMargin + row * (labelHeight + rowGap);

        ctx.drawImage(qrImg, labelX + qrOffsetX, labelY + qrOffsetY, qrSize, qrSize);

        const textX = labelX + textXOffset;
        const line1Y = labelY + headlineTopOffset;
        const line2Y = line1Y + headlineLineGap;

        ctx.fillStyle = "#111827";
        ctx.font = `800 ${headlineFontPx}px Inter, Arial, sans-serif`;

        const line1Text = "Watch Color";
        const line2Text = "Before & Afters";
        const line1Scale = Math.min(1, textWidth / Math.max(ctx.measureText(line1Text).width, 1));
        const line2Scale = Math.min(1, textWidth / Math.max(ctx.measureText(line2Text).width, 1));

        if (line1Scale < 1) {
          ctx.save();
          ctx.translate(textX, line1Y);
          ctx.scale(line1Scale, 1);
          ctx.fillText(line1Text, 0, 0);
          ctx.restore();
        } else {
          ctx.fillText(line1Text, textX, line1Y);
        }

        if (line2Scale < 1) {
          ctx.save();
          ctx.translate(textX, line2Y);
          ctx.scale(line2Scale, 1);
          ctx.fillText(line2Text, 0, 0);
          ctx.restore();
        } else {
          ctx.fillText(line2Text, textX, line2Y);
        }

        const brandNaturalWidth = brandImg.naturalWidth || 1;
        const brandNaturalHeight = brandImg.naturalHeight || 1;
        const brandMaxWidth = Math.min(textWidth, Math.round(labelWidth * 0.24));
        const brandScale = brandMaxWidth / brandNaturalWidth;
        const brandDrawWidth = Math.round(brandNaturalWidth * brandScale);
        const brandDrawHeight = Math.round(brandNaturalHeight * brandScale);
        ctx.drawImage(
          brandImg,
          textX,
          labelY + labelHeight - brandBottomInset - brandDrawHeight,
          brandDrawWidth,
          brandDrawHeight
        );
      }
    }

    const link = document.createElement("a");
    link.download = "watch-sticker-sheet-avery-15660.png";
    const pngData = canvas.toDataURL("image/png");
    link.href = addPngDpi(pngData, dpi);
    link.click();
    return;
  }

  if (selected?.type === "wheel") {
    const svgUrl = wheelSrc || selected.asset;
    if (!svgUrl) return;

    const size = 2400;
    const canvas = document.createElement("canvas");
    canvas.width = size;
    canvas.height = size;
    const ctx = canvas.getContext("2d");
    if (!ctx) return;

    ctx.fillStyle = "#ffffff";
    ctx.fillRect(0, 0, size, size);

    const svgText = await fetch(svgUrl, { cache: "no-store" }).then((r) => r.text());
    const svgData = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svgText)}`;

    const img = new Image();
    img.src = svgData;
    await new Promise((resolve, reject) => {
      img.onload = resolve;
      img.onerror = reject;
    });

    const title = selected.headline || "Hue Wheel";
    ctx.fillStyle = "#111827";
    ctx.textAlign = "center";
    ctx.font = "700 72px Georgia, 'Times New Roman', serif";
    ctx.fillText(title, size / 2, 140);

    const padX = 120;
    const padTop = 220;
    const padBottom = 120;
    const availW = size - padX * 2;
    const availH = size - padTop - padBottom;
    const drawSize = Math.min(availW, availH);
    const drawX = (size - drawSize) / 2;
    const drawY = padTop;
    ctx.drawImage(img, drawX, drawY, drawSize, drawSize);

    const link = document.createElement("a");
    link.download = `${selected.id}-wheel.png`;
    link.href = canvas.toDataURL("image/png");
    link.click();
    return;
  }

  if (!selectedUrl) return;

  const width = 1200;
  const height = 1600;
  const headline = selected.headline || "Reserve Time";
  const subline = selected.subline || "";
  const qrUrl = buildQrUrl(selectedUrl, 600);

  const canvas = document.createElement("canvas");
  canvas.width = width;
  canvas.height = height;
  const ctx = canvas.getContext("2d");
  if (!ctx) return;

  ctx.fillStyle = "#ffffff";
  ctx.fillRect(0, 0, width, height);

  ctx.textAlign = "center";
  ctx.fillStyle = "#d97706";
  ctx.font = "700 64px Georgia, 'Times New Roman', serif";
  ctx.fillText(headline, width / 2, 220);

  const qrImg = new Image();
  qrImg.crossOrigin = "anonymous";
  qrImg.src = qrUrl;
  await new Promise((resolve, reject) => {
    qrImg.onload = resolve;
    qrImg.onerror = reject;
  });

  const qrSize = 420;
  const qrX = (width - qrSize) / 2;
  const qrY = 320;
  ctx.drawImage(qrImg, qrX, qrY, qrSize, qrSize);

  if (subline) {
    ctx.fillStyle = "#1f2937";
    ctx.font = "400 30px Georgia, 'Times New Roman', serif";
    ctx.fillText(subline, width / 2, qrY + qrSize + 120);
  }

  const link = document.createElement("a");
  link.download = `${selected.id}-qr.png`;
  link.href = canvas.toDataURL("image/png");
  link.click();
}

  return (
    <div className="admin-qr-sheets">
      <header className="admin-qr-sheets__header">
        <h1>QR Sheets</h1>
        <div className="admin-qr-sheets__actions">
          <button type="button" onClick={handleDownloadPng}>
            Download PNG
          </button>
  <button type="button" onClick={handlePrintSheet}>
  Print Sheet
</button>
        </div>
      </header>

      <section className="admin-qr-sheets__editor">
        <label>
          Site
          <select value={selectedId} onChange={(e) => setSelectedId(e.target.value)}>
            {qrOptions.map((option) => (
              <option key={option.id} value={option.id}>
                {option.label}
              </option>
            ))}
          </select>
        </label>
        <label>
          URL
          <input
            type="text"
            value={selected?.type === "wheel" ? wheelSrc : selectedUrl}
            readOnly
          />
        </label>
      </section>

      <section className="admin-qr-sheets__watch-config">
        <div className="admin-qr-sheets__watch-config-header">
          <div>
            <div className="admin-qr-sheets__watch-config-title">Watch Link Target</div>
            <div className="admin-qr-sheets__watch-config-note">
              Choose which playlist slug the printed Watch QR code opens.
            </div>
          </div>
          <button type="button" onClick={handleSaveWatchConfig} disabled={watchSaving || watchLoading}>
            {watchSaving ? "Saving..." : "Save Watch Target"}
          </button>
        </div>

        <div className="admin-qr-sheets__watch-config-grid">
          <label>
            Playlist Instance
            <select
              value={watchForm.playlist_instance_id}
              onChange={(e) => {
                setWatchForm((prev) => ({ ...prev, playlist_instance_id: e.target.value }));
                setWatchStatus("");
                setWatchError("");
              }}
              disabled={watchLoading}
            >
              <option value="">{watchLoading ? "Loading instances..." : "Select playlist instance"}</option>
              {instances.map((item) => (
                <option key={item.playlist_instance_id} value={item.playlist_instance_id}>
                  {(item.instance_name || item.display_title || "Untitled")} (#{item.playlist_instance_id}
                  {item.playlist_slug ? ` / ${item.playlist_slug}` : ""})
                </option>
              ))}
            </select>
          </label>
        </div>

        {watchStatus ? <div className="admin-qr-sheets__watch-config-status">{watchStatus}</div> : null}
        {watchError ? <div className="admin-qr-sheets__watch-config-status admin-qr-sheets__watch-config-status--error">{watchError}</div> : null}
      </section>

      <section className="admin-qr-sheets__grid">
          <div className={`admin-qr-sheets__card${selected?.type === "sticker" ? " admin-qr-sheets__card--sticker" : ""}`}>
            {selected?.type !== "sticker" && (
              <div className="admin-qr-sheets__label">{selected.headline}</div>
            )}
            {selected?.type === "wheel" ? (
              <img
                className="admin-qr-sheets__wheel"
                src={wheelSrc}
                alt={selected.headline}
              />
            ) : selected?.type === "sticker" ? (
              <>
             
               <Avery15660SheetPreview url={selectedUrl} />
              </>
            ) : (
              <img src={buildQrUrl(selectedUrl, 420)} alt={selected.headline} />
            )}
            {selected.subline && selected?.type !== "wheel" && selected?.type !== "sticker" && (
              <div className="admin-qr-sheets__subline">{selected.subline}</div>
            )}
          </div>
      </section>
    </div>
  );
}
