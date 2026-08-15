import { useEffect, useState } from "react";
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

export default function AdminAnalyticsPage() {
  const [items, setItems] = useState([]);
  const [selectedKey, setSelectedKey] = useState("");
  const [resourceType, setResourceType] = useState("playlist");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [resourceTypes, setResourceTypes] = useState(["playlist"]);

  useEffect(() => {
    let active = true;

    async function load() {
      setLoading(true);
      setError("");

      try {
        const params = new URLSearchParams({
          resource_type: resourceType,
          event_key: "playlist_open",
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
                Playlist opens by source.
              </p>
            </div>
          </div>

          {loading ? (
            <AdminEmptyState title="Loading analytics" />
          ) : error ? (
            <AdminEmptyState title="Analytics could not load" message={error} />
          ) : items.length === 0 ? (
            <AdminEmptyState title="No analytics yet" />
          ) : (
            <AdminDataGrid ariaLabel="Playlist open counts">
              <thead>
              <tr>
                  <th>Playlist</th>
                  <th>Source</th>
                  <th>Opens</th>
                  <th>Watch More</th>
                  <th>Rate</th>
                </tr>
              </thead>

              <tbody>
                {items.map((item) => {
                  const key = `${item.resource_id}-${item.source_key || "direct"}`;
                  const selected = key === selectedKey;

                  return (
                    <tr
                      key={key}
                      className={selected ? "is-selected" : ""}
                      onClick={() => setSelectedKey(key)}
                    >
                      <td>{item.title || `Playlist #${item.resource_id}`}</td>
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