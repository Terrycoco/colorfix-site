import {
  useEffect,
  useMemo,
  useState,
} from "react";

import {
  AdminButton,
  AdminDetailPane,
  AdminWorkbenchDrawer,
  AdminEmptyState,
  AdminField,
  AdminNotice,
  AdminSmartGrid,
  AdminStack,
  AdminToolbar,
  AdminToolbarSpacer,
} from "@components/AdminLayout";

import {
  API_FOLDER,
} from "@helpers/config";

const LIST_URL =
  `${API_FOLDER}/v2/admin/projects/activity/list.php`;

const SAVE_URL =
  `${API_FOLDER}/v2/admin/projects/activity/save.php`;

function cleanText(value) {
  return String(value ?? "").trim();
}

async function readJson(response, fallbackMessage) {
  const text = await response.text();
  let data = {};

  try {
    data = text.trim() ? JSON.parse(text) : {};
  } catch {
    throw new Error(`${fallbackMessage}: invalid JSON response`);
  }

  if (!response.ok || data?.ok === false) {
    throw new Error(data?.error || `HTTP ${response.status}`);
  }

  return data;
}

function todayValue() {
  const now = new Date();
  const offset = now.getTimezoneOffset();
  return new Date(now.getTime() - offset * 60000)
    .toISOString()
    .slice(0, 10);
}

function formatDate(value) {
  const text = cleanText(value);
  if (!text) return "—";

  const date = new Date(`${text.slice(0, 10)}T12:00:00`);
  if (Number.isNaN(date.getTime())) return text;

  return date.toLocaleDateString(undefined, {
    month: "short",
    day: "numeric",
    year: "numeric",
  });
}

function formatNumber(value) {
  if (value === null || value === undefined || value === "") {
    return "—";
  }

  const number = Number(value);
  return Number.isFinite(number) ? number.toString() : "—";
}

function formatAmount(value) {
  if (value === null || value === undefined || value === "") {
    return "—";
  }

  const number = Number(value);
  if (!Number.isFinite(number)) return "—";

  return number.toLocaleString(undefined, {
    style: "currency",
    currency: "USD",
  });
}

function emptyEntry() {
  return {
    activity_date: todayValue(),
    description: "",
    hours: "",
    miles: "",
    amount: "",
  };
}

