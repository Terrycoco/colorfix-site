import { useEffect, useMemo, useState } from "react";
import { API_FOLDER } from "@helpers/config";

import {
  AdminDataGrid,
  AdminDetailPane,
  AdminEmptyState,
  AdminListPane,
  AdminMasterDetail,
  AdminObjectList,
  AdminObjectListItem,
  AdminWorkbench,
} from "@components/AdminLayout";

import "../admin-rex.css";

const PLAYLISTS_URL = `${API_FOLDER}/v2/admin/playlists/list.php`;
const EXPERIENCES_URL = `${API_FOLDER}/v2/admin/rex/playlist-experiences.php`;

const OBJECT_TYPES = [
  { key: "playlist", label: "Playlists" },
  { key: "palette_viewer", label: "Viewers" },
  { key: "article", label: "Articles" },
  { key: "page", label: "Pages" },
];

const EXPERIENCE_TABS = [
  { key: "public", label: "Public" },
  { key: "concept", label: "Concept" },
  { key: "client", label: "Client" },
];

export default function RexRelationshipsPage() {
  const [objectType, setObjectType] = useState("playlist");
  const [objects, setObjects] = useState([]);
  const [objectsLoading, setObjectsLoading] = useState(false);
  const [objectsError, setObjectsError] = useState("");
  const [selectedObjectId, setSelectedObjectId] = useState(null);

  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [activeExperience, setActiveExperience] = useState("public");
  const [syncing, setSyncing] = useState(false);
  const [syncMessage, setSyncMessage] = useState("");
  const [selectedChild, setSelectedChild] = useState(null);
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [copyStatus, setCopyStatus] = useState("");

  useEffect(() => {
    setSelectedObjectId(null);
    setData(null);
    setError("");
    setSyncMessage("");

    if (objectType !== "playlist") {
      setObjects([]);
      setObjectsLoading(false);
      setObjectsError("");
      return;
    }

    let active = true;

    async function loadPlaylists() {
      setObjectsLoading(true);
      setObjectsError("");

      try {
        const res = await fetch(`${PLAYLISTS_URL}?_=${Date.now()}`, {
          credentials: "include",
        });
        const payload = await res.json();

        if (!res.ok || !payload?.ok) {
          throw new Error(payload?.error || "Failed to load playlists");
        }

        if (!active) return;

        const rows = Array.isArray(payload.items) ? [...payload.items] : [];
        rows.sort((a, b) => {
          const byTitle = String(a?.title || "").localeCompare(
            String(b?.title || ""),
            undefined,
            { numeric: true, sensitivity: "base" }
          );
          return byTitle || Number(a?.playlist_id || 0) - Number(b?.playlist_id || 0);
        });

        setObjects(rows);
      } catch (err) {
        if (!active) return;
        setObjects([]);
        setObjectsError(err?.message || "Failed to load playlists");
      } finally {
        if (active) setObjectsLoading(false);
      }
    }

    loadPlaylists();

    return () => {
      active = false;
    };
  }, [objectType]);

  useEffect(() => {
    setData(null);
    setError("");
    setSyncMessage("");
    setActiveExperience("public");
    setSelectedChild(null);
    setDrawerOpen(false);
    setCopyStatus("");

    if (objectType !== "playlist" || !selectedObjectId) return;

    let active = true;

    async function loadExperienceGraph() {
      setLoading(true);
      setError("");

      try {
        const payload = await fetchInspection(selectedObjectId);
        if (!active) return;
        setData(payload);
      } catch (err) {
        if (!active) return;
        setError(err?.message || "Failed to load Playlist REX graph");
      } finally {
        if (active) setLoading(false);
      }
    }

    loadExperienceGraph();

    return () => {
      active = false;
    };
  }, [objectType, selectedObjectId]);

  useEffect(() => {
    setSelectedChild(null);
    setDrawerOpen(false);
    setCopyStatus("");
  }, [activeExperience]);

  const selectedObject = useMemo(() => {
    if (objectType !== "playlist" || selectedObjectId === null) return null;
    return (
      objects.find(
        (item) => Number(item.playlist_id) === Number(selectedObjectId)
      ) || null
    );
  }, [objectType, objects, selectedObjectId]);

  const selectedType = OBJECT_TYPES.find((type) => type.key === objectType);
  const experience = data?.experiences?.[activeExperience] || null;
  const children = Array.isArray(experience?.children) ? experience.children : [];
  const playlistRex = experience?.playlist_rex || null;
  const playlistRexUrl = playlistRex?.url || "";
  const thumbsChild = children.find((item) => item.type === "thumbs") || null;
  const thumbsSummary = !experience?.thumbs_required
    ? (thumbsChild?.status === "retained" ? "Not required · REX retained" : "Not required")
    : thumbsChild
      ? `Required · ${statusLabel(thumbsChild.status)}`
      : "Required · Missing";
  const thumbsSummaryStatus = !experience?.thumbs_required
    ? (thumbsChild?.status || "not_applicable")
    : (thumbsChild?.status || "missing_rex");

  const childColumns = useMemo(
    () => [
      {
        key: "type",
        label: "Type",
        value: (item) => (item.type === "thumbs" ? "Thumbs" : "Viewer"),
      },
      {
        key: "title",
        label: "Title",
        value: (item) => item.title || "—",
      },
      {
        key: "viewer_format",
        label: "Experience",
        value: (item) =>
          item.type === "thumbs"
            ? humanize(activeExperience)
            : humanize(item.viewer_format || activeExperience),
      },
      {
        key: "pv_id",
        label: "PV",
        value: (item) => (item.pv_id ? `#${item.pv_id}` : "—"),
        sortValue: (item) => Number(item.pv_id || 0),
      },
      {
        key: "rex",
        label: "REX",
        render: (item) =>
          item.rex ? (
            <a href={item.rex.url} target="_blank" rel="noreferrer">
              #{item.rex.id}
            </a>
          ) : (
            "—"
          ),
        sortValue: (item) => Number(item.rex?.id || 0),
      },
      {
        key: "linked",
        label: "Link",
        value: (item) =>
          item.required === false
            ? "Not required"
            : item.linked
              ? "Linked"
              : "Missing",
      },
      {
        key: "status",
        label: "Status",
        render: (item) => (
          <span style={statusStyle(item.status)}>
            {statusGlyph(item.status)} {statusLabel(item.status)}
          </span>
        ),
        sortValue: (item) => String(item.status || ""),
      },
      {
        key: "message",
        label: "Message",
        value: (item) => item.message || "—",
      },
    ],
    [activeExperience]
  );

  async function reconcileNow() {
    if (!selectedObjectId) return;

    setSyncing(true);
    setSyncMessage("");
    setError("");

    try {
      const res = await fetch(EXPERIENCES_URL, {
        method: "POST",
        credentials: "include",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify({ playlist_id: Number(selectedObjectId) }),
      });
      const payload = await res.json();

      if (!res.ok || !payload?.ok || !payload?.data) {
        throw new Error(payload?.error || "REX reconciliation failed");
      }

      const refreshedData = await fetchInspection(selectedObjectId);
      setData(refreshedData);
      setSyncMessage("REX graph reconciled to the saved Playlist.");
    } catch (err) {
      setError(err?.message || "REX reconciliation failed");
    } finally {
      setSyncing(false);
    }
  }

  function renderObjectList() {
    return (
      <AdminListPane title="ColorFix Objects">
        <div style={{ padding: 12 }}>
          <label>
            Object Type
            <select
              value={objectType}
              onChange={(event) => setObjectType(event.target.value)}
            >
              {OBJECT_TYPES.map((type) => (
                <option key={type.key} value={type.key}>
                  {type.label}
                </option>
              ))}
            </select>
          </label>
        </div>

        {objectsLoading ? (
          <AdminEmptyState title="Loading objects" />
        ) : objectsError ? (
          <AdminEmptyState
            title="Objects could not load"
            message={objectsError}
          />
        ) : objectType !== "playlist" ? (
          <AdminEmptyState
            title={`${selectedType?.label || "Objects"} not wired yet`}
            message="This workspace is object-first. Playlist experience graphs are wired first."
          />
        ) : objects.length === 0 ? (
          <AdminEmptyState title="No playlists found" />
        ) : (
          <AdminObjectList ariaLabel="Playlist objects">
            {objects.map((item) => (
              <AdminObjectListItem
                key={item.playlist_id}
                id={item.playlist_id}
                title={item.title || `Playlist #${item.playlist_id}`}
                selected={Number(selectedObjectId) === Number(item.playlist_id)}
                onSelect={() => setSelectedObjectId(item.playlist_id)}
              />
            ))}
          </AdminObjectList>
        )}
      </AdminListPane>
    );
  }

  if (!selectedObject) {
    return (
      <AdminMasterDetail
        storageKey="admin-rex-relationships-list-width"
        defaultListWidth={280}
        minListWidth={240}
        maxListWidth={420}
        list={renderObjectList()}
        detail={
          <AdminDetailPane ariaLabel="REX object workspace">
            <div className="admin-detail-header">
              <div>
                <h1 className="admin-detail-header__title">REX Objects</h1>
                <p className="admin-detail-header__description">
                  Inspect the permanent REX identities and current relationship graph for ColorFix objects.
                </p>
              </div>
            </div>
            <AdminEmptyState
              title="Select an object"
              message="Choose a Playlist on the left."
            />
          </AdminDetailPane>
        }
      />
    );
  }

  return (
    <AdminMasterDetail
      storageKey="admin-rex-relationships-list-width"
      defaultListWidth={280}
      minListWidth={240}
      maxListWidth={420}
      list={renderObjectList()}
      detail={
        <AdminDetailPane ariaLabel="REX object workspace">
          <AdminWorkbench
            header={
              <div style={headerStyle}>
                <div>
                  <h1 style={playlistTitleStyle}>
                    {selectedObject.title || `Playlist #${selectedObject.playlist_id}`}
                  </h1>
                  <div style={playlistMetaStyle}>
                    Playlist #{selectedObject.playlist_id}
                    {data?.playlist_status ? ` · ${humanize(data.playlist_status)}` : ""}
                    {experience ? ` · ${humanize(activeExperience)} ${statusLabel(experience.status)}` : ""}
                  </div>
                </div>

                <div style={headerActionsStyle}>
                  <button
                    type="button"
                    onClick={() => {
                      window.location.href = `/admin/playlists/${selectedObject.playlist_id}`;
                    }}
                  >
                    Edit Playlist
                  </button>

                  <button
                    type="button"
                    disabled={syncing || loading}
                    onClick={reconcileNow}
                    title="Normally automatic when the Playlist is saved."
                  >
                    {syncing ? "Reconciling..." : "Reconcile REX"}
                  </button>

                  <button
                    type="button"
                    disabled={!playlistRexUrl}
                    onClick={() => {
                      if (!playlistRexUrl) return;
                      window.open(playlistRexUrl, "_blank", "noopener,noreferrer");
                    }}
                  >
                    Launch {humanize(activeExperience)}
                  </button>
                </div>
              </div>
            }

            upperLeft={
              <div style={upperPanelStyle}>
                <div style={experienceTabsStyle}>
                  {EXPERIENCE_TABS.map((tab) => {
                    const tabData = data?.experiences?.[tab.key];
                    const selected = activeExperience === tab.key;
                    return (
                      <button
                        key={tab.key}
                        type="button"
                        onClick={() => setActiveExperience(tab.key)}
                        style={experienceTabStyle(selected)}
                      >
                        {tab.label}
                        <span style={tabStatusStyle(tabData?.status)}>
                          {statusGlyph(tabData?.status)}
                        </span>
                      </button>
                    );
                  })}
                </div>

                {loading ? (
                  <AdminEmptyState title="Loading Playlist experiences" />
                ) : error ? (
                  <AdminEmptyState title="REX graph could not load" message={error} />
                ) : !experience ? (
                  <AdminEmptyState title="No experience data" />
                ) : (
                  <div style={factsGridStyle}>
                    <Fact label="Experience" value={humanize(activeExperience)} />
                    <Fact label="Status" value={statusLabel(experience.status)} emphasis={experience.status} />
                    <Fact label="Slides" value={Number(experience.slide_count || 0)} />
                    <Fact label="Palettes" value={Number(experience.palette_count || 0)} />
                    <Fact label="Primary PVs" value={Number(experience.primary_viewer_count || 0)} />
                    <Fact
                      label="Thumbs"
                      value={thumbsSummary}
                      emphasis={thumbsSummaryStatus}
                    />
                  </div>
                )}
              </div>
            }

            upperRight={
              <div style={upperPanelStyle}>
                <div style={sectionLabelStyle}>PERMANENT EXPERIENCE REX</div>

                {loading ? (
                  <AdminEmptyState title="Loading REX" />
                ) : playlistRex ? (
                  <div style={rexCardStyle}>
                    <div>
                      <strong>{playlistRex.label || `REX #${playlistRex.id}`}</strong>
                    </div>
                    <div>REX #{playlistRex.id}</div>
                    <div>Experience: {humanize(playlistRex.experience_key || activeExperience)}</div>
                    <div>Status: {humanize(playlistRex.status || "")}</div>
                    <div>Resolver: {playlistRex.resolver_key || "—"}</div>
                    <div>
                      <a href={playlistRex.url} target="_blank" rel="noreferrer">
                        {playlistRex.url}
                      </a>
                    </div>
                    {syncMessage ? <div style={readyStyle}>{syncMessage}</div> : null}
                  </div>
                ) : (
                  <AdminEmptyState
                    title="No permanent REX yet"
                    message={
                      Number(experience?.slide_count || 0) > 0
                        ? "This experience exists in the saved Playlist but does not yet have its permanent REX."
                        : "This experience is not currently used by the Playlist."
                    }
                  />
                )}
              </div>
            }

            upperLeftWidth={380}
            upperHeight="235px"

            lower={
              <div className="admin-detail-workarea" style={{ height: "100%" }}>
                <div style={resultsHeaderStyle}>
                  <div>
                    <div style={sectionLabelStyle}>EXPECTED CHILD GRAPH</div>
                    <div style={resultsSubheadStyle}>
                      Derived from the saved Playlist. Reservations are permanent; these relationships are the mutable layer.
                    </div>
                  </div>
                  <div style={resultsCountStyle}>
                    {children.length} child{children.length === 1 ? "" : "ren"}
                  </div>
                </div>

                {loading ? (
                  <AdminEmptyState title="Loading child graph" />
                ) : error ? (
                  <AdminEmptyState title="REX graph could not load" message={error} />
                ) : !experience ? (
                  <AdminEmptyState title="No experience selected" />
                ) : children.length === 0 ? (
                  <AdminEmptyState
                    title="No REX children required"
                    message="The saved Playlist does not currently require Viewer or Thumbs children for this experience."
                  />
                ) : (
                  <AdminDataGrid
                    items={children}
                    columns={childColumns}
                    getRowKey={(item) => item.row_key}
                    selectedKey={selectedChild?.row_key ?? null}
                    onSelectionChange={(item) => {
                      setSelectedChild(item);
                      setCopyStatus("");
                    }}
                    onRowDoubleClick={(item) => {
                      setSelectedChild(item);
                      setCopyStatus("");
                      setDrawerOpen(true);
                    }}
                    defaultSortKey="sort_order"
                    defaultSortDirection="asc"
                    ariaLabel={`${humanize(activeExperience)} REX child graph`}
                  />
                )}
              </div>
            }

            drawerOpen={drawerOpen && Boolean(selectedChild)}
            drawerWidth={460}
            drawerTitle={
              selectedChild?.title ||
              (selectedChild?.type === "thumbs" ? "Colors Used" : "REX Child")
            }
            drawerContent={
              <RexChildDrawer
                item={selectedChild}
                experienceKey={activeExperience}
                copyStatus={copyStatus}
                onCopyStatus={setCopyStatus}
              />
            }
            onCloseDrawer={() => {
              setDrawerOpen(false);
              setCopyStatus("");
            }}
          />
        </AdminDetailPane>
      }
    />
  );
}

