import {
  useCallback,
  useEffect,
  useMemo,
  useState,
} from "react";
import { isAnalyticsTestMode } from "@helpers/authHelper";

import {
  AdminButton,
  AdminDataGrid,
  AdminDetailPane,
  AdminEmptyState,
  AdminListPane,
  AdminMasterDetail,
  AdminObjectList,
  AdminObjectListItem,
  AdminSmartGrid,
} from "@components/AdminLayout";
import { API_FOLDER } from "@helpers/config";

const COUNTS_URL = `${API_FOLDER}/v2/admin/analytics/counts.php`;
const EVENTS_URL = `${API_FOLDER}/v2/admin/analytics/events.php`;
const DELETE_TESTS_URL = `${API_FOLDER}/v2/admin/analytics/delete-tests.php`;
const EVENT_KEY = "visit";

function formatDateTime(value) {
  if (!value) return "—";

  const [datePart, timePart] = String(value).split(" ");
  if (!datePart || !timePart) return String(value);

  const date = new Date(`${datePart}T${timePart}`);

  const dateText = date.toLocaleDateString(undefined, {
    month: "short",
    day: "numeric",
    year: "numeric",
  });

  const timeText = date.toLocaleTimeString(undefined, {
    hour: "numeric",
    minute: "2-digit",
  });

  return `${dateText} ${timeText}`;
}

function resourceLabel(resourceType) {
  if (resourceType === "page") return "Page";
  if (resourceType === "article") return "Article";
  if (resourceType === "playlist") return "Playlist";
  return "Resource";
}

function fallbackTitle(resourceType, resourceId) {
  return `${resourceLabel(resourceType)} #${resourceId}`;
}

function analyticsRowKey(resourceType, item) {
  const sourcePart =
    item?.source_key === null || item?.source_key === undefined
      ? "__NULL__"
      : `source:${item.source_key}`;

  return `${resourceType}:${item?.resource_id}:${sourcePart}`;
}

function AnalyticsVisitsDrawer({
  item,
  resourceType,
  onChanged,
}) {
  const [events, setEvents] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [deletingId, setDeletingId] = useState(null);

  const loadEvents = useCallback(async () => {
    if (!item?.resource_id) {
      setEvents([]);
      setLoading(false);
      return;
    }

    setLoading(true);
    setError("");

    try {
      const params = new URLSearchParams({
        resource_type: resourceType,
        resource_id: String(item.resource_id),
        event_key: EVENT_KEY,
      });

      // Omit source_key for SQL NULL; preserve a real empty-string source.
      if (item.source_key !== null && item.source_key !== undefined) {
        params.set("source_key", String(item.source_key));
      }

      const res = await fetch(`${EVENTS_URL}?${params.toString()}`, {
        credentials: "include",
      });

      const data = await res.json();

      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || "Failed to load visits");
      }

      setEvents(Array.isArray(data.items) ? data.items : []);
    } catch (err) {
      setError(err?.message || "Failed to load visits");
    } finally {
      setLoading(false);
    }
  }, [
    item?.resource_id,
    item?.source_key,
    resourceType,
  ]);

  useEffect(() => {
    loadEvents();
  }, [loadEvents]);

  async function deleteEvent(event) {
    if (!event?.id) return;

    if (!window.confirm("Delete this analytics visit?")) {
      return;
    }

    setDeletingId(event.id);

    try {
      const res = await fetch(EVENTS_URL, {
        method: "DELETE",
        credentials: "include",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          id: event.id,
        }),
      });

      const data = await res.json();

      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || "Failed to delete visit");
      }

      await loadEvents();
      await onChanged?.();
    } catch (err) {
      window.alert(err?.message || "Failed to delete visit");
    } finally {
      setDeletingId(null);
    }
  }

  const columns = useMemo(
    () => [
      {
        key: "created_at",
        label: "Time",
        render: (event) => formatDateTime(event.created_at),
      },
      {
        key: "session_id",
        label: "Session",
        render: (event) => event.session_id || "—",
      },
      {
        key: "referrer",
        label: "Referrer",
        render: (event) => event.referrer || "—",
      },
      {
        key: "path",
        label: "Path",
        render: (event) => event.path || "—",
      },
      {
        key: "is_test",
        label: "Test",
        sortValue: (event) => Number(event.is_test || 0),
        render: (event) => (Number(event.is_test) === 1 ? "Yes" : "No"),
      },
      {
        key: "__delete__",
        label: "",
        sortable: false,
        render: (event) => (
          <AdminButton
            type="button"
            size="sm"
            variant="secondary"
            disabled={deletingId === event.id}
            onClick={(clickEvent) => {
              clickEvent.stopPropagation();
              deleteEvent(event);
            }}
          >
            {deletingId === event.id ? "Deleting…" : "Delete"}
          </AdminButton>
        ),
      },
    ],
    [deletingId]
  );

  if (loading) {
    return <AdminEmptyState title="Loading visits" />;
  }

  if (error) {
    return (
      <AdminEmptyState
        title="Visits could not load"
        message={error}
      />
    );
  }

  if (events.length === 0) {
    return <AdminEmptyState title="No visits remain" />;
  }

  return (
    <AdminDataGrid
      ariaLabel="Individual analytics visits"
      items={events}
      columns={columns}
      getRowKey={(event) => event.id}
      defaultSortKey="created_at"
      defaultSortDirection="desc"
      minWidth={760}
      verticalAlign="top"
    />
  );
}

