import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import AddressModal from "@components/AddressModal";
import { API_FOLDER } from "@helpers/config";
import "../AdminProjectsPage/admin-projects.css";
import "./admin-properties.css";

const LIST_URL = `${API_FOLDER}/v2/admin/properties/list.php`;
const GET_URL = `${API_FOLDER}/v2/admin/properties/get.php`;
const SAVE_URL = `${API_FOLDER}/v2/admin/properties/save.php`;
const ADDRESS_SAVE_URL = `${API_FOLDER}/v2/admin/properties/address-save.php`;
const ADDRESS_REMOVE_URL = `${API_FOLDER}/v2/admin/properties/address-remove.php`;
const OPTIONS_URL = `${API_FOLDER}/v2/admin/projects/options.php`;

const emptyProperty = {
  id: null,
  name: "",
  client_id: "",
  notes: "",
  address: null,
};

function addressLine(address) {
  if (!address) return "No address entered";
  return [address.street_1, address.street_2, address.city, address.state, address.postal_code]
    .filter(Boolean)
    .join(", ");
}

function formatDate(value) {
  if (!value) return "";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);
  return date.toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" });
}

function clientDisplayName(client) {
  return client?.name || client?.email || `Client #${client?.id || ""}`;
}

function sortClientsByName(items) {
  return [...items].sort((a, b) => clientDisplayName(a).localeCompare(clientDisplayName(b), undefined, { sensitivity: "base" }));
}

