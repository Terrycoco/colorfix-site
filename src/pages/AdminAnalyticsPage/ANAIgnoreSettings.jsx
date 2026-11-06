import { useEffect, useState } from "react";
import { AdminButton, AdminDialog, AdminField, AdminNotice } from "@components/AdminLayout";
import { API_FOLDER } from "@helpers/config";

const CONFIG_URL = `${API_FOLDER}/v2/admin/ana/ignore-config.php`;

export default function ANAIgnoreSettings() {
  const [open, setOpen] = useState(false);
  const [patterns, setPatterns] = useState("");
  const [loaded, setLoaded] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    if (!open) return;
    const controller = new AbortController();
    setLoaded(false);
    setError("");
    fetch(CONFIG_URL, { credentials: "include", cache: "no-store", signal: controller.signal })
      .then(async response => {
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.error || "Unable to load crawler settings.");
        setPatterns(data.item.patterns.join("\n"));
        setLoaded(true);
      })
      .catch(error => { if (!controller.signal.aborted) setError(error.message); });
    return () => controller.abort();
  }, [open]);

  const save = async () => {
    setSaving(true);
    setError("");
    try {
      const response = await fetch(CONFIG_URL, {
        method: "POST", credentials: "include", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ patterns: patterns.split(/\r?\n/).map(value => value.trim()).filter(Boolean) }),
      });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || "Unable to save crawler settings.");
      setOpen(false);
    } catch (error) {
      setError(error.message);
    } finally {
      setSaving(false);
    }
  };

  return <>
    <AdminButton type="button" variant="secondary" onClick={() => setOpen(true)}>Crawler filtering</AdminButton>
    <AdminDialog open={open} title="ANA crawler filtering" onClose={() => { if (!saving) setOpen(false); }}
      actions={[
        { label: "Cancel", variant: "secondary", disabled: saving, onClick: () => setOpen(false) },
        { label: saving ? "Saving…" : "Save", disabled: !loaded || saving, onClick: save },
      ]}>
      <p>Exclude analytics from user agents containing these specific crawler tokens. Matching ignores letter case. Site access is unaffected.</p>
      {error && <AdminNotice variant="danger">{error}</AdminNotice>}
      <AdminField label="Crawler patterns — one per line">
        <textarea className="admin-field__control" rows={8} value={patterns}
          aria-label="Crawler patterns — one per line" disabled={!loaded || saving}
          onChange={event => setPatterns(event.target.value)} />
      </AdminField>
      <p>An empty list disables crawler filtering. Changes apply to new events after saving.</p>
    </AdminDialog>
  </>;
}
