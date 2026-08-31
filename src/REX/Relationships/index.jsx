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
const RELATIONSHIPS_URL = `${API_FOLDER}/v2/admin/rex/relationships.php`;
const PLAYLIST_AUDIT_URL = `${API_FOLDER}/v2/admin/rex/audit-playlist.php`;
const PLAYLIST_SYNC_URL = `${API_FOLDER}/v2/admin/rex/sync-playlist.php`;

const OBJECT_TYPES = [
  { key: "playlist", label: "Playlists" },
  { key: "palette_viewer", label: "Viewers" },
  { key: "thumbs", label: "Thumbs" },
  { key: "article", label: "Articles" },
  { key: "page", label: "Pages" },
];

export default function RexRelationshipsPage() {
  const [objectType, setObjectType] = useState("playlist");

  const [objects, setObjects] = useState([]);
  const [objectsLoading, setObjectsLoading] = useState(false);
  const [objectsError, setObjectsError] = useState("");

  const [selectedObjectId, setSelectedObjectId] = useState(null);

  const [relationshipData, setRelationshipData] = useState(null);
  const [relationshipLoading, setRelationshipLoading] = useState(false);
  const [relationshipError, setRelationshipError] = useState("");

  const [auditData, setAuditData] = useState(null);
  const [auditLoading, setAuditLoading] = useState(false);
  const [auditError, setAuditError] = useState("");

  const [syncLoading, setSyncLoading] = useState(false);
  const [syncMessage, setSyncMessage] = useState("");
  const [syncError, setSyncError] = useState("");

  const [selectedAuditItem, setSelectedAuditItem] = useState(null);
  const [drawerItem, setDrawerItem] = useState(null);
  const [drawerOpen, setDrawerOpen] = useState(false);

  useEffect(() => {
    setSelectedObjectId(null);
    setRelationshipData(null);
    setRelationshipError("");
    setAuditData(null);
    setAuditError("");
    setSyncMessage("");
    setSyncError("");
    setSelectedAuditItem(null);
    setDrawerItem(null);
    setDrawerOpen(false);

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

        const data = await res.json();

        if (!res.ok || !data?.ok) {
          throw new Error(data?.error || "Failed to load playlists");
        }

        if (!active) return;

        const rows = Array.isArray(data.items) ? [...data.items] : [];

        rows.sort((a, b) => {
          const aTitle = String(a?.title || "");
          const bTitle = String(b?.title || "");

          const byTitle = aTitle.localeCompare(bTitle, undefined, {
            numeric: true,
            sensitivity: "base",
          });

          if (byTitle !== 0) return byTitle;

          return Number(a?.playlist_id || 0) - Number(b?.playlist_id || 0);
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
    setAuditData(null);
    setAuditError("");
    setSyncMessage("");
    setSyncError("");
    setSelectedAuditItem(null);
    setDrawerItem(null);
    setDrawerOpen(false);

    if (objectType !== "playlist" || !selectedObjectId) {
      setRelationshipData(null);
      setRelationshipError("");
      return;
    }

    let active = true;

    async function loadSelectedRelationships() {
      setRelationshipLoading(true);
      setRelationshipError("");

      try {
        const data = await fetchRelationships(objectType, selectedObjectId);

        if (!active) return;

        setRelationshipData(data);
      } catch (err) {
        if (!active) return;

        setRelationshipData(null);
        setRelationshipError(
          err?.message || "Failed to load REX relationships"
        );
      } finally {
        if (active) setRelationshipLoading(false);
      }
    }

    loadSelectedRelationships();

    return () => {
      active = false;
    };
  }, [objectType, selectedObjectId]);

  const selectedObject = useMemo(() => {
    if (selectedObjectId === null) return null;

    if (objectType === "playlist") {
      return (
        objects.find(
          (item) => Number(item.playlist_id) === Number(selectedObjectId)
        ) || null
      );
    }

    return null;
  }, [objectType, objects, selectedObjectId]);

  const selectedType = OBJECT_TYPES.find((type) => type.key === objectType);

  const auditItems = Array.isArray(auditData?.items)
    ? auditData.items
    : [];

  const auditColumns = useMemo(
    () => [
      {
        key: "pv_title",
        label: "Palette / Viewer",
        value: (item) =>
          item.pv_title ||
          `Saved Palette #${item.saved_palette_id || "—"}`,
      },
      {
        key: "reference_count",
        label: "Refs",
        value: (item) => Number(item.reference_count || 0),
      },
      {
        key: "pv_id",
        label: "PV",
        value: (item) => (item.pv_id ? `#${item.pv_id}` : "—"),
        sortValue: (item) => Number(item.pv_id || 0),
      },
      {
        key: "color_count",
        label: "Colors",
        value: (item) =>
          item.color_count === null || item.color_count === undefined
            ? "—"
            : Number(item.color_count),
        sortValue: (item) => Number(item.color_count ?? -1),
      },
      {
        key: "has_photo",
        label: "Photo",
        value: (item) =>
          item.has_photo === null || item.has_photo === undefined
            ? "—"
            : item.has_photo
              ? "Yes"
              : "No",
      },
      {
        key: "viewer_rex_id",
        label: "Viewer REX",
        value: (item) =>
          item.viewer_rex_id ? `#${item.viewer_rex_id}` : "—",
        sortValue: (item) => Number(item.viewer_rex_id || 0),
      },
      {
        key: "status",
        label: "Result",
        render: (item) => {
          const ready = String(item.status || "") === "ready";

          return (
            <span style={ready ? readyStyle : issueStyle}>
              {ready ? "✓ Ready" : `⚠ ${humanize(item.status)}`}
            </span>
          );
        },
        sortValue: (item) => String(item.status || ""),
      },
      {
        key: "message",
        label: "Message",
        value: (item) => item.message || "—",
      },
    ],
    []
  );

  async function runAudit() {
    if (objectType !== "playlist" || !selectedObjectId) {
      return;
    }

    setAuditLoading(true);
    setAuditError("");
    setSyncMessage("");
    setSyncError("");
    setSelectedAuditItem(null);
    setDrawerItem(null);
    setDrawerOpen(false);

    try {
      const params = new URLSearchParams({
        playlist_id: String(selectedObjectId),
        _: String(Date.now()),
      });

      const res = await fetch(`${PLAYLIST_AUDIT_URL}?${params.toString()}`, {
        credentials: "include",
      });

      const payload = await res.json();

      if (!res.ok || !payload?.ok || !payload?.data) {
        throw new Error(payload?.error || "Playlist REX audit failed");
      }

      setAuditData(payload.data);

      const refreshedRelationships = await fetchRelationships(
        objectType,
        selectedObjectId
      );

      setRelationshipData(refreshedRelationships);
      setRelationshipError("");
    } catch (err) {
      setAuditData(null);
      setAuditError(err?.message || "Playlist REX audit failed");
    } finally {
      setAuditLoading(false);
    }
  }

  async function runSync() {
    if (objectType !== "playlist" || !selectedObjectId || !auditData) {
      return;
    }

    setSyncLoading(true);
    setSyncMessage("");
    setSyncError("");
    setSelectedAuditItem(null);
    setDrawerItem(null);
    setDrawerOpen(false);

    try {
      const res = await fetch(PLAYLIST_SYNC_URL, {
        method: "POST",
        credentials: "include",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify({
          playlist_id: Number(selectedObjectId),
        }),
      });

      const payload = await res.json();

      if (!res.ok || !payload?.ok || !payload?.result) {
        throw new Error(payload?.error || "REX link sync failed");
      }

      const result = payload.result;

      if (result.after) {
        setAuditData(result.after);
      }

      setSyncMessage(result.message || "REX links synchronized.");

      const refreshedRelationships = await fetchRelationships(
        objectType,
        selectedObjectId
      );

      setRelationshipData(refreshedRelationships);
      setRelationshipError("");
    } catch (err) {
      setSyncError(err?.message || "REX link sync failed");
    } finally {
      setSyncLoading(false);
    }
  }

  function selectAuditItem(item) {
    setSelectedAuditItem(item);

    if (drawerOpen) {
      setDrawerItem(item);
    }
  }

  function openAuditDrawer(item) {
    setSelectedAuditItem(item);
    setDrawerItem(item);
    setDrawerOpen(true);
  }

  const playlistRex =
    relationshipData?.has_rex && relationshipData?.reservation
      ? relationshipData.reservation
      : null;

  const playlistRexUrl = playlistRex?.token
    ? `/t/${playlistRex.token}`
    : "";

  const playlistStatus = playlistStatusLabel(selectedObject);

  if (!selectedObject) {
    return (
      <AdminMasterDetail
        storageKey="admin-rex-relationships-list-width"
        defaultListWidth={280}
        minListWidth={240}
        maxListWidth={420}
        list={renderObjectList()}
        detail={
          <AdminDetailPane ariaLabel="REX relationships">
            <div className="admin-detail-header">
              <div>
                <h1 className="admin-detail-header__title">
                  REX Relationships
                </h1>

                <p className="admin-detail-header__description">
                  Audit and manage REX relationships for ColorFix objects.
                </p>
              </div>
            </div>

            <AdminEmptyState
              title="Select an object"
              message="Choose an object on the left."
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
        <AdminDetailPane ariaLabel="REX relationships">
          <AdminWorkbench
            header={
              <div style={headerStyle}>
                <div>
                  <h1 style={playlistTitleStyle}>
                    {selectedObject.title ||
                      `Playlist #${selectedObject.playlist_id}`}
                  </h1>

                  <div style={playlistMetaStyle}>
                    Playlist #{selectedObject.playlist_id}
                    {playlistStatus ? ` · ${playlistStatus}` : ""}
                  </div>
                </div>

                <div style={headerActionsStyle}>
                  <button
                    type="button"
                    disabled={!playlistRexUrl}
                    onClick={() => {
                      if (!playlistRexUrl) return;
                      window.open(playlistRexUrl, "_blank", "noopener,noreferrer");
                    }}
                  >
                    View Playlist
                  </button>

                  <button
                    type="button"
                    onClick={() => {
                      window.location.href =
                        `/admin/playlists/${selectedObject.playlist_id}`;
                    }}
                  >
                    Edit Playlist
                  </button>

                  <button
                    type="button"
                    disabled={auditLoading || syncLoading}
                    onClick={runAudit}
                  >
                    {auditLoading ? "Auditing..." : "Audit"}
                  </button>

                  <button
                    type="button"
                    disabled={!auditData || auditLoading || syncLoading}
                    onClick={runSync}
                  >
                    {syncLoading ? "Syncing..." : "Sync REX Links"}
                  </button>
                </div>
              </div>
            }

            upperLeft={
              <div style={upperPanelStyle}>
                <div style={sectionLabelStyle}>PLAYLIST REX</div>

                {relationshipLoading ? (
                  <AdminEmptyState title="Loading Playlist REX" />
                ) : relationshipError ? (
                  <AdminEmptyState
                    title="Playlist REX could not load"
                    message={relationshipError}
                  />
                ) : playlistRex ? (
                  <div style={rexCardStyle}>
                    <div>
                      <strong>
                        {playlistRex.descriptor?.title ||
                          playlistRex.label ||
                          `REX #${playlistRex.id}`}
                      </strong>
                    </div>

                    <div>REX #{playlistRex.id}</div>
                    <div>Status: {playlistRex.status || "—"}</div>
                    <div>Resolver: {playlistRex.resolver_key || "—"}</div>

                    {playlistRexUrl ? (
                      <div>
                        <a
                          href={playlistRexUrl}
                          target="_blank"
                          rel="noreferrer"
                        >
                          {playlistRexUrl}
                        </a>
                      </div>
                    ) : null}
                  </div>
                ) : (
                  <AdminEmptyState
                    title="No Public Playlist REX"
                    message="This playlist does not currently have a canonical active Public REX."
                  />
                )}
              </div>
            }

            upperRight={
              <div style={upperPanelStyle}>
                <div style={sectionLabelStyle}>AUDIT</div>

                {auditError ? (
                  <AdminEmptyState
                    title="Audit failed"
                    message={auditError}
                  />
                ) : !auditData ? (
                  <AdminEmptyState
                    title="Audit not run"
                    message="Run Audit to compare the Playlist source against its PV and REX relationships."
                  />
                ) : (
                  <div style={auditSummaryStyle}>
                    <div>
                      <strong>
                        {auditData.summary?.is_clean
                          ? "✓ Clean"
                          : "⚠ Issues found"}
                      </strong>
                    </div>

                    <div>
                      {Number(auditData.summary?.palette_count || 0)} palettes
                    </div>

                    <div>
                      {Number(auditData.summary?.ready_count || 0)} ready
                    </div>

                    <div>
                      {Number(auditData.summary?.issue_count || 0)} issues
                    </div>

                    <div>
                      {Number(auditData.summary?.reference_count || 0)} source
                      references
                    </div>

                    <div>
                      Thumbs:{" "}
                      <span
                        style={
                          auditData.thumbs?.status === "ready"
                            ? readyStyle
                            : auditData.thumbs?.status === "not_required" ||
                                auditData.thumbs?.status === "retained"
                              ? mutedStyle
                              : issueStyle
                        }
                      >
                        {thumbsAuditLabel(auditData.thumbs)}
                      </span>
                    </div>

                    {syncMessage ? (
                      <div style={readyStyle}>{syncMessage}</div>
                    ) : null}

                    {syncError ? (
                      <div style={issueStyle}>{syncError}</div>
                    ) : null}
                  </div>
                )}
              </div>
            }

            upperHeight="190px"

            lower={
              <div
                className="admin-detail-workarea"
                style={{ height: "100%" }}
              >
                <div style={resultsHeaderStyle}>
                  <div style={sectionLabelStyle}>RESULTS</div>

                  {auditData ? (
                    <div style={resultsCountStyle}>
                      {auditItems.length} child
                      {auditItems.length === 1 ? "" : "ren"}
                    </div>
                  ) : null}
                </div>

                {auditLoading && !auditData ? (
                  <AdminEmptyState title="Auditing Playlist" />
                ) : auditError ? (
                  <AdminEmptyState
                    title="Audit failed"
                    message={auditError}
                  />
                ) : !auditData ? (
                  <AdminEmptyState
                    title="Run Audit"
                    message="Results will show every palette/PV child expected from the current Playlist source."
                  />
                ) : auditItems.length === 0 ? (
                  <AdminEmptyState
                    title="No palette children"
                    message="The Playlist audit found no palette references."
                  />
                ) : (
                  <AdminDataGrid
                    items={auditItems}
                    columns={auditColumns}
                    getRowKey={(item) =>
                      `${item.saved_palette_id}:${item.pv_id || 0}`
                    }
                    selectedKey={
                      selectedAuditItem
                        ? `${selectedAuditItem.saved_palette_id}:${selectedAuditItem.pv_id || 0}`
                        : null
                    }
                    onSelectionChange={selectAuditItem}
                    onRowDoubleClick={openAuditDrawer}
                    defaultSortKey="sort_order"
                    defaultSortDirection="asc"
                    ariaLabel="REX Playlist audit results"
                  />
                )}
              </div>
            }

            drawerOpen={drawerOpen}
            drawerWidth={440}
            drawerTitle={
              drawerItem?.pv_title ||
              (drawerItem?.saved_palette_id
                ? `Saved Palette #${drawerItem.saved_palette_id}`
                : "Audit Result")
            }
            drawerContent={<AuditResultDrawer item={drawerItem} />}
            onCloseDrawer={() => {
              setDrawerOpen(false);
            }}
          />
        </AdminDetailPane>
      }
    />
  );

  function renderObjectList() {
    return (
      <AdminListPane title="ColorFix Objects">
        <div style={{ padding: 12 }}>
          <label>
            Object Type
            <select
              value={objectType}
              onChange={(e) => setObjectType(e.target.value)}
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
          />
        ) : objects.length === 0 ? (
          <AdminEmptyState title="No playlists found" />
        ) : (
          <AdminObjectList ariaLabel={`${objectType} objects`}>
            {objects.map((item) => (
              <AdminObjectListItem
                key={item.playlist_id}
                id={item.playlist_id}
                title={item.title || `Playlist #${item.playlist_id}`}
                selected={
                  Number(selectedObjectId) === Number(item.playlist_id)
                }
                onSelect={() => setSelectedObjectId(item.playlist_id)}
              />
            ))}
          </AdminObjectList>
        )}
      </AdminListPane>
    );
  }
}

function AuditResultDrawer({ item }) {
  if (!item) {
    return <AdminEmptyState title="No audit result selected" />;
  }

  const ready = String(item.status || "") === "ready";

  return (
    <div style={drawerBodyStyle}>
      <section style={drawerSectionStyle}>
        <div style={sectionLabelStyle}>RESULT</div>

        <div style={ready ? readyStyle : issueStyle}>
          {ready ? "✓ Ready" : `⚠ ${humanize(item.status)}`}
        </div>

        <div style={{ marginTop: 8 }}>{item.message || "—"}</div>
      </section>

      <section style={drawerSectionStyle}>
        <div style={sectionLabelStyle}>SOURCE</div>

        <div>Saved Palette #{item.saved_palette_id || "—"}</div>
        <div>
          Playlist item IDs:{" "}
          {Array.isArray(item.playlist_item_ids) &&
          item.playlist_item_ids.length
            ? item.playlist_item_ids.join(", ")
            : "—"}
        </div>
        <div>References: {Number(item.reference_count || 0)}</div>
        <div>Sort order: {Number(item.sort_order || 0)}</div>
      </section>

      <section style={drawerSectionStyle}>
        <div style={sectionLabelStyle}>PALETTE VIEWER</div>

        <div>{item.pv_title || "—"}</div>
        <div>PV: {item.pv_id ? `#${item.pv_id}` : "—"}</div>
        <div>
          Colors:{" "}
          {item.color_count === null || item.color_count === undefined
            ? "—"
            : Number(item.color_count)}
        </div>
        <div>
          Photo:{" "}
          {item.has_photo === null || item.has_photo === undefined
            ? "—"
            : item.has_photo
              ? "Yes"
              : "No"}
        </div>
      </section>

      <section style={drawerSectionStyle}>
        <div style={sectionLabelStyle}>VIEWER REX</div>

        <div>
          REX: {item.viewer_rex_id ? `#${item.viewer_rex_id}` : "—"}
        </div>

        <div>
          Relationship link:{" "}
          {item.viewer_link_id ? `#${item.viewer_link_id}` : "—"}
        </div>

        {item.viewer_rex_url ? (
          <div>
            <a
              href={item.viewer_rex_url}
              target="_blank"
              rel="noreferrer"
            >
              {item.viewer_rex_url}
            </a>
          </div>
        ) : null}
      </section>

      <div style={drawerActionsStyle}>
        {item.viewer_rex_url ? (
          <button
            type="button"
            onClick={() => {
              window.open(
                item.viewer_rex_url,
                "_blank",
                "noopener,noreferrer"
              );
            }}
          >
            View Public
          </button>
        ) : null}

        <button
          type="button"
          disabled={!item.pv_id}
          onClick={() => {
            if (!item.pv_id) return;
            window.location.href =
              `/admin/palette-viewers?pv_id=${item.pv_id}`;
          }}
        >
          Open PV Setup
        </button>
      </div>
    </div>
  );
}

async function fetchRelationships(resourceType, resourceId) {
  const params = new URLSearchParams({
    resource_type: String(resourceType),
    resource_id: String(resourceId),
    _: String(Date.now()),
  });

  const res = await fetch(`${RELATIONSHIPS_URL}?${params.toString()}`, {
    credentials: "include",
  });

  const data = await res.json();

  if (!res.ok || !data?.ok) {
    throw new Error(data?.error || "Failed to load REX relationships");
  }

  return data;
}

function thumbsAuditLabel(thumbs) {
  if (!thumbs) return "—";

  const status = String(thumbs.status || "");

  if (status === "ready") {
    return thumbs.thumbs_rex_id
      ? `Ready · REX #${thumbs.thumbs_rex_id}`
      : "Ready";
  }

  if (status === "not_required") {
    return "Not required";
  }

  if (status === "retained") {
    return thumbs.thumbs_rex_id
      ? `Retained · REX #${thumbs.thumbs_rex_id}`
      : "Retained";
  }

  if (status === "missing_thumbs_rex") {
    return "Missing";
  }

  return humanize(status);
}

function playlistStatusLabel(item) {
  if (!item) return "";

  const raw = String(item.status || "").trim();
  if (raw) return humanize(raw);

  if (item.is_active !== undefined && item.is_active !== null) {
    return Number(item.is_active) === 1 ? "Active" : "Inactive";
  }

  return "";
}

function humanize(value) {
  return String(value || "")
    .replace(/[_-]+/g, " ")
    .replace(/\b\w/g, (character) => character.toUpperCase());
}

const headerStyle = {
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
  height: "100%",
  minHeight: 0,
  padding: "12px 14px",
  overflow: "auto",
};

const sectionLabelStyle = {
  marginBottom: 8,
  color: "#586675",
  fontSize: 11,
  fontWeight: 700,
  letterSpacing: "0.06em",
};

const rexCardStyle = {
  display: "grid",
  gap: 4,
  fontSize: 13,
};

const auditSummaryStyle = {
  display: "grid",
  gap: 5,
  fontSize: 13,
};

const resultsHeaderStyle = {
  display: "flex",
  alignItems: "center",
  justifyContent: "space-between",
  minHeight: 34,
};

const resultsCountStyle = {
  color: "#586675",
  fontSize: 12,
};

const mutedStyle = {
  color: "#586675",
  fontWeight: 700,
};

const readyStyle = {
  color: "#248451",
  fontWeight: 700,
};

const issueStyle = {
  color: "#9a5c16",
  fontWeight: 700,
};

const drawerBodyStyle = {
  padding: 16,
};

const drawerSectionStyle = {
  marginBottom: 22,
};

const drawerActionsStyle = {
  display: "flex",
  gap: 8,
  flexWrap: "wrap",
};
