import { useMemo, useState } from "react";
import {
  AdminMasterDetail,
  AdminListPane,
  AdminDetailPane,
  AdminObjectList,
  AdminObjectListItem,
  AdminEmptyState,
} from "@components/AdminLayout";

const RESOURCE_TYPES = [
  { key: "playlist", label: "Playlists", singular: "Playlist" },
  { key: "viewer", label: "Viewers", singular: "Viewer" },
  { key: "thumbs", label: "Thumbs", singular: "Thumbs" },
  { key: "article", label: "Articles", singular: "Article" },
  { key: "page", label: "Pages", singular: "Page" },
];

export default function RexResourcesPage() {
  const [resourceType, setResourceType] = useState("playlist");
  const [query, setQuery] = useState("");
  const [resources] = useState([]);
  const [selectedId, setSelectedId] = useState(null);

  const type = RESOURCE_TYPES.find((item) => item.key === resourceType) || RESOURCE_TYPES[0];

  const visibleResources = useMemo(() => {
    const needle = query.trim().toLowerCase();
    if (!needle) return resources;

    return resources.filter((resource) => {
      const haystack = [resource.id, resource.title, ...(resource.meta || [])]
        .filter((value) => value !== null && value !== undefined)
        .join(" ")
        .toLowerCase();

      return haystack.includes(needle);
    });
  }, [query, resources]);

  const selectedResource = resources.find((resource) => resource.id === selectedId) || null;

  function changeResourceType(event) {
    setResourceType(event.target.value);
    setQuery("");
    setSelectedId(null);
  }

  return (
    <AdminMasterDetail
      storageKey="rex-resources-list-width"
      defaultListWidth={340}
      list={
        <AdminListPane
          title="REX"
          toolbar={
            <div style={{ display: "grid", gap: 8 }}>
              <label style={{ display: "grid", gap: 4, fontSize: 12 }}>
                <span>Object Type</span>
                <select value={resourceType} onChange={changeResourceType}>
                  {RESOURCE_TYPES.map((item) => (
                    <option key={item.key} value={item.key}>
                      {item.label}
                    </option>
                  ))}
                </select>
              </label>

              <input
                type="search"
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                placeholder={`Search ${type.label.toLowerCase()}…`}
                aria-label={`Search ${type.label}`}
              />
            </div>
          }
        >
          {visibleResources.length > 0 ? (
            <AdminObjectList ariaLabel={type.label}>
              {visibleResources.map((resource) => (
                <AdminObjectListItem
                  key={resource.id}
                  id={resource.id}
                  title={resource.title}
                  meta={resource.meta || []}
                  selected={selectedId === resource.id}
                  status={resource.rexStatus || null}
                  onSelect={() => setSelectedId(resource.id)}
                />
              ))}
            </AdminObjectList>
          ) : (
            <AdminEmptyState
              title={`${type.label} will appear here`}
              message={`The ${type.singular.toLowerCase()} loader is the next piece to wire into REX.`}
            />
          )}
        </AdminListPane>
      }
      detail={
        <AdminDetailPane ariaLabel="REX details">
          {selectedResource ? (
            <div>
              <h1 style={{ marginTop: 0 }}>{selectedResource.title}</h1>

              <section>
                <h2>REX</h2>
                <div>Own reservation(s) will appear here.</div>
              </section>

              <section>
                <h2>Parents</h2>
                <div>Parent REX relationships will appear here.</div>
              </section>

              <section>
                <h2>Children</h2>
                <div>Child REX relationships will appear here.</div>
              </section>

              <section>
                <h2>Fallback</h2>
                <div>Fallback REX will appear here.</div>
              </section>
            </div>
          ) : (
            <AdminEmptyState
              title={`Select a ${type.singular.toLowerCase()}`}
              message={`Choose a ${type.singular.toLowerCase()} on the left to inspect its REX reservations and relationships.`}
            />
          )}
        </AdminDetailPane>
      }
    />
  );
}