export default function AdminAnalyticsPage() {
  const [items, setItems] = useState([]);
  const [selectedKey, setSelectedKey] = useState("");
  const [resourceType, setResourceType] = useState("playlist");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [resourceTypes, setResourceTypes] = useState(["playlist"]);
  const [testMode, setTestMode] = useState(isAnalyticsTestMode());

  const loadCounts = useCallback(
    async ({ showLoading = true } = {}) => {
      if (showLoading) {
        setLoading(true);
      }

      setError("");

      try {
        const params = new URLSearchParams({
          resource_type: resourceType,
          event_key: EVENT_KEY,
        });

        const res = await fetch(`${COUNTS_URL}?${params.toString()}`, {
          credentials: "include",
        });

        const data = await res.json();

        if (!res.ok || !data?.ok) {
          throw new Error(data?.error || "Failed to load analytics");
        }

        setItems(Array.isArray(data.items) ? data.items : []);

        setResourceTypes(
          Array.isArray(data.resource_types) && data.resource_types.length
            ? data.resource_types
            : ["playlist"]
        );
      } catch (err) {
        setError(err?.message || "Failed to load analytics");
      } finally {
        if (showLoading) {
          setLoading(false);
        }
      }
    },
    [resourceType]
  );

  useEffect(() => {
    setSelectedKey("");
    loadCounts();
  }, [loadCounts]);

  async function deleteTestData() {
    if (!window.confirm("Delete all analytics test records?")) return;

    try {
      const res = await fetch(DELETE_TESTS_URL, {
        method: "POST",
        credentials: "include",
      });

      const data = await res.json();

      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || "Failed to delete test data");
      }

      window.alert(`Deleted ${data.deleted} test record(s).`);
      await loadCounts({ showLoading: false });
    } catch (err) {
      window.alert(err?.message || "Failed to delete test data");
    }
  }

  const columns = useMemo(
    () => [
      {
        key: "title",
        label: resourceLabel(resourceType),
        render: (item) =>
          item.title || fallbackTitle(resourceType, item.resource_id),
      },
      {
        key: "source_key",
        label: "Source",
        render: (item) => item.source_key || "Direct",
      },
      {
        key: "event_count",
        label: "Visits",
        sortValue: (item) => Number(item.event_count || 0),
        render: (item) => item.event_count,
      },
      {
        key: "last_visit",
        label: "Last Visit",
        render: (item) => formatDateTime(item.last_visit),
      },
    ],
    [resourceType]
  );

  return (
    <AdminMasterDetail
      storageKey="admin-analytics-list-width"
      defaultListWidth={280}
      minListWidth={240}
      maxListWidth={420}
      list={
        <AdminListPane title="Analytics">
          <AdminObjectList ariaLabel="Analytics resource types">
            {resourceTypes.map((type) => (
              <AdminObjectListItem
                key={type}
                id={type}
                title={type.charAt(0).toUpperCase() + type.slice(1)}
                selected={resourceType === type}
                onSelect={() => setResourceType(type)}
              />
            ))}
          </AdminObjectList>
        </AdminListPane>
      }
      detail={
        <AdminDetailPane ariaLabel="Analytics results">
          <div className="admin-detail-header">
            <div>
              <h1 className="admin-detail-header__title">Analytics</h1>
              <p className="admin-detail-header__description">
                Event Counts by Resource
              </p>
            </div>

            <div className="admin-detail-header__actions">
              <label
                style={{
                  display: "inline-flex",
                  alignItems: "center",
                  gap: 7,
                  fontSize: 12,
                  fontWeight: 700,
                }}
              >
                <input
                  type="checkbox"
                  checked={testMode}
                  onChange={(e) => {
                    const enabled = e.target.checked;

                    if (enabled) {
                      localStorage.setItem("analyticsTestMode", "1");
                    } else {
                      localStorage.removeItem("analyticsTestMode");
                    }

                    setTestMode(enabled);
                  }}
                />
                Test Mode
              </label>

              <AdminButton
                type="button"
                variant="secondary"
                onClick={deleteTestData}
              >
                Delete Test Data
              </AdminButton>
            </div>
          </div>

          {loading ? (
            <AdminEmptyState title="Loading analytics" />
          ) : error ? (
            <AdminEmptyState
              title="Analytics could not load"
              message={error}
            />
          ) : items.length === 0 ? (
            <AdminEmptyState title="No analytics yet" />
          ) : (
            <AdminSmartGrid
              ariaLabel={`${resourceType} analytics`}
              items={items}
              columns={columns}
              getRowKey={(item) => analyticsRowKey(resourceType, item)}
              selectedKey={selectedKey}
              onSelectionChange={(_item, key) => setSelectedKey(key)}
              defaultSortKey="last_visit"
              defaultSortDirection="desc"
              drawer={{
                width: 680,
                title: (item) =>
                  `${item.title || fallbackTitle(resourceType, item.resource_id)} · ${
                    item.source_key || "Direct"
                  }`,
                render: ({ item }) => (
                  <AnalyticsVisitsDrawer
                    item={item}
                    resourceType={resourceType}
                    onChanged={() =>
                      loadCounts({ showLoading: false })
                    }
                  />
                ),
              }}
            />
          )}
        </AdminDetailPane>
      }
    />
  );
}
