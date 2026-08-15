import { useEffect, useState } from "react";
import { isAnalyticsTestMode } from "@helpers/authHelper";

import {
  AdminDataGrid,
  AdminDetailPane,
  AdminEmptyState,
  AdminListPane,
  AdminMasterDetail,
  AdminObjectList,
  AdminObjectListItem,
} from "@components/AdminLayout";
import { API_FOLDER } from "@helpers/config";

const COUNTS_URL = `${API_FOLDER}/v2/admin/analytics/counts.php`;
const DELETE_TESTS_URL = `${API_FOLDER}/v2/admin/analytics/delete-tests.php`;

export default function AdminAnalyticsPage() {
  const [items, setItems] = useState([]);
  const [selectedKey, setSelectedKey] = useState("");
  const [resourceType, setResourceType] = useState("playlist");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [resourceTypes, setResourceTypes] = useState(["playlist"]);
  const [testMode, setTestMode] = useState(isAnalyticsTestMode());

  useEffect(() => {
    let active = true;

    async function load() {
      setLoading(true);
      setError("");

      try {
        const eventKey =
          resourceType === "article"
            ? "article_open"
            : resourceType === "page"
              ? "page_open"
              : "playlist_open";

        const params = new URLSearchParams({
          resource_type: resourceType,
          event_key: eventKey,
        });

        const res = await fetch(`${COUNTS_URL}?${params.toString()}`, {
          credentials: "include",
        });

        const data = await res.json();

        if (!res.ok || !data?.ok) {
          throw new Error(data?.error || "Failed to load analytics");
        }

        if (!active) return;

        setItems(Array.isArray(data.items) ? data.items : []);

        setResourceTypes(
          Array.isArray(data.resource_types) && data.resource_types.length
            ? data.resource_types
            : ["playlist"]
        );
      } catch (err) {
        if (!active) return;
        setError(err?.message || "Failed to load analytics");
      } finally {
        if (active) setLoading(false);
      }
    }

    load();

    return () => {
      active = false;
    };
  }, [resourceType]);

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
      window.location.reload();
    } catch (err) {
      window.alert(err?.message || "Failed to delete test data");
    }
  }

  const simpleOpenResource =
    resourceType === "article" || resourceType === "page";

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

              <button
                type="button"
                onClick={deleteTestData}
              >
                Delete Test Data
              </button>
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
            <AdminDataGrid ariaLabel={`${resourceType} analytics`}>
              <thead>
                {simpleOpenResource ? (
                  <tr>
                    <th>
                      {resourceType === "page" ? "Page" : "Article"}
                    </th>
                    <th>Source</th>
                    <th>Opens</th>
                  </tr>
                ) : (
                  <tr>
                    <th>Playlist</th>
                    <th>Source</th>
                    <th>Opens</th>
                    <th>Watch More</th>
                    <th>Rate</th>
                  </tr>
                )}
              </thead>

              <tbody>
                {items.map((item) => {
                  const key = `${item.resource_id}-${item.source_key || "direct"}`;
                  const selected = key === selectedKey;

                  if (simpleOpenResource) {
                    const fallbackTitle =
                      resourceType === "page"
                        ? `Page #${item.resource_id}`
                        : `Article #${item.resource_id}`;

                    return (
                      <tr
                        key={key}
                        className={selected ? "is-selected" : ""}
                        onClick={() => setSelectedKey(key)}
                      >
                        <td>{item.title || fallbackTitle}</td>
                        <td>{item.source_key || "Direct"}</td>
                        <td>{item.event_count}</td>
                      </tr>
                    );
                  }

                  return (
                    <tr
                      key={key}
                      className={selected ? "is-selected" : ""}
                      onClick={() => setSelectedKey(key)}
                    >
                      <td>
                        {item.title || `Playlist #${item.resource_id}`}
                      </td>
                      <td>{item.source_key || "Direct"}</td>
                      <td>{item.opens}</td>
                      <td>{item.watch_more}</td>
                      <td>{item.watch_more_rate}%</td>
                    </tr>
                  );
                })}
              </tbody>
            </AdminDataGrid>
          )}
        </AdminDetailPane>
      }
    />
  );
}