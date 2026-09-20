import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import AddressModal from "@components/AddressModal";
import {
  AdminDetailPane,
  AdminEmptyState,
  AdminListPane,
  AdminMasterDetail,
  AdminObjectList,
  AdminObjectListItem,
} from "@components/AdminLayout";
import { API_FOLDER } from "@helpers/config";
import { useAppState } from "@context/AppStateContext";
import "./admin-properties.css";

const LIST_URL = `${API_FOLDER}/v2/admin/properties/list.php`;
const GET_URL = `${API_FOLDER}/v2/admin/properties/get.php`;
const SAVE_URL = `${API_FOLDER}/v2/admin/properties/save.php`;
const ADDRESS_SAVE_URL = `${API_FOLDER}/v2/admin/properties/address-save.php`;
const ADDRESS_REMOVE_URL = `${API_FOLDER}/v2/admin/properties/address-remove.php`;

const emptyProperty = {
  id: null,
  name: "",
  notes: "",
  address: null,
};

function addressLine(address) {
  if (!address) return "No address entered";
  return [
    address.street_1,
    address.street_2,
    address.city,
    address.state,
    address.postal_code,
  ]
    .filter(Boolean)
    .join(", ");
}

function formatDate(value) {
  if (!value) return "";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleDateString(undefined, {
    month: "short",
    day: "numeric",
    year: "numeric",
  });
}