function RexChildDrawer({
  item,
  experienceKey,
  copyStatus,
  onCopyStatus,
}) {
  if (!item) {
    return <AdminEmptyState title="No REX child selected" />;
  }

  const rex = item.rex || null;
  const fullUrl = rex?.url ? absoluteRexUrl(rex.url) : "";
  const typeLabel = item.type === "thumbs" ? "Thumbs" : "Viewer";

  async function copyUrl() {
    if (!fullUrl) return;

    try {
      await copyTextToClipboard(fullUrl);
      onCopyStatus("Copied");
    } catch {
      onCopyStatus("Copy failed");
    }
  }

  return (
    <div style={drawerBodyStyle}>
      <div style={drawerStickyActionsStyle}>
        <button
          type="button"
          disabled={!fullUrl}
          onClick={copyUrl}
        >
          Copy URL
        </button>

        <button
          type="button"
          disabled={!fullUrl}
          onClick={() => {
            if (!fullUrl) return;
            window.open(fullUrl, "_blank", "noopener,noreferrer");
          }}
        >
          Launch
        </button>

        {copyStatus ? (
          <span style={copyStatus === "Copied" ? readyStyle : issueStyle}>
            {copyStatus === "Copied" ? "✓ Copied" : "⚠ Copy failed"}
          </span>
        ) : null}
      </div>

      <section style={drawerSectionStyle}>
        <div style={sectionLabelStyle}>REX CHILD</div>
        <div style={drawerTitleStyle}>{item.title || typeLabel}</div>
        <div style={statusStyle(item.status)}>
          {statusGlyph(item.status)} {statusLabel(item.status)}
        </div>
        <div style={drawerMessageStyle}>{item.message || "—"}</div>
      </section>

      <section style={drawerSectionStyle}>
        <div style={sectionLabelStyle}>RELATIONSHIP</div>
        <div>Type: {typeLabel}</div>
        <div>Playlist experience: {humanize(experienceKey)}</div>
        <div>
          Child experience:{" "}
          {item.type === "thumbs"
            ? humanize(experienceKey)
            : humanize(item.viewer_format || experienceKey)}
        </div>
        <div>
          Required: {item.required === false ? "No" : "Yes"}
        </div>
        <div>
          Linked: {item.linked ? "Yes" : "No"}
        </div>
        <div>
          Relationship link: {item.link_id ? `#${item.link_id}` : "—"}
        </div>
        {item.pv_id ? <div>PV: #{item.pv_id}</div> : null}
        {item.saved_palette_id ? (
          <div>Saved Palette: #{item.saved_palette_id}</div>
        ) : null}
      </section>

      <section style={drawerSectionStyle}>
        <div style={sectionLabelStyle}>PERMANENT REX</div>

        {rex ? (
          <>
            <div>REX: #{rex.id}</div>
            <div>Resolver: {rex.resolver_key || "—"}</div>
            <div>Resource: {rex.resource_type || "—"} #{rex.resource_id || "—"}</div>
            <div>Experience: {humanize(rex.experience_key || experienceKey)}</div>
            <div>Status: {humanize(rex.status || "")}</div>

            <div style={urlBlockStyle}>
              <div style={sectionLabelStyle}>FULL URL</div>
              <a
                href={fullUrl}
                target="_blank"
                rel="noreferrer"
                style={fullUrlStyle}
              >
                {fullUrl}
              </a>
            </div>
          </>
        ) : (
          <div style={issueStyle}>No permanent REX exists for this child.</div>
        )}
      </section>

    </div>
  );
}

function absoluteRexUrl(url) {
  const value = String(url || "").trim();
  if (!value) return "";

  try {
    return new URL(value, window.location.origin).toString();
  } catch {
    return value;
  }
}

async function copyTextToClipboard(text) {
  if (navigator.clipboard?.writeText) {
    await navigator.clipboard.writeText(text);
    return;
  }

  const textarea = document.createElement("textarea");
  textarea.value = text;
  textarea.setAttribute("readonly", "");
  textarea.style.position = "fixed";
  textarea.style.opacity = "0";
  document.body.appendChild(textarea);
  textarea.select();

  const copied = document.execCommand("copy");
  document.body.removeChild(textarea);

  if (!copied) {
    throw new Error("Clipboard copy failed");
  }
}

function Fact({ label, value, emphasis = "" }) {
  return (
    <div style={factStyle}>
      <div style={factLabelStyle}>{label}</div>
      <div style={emphasis ? statusStyle(emphasis) : factValueStyle}>{value}</div>
    </div>
  );
}

async function fetchInspection(playlistId) {
  const params = new URLSearchParams({
    playlist_id: String(playlistId),
    _: String(Date.now()),
  });

  const res = await fetch(`${EXPERIENCES_URL}?${params.toString()}`, {
    credentials: "include",
  });
  const payload = await res.json();

  if (!res.ok || !payload?.ok || !payload?.data) {
    throw new Error(payload?.error || "Failed to load Playlist REX graph");
  }

  return payload.data;
}

function humanize(value) {
  return String(value || "")
    .replace(/[_-]+/g, " ")
    .replace(/\b\w/g, (character) => character.toUpperCase());
}

function statusLabel(status) {
  const value = String(status || "").toLowerCase();
  if (value === "ready") return "Ready";
  if (value === "retained") return "Retained";
  if (value === "not_applicable") return "Not used";
  if (value === "missing_rex") return "Missing REX";
  if (value === "unlinked") return "Not linked";
  if (value === "blocked") return "Blocked";
  if (value === "incomplete") return "Incomplete";
  if (value === "revoked") return "Revoked";
  return humanize(value || "Unknown");
}

function statusGlyph(status) {
  const value = String(status || "").toLowerCase();
  if (["ready", "retained"].includes(value)) return "✓";
  if (value === "not_applicable") return "—";
  return "⚠";
}

function statusStyle(status) {
  const value = String(status || "").toLowerCase();
  if (["ready", "retained"].includes(value)) return readyStyle;
  if (value === "not_applicable") return mutedStyle;
  return issueStyle;
}

const drawerBodyStyle = {
  color: "#152033",
  padding: 16,
  fontSize: 13,
  lineHeight: 1.6,
};

const drawerSectionStyle = {
  paddingBottom: 16,
  marginBottom: 16,
  borderBottom: "1px solid #dfe4ea",
};

const drawerTitleStyle = {
  marginTop: 4,
  marginBottom: 4,
  fontSize: 18,
  fontWeight: 700,
  color: "#152033",
};

const drawerMessageStyle = {
  marginTop: 8,
  color: "#586675",
};

const urlBlockStyle = {
  marginTop: 14,
  padding: 12,
  background: "#f4f6f8",
  border: "1px solid #d9e0e7",
  borderRadius: 4,
};

const fullUrlStyle = {
  display: "block",
  marginTop: 4,
  color: "#0b5ea8",
  wordBreak: "break-all",
  overflowWrap: "anywhere",
};

const drawerStickyActionsStyle = {
  position: "sticky",
  top: 0,
  zIndex: 2,
  display: "flex",
  alignItems: "center",
  flexWrap: "wrap",
  gap: 8,
  margin: "-16px -16px 16px",
  padding: "12px 16px",
  background: "#fff",
  borderBottom: "1px solid #dfe4ea",
};

const headerStyle = {
  color: "#152033",
  display: "flex",
  alignItems: "center",
  justifyContent: "space-between",
  gap: 16,
  padding: "12px 0",
};

const headerActionsStyle = {
  display: "flex",
  alignItems: "center",
  gap: 8,
};

const playlistTitleStyle = {
  color: "#152033",
  margin: 0,
  fontSize: 22,
  lineHeight: 1.2,
};

const playlistMetaStyle = {
  marginTop: 4,
  color: "#586675",
  fontSize: 13,
};

const upperPanelStyle = {
  color: "#152033",
  height: "100%",
  minHeight: 0,
  padding: "12px 14px",
  boxSizing: "border-box",
  overflowY: "auto",
  overflowX: "hidden",
};

const experienceTabsStyle = {
  display: "flex",
  flexWrap: "wrap",
  gap: 6,
  marginBottom: 14,
};

const experienceTabStyle = (selected) => ({
  display: "inline-flex",
  alignItems: "center",
  gap: 6,
  padding: "7px 13px",
  border: selected ? "1px solid #d86700" : "1px solid #4f5b67",
  borderRadius: 4,
  background: selected ? "#f47c00" : "#5f6b78",
  color: "#fff",
  fontWeight: selected ? 700 : 600,
  cursor: "pointer",
});

const tabStatusStyle = () => ({
  color: "#fff",
  fontWeight: 700,
  fontSize: 11,
});

const factsGridStyle = {
  display: "grid",
  gridTemplateColumns: "repeat(3, minmax(0, 1fr))",
  gap: 10,
};

const factStyle = {
  minWidth: 0,
};

const factLabelStyle = {
  color: "#586675",
  fontSize: 11,
  textTransform: "uppercase",
  letterSpacing: "0.05em",
};

const factValueStyle = {
  color: "#152033",
  marginTop: 2,
  fontSize: 14,
  fontWeight: 600,
};

const sectionLabelStyle = {
  marginBottom: 8,
  color: "#586675",
  fontSize: 11,
  fontWeight: 700,
  letterSpacing: "0.06em",
};

const rexCardStyle = {
  color: "#152033",
  display: "grid",
  gap: 4,
  fontSize: 13,
};

const resultsHeaderStyle = {
  display: "flex",
  alignItems: "flex-start",
  justifyContent: "space-between",
  gap: 16,
  minHeight: 48,
};

const resultsSubheadStyle = {
  color: "#586675",
  fontSize: 12,
};

const resultsCountStyle = {
  color: "#586675",
  fontSize: 12,
  whiteSpace: "nowrap",
};

const readyStyle = {
  color: "#18794e",
  fontWeight: 600,
};

const issueStyle = {
  color: "#a23b2a",
  fontWeight: 600,
};

const mutedStyle = {
  color: "#586675",
  fontWeight: 500,
};
