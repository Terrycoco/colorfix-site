import { useMemo, useState } from "react";
import LogoAnimated from "@components/LogoAnimated";
import "./appliedpaletteviewer.css";

const DEFAULT_ROLE_GROUPS = [
  { slug: "body", display_name: "BODY", roles: ["body", "stucco", "siding", "brick", "stone"] },
  { slug: "trim", display_name: "TRIM", roles: ["trim", "fascia", "bellyband", "windowtrim", "doortrim", "gutter", "railing", "hardware"] },
  { slug: "accent", display_name: "ACCENT", roles: ["accent", "frontdoor", "door", "shutters", "garage"] },
];

const BRAND_LABELS = {
  de: "Dunn Edwards",
  sw: "Sherwin-Williams",
  behr: "Behr",
  bm: "Benjamin Moore",
  benjaminmoore: "Benjamin Moore",
  valspar: "Valspar",
  ppg: "PPG",
  glidden: "Glidden",
  pratt: "Pratt & Lambert",
};

export default function AppliedPaletteViewer({
  palette,
  renderInfo,
  entries = [],
  adminMode = false,
  shareEnabled = true,
  showBackButton = true,
  onBack,
  showLogo = true,
  shareCtaLabel = "See Colors",
  customShareMessage,
  shareUrl: shareUrlProp,
}) {
  const [shareStatus, setShareStatus] = useState("");
  const [shareSheetOpen, setShareSheetOpen] = useState(false);
  const [photoExpanded, setPhotoExpanded] = useState(false);

  const title = palette?.title || "ColorFix Palette";
  const shareUrl = useMemo(() => {
    if (shareUrlProp) return shareUrlProp;
    if (typeof window === "undefined") return "";
    try {
      const url = new URL(window.location.href);
      if (adminMode) {
        url.searchParams.delete("admin");
      }
      return url.toString();
    } catch (err) {
      return window.location.href || "";
    }
  }, [shareUrlProp, adminMode]);

  const shareMessage = customShareMessage || `Check out ${title} from ColorFix: ${shareUrl}`;
  const smsLink = `sms:&body=${encodeURIComponent(shareMessage)}`;
  const emailLink = `mailto:?subject=${encodeURIComponent("Your ColorFix Palette")}&body=${encodeURIComponent(shareMessage)}`;
  const paletteRoleGroups = useMemo(
    () => normalizeRoleGroups(palette?.role_groups),
    [palette?.role_groups]
  );

  const handleBack = () => {
    if (onBack) {
      onBack();
      return;
    }
    if (typeof window !== "undefined" && window.history.length > 1) {
      window.history.back();
    }
  };

  const copyLink = async () => {
    if (!shareUrl) return;
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(shareUrl);
      } else {
        const tmp = document.createElement("textarea");
        tmp.value = shareUrl;
        document.body.appendChild(tmp);
        tmp.select();
        document.execCommand("copy");
        tmp.remove();
      }
      setShareStatus("Link copied");
    } catch (err) {
      setShareStatus(err?.message || "Unable to copy");
    }
  };

  return (
    <div className={`apv-shell ${adminMode ? "apv-shell--admin" : ""}`}>
      <div className="apv-header">
        <div className="apv-header-slot">
          {showBackButton ? (
            <button className="apv-btn apv-btn--ghost" onClick={handleBack}>
              ← Back
            </button>
          ) : (
            showLogo && (
              <button className="apv-logo-button" onClick={() => (window.location.href = "/")}>
                <LogoAnimated />
              </button>
            )
          )}
        </div>
        <div className="apv-header-logo" />
        <div className="apv-header-slot apv-header-slot--right">
          {shareEnabled && (
            <button className="apv-btn" onClick={() => setShareSheetOpen(true)}>
              Share
            </button>
          )}
        </div>
      </div>

      <div className="apv-content">
        <div className="apv-column apv-column--photo">
          <div className="apv-photo-wrap" onClick={() => setPhotoExpanded(true)}>
            {renderInfo?.render_url ? (
              <img src={renderInfo.render_url} alt="Rendered palette" className="apv-photo" />
            ) : (
              <div className="apv-photo placeholder">No render available</div>
            )}
          </div>
        </div>

        <div className="apv-column apv-column--details">
          <div className="apv-info">
            <h1>{title}</h1>
            {palette?.notes && <p className="apv-notes">{palette.notes}</p>}
            {shareStatus && <div className="apv-share-status">{shareStatus}</div>}
          </div>

          {entries.length > 0 && (
            <div className="apv-entries">
              {groupEntries(entries, paletteRoleGroups).map((block) => (
                <div key={`${block.groupKey}-${block.entry.mask_role}-${block.entry.color_id || block.entry.color_hex6}`}>
                  {block.header && <div className="apv-entry-group">{block.header}</div>}
                  <div className="apv-entry">
                    <div className="apv-role">{block.entry.mask_role}</div>
                    <div className="apv-color">
                      <span
                        className="apv-swatch"
                        style={{ backgroundColor: block.entry.color_hex6 ? `#${block.entry.color_hex6}` : "#ccc" }}
                      />
                      <div className="apv-color-meta">
                        <div className="apv-name">
                          {block.entry.color_name || `Color #${block.entry.color_id}`}
                          {block.entry.color_code ? `, ${block.entry.color_code}` : ""}
                        </div>
                        <div className="apv-brand">{brandLabel(block.entry.color_brand)}</div>
                      </div>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
      {shareEnabled && shareSheetOpen && (
        <div className="apv-share-modal" role="dialog" aria-modal="true">
          <div className="apv-share-panel">
            <button className="apv-share-close" onClick={() => setShareSheetOpen(false)} aria-label="Close share options">
              ×
            </button>
            <h3>Share Palette</h3>
            <label>
              Link
              <input type="text" readOnly value={shareUrl} onFocus={(e) => e.target.select()} />
            </label>
            <button className="apv-btn apv-btn--copy" onClick={copyLink}>
              Copy Link
            </button>
            <a className="apv-share-option" href={smsLink}>
              Text Link
            </a>
            <a className="apv-share-option" href={emailLink}>
              Email Link
            </a>
            <button className="apv-btn apv-btn--ghost" onClick={() => setShareSheetOpen(false)}>
              Done
            </button>
          </div>
        </div>
      )}

      {photoExpanded && renderInfo?.render_url && (
        <div className="apv-photo-fullscreen" onClick={() => setPhotoExpanded(false)}>
          <img src={renderInfo.render_url} alt="Rendered palette full view" />
          <div className="apv-photo-fullscreen-hint">Tap to close</div>
        </div>
      )}
    </div>
  );
}

function groupEntries(entries, roleGroups) {
  if (!Array.isArray(entries)) return [];
  const hasServerGrouping = entries.some((e) => e.group_label || e.group_header);
  if (hasServerGrouping) {
    let lastSlug = null;
    return entries.map((entry) => {
      let header = null;
      if (entry.group_header && entry.group_label) {
        header = entry.group_label;
      } else if (entry.group_header && entry.group_slug) {
        header = entry.group_slug.toUpperCase();
      }
      if (entry.group_header) {
        lastSlug = entry.group_slug;
      } else if (!entry.group_header && entry.group_slug !== lastSlug) {
        header = entry.group_label || entry.group_slug || null;
        lastSlug = entry.group_slug;
      }
      return {
        entry,
        header,
        groupKey: entry.group_slug || entry.mask_role || "misc",
      };
    });
  }
  const roleToGroup = {};
  roleGroups.forEach((group, idx) => {
    group.roles.forEach((role) => {
      roleToGroup[role.toLowerCase()] = {
        order: idx,
        label: group.display_name || group.slug,
        key: group.slug || `group-${idx}`,
      };
    });
  });
  const sorted = [...entries].sort((a, b) => {
    const groupA = roleToGroup[a.mask_role?.toLowerCase?.()]?.order ?? 99;
    const groupB = roleToGroup[b.mask_role?.toLowerCase?.()]?.order ?? 99;
    if (groupA !== groupB) return groupA - groupB;
    return (a.mask_role || "").localeCompare(b.mask_role || "");
  });
  let lastGroup = null;
  return sorted.map((entry) => {
    const meta = roleToGroup[entry.mask_role?.toLowerCase?.()];
    const header = meta && meta.key !== lastGroup ? meta.label : null;
    lastGroup = meta ? meta.key : lastGroup;
    return { entry, header, groupKey: meta?.key || entry.mask_role || "misc" };
  });
}

function normalizeRoleGroups(groups) {
  if (!Array.isArray(groups) || !groups.length) return DEFAULT_ROLE_GROUPS;
  return groups.map((group, idx) => ({
    slug: group.slug || `group-${idx}`,
    display_name: group.display_name || group.slug || `Group ${idx + 1}`,
    roles: Array.isArray(group.roles) ? group.roles : [],
  }));
}

function brandLabel(code) {
  if (!code) return "";
  const key = code.toString().trim().toLowerCase();
  return BRAND_LABELS[key] || code.toString();
}
