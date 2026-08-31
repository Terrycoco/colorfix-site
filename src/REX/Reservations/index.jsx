import { useEffect, useState } from "react";
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

const LIST_URL = `${API_FOLDER}/v2/admin/rex/list.php`;
const ROUTE_CATALOG_URL = `${API_FOLDER}/v2/admin/rex/route-catalog.php`;
const CREATE_URL = `${API_FOLDER}/v2/admin/rex/create.php`;

export default function RexReservationsPage() {
  const [resourceTypes, setResourceTypes] = useState([]);
  const [resourceType, setResourceType] = useState("");
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const [selectedItem, setSelectedItem] = useState(null);
  const [showDetail, setShowDetail] = useState(false);

  const [showAdd, setShowAdd] = useState(false);
  const [routeItems, setRouteItems] = useState([]);
  const [selectedRouteId, setSelectedRouteId] = useState("");
  const [addLoading, setAddLoading] = useState(false);
  const [addError, setAddError] = useState("");

  useEffect(() => {
    let active = true;

    async function load() {
      setLoading(true);
      setError("");

      try {
        const params = new URLSearchParams();

        if (resourceType) {
          params.set("resource_type", resourceType);
        } else {
          params.set("resource_type", "page");
        }

        const res = await fetch(`${LIST_URL}?${params.toString()}`, {
          credentials: "include",
        });

        const data = await res.json();

        if (!res.ok || !data?.ok) {
          throw new Error(data?.error || "Failed to load REX reservations");
        }

        if (!active) return;

        const types = Array.isArray(data.resource_types)
          ? data.resource_types
          : [];

        setResourceTypes(types);

        if (!resourceType && types.length) {
          setResourceType(types[0]);
        }

        setItems(Array.isArray(data.items) ? data.items : []);
      } catch (err) {
        if (!active) return;
        setError(err?.message || "Failed to load REX reservations");
      } finally {
        if (active) setLoading(false);
      }
    }

    load();

    return () => {
      active = false;
    };
  }, [resourceType]);

  useEffect(() => {
    if (!showAdd || resourceType !== "page") return;

    let active = true;

    async function loadRoutes() {
      setAddLoading(true);
      setAddError("");

      try {
        const res = await fetch(ROUTE_CATALOG_URL, {
          credentials: "include",
        });

        const data = await res.json();

        if (!res.ok || !data?.ok) {
          throw new Error(data?.error || "Failed to load route catalog");
        }

        if (!active) return;

        const routes = Array.isArray(data.items) ? data.items : [];

        setRouteItems(routes);

        setSelectedRouteId((current) =>
          current || (routes[0]?.resource_id ? String(routes[0].resource_id) : "")
        );
      } catch (err) {
        if (!active) return;
        setAddError(err?.message || "Failed to load route catalog");
      } finally {
        if (active) setAddLoading(false);
      }
    }

    loadRoutes();

    return () => {
      active = false;
    };
  }, [showAdd, resourceType]);

  async function createPageRex() {
    const resourceId = Number(selectedRouteId || 0);
    if (!resourceId) return;

    const route = routeItems.find(
      (item) => Number(item.resource_id) === resourceId
    );

    if (!route) return;

    setAddLoading(true);
    setAddError("");

    try {
      const res = await fetch(CREATE_URL, {
        method: "POST",
        credentials: "include",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          label: route.title,
          resolver_key: "route",
          resource_type: "page",
          resource_id: resourceId,
          reuse_existing: true,
        }),
      });

      const data = await res.json();

      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || "Failed to create REX reservation");
      }

      setShowAdd(false);
      setResourceType("page");

      const listRes = await fetch(
        `${LIST_URL}?resource_type=page&_=${Date.now()}`,
        { credentials: "include" }
      );

      const listData = await listRes.json();

      if (!listRes.ok || !listData?.ok) {
        throw new Error(listData?.error || "REX created, but refresh failed");
      }

      setItems(Array.isArray(listData.items) ? listData.items : []);
    } catch (err) {
      setAddError(err?.message || "Failed to create REX reservation");
    } finally {
      setAddLoading(false);
    }
  }

  const drawerTitle = selectedItem
    ? `REX Reservation #${selectedItem.id}`
    : "REX Reservation";

  return (
    <>
      <AdminMasterDetail
        storageKey="admin-rex-list-width"
        defaultListWidth={280}
        minListWidth={240}
        maxListWidth={420}
        list={
          <AdminListPane title="REX Resources">
            {loading && resourceTypes.length === 0 ? (
              <AdminEmptyState title="Loading resource types" />
            ) : (
              <AdminObjectList ariaLabel="REX resource types">
                {resourceTypes.map((type) => (
                  <AdminObjectListItem
                    key={type}
                    id={type}
                    title={type.charAt(0).toUpperCase() + type.slice(1)}
                    selected={resourceType === type}
                    onSelect={() => {
                      setResourceType(type);
                      setSelectedItem(null);
                      setShowDetail(false);
                    }}
                  />
                ))}
              </AdminObjectList>
            )}
          </AdminListPane>
        }
        detail={
          <AdminDetailPane ariaLabel="REX reservations">
            <AdminWorkbench
              header={
                <div className="admin-detail-header">
                  <div>
                    <h1 className="admin-detail-header__title">
                      REX Reservations
                    </h1>
                    <p className="admin-detail-header__description">
                      Permanent reservations by resource.
                    </p>
                  </div>

                  <div className="admin-detail-header__actions">
                    <button
                      type="button"
                      onClick={() => setShowAdd(true)}
                    >
                      Add
                    </button>
                  </div>
                </div>
              }
              upperLeft={null}
              upperRight={null}
              upperHeight="0px"
              lower={
                <div
                  className="admin-detail-workarea"
                  style={{ height: "100%" }}
                >
                  {loading ? (
                    <AdminEmptyState title="Loading reservations" />
                  ) : error ? (
                    <AdminEmptyState
                      title="REX could not load"
                      message={error}
                    />
                  ) : items.length === 0 ? (
                    <AdminEmptyState
                      title={`No ${resourceType || "REX "} reservations yet`}
                      message="Use Add to create the first reservation for this resource type."
                    />
                  ) : (
                    <AdminDataGrid
                      ariaLabel={`${resourceType} REX reservations`}
                      items={items}
                      selectedKey={selectedItem?.id ?? null}
                      onSelectionChange={(item) => setSelectedItem(item)}
                      columns={[
                        {
                          key: "resource",
                          label: "Resource",
                          value: (item) =>
                            item.descriptor?.title ||
                            item.label ||
                            `${resourceType} #${item.resource_id}`,
                          render: (item) => (
                            <>
                              {item.descriptor?.error && (
                                <span
                                  className="admin-rex-resource-warning"
                                  title={item.descriptor.error}
                                >
                                  ⚠
                                </span>
                              )}
                              {item.descriptor?.title ||
                                item.label ||
                                `${resourceType} #${item.resource_id}`}
                            </>
                          ),
                        },
                        {
                          key: "id",
                          label: "REX ID",
                          value: (item) => Number(item.id),
                          render: (item) => `#${item.id}`,
                        },
                        {
                          key: "status",
                          label: "Status",
                          value: (item) => item.status || "",
                        },
                        {
                          key: "token",
                          label: "Token",
                          value: (item) => item.token || "",
                        },
                      ]}
                      getRowKey={(item) => item.id}
                      defaultSortKey="resource"
                      onRowDoubleClick={(item) => {
                        setSelectedItem(item);
                        setShowDetail(true);
                      }}
                    />
                  )}
                </div>
              }
              drawerOpen={showDetail && Boolean(selectedItem)}
              drawerWidth={440}
              drawerTitle={drawerTitle}
              drawerContent={
                selectedItem ? (
                  <div style={{ padding: 16 }}>
                    <div style={{ marginBottom: 14 }}>
                      <div style={{ fontWeight: 700 }}>
                        {selectedItem.descriptor?.title || selectedItem.label}
                      </div>
                      <div style={{ marginTop: 3, fontSize: 12, color: "#586675" }}>
                        {selectedItem.resolver_key || "—"} ·{" "}
                        {selectedItem.status || "—"}
                      </div>
                    </div>

                    <div
                      className="admin-detail-header__actions"
                      style={{ marginBottom: 16 }}
                    >
                      <button
                        type="button"
                        disabled={
                          !selectedItem.public_url && !selectedItem.token
                        }
                        onClick={() => {
                          const url =
                            selectedItem.public_url ||
                            (selectedItem.token
                              ? `/t/${selectedItem.token}`
                              : "");

                          if (!url) return;

                          window.open(
                            url,
                            "_blank",
                            "noopener,noreferrer"
                          );
                        }}
                      >
                        Launch
                      </button>
                    </div>

                    <pre
                      style={{
                        margin: 0,
                        padding: 12,
                        overflow: "auto",
                        whiteSpace: "pre-wrap",
                        wordBreak: "break-word",
                        fontSize: 12,
                      }}
                    >
                      {JSON.stringify(selectedItem, null, 2)}
                    </pre>
                  </div>
                ) : null
              }
              onCloseDrawer={() => setShowDetail(false)}
            />
          </AdminDetailPane>
        }
      />

      {showAdd && (
        <div
          className="admin-rex-detail-modal"
          role="dialog"
          aria-modal="true"
          aria-label="Add REX reservation"
        >
          <div
            className="admin-rex-detail-modal__backdrop"
            onClick={() => setShowAdd(false)}
          />

          <div className="admin-rex-detail-modal__panel">
            <div className="admin-rex-detail-modal__header">
              <div>
                <h2>Add REX Reservation</h2>
                <div>
                  {resourceType
                    ? `Resource type: ${resourceType}`
                    : "Select a resource type"}
                </div>
              </div>

              <button
                type="button"
                onClick={() => setShowAdd(false)}
                aria-label="Close add reservation"
              >
                ×
              </button>
            </div>

            <div className="admin-rex-detail-modal__body">
              {resourceType === "page" ? (
                addLoading ? (
                  <AdminEmptyState title="Loading routes" />
                ) : addError ? (
                  <AdminEmptyState
                    title="Routes could not load"
                    message={addError}
                  />
                ) : routeItems.length === 0 ? (
                  <AdminEmptyState title="No routes in catalog" />
                ) : (
                  <label>
                    Route
                    <select
                      value={selectedRouteId}
                      onChange={(e) => setSelectedRouteId(e.target.value)}
                    >
                      {routeItems.map((route) => (
                        <option
                          key={route.resource_id}
                          value={route.resource_id}
                        >
                          {route.title} — {route.path}
                        </option>
                      ))}
                    </select>

                    <div className="admin-rex-route-note">
                      To add a new Page route, first add it to:
                      <br />
                      <code>app/REX/Resources/RexRouteCatalog.php</code>
                    </div>
                  </label>
                )
              ) : (
                <AdminEmptyState
                  title="Picker not added yet"
                  message={`Resource picker for ${resourceType || "this type"} comes next.`}
                />
              )}

              {resourceType === "page" &&
                routeItems.length > 0 &&
                !addLoading && (
                  <div style={{ marginTop: 16 }}>
                    <button
                      type="button"
                      onClick={createPageRex}
                      disabled={!selectedRouteId}
                    >
                      Fetch Token
                    </button>
                  </div>
                )}
            </div>
          </div>
        </div>
      )}
    </>
  );
}
