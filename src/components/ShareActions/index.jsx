import React, { useRef } from "react";

/**
 * ShareActions
 * - Pass a ref to the element you want to snapshot (grid of swatches)
 * - Gives buttons: Copy Link, Download PNG, Print
 *
 * Requirements: none (uses dynamic import('html2canvas') only when needed)
 */
export default function ShareActions({ targetRef, filename = "palette.png", className = "" }) {
  async function copyLink() {
    try {
      await navigator.clipboard.writeText(window.location.href);
      alert("Link copied!");
    } catch {
      prompt("Copy link:", window.location.href);
    }
  }

  async function downloadPng() {
    const node = targetRef?.current;
    if (!node) return alert("Nothing to capture yet.");
    const html2canvas = (await import("html2canvas")).default;
    const canvas = await html2canvas(node, {
      backgroundColor: "#ffffff",
      scale: window.devicePixelRatio || 2,
    });
    const url = canvas.toDataURL("image/png");
    const a = document.createElement("a");
    a.href = url;
    a.download = filename;
    a.click();
  }

  function doPrint() {
    window.print();
  }

  return (
    <div className={`share-actions ${className}`}>
      <button className="btn" onClick={copyLink} type="button">Copy Link</button>
      <button className="btn" onClick={downloadPng} type="button">Download PNG</button>
      <button className="btn-secondary" onClick={doPrint} type="button">Print</button>
    </div>
  );
}