export default function AdminPropertiesPage() {
  const {
    adminExitPath,
    clearAdminExitPath,
  } = useAppState();

  const initialRouteRef = useRef(
    typeof window === "undefined"
      ? { propertyId: "", action: "" }
      : {
          propertyId: new URLSearchParams(window.location.search).get("property_id") || "",
          action: new URLSearchParams(window.location.search).get("action") || "",
        }
  );
  const routeConsumedRef = useRef(false);

  const [properties, setProperties] = useState([]);
  const [projects, setProjects] = useState([]);
  const [selectedId, setSelectedId] = useState(null);
  const [form, setForm] = useState(emptyProperty);
  const [query, setQuery] = useState("");
  const [loadingList, setLoadingList] = useState(true);
  const [loadingDetail, setLoadingDetail] = useState(false);
  const [saving, setSaving] = useState(false);
  const [addressModalOpen, setAddressModalOpen] = useState(false);
  const [addressSaving, setAddressSaving] = useState(false);
  const [status, setStatus] = useState("");
  const [error, setError] = useState("");

  const selectedIdRef = useRef(null);
  const modeRef = useRef("detail");

  function handleBack() {
    const target =
      String(
        adminExitPath ||
        ""
      ).trim();

    if (target) {
      clearAdminExitPath();
      window.location.assign(target);
      return;
    }

    window.history.back();
  }

  const selectedProperty = useMemo(
    () => properties.find((item) => Number(item.id) === Number(selectedId)) || null,
    [properties, selectedId]
  );

  const loadProperty = useCallback(async (id) => {
    if (!id) return;

    modeRef.current = "detail";
    selectedIdRef.current = Number(id);
    setSelectedId(Number(id));
    setLoadingDetail(true);
    setError("");

    try {
      const res = await fetch(
        `${GET_URL}?id=${encodeURIComponent(id)}&_=${Date.now()}`,
        { credentials: "include" }
      );
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);

      const data = JSON.parse(text);
      if (!data?.ok || !data?.property) {
        throw new Error(data?.error || "Failed to load property");
      }

      setForm({
        id: data.property.id,
        name: data.property.name || "",
        notes: data.property.notes || "",
        address: data.property.address || null,
      });
      setProjects(Array.isArray(data.projects) ? data.projects : []);
    } catch (err) {
      setError(err?.message || "Failed to load property");
    } finally {
      setLoadingDetail(false);
    }
  }, []);

  const loadProperties = useCallback(
    async (preferredId = null) => {
      setLoadingList(true);
      setError("");

      try {
        const params = new URLSearchParams();
        if (query.trim()) params.set("q", query.trim());
        params.set("_", String(Date.now()));

        const res = await fetch(`${LIST_URL}?${params.toString()}`, {
          credentials: "include",
        });
        const text = await res.text();
        if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);

        const data = JSON.parse(text);
        if (!data?.ok) throw new Error(data?.error || "Failed to load properties");

        const items = Array.isArray(data.items) ? data.items : [];
        setProperties(items);

        if (!routeConsumedRef.current) {
          routeConsumedRef.current = true;

          const requestedAction =
            String(
              initialRouteRef.current.action ||
              ""
            ).trim().toLowerCase();

          const requestedPropertyId =
            Number(
              initialRouteRef.current.propertyId ||
              0
            );

          if (requestedAction === "new") {
            startNewProperty();
            return;
          }

          if (requestedPropertyId > 0) {
            await loadProperty(
              requestedPropertyId
            );
            return;
          }
        }

        if (modeRef.current === "new" && !preferredId) return;

        const preferred = preferredId ? Number(preferredId) : null;
        const current = selectedIdRef.current ? Number(selectedIdRef.current) : null;
        const currentStillVisible = current && items.some((item) => Number(item.id) === current);
        const targetId =
          preferred ||
          (currentStillVisible ? current : null) ||
          (items[0] ? Number(items[0].id) : null);

        if (targetId) {
          await loadProperty(targetId);
        } else {
          selectedIdRef.current = null;
          setSelectedId(null);
          setForm(emptyProperty);
          setProjects([]);
        }
      } catch (err) {
        setError(err?.message || "Failed to load properties");
      } finally {
        setLoadingList(false);
      }
    },
    [loadProperty, query]
  );

  useEffect(() => {
    void loadProperties();
  }, [loadProperties]);

  function startNewProperty() {
    modeRef.current = "new";
    selectedIdRef.current = null;
    setSelectedId(null);
    setForm(emptyProperty);
    setProjects([]);
    setStatus("");
    setError("");
  }

  function updateForm(field, value) {
    setForm((prev) => ({ ...prev, [field]: value }));
  }

  async function saveProperty() {
    const name = form.name.trim();
    if (!name) {
      setError("Property name required.");
      return;
    }

    setSaving(true);
    setStatus("");
    setError("");

    try {
      const payload = {
        id: form.id,
        name,
        notes: form.notes.trim(),
      };

      const res = await fetch(SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);

      const data = JSON.parse(text);
      if (!data?.ok) throw new Error(data?.error || "Failed to save property");

      const propertyId = Number(data.id);

      if (!form.id && form.address?.street_1) {
        const addressRes = await fetch(ADDRESS_SAVE_URL, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ property_id: propertyId, address: form.address }),
        });
        const addressText = await addressRes.text();
        if (!addressRes.ok) {
          throw new Error(`HTTP ${addressRes.status}: ${addressText.slice(0, 200)}`);
        }

        const addressData = JSON.parse(addressText);
        if (!addressData?.ok) {
          throw new Error(addressData?.error || "Failed to save address");
        }
      }

      modeRef.current = "detail";
      selectedIdRef.current = propertyId;
      setStatus("Property saved.");
      await loadProperties(propertyId);
    } catch (err) {
      setError(err?.message || "Failed to save property");
    } finally {
      setSaving(false);
    }
  }

  async function saveAddress(address) {
    if (!form.id) {
      updateForm("address", address);
      setAddressModalOpen(false);
      setStatus("Address will be saved with the property.");
      return;
    }

    setAddressSaving(true);
    setError("");

    try {
      const res = await fetch(ADDRESS_SAVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ property_id: form.id, address }),
      });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);

      const data = JSON.parse(text);
      if (!data?.ok) throw new Error(data?.error || "Failed to save address");

      setAddressModalOpen(false);
      setStatus("Address saved.");
      await loadProperties(form.id);
    } catch (err) {
      setError(err?.message || "Failed to save address");
    } finally {
      setAddressSaving(false);
    }
  }

  async function removeAddress() {
    if (!form.id) {
      updateForm("address", null);
      setAddressModalOpen(false);
      return;
    }

    if (!window.confirm("Remove the address from this property?")) return;

    setAddressSaving(true);
    setError("");

    try {
      const res = await fetch(ADDRESS_REMOVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ property_id: form.id }),
      });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);

      const data = JSON.parse(text);
      if (!data?.ok) throw new Error(data?.error || "Failed to remove address");

      setAddressModalOpen(false);
      setStatus("Address removed.");
      await loadProperties(form.id);
    } catch (err) {
      setError(err?.message || "Failed to remove address");
    } finally {
      setAddressSaving(false);
    }
  }

  const listPane = (
    <AdminListPane
      title="Properties"
      actions={
        <div
          style={{
            display: "flex",
            alignItems: "center",
            gap: "6px",
          }}
        >
          <button
            type="button"
            className="admin-properties__button admin-properties__button--compact"
            onClick={handleBack}
            title="Back"
          >
            ← Back
          </button>

          <button
            type="button"
            className="admin-properties__button admin-properties__button--primary admin-properties__button--compact"
            onClick={startNewProperty}
          >
            New
          </button>
        </div>
      }
      toolbar={
        <input
          className="admin-properties__search"
          type="search"
          placeholder="Search properties"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
        />
      }
    >
      {loadingList ? (
        <AdminEmptyState title="Loading properties…" />
      ) : properties.length === 0 ? (
        <AdminEmptyState
          title="No properties found"
          message={query.trim() ? "Try a different search." : "Create the first property."}
        />
      ) : (
        <AdminObjectList ariaLabel="Properties">
          {properties.map((property) => (
            <AdminObjectListItem
              key={property.id}
              id={property.id}
              title={property.name || "Untitled property"}
              selected={Number(selectedId) === Number(property.id)}
              meta={[
                addressLine(property.address),
                `${Number(property.project_count || 0)} project${Number(property.project_count || 0) === 1 ? "" : "s"}`,
              ]}
              onSelect={() => void loadProperty(property.id)}
            />
          ))}
        </AdminObjectList>
      )}
    </AdminListPane>
  );

  const detailPane = (
    <AdminDetailPane ariaLabel="Property detail" className="admin-properties__detail">
      {error ? <div className="admin-properties__message admin-properties__message--error">{error}</div> : null}
      {status ? <div className="admin-properties__message admin-properties__message--status">{status}</div> : null}

      <header className="admin-detail-header">
        <div>
          <h1 className="admin-detail-header__title">
            {form.id ? form.name || "Property" : "New Property"}
          </h1>
          <p className="admin-detail-header__description">
            {form.id && selectedProperty?.updated_at
              ? `Updated ${formatDate(selectedProperty.updated_at)}`
              : "A physical place where project work may happen."}
          </p>
        </div>

        <div className="admin-detail-header__actions">
          {form.id ? (
            <a
              className="admin-properties__button"
              href={`/admin/projects?action=new&property_id=${encodeURIComponent(form.id)}`}
            >
              New Project
            </a>
          ) : null}
          <button
            type="button"
            className="admin-properties__button admin-properties__button--primary"
            onClick={saveProperty}
            disabled={saving || loadingDetail}
          >
            {saving ? "Saving…" : "Save Property"}
          </button>
        </div>
      </header>

      {loadingDetail ? (
        <AdminEmptyState title="Loading property…" />
      ) : (
        <>
          <section className="admin-properties__section">
            <h2 className="admin-properties__section-title">Property</h2>

            <div className="admin-properties__form-grid">
              <label className="admin-field admin-properties__wide">
                <span className="admin-field__label">Property name</span>
                <input
                  className="admin-field__control admin-properties__input"
                  value={form.name}
                  onChange={(event) => updateForm("name", event.target.value)}
                  placeholder="e.g. Biane Winery"
                />
              </label>

              <div className="admin-field admin-properties__wide">
                <span className="admin-field__label">Address</span>
                <div className="admin-properties__address-box">
                  <div className={`admin-properties__address-text${form.address ? "" : " is-empty"}`}>
                    {addressLine(form.address)}
                  </div>
                  <button
                    type="button"
                    className="admin-properties__button"
                    onClick={() => setAddressModalOpen(true)}
                  >
                    {form.address ? "Edit Address" : "Add Address"}
                  </button>
                </div>
              </div>

              <label className="admin-field admin-properties__wide">
                <span className="admin-field__label">Notes</span>
                <textarea
                  className="admin-properties__textarea"
                  value={form.notes}
                  rows={5}
                  onChange={(event) => updateForm("notes", event.target.value)}
                />
              </label>
            </div>
          </section>

          <section className="admin-properties__section">
            <div className="admin-properties__section-heading-row">
              <h2 className="admin-properties__section-title">Projects at this property</h2>
              {form.id ? <span className="admin-properties__count">{projects.length}</span> : null}
            </div>

            {!form.id ? (
              <div className="admin-properties__empty-row">Save the property before adding projects.</div>
            ) : projects.length === 0 ? (
              <div className="admin-properties__empty-row">No projects at this property yet.</div>
            ) : (
              <div className="admin-properties__project-list">
                {projects.map((project) => (
                  <a
                    key={project.id}
                    href={`/admin/projects?project_id=${encodeURIComponent(project.id)}`}
                    className="admin-properties__project-row"
                  >
                    <span className="admin-properties__project-name">{project.project_name || `Project #${project.id}`}</span>
                    <span>{project.client_name || ""}</span>
                    <span>{project.playlist_title || ""}</span>
                  </a>
                ))}
              </div>
            )}
          </section>
        </>
      )}

      <AddressModal
        open={addressModalOpen}
        title="Property Address"
        address={form.address}
        saving={addressSaving}
        onClose={() => setAddressModalOpen(false)}
        onSave={saveAddress}
        onRemove={form.address ? removeAddress : undefined}
      />
    </AdminDetailPane>
  );

  return (
    <AdminMasterDetail
      className="admin-properties"
      storageKey="admin-properties-list-width"
      defaultListWidth={330}
      minListWidth={240}
      maxListWidth={500}
      list={listPane}
      detail={detailPane}
    />
  );
}
