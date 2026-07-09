import { useEffect, useMemo, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { API_FOLDER } from "@helpers/config";
import "./admin-publishing-defaults.css";

const DEFAULTS_URL = `${API_FOLDER}/v2/admin/asset-creators/defaults.php`;

const CHANNELS = [
  { value: "pinterest", label: "Pinterest" },
  { value: "youtube", label: "YouTube" },
];

const PLAYLIST_TYPES = [
  { value: "any", label: "Any playlist" },
  { value: "palettes", label: "Palette playlist" },
  { value: "makeovers", label: "Makeover playlist" },
  { value: "articles", label: "Article playlist" },
];

const FIELD_KEYS = [
  { value: "description", label: "Description" },
  { value: "title", label: "Title" },
];

export default function AdminPublishingDefaultsPage() {
  const [searchParams] = useSearchParams();
  const initialChannel = normalizeChannel(searchParams.get("channel") || searchParams.get("platform") || creatorKeyToChannel(searchParams.get("creator_key")));
  const initialPlaylistType = normalizePlaylistType(searchParams.get("playlist_type") || "any");
  const initialFieldKey = normalizeFieldKey(searchParams.get("field_key") || "description");

  const [form, setForm] = useState({
    channel: initialChannel,
    playlist_type: initialPlaylistType,
    field_key: initialFieldKey,
    template_text: "",
  });
  const [loadedItem, setLoadedItem] = useState(null);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [status, setStatus] = useState("");
  const [error, setError] = useState("");

  const assetType = useMemo(() => assetTypeForChannel(form.channel), [form.channel]);
  const defaultLabel = useMemo(() => {
    const channel = CHANNELS.find((item) => item.value === form.channel)?.label || form.channel;
    const playlistType = PLAYLIST_TYPES.find((item) => item.value === form.playlist_type)?.label || form.playlist_type;
    const field = FIELD_KEYS.find((item) => item.value === form.field_key)?.label || form.field_key;
    return `${channel} ${playlistType} ${field}`.trim();
  }, [form.channel, form.field_key, form.playlist_type]);

  useEffect(() => {
    loadDefault();
  }, [form.channel, form.playlist_type, form.field_key]);

  async function loadDefault() {
    setLoading(true);
    setStatus("");
    setError("");
    try {
      const params = new URLSearchParams({
        platform: form.channel,
        asset_type: assetTypeForChannel(form.channel),
        playlist_type: form.playlist_type,
        field_key: form.field_key,
        _: String(Date.now()),
      });
      const res = await fetch(`${DEFAULTS_URL}?${params.toString()}`, { credentials: "include" });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load default");
      const item = data.item || null;
      setLoadedItem(item);
      setForm((current) => ({
        ...current,
        template_text: item?.template_text || "",
      }));
    } catch (err) {
      setError(err?.message || "Failed to load default");
    } finally {
      setLoading(false);
    }
  }

  async function saveDefault(event) {
    event.preventDefault();
    const template = String(form.template_text || "").trim();
    if (!template) {
      setError("Default text is empty.");
      return;
    }

    setSaving(true);
    setStatus("");
    setError("");
    try {
      const res = await fetch(DEFAULTS_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          platform: form.channel,
          asset_type: assetType,
          playlist_type: form.playlist_type,
          field_key: form.field_key,
          label: defaultLabel,
          template_text: form.template_text,
        }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to save default");
      setLoadedItem(data.item || null);
      setStatus("Default saved.");
    } catch (err) {
      setError(err?.message || "Failed to save default");
    } finally {
      setSaving(false);
    }
  }

  function update(key, value) {
    setForm((current) => ({ ...current, [key]: value }));
  }

  return (
    <div className="publishing-defaults">
      <header className="publishing-defaults__header">
        <div>
          <h1>Publisher Defaults</h1>
          <p>Starting text for analyzer recipes by channel and playlist type.</p>
        </div>
        <Link className="publishing-defaults__button" to="/admin/asset-creators">
          Creator
        </Link>
      </header>

      {error ? <div className="publishing-defaults__status publishing-defaults__status--error">{error}</div> : null}
      {status ? <div className="publishing-defaults__status publishing-defaults__status--success">{status}</div> : null}

      <form className="publishing-defaults__panel" onSubmit={saveDefault}>
        <div className="publishing-defaults__controls">
          <label>
            Channel
            <select value={form.channel} onChange={(event) => update("channel", event.target.value)}>
              {CHANNELS.map((channel) => (
                <option key={channel.value} value={channel.value}>{channel.label}</option>
              ))}
            </select>
          </label>
          <label>
            Playlist Type
            <select value={form.playlist_type} onChange={(event) => update("playlist_type", event.target.value)}>
              {PLAYLIST_TYPES.map((type) => (
                <option key={type.value} value={type.value}>{type.label}</option>
              ))}
            </select>
          </label>
          <label>
            Field
            <select value={form.field_key} onChange={(event) => update("field_key", event.target.value)}>
              {FIELD_KEYS.map((field) => (
                <option key={field.value} value={field.value}>{field.label}</option>
              ))}
            </select>
          </label>
        </div>

        <label className="publishing-defaults__template">
          Starting Blurb
          <textarea
            value={form.template_text}
            onChange={(event) => update("template_text", event.target.value)}
            rows={12}
            placeholder="Write the starting analyzer text for this channel and playlist type."
            disabled={loading}
          />
        </label>

        <div className="publishing-defaults__meta">
          <span>Asset type: {assetType}</span>
          <span>Match: {loadedItem ? matchLabel(loadedItem) : "new exact default"}</span>
        </div>

        <div className="publishing-defaults__actions">
          <button type="button" className="publishing-defaults__button" onClick={loadDefault} disabled={loading || saving}>
            {loading ? "Loading..." : "Reload"}
          </button>
          <button type="submit" className="publishing-defaults__button publishing-defaults__button--primary" disabled={loading || saving}>
            {saving ? "Saving..." : "Save Default"}
          </button>
        </div>
      </form>

      <section className="publishing-defaults__help">
        <h2>Placeholders</h2>
        <p>
          Available in analyzer descriptions: <code>{"{{playlist_url}}"}</code>, <code>{"{{playlist_title}}"}</code>, <code>{"{{playlist_type}}"}</code>, <code>{"{{channel}}"}</code>.
        </p>
      </section>
    </div>
  );
}

function assetTypeForChannel(channel) {
  if (channel === "youtube") return "youtube_playlist_video";
  if (channel === "pinterest") return "pinterest_pin";
  return "any";
}

function creatorKeyToChannel(creatorKey) {
  const value = String(creatorKey || "");
  return value.includes(".") ? value.split(".")[0] : value;
}

function normalizeChannel(value) {
  return CHANNELS.some((item) => item.value === value) ? value : "pinterest";
}

function normalizePlaylistType(value) {
  return PLAYLIST_TYPES.some((item) => item.value === value) ? value : "any";
}

function normalizeFieldKey(value) {
  return FIELD_KEYS.some((item) => item.value === value) ? value : "description";
}

function matchLabel(item) {
  const exact = Number(item.match_rank || 0) === 1;
  const scope = `${item.platform || "any"} / ${item.asset_type || "any"} / ${item.playlist_type || "any"} / ${item.field_key || "description"}`;
  return exact ? `exact ${scope}` : `fallback ${scope}`;
}