export default function ProjectActivity({ projectId, projectName = "" }) {
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [selectedKey, setSelectedKey] = useState(null);
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [drawerMode, setDrawerMode] = useState("new");
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState("");
  const [entry, setEntry] = useState(emptyEntry);

  async function loadActivity() {
    const id = Number(projectId || 0);

    if (id <= 0) {
      setItems([]);
      setLoading(false);
      return;
    }

    setLoading(true);
    setError("");

    try {
      const data = await readJson(
        await fetch(
          `${LIST_URL}?project_id=${encodeURIComponent(id)}&_=${Date.now()}`,
          {
            credentials: "include",
            cache: "no-store",
          }
        ),
        "Failed to load Project Activity"
      );

      const rows = Array.isArray(data?.items)
        ? data.items
        : Array.isArray(data?.activity)
          ? data.activity
          : [];

      setItems(rows);
      setSelectedKey((current) =>
        current && rows.some((row) => Number(row?.id) === Number(current))
          ? current
          : null
      );
    } catch (err) {
      setItems([]);
      setError(err?.message || "Failed to load Project Activity.");
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    void loadActivity();
  }, [projectId]);

  const columns = useMemo(
    () => [
      {
        key: "activity_date",
        label: "Date",
        sortable: true,
        value: (item) => formatDate(item?.activity_date),
      },
      {
        key: "description",
        label: "Description",
        sortable: true,
        value: (item) => cleanText(item?.description) || "—",
      },
      {
        key: "hours",
        label: "Hours",
        sortable: true,
        value: (item) => formatNumber(item?.hours),
      },
      {
        key: "miles",
        label: "Miles",
        sortable: true,
        value: (item) => formatNumber(item?.miles),
      },
      {
        key: "amount",
        label: "Amount",
        sortable: true,
        value: (item) => formatAmount(item?.amount),
      },
      {
        key: "entry_type",
        label: "Type",
        sortable: true,
        value: (item) =>
          cleanText(item?.entry_type).toLowerCase() === "system"
            ? "System"
            : "Manual",
      },
    ],
    []
  );

  function openNewEntry() {
    setEntry(emptyEntry());
    setSaveError("");
    setDrawerMode("new");
    setSelectedKey(null);
    setDrawerOpen(true);
  }

  function openExistingEntry(row) {
    const key = Number(row?.id || 0);

    setSelectedKey(key > 0 ? key : null);
    setDrawerMode("edit");
    setEntry({
      id: key > 0 ? key : null,
      activity_date: cleanText(row?.activity_date).slice(0, 10),
      description: cleanText(row?.description),
      hours: row?.hours ?? "",
      miles: row?.miles ?? "",
      amount: row?.amount ?? "",
    });
    setSaveError("");
    setDrawerOpen(true);
  }

  function closeDrawer() {
    if (saving) return;
    setDrawerOpen(false);
    setSaveError("");
  }

  function setField(key, value) {
    setEntry((current) => ({
      ...current,
      [key]: value,
    }));
    setSaveError("");
  }

  async function saveEntry() {
    const id = Number(projectId || 0);
    const description = cleanText(entry.description);

    if (id <= 0 || !entry.activity_date || !description) {
      setSaveError("Date and description are required.");
      return;
    }

    setSaving(true);
    setSaveError("");

    try {
      await readJson(
        await fetch(SAVE_URL, {
          method: "POST",
          credentials: "include",
          headers: {
            "Content-Type": "application/json",
          },
          body: JSON.stringify({
            project_id: id,
            activity_id: drawerMode === "edit" ? Number(entry?.id || 0) : null,
            activity_date: entry.activity_date,
            description,
            hours: entry.hours === "" ? null : Number(entry.hours),
            miles: entry.miles === "" ? null : Number(entry.miles),
            amount: entry.amount === "" ? null : Number(entry.amount),
          }),
        }),
        "Failed to save Project Activity"
      );

      setDrawerOpen(false);
      setEntry(emptyEntry());
      await loadActivity();
    } catch (err) {
      setSaveError(err?.message || "Failed to save Project Activity.");
    } finally {
      setSaving(false);
    }
  }

  const canSave =
    Boolean(entry.activity_date) && Boolean(cleanText(entry.description));

  return (
    <>
      <AdminDetailPane
        ariaLabel="Project Activity"
        title={`${cleanText(projectName) || `Project #${projectId}`} Activity`}
        actions={
          <AdminButton
            type="button"
            onClick={openNewEntry}
            disabled={loading}
          >
            New
          </AdminButton>
        }
      >
        {error ? (
          <AdminNotice variant="danger">{error}</AdminNotice>
        ) : null}

        {!loading && !error && items.length ? (
          <AdminSmartGrid
            items={items}
            columns={columns}
            getRowKey={(item) => Number(item?.id || 0)}
            selectedKey={selectedKey}
            onSelectionChange={(item, key) =>
              setSelectedKey(key ?? item?.id ?? null)
            }
            defaultSortKey="activity_date"
            defaultSortDirection="desc"
            ariaLabel="Project Activity"
            verticalAlign="middle"
            onRowDoubleClick={openExistingEntry}
          />
        ) : null}

        {!loading && !error && !items.length ? (
          <AdminEmptyState
            title="No activity yet"
            message="Click New to enter the first project activity."
          />
        ) : null}
      </AdminDetailPane>

      <AdminWorkbenchDrawer
        open={drawerOpen}
        width={560}
        title={
          drawerMode === "new"
            ? "New Activity"
            : "Edit Activity"
        }
        onClose={closeDrawer}
        portal
        padded
      >
        <AdminStack gap="md">
          {saveError ? (
            <AdminNotice variant="danger">{saveError}</AdminNotice>
          ) : null}

          <AdminField label="Date">
            <input
              className="admin-field__control"
              type="date"
              value={entry.activity_date}
              disabled={saving}
              onChange={(event) =>
                setField("activity_date", event.target.value)
              }
            />
          </AdminField>

          <AdminField label="Description">
            <textarea
              className="admin-field__control"
              rows={4}
              value={entry.description}
              disabled={saving}
              autoFocus
              onChange={(event) =>
                setField("description", event.target.value)
              }
            />
          </AdminField>

          <AdminField label="Hours">
            <input
              className="admin-field__control"
              type="number"
              min="0"
              step="0.25"
              value={entry.hours}
              disabled={saving}
              onChange={(event) => setField("hours", event.target.value)}
            />
          </AdminField>

          <AdminField label="Miles">
            <input
              className="admin-field__control"
              type="number"
              min="0"
              step="0.1"
              value={entry.miles}
              disabled={saving}
              onChange={(event) => setField("miles", event.target.value)}
            />
          </AdminField>

          <AdminField label="Amount">
            <input
              className="admin-field__control"
              type="number"
              step="0.01"
              value={entry.amount}
              disabled={saving}
              onChange={(event) => setField("amount", event.target.value)}
            />
          </AdminField>

          <AdminToolbar>
            <AdminToolbarSpacer />

            <AdminButton
              type="button"
              variant="secondary"
              disabled={saving}
              onClick={closeDrawer}
            >
              Cancel
            </AdminButton>

            <AdminButton
              type="button"
              disabled={saving || !canSave}
              onClick={saveEntry}
            >
              {saving ? "Saving..." : "Save Activity"}
            </AdminButton>
          </AdminToolbar>
        </AdminStack>
      </AdminWorkbenchDrawer>
    </>
  );
}
