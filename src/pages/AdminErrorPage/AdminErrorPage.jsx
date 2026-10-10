import { useCallback, useEffect, useMemo, useState } from "react";
import {
  AdminButton,
  AdminDialog,
  AdminDetailPane,
  AdminField,
  AdminStack,
  AdminWorkbenchDrawer,
  AdminEmptyState,
  AdminListPane,
  AdminMasterDetail,
  AdminNotice,
  AdminObjectList,
  AdminObjectListItem,
  AdminSmartGrid,
  AdminToolbar,
} from "@components/AdminLayout";
import { API_FOLDER } from "@helpers/config";

const URL = `${API_FOLDER}/v2/admin/errors/report.php`;
const SAVE_URL = `${API_FOLDER}/v2/admin/errors/save.php`;
const RECOVER_URL = `${API_FOLDER}/v2/admin/errors/recover.php`;
const VIEWS = [
  { id: "working", title: "Working" },
  { id: "history", title: "History" },
  { id: "codes", title: "Error Codes" },
];

const text = (value) => String(value ?? "").trim();
const label = (value) => text(value) || "—";
const shortDate = (value) => text(value) || "—";

async function readResponse(response) {
  const body = await response.text();
  let data;
  try {
    data = JSON.parse(body);
  } catch {
    throw new Error("ERR report did not return valid JSON.");
  }
  if (!response.ok || data?.ok === false) {
    throw new Error(data?.error || `ERR report failed (HTTP ${response.status}).`);
  }
  return data;
}