export default function AdminPropertiesPage() {
  const [properties, setProperties] = useState([]);
  const [clients, setClients] = useState([]);
  const [projects, setProjects] = useState([]);
  const [selectedId, setSelectedId] = useState(null);
  const [form, setForm] = useState(emptyProperty);
  const [filters, setFilters] = useState({ q: "" });
  const [loadingList, setLoadingList] = useState(true);
  const [loadingDetail, setLoadingDetail] = useState(false);
  const [saving, setSaving] = useState(false);
  const [addressModalOpen, setAddressModalOpen] = useState(false);
  const [addressSaving, setAddressSaving] = useState(false);
  const [status, setStatus] = useState("");
  const [error, setError] = useState("");
  const selectedIdRef = useRef(null);
  const propertyModeRef = useRef("detail");

  const selectedProperty = useMemo(
    () => properties.find((item) => Number(item.id) === Number(selectedId)) || null,
    [properties, selectedId]
  );

  const loadOptions = useCallback(async () => {
    const res = await fetch(`${OPTIONS_URL}?_=${Date.now()}`, { credentials: "include" });
    const data = await res.json();
    if (res.ok && data?.ok) {
      setClients(sortClientsByName(Array.isArray(data.clients) ? data.clients : []));
    }
  }, []);

  const loadProperty = useCallback(async (id) => {
    if (!id) return;
    propertyModeRef.current = "detail";
    selectedIdRef.current = id;
    setLoadingDetail(true);
    setError("");
    try {
      const res = await fetch(`${GET_URL}?id=${encodeURIComponent(id)}&_=${Date.now()}`, { credentials: "include" });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      const data = JSON.parse(text);
      if (!data?.ok || !data?.property) throw new Error(data?.error || "Failed to load property");
      setForm({
        id: data.property.id,
        name: data.property.name || "",
        client_id: data.property.client_id || "",
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

  const loadProperties = useCallback(async (preferredId = null) => {
    setLoadingList(true);
    setError("");
    try {
      const params = new URLSearchParams();
      if (filters.q.trim()) params.set("q", filters.q.trim());
      params.set("_", String(Date.now()));
      const res = await fetch(`${LIST_URL}?${params.toString()}`, { credentials: "include" });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status}: ${text.slice(0, 200)}`);
      const data = JSON.parse(text);
      if (!data?.ok) throw new Error(data?.error || "Failed to load properties");
      const items = Array.isArray(data.items) ? data.items : [];
      setProperties(items);
      if (!preferredId && propertyModeRef.current === "new") {
        return;
      }
      const targetId = preferredId || selectedIdRef.current || (items[0] ? Number(items[0].id) : null);
      if (targetId) {
        propertyModeRef.current = "detail";
        selectedIdRef.current = targetId;
        setSelectedId(targetId);
        await loadProperty(targetId);
      } else {
        resetForm();
      }
    } catch (err) {
      setError(err?.message || "Failed to load properties");
    } finally {
      setLoadingList(false);
    }
  }, [filters.q, loadProperty]);

  useEffect(() => {
    void loadOptions();
  }, [loadOptions]);

  useEffect(() => {
    void loadProperties();
  }, [loadProperties]);

  function resetForm() {
    propertyModeRef.current = "new";
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
    setSaving(true);
    setStatus("");
    setError("");
    try {
      const payload = {
        id: form.id,
        name: form.name.trim(),
        client_id: Number(form.client_id || 0) || null,
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
      if (!form.id && form.address?.street_1) {
        const addressRes = await fetch(ADDRESS_SAVE_URL, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ property_id: data.id, address: form.address }),
        });
        const addressText = await addressRes.text();
        if (!addressRes.ok) throw new Error(`HTTP ${addressRes.status}: ${addressText.slice(0, 200)}`);
        const addressData = JSON.parse(addressText);
        if (!addressData?.ok) throw new Error(addressData?.error || "Failed to save address");
      }
      setStatus("Property saved.");
      await loadProperties(Number(data.id));
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
    if (!form.id || !window.confirm("Remove the address from this property?")) return;
    setAddressSaving(true);
    setError("");
    try {
      const res = await fetch(ADDRESS_REMOVE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ property_id: form.id }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to remove address");
      setAddressModalOpen(false);
      setStatus("Address removed.");
      await loadProperties(form.id);
    } catch (err) {
      setError(err?.message || "Failed to remove address");
    } finally {
      setAddressSaving(false);
    }
  }

  return (
    <div className="admin-projects admin-properties">
      <aside className="admin-projects__sidebar">
        <div className="admin-projects__sidebar-header">
          <div>
            <h1>Properties</h1>
            <p>Physical places where project work may happen.</p>
          </div>
          <button type="button" className="admin-projects__btn admin-projects__btn--primary" onClick={resetForm}>
            New Property
          </button>
        </div>

        <div className="admin-projects__filters">
          <input
            type="text"
            placeholder="Search properties"
            value={filters.q}
            onChange={(event) => setFilters({ q: event.target.value })}
          />
        </div>

        <div className="admin-projects__project-list">
          {loadingList ? (
            <div className="admin-projects__empty">Loading properties...</div>
          ) : properties.length === 0 ? (
            <div className="admin-projects__empty">No properties yet.</div>
          ) : properties.map((property) => (
            <button
              key={property.id}
              type="button"
              className={`admin-projects__project-card${Number(selectedId) === Number(property.id) ? " is-active" : ""}`}
              onClick={() => {
                setSelectedId(property.id);
                void loadProperty(property.id);
              }}
            >
              <span className="admin-projects__project-title">{property.name || "Untitled property"}</span>
              <span className="admin-projects__project-meta">
                <span>{property.client_name || "No client assigned"}</span>
                <span>{property.project_count || 0} project{Number(property.project_count || 0) === 1 ? "" : "s"}</span>
              </span>
              <span className="admin-projects__project-slug">{addressLine(property.address)}</span>
            </button>
          ))}
        </div>
      </aside>

      <main className="admin-projects__main">
        {error ? <div className="admin-projects__message admin-projects__message--error">{error}</div> : null}
        {status ? <div className="admin-projects__message admin-projects__message--status">{status}</div> : null}

        <section className="admin-projects__panel">
          <div className="admin-projects__panel-header">
            <div>
              <h2>{form.id ? form.name || "Property" : "New Property"}</h2>
              {selectedProperty?.updated_at ? <p className="admin-properties__subhead">Updated {formatDate(selectedProperty.updated_at)}</p> : null}
            </div>
            <div className="admin-projects__actions">
              {form.id ? (
                <a className="admin-projects__btn" href={`/admin/projects?action=new&property_id=${encodeURIComponent(form.id)}`}>
                  New Project for this Property
                </a>
              ) : null}
              <button type="button" className="admin-projects__btn admin-projects__btn--primary" onClick={saveProperty} disabled={saving}>
                {saving ? "Saving..." : "Save Property"}
              </button>
            </div>
          </div>

          {loadingDetail ? (
            <div className="admin-projects__empty">Loading property...</div>
          ) : (
            <div className="admin-projects__form-grid">
              <label>
                <span>Property name</span>
                <input value={form.name} onChange={(event) => updateForm("name", event.target.value)} />
              </label>
              <label>
                <span>Client</span>
                <select value={form.client_id || ""} onChange={(event) => updateForm("client_id", event.target.value)}>
                  <option value="">No client assigned</option>
                  {clients.map((client) => (
                    <option key={client.id} value={client.id}>{clientDisplayName(client)}</option>
                  ))}
                </select>
              </label>
              <div className="admin-projects__field admin-projects__wide">
                <span>Address</span>
                <div className="admin-properties__address-row">
                  <strong>{addressLine(form.address)}</strong>
                  <button type="button" className="admin-projects__btn" onClick={() => setAddressModalOpen(true)}>
                    {form.address ? "Add/Edit Address" : "Add Address"}
                  </button>
                </div>
              </div>
              <label className="admin-projects__wide">
                <span>Notes</span>
                <textarea value={form.notes} rows={4} onChange={(event) => updateForm("notes", event.target.value)} />
              </label>
            </div>
          )}
        </section>

        <section className="admin-projects__panel">
          <div className="admin-projects__panel-header">
            <h2>Projects at this property</h2>
          </div>
          {projects.length === 0 ? (
            <div className="admin-projects__empty">No projects yet.</div>
          ) : (
            <div className="admin-properties__project-table">
              {projects.map((project) => (
                <a key={project.id} href={`/admin/projects?project_id=${project.id}`} className="admin-properties__project-row">
                  <span>{project.name}</span>
                  <span>{project.project_type_name}</span>
                  <span>{project.experience_key}</span>
                </a>
              ))}
            </div>
          )}
        </section>
      </main>

      <AddressModal
        open={addressModalOpen}
        title="Property Address"
        address={form.address}
        saving={addressSaving}
        onClose={() => setAddressModalOpen(false)}
        onSave={saveAddress}
        onRemove={form.address ? removeAddress : undefined}
      />
    </div>
  );
}
