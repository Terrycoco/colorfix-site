import { useEffect, useMemo, useState } from "react";
import "./admin-qr-sheets.css";

const qrOptions = [
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

export default function AdminQrSheetsPage() {
  const [selectedId, setSelectedId] = useState(qrOptions[0].id);
  const wheelVersion = useMemo(() => Date.now(), []);

  const selected = useMemo(
    () => qrOptions.find((option) => option.id === selectedId) || qrOptions[0],
    [selectedId]
  );

  const wheelSrc =
    selected?.type === "wheel" ? `${selected.asset}?v=${wheelVersion}` : "";

  async function handleDownloadPng() {
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

    if (!selected?.url) return;
    const width = 1200;
    const height = 1600;
    const headline = selected.headline || "Reserve Time";
    const subline = selected.subline || "";
    const qrUrl = buildQrUrl(selected.url, 600);

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
          <button type="button" onClick={() => window.print()}>
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
            value={selected?.type === "wheel" ? wheelSrc : selected.url}
            readOnly
          />
        </label>
      </section>

      <section className="admin-qr-sheets__grid">
          <div className="admin-qr-sheets__card">
            <div className="admin-qr-sheets__label">{selected.headline}</div>
            {selected?.type === "wheel" ? (
              <img
                className="admin-qr-sheets__wheel"
                src={wheelSrc}
                alt={selected.headline}
              />
            ) : (
              <img src={buildQrUrl(selected.url, 420)} alt={selected.headline} />
            )}
            {selected.subline && selected?.type !== "wheel" && (
              <div className="admin-qr-sheets__subline">{selected.subline}</div>
            )}
          </div>
      </section>
    </div>
  );
}