export default function AdminErrorPage() {
  const [view, setView] = useState("working");
  const [report, setReport] = useState({ working: [], history: [], codes: [] });
  const [busy, setBusy] = useState(true);
  const [error, setError] = useState("");
  const [selectedKey, setSelectedKey] = useState(null);
  const [search, setSearch] = useState("");
  const [drawer, setDrawer] = useState(null);
  const [draft, setDraft] = useState({});
  const [saveError, setSaveError] = useState("");
  const [saving, setSaving] = useState(false);
  const [bookmarkExpanded, setBookmarkExpanded] = useState(false);
  const [recovering, setRecovering] = useState(false);
  const [recoveryAlert, setRecoveryAlert] = useState(null);

  const load = useCallback(async () => {
    setBusy(true);
    setError("");
    try {
      const data = await readResponse(await fetch(`${URL}?_=${Date.now()}`, {
        method: "GET",
        credentials: "include",
        cache: "no-store",
      }));
      setReport({
        working: Array.isArray(data.working) ? data.working : [],
        history: Array.isArray(data.history) ? data.history : [],
        codes: Array.isArray(data.codes) ? data.codes : [],
      });
    } catch (failure) {
      setError(failure?.message || "Couldn't load ERR report.");
    } finally {
      setBusy(false);
    }
  }, []);

  useEffect(() => { void load(); }, [load]);
  useEffect(() => { setSelectedKey(null); setSearch(""); }, [view]);

  const openRow = useCallback((item) => {
    if (!item) return;
    setSelectedKey(item.id ?? item.err_num);
    setDrawer({ view, row: item });
    setDraft({ ...item });
    setSaveError("");
    setBookmarkExpanded(false);
  }, [view]);

  const change = (field, value) => setDraft((prev) => ({ ...prev, [field]: value }));

  async function closeDrawer() {
    if (!drawer || saving || recovering) return;
    const allowed = {
      working: ["detail"],
      history: ["handling_result"],
      codes: ["description", "explanation", "severity", "context_type", "handling_mode", "notify_policy", "is_active"],
    };
    const changes = Object.fromEntries(
      allowed[drawer.view].filter((field) => String(draft[field] ?? "") !== String(drawer.row[field] ?? ""))
        .map((field) => [field, draft[field]])
    );
    if (Object.keys(changes).length === 0) {
      setDrawer(null);
      return;
    }
    setSaving(true);
    setSaveError("");
    try {
      const response = await fetch(SAVE_URL, {
        method: "POST",
        credentials: "include",
        cache: "no-store",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          view: drawer.view,
          id: drawer.row.id ?? drawer.row.err_num,
          changes,
        }),
      });
      await readResponse(response);
      await load();
      setDrawer(null);
    } catch (failure) {
      setSaveError(failure?.message || "Could not save ERR record.");
      return false;
    } finally {
      setSaving(false);
    }
  }

  async function recoverSelected() {
    if (!drawer || drawer.view !== "working" || recovering) return;
    setRecovering(true);
    try {
      const data = await readResponse(await fetch(RECOVER_URL, {
        method: "POST",
        credentials: "include",
        cache: "no-store",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ working_id: drawer.row.id }),
      }));
      if (data.status === "success") {
        setDrawer(null);
      }
      setRecoveryAlert({
        title: data.status === "success" ? "Recovery Accepted" : "Recovery Not Complete",
        message: data.message || "The interrupted work remains in Working.",
      });
      await load();
    } catch (failure) {
      setRecoveryAlert({ title: "Recovery Not Complete", message: failure?.message || "Please try again." });
      await load();
    } finally {
      setRecovering(false);
    }
  }

  const field = (name, title, multiline = false) => (
    <AdminField label={title} key={name}>
      {multiline ? (
        <textarea className="admin-field__control" rows={4} value={draft[name] ?? ""}
          disabled={saving} onChange={(e) => change(name, e.target.value)} />
      ) : (
        <input className="admin-field__control" value={draft[name] ?? ""}
          disabled={saving} onChange={(e) => change(name, e.target.value)} />
      )}
    </AdminField>
  );

  const readonly = (name, title = name.replaceAll("_", " ")) => (
    <AdminField label={title} key={name}>
      <div style={{ whiteSpace: "pre-wrap", overflowWrap: "anywhere" }}>
        {typeof draft[name] === "object" ? JSON.stringify(draft[name], null, 2) : label(draft[name])}
      </div>
    </AdminField>
  );

  // The department owns the bookmark. ERR only shows its human summary.
  const parseBookmark = (value) => {
    if (!value) return null;
    if (typeof value === "object" && !Array.isArray(value)) return value;
    try {
      const parsed = JSON.parse(value);
      return parsed && typeof parsed === "object" && !Array.isArray(parsed) ? parsed : null;
    } catch {
      return null;
    }
  };
  const workTitle = (r) => {
    const b = parseBookmark(r.bookmark_json);
    return b?.description || b?.summary?.title || (r.resource_type && r.resource_id
      ? `${r.resource_type} #${r.resource_id}` : "—");
  };

  const columns = useMemo(() => ({
    working: [
      { key: "err_num", label: "Code", sortable: true, value: (r) => label(r.err_num) },
      { key: "department", label: "Dept", sortable: true },
      { key: "description", label: "Problem", sortable: true, value: (r) => label(r.description || r.message) },
      { key: "interrupted_work", label: "Interrupted work", value: workTitle },
      { key: "step_key", label: "Step", sortable: true },
      { key: "status", label: "Status", sortable: true },
      { key: "resource", label: "Resource", value: (r) => r.resource_type && r.resource_id ? `${r.resource_type} #${r.resource_id}` : "—" },
      { key: "started_at", label: "Started", sortable: true, value: (r) => shortDate(r.started_at) },
    ],
    history: [
      { key: "created_at", label: "When", sortable: true },
      { key: "err_num", label: "Code", sortable: true, value: (r) => label(r.err_num) },
      { key: "department", label: "Dept", sortable: true },
      { key: "message", label: "Error", sortable: true },
      { key: "handling_status", label: "Handling", sortable: true },
      { key: "resource", label: "Resource", value: (r) => r.resource_type && r.resource_id ? `${r.resource_type} #${r.resource_id}` : "—" },
      { key: "resolved_at", label: "Resolved", sortable: true, value: (r) => shortDate(r.resolved_at) },
    ],
    codes: [
      { key: "err_num", label: "Code", sortable: true },
      { key: "department", label: "Dept", sortable: true },
      { key: "code", label: "Constant", sortable: true },
      { key: "description", label: "Description", sortable: true },
      { key: "severity", label: "Severity", sortable: true },
      { key: "context_type", label: "Context", sortable: true },
      { key: "handler_key", label: "Handler", sortable: true },
    ],
  }), []);

  const rawRows = report[view] || [];
  const rows = useMemo(() => rawRows.filter((r) =>
    !search || Object.values(r).some((v) =>
      typeof v === "string" && v.toLowerCase().includes(search.toLowerCase())
    )
  ), [rawRows, search]);
  const title = VIEWS.find((v) => v.id === view)?.title || "Errors";

  return (
    <>
    <AdminMasterDetail
      storageKey="admin-err-list-width"
      defaultListWidth={210}
      minListWidth={160}
      maxListWidth={340}
      list={
        <AdminListPane title="Error Report">
          <AdminObjectList ariaLabel="Error views">
            {VIEWS.map((v) => (
              <AdminObjectListItem
                key={v.id}
                id={null}
                title={`${v.title} (${report[v.id]?.length ?? 0})`}
                selected={view === v.id}
                onSelect={() => setView(v.id)}
              />
            ))}
          </AdminObjectList>
        </AdminListPane>
      }
      detail={
        <AdminDetailPane
          title={title}
          ariaLabel={`ERR ${title}`}
          actions={
            <AdminToolbar>
              <AdminButton type="button" variant="secondary" disabled={busy} onClick={() => void load()}>
                Refresh
              </AdminButton>
            </AdminToolbar>
          }
        >
          {error ? <AdminNotice variant="danger">{error}</AdminNotice> : null}
          <input
            type="search"
            className="admin-field__control"
            placeholder={`Search ${title.toLowerCase()}...`}
            aria-label={`Search ${title}`}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
          {busy && !rawRows.length ? <AdminEmptyState title="Loading error report..." /> : null}
          {!busy && !error && !rows.length ? (
            <AdminEmptyState title="Nothing to show" message={search ? "No matching records." : `No ${title.toLowerCase()} records.`} />
          ) : null}
          {rows.length > 0 ? (
            <AdminSmartGrid
              key={view}
              items={rows}
              columns={columns[view]}
              getRowKey={(r) => r.id ?? r.err_num}
              selectedKey={selectedKey}
              onSelectionChange={(row, key) => setSelectedKey(key ?? row?.id ?? row?.err_num ?? null)}
              defaultSortKey={view === "working" ? "started_at" : view === "history" ? "created_at" : "err_num"}
              defaultSortDirection={view === "codes" ? "asc" : "desc"}
              ariaLabel={`ERR ${title}`}
              onRowDoubleClick={openRow}
              verticalAlign="middle"
            />
          ) : null}

        </AdminDetailPane>
      }
    />
    <AdminWorkbenchDrawer
      open={Boolean(drawer)}
      title={drawer ? `${VIEWS.find((v) => v.id === drawer.view)?.title} — ${draft.err_num ?? draft.id ?? ""}` : ""}
      width="min(680px, 100vw)"
      onClose={closeDrawer}
      closeLabel={drawer?.view === "working" ? "Close" : "Save & Close"}
      portal
      padded
    >
      <AdminStack gap="md">
        {saveError ? <AdminNotice variant="danger">{saveError}</AdminNotice> : null}
        {drawer?.view === "working" ? (
          <>
            {readonly("err_num", "Error number")}
            {readonly("department", "Department")}
            {readonly("occurred_at", "Error occurred")}
            {readonly("description", "Problem")}
            <AdminField label={
              <span style={{ display: "inline-flex", alignItems: "center", gap: 6 }}>
                You Were Here
                <button
                  type="button"
                  aria-label={bookmarkExpanded ? "Hide rerun parameters" : "Show rerun parameters"}
                  aria-expanded={bookmarkExpanded}
                  onClick={() => setBookmarkExpanded((value) => !value)}
                  style={{ border: 0, background: "transparent", padding: "0 3px", cursor: "pointer", fontSize: 12, color: "inherit" }}
                >
                  {bookmarkExpanded ? "▴" : "▾"}
                </button>
              </span>
            }>
              <div style={{ whiteSpace: "pre-wrap", overflowWrap: "anywhere" }}>
                {workTitle(draft)}
              </div>
              {parseBookmark(draft.bookmark_json)?.summary?.asset_id ? (
                <div className="admin-field__hint">
                  Asset #{parseBookmark(draft.bookmark_json).summary.asset_id}
                </div>
              ) : null}
              {bookmarkExpanded ? (
                <textarea
                  className="admin-field__control"
                  aria-label="Rerun parameters JSON"
                  readOnly
                  rows={12}
                  spellCheck={false}
                  style={{ width: "100%", marginTop: 8, fontFamily: "monospace", fontSize: 12, resize: "vertical" }}
                  value={typeof draft.bookmark_json === "string"
                    ? (() => { try { return JSON.stringify(JSON.parse(draft.bookmark_json), null, 2); } catch { return draft.bookmark_json; } })()
                    : draft.bookmark_json ? JSON.stringify(draft.bookmark_json, null, 2) : "No bookmark recorded."}
                />
              ) : null}
            </AdminField>
            {readonly("message", "Original error")}
            {readonly("step_key", "Recovery step")}
            {readonly("status", "Step status")}
            {readonly("resource_type", "Resource type")}
            {readonly("resource_id", "Resource ID")}
            {readonly("explanation", "Recovery instructions")}
            <AdminButton type="button" disabled={recovering} onClick={() => void recoverSelected()}>
              {recovering ? "Recovering..." : "Recover"}
            </AdminButton>
            {readonly("detail", "Working notes")}
          </>
        ) : null}
        {drawer?.view === "history" ? (
          <>
            {readonly("id", "Log ID")}
            {readonly("created_at", "Error occurred")}
            {readonly("err_num", "Error number")}
            {readonly("department", "Department")}
            {readonly("message", "Original error")}
            {readonly("technical_detail", "Technical detail")}
            {readonly("context_json", "Context")}
            {readonly("resource_type", "Resource type")}
            {readonly("resource_id", "Resource ID")}
            {readonly("handling_status", "Handling status")}
            {readonly("resolved_at", "Resolved at")}
            {field("handling_result", "Handling notes", true)}
          </>
        ) : null}
        {drawer?.view === "codes" ? (
          <>
            {readonly("err_num", "Registered error number")}
            {readonly("department", "Owning department")}
            {readonly("code", "Constant")}
            {readonly("handler_key", "Registered handler key — code controlled")}
            {field("description", "Description")}
            {field("explanation", "Explanation and recovery instructions", true)}
            {[["severity", "Severity", ["info", "warning", "error", "critical"]],
              ["context_type", "Context", ["interactive", "background", "both"]],
              ["handling_mode", "Handling", ["automatic", "manual", "log_only"]],
              ["notify_policy", "Notification", ["none", "once_per_incident", "on_failure", "always"]]]
              .map(([key, title, options]) => (
                <AdminField key={key} label={title}>
                  <select className="admin-field__control" value={draft[key] ?? ""}
                    onChange={(e) => change(key, e.target.value)} disabled={saving}>
                    {!options.includes(draft[key]) && <option value={draft[key] ?? ""}>{draft[key] || "—"}</option>}
                    {options.map((option) => <option key={option} value={option}>{option}</option>)}
                  </select>
                </AdminField>
              ))}
            <AdminField label="Registration active">
              <input type="checkbox" checked={Boolean(Number(draft.is_active))}
                onChange={(e) => change("is_active", e.target.checked ? 1 : 0)} disabled={saving} />
            </AdminField>
          </>
        ) : null}
      </AdminStack>
    </AdminWorkbenchDrawer>
    <AdminDialog
      open={Boolean(recoveryAlert)}
      mode="alert"
      title={recoveryAlert?.title || ""}
      message={recoveryAlert?.message || ""}
      onConfirm={() => setRecoveryAlert(null)}
    />
    </>
  );
}