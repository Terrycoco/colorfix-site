import { useEffect, useMemo, useRef, useState } from "react";
import { API_FOLDER } from "@helpers/config";
import { canUseNativeShare, copyShareText, openNativeShare, openTextShare } from "@helpers/shareUrls";
import EmailShareModal from "@components/EmailShareModal/EmailShareModal";
import "./admin-share.css";

const PLAYLIST_INSTANCES_URL = `${API_FOLDER}/v2/admin/playlist-instances/list.php`;
const PLAYLIST_INSTANCE_SETS_URL = `${API_FOLDER}/v2/admin/playlist-instance-sets/list.php`;
const SAVED_PALETTES_URL = `${API_FOLDER}/v2/admin/saved-palettes.php`;
const SEND_EMAIL_URL = `${API_FOLDER}/v2/admin/share/send-email.php`;
const CLIENTS_URL = `${API_FOLDER}/v2/admin/clients/list.php`;

const ASSET_TYPE_OPTIONS = [
  { value: "playlist_instance", label: "Playlist Instance" },
  { value: "saved_palette", label: "Saved Palette" },
  { value: "playlist_instance_set", label: "Playlist Set" },
];

const emptyRecipient = {
  id: null,
  name: "",
  email: "",
  phone: "",
};

function normalizeAssetItems(assetType, rows) {
  const list = Array.isArray(rows) ? rows : [];

  if (assetType === "playlist_instance") {
    return list
      .map((row) => ({
        ...row,
        playlist_instance_id: row?.playlist_instance_id ?? row?.id ?? null,
        display_title: row?.display_title ?? row?.title ?? "",
        instance_name: row?.instance_name ?? row?.name ?? "",
        audience: row?.audience ?? "any",
        instance_notes: row?.instance_notes ?? row?.notes ?? "",
        player_url: row?.player_url ?? "",
        playlist_slug: row?.playlist_slug ?? row?.slug ?? "",
      }))
      .filter((row) => row.playlist_instance_id);
  }

  if (assetType === "saved_palette") {
    return list
      .map((row) => ({
        ...row,
        id: row?.id ?? row?.palette_id ?? row?.saved_palette_id ?? null,
        nickname: row?.nickname ?? row?.title ?? row?.display_title ?? "",
        brand: row?.brand ?? row?.palette_brand ?? "",
        palette_type: row?.palette_type ?? row?.type ?? "",
        palette_hash: row?.palette_hash ?? row?.hash ?? "",
      }))
      .filter((row) => row.id || row.palette_hash);
  }

  return list
    .map((row) => ({
      ...row,
      id: row?.id ?? row?.set_id ?? row?.playlist_instance_set_id ?? null,
      title: row?.title ?? row?.name ?? "",
      handle: row?.handle ?? row?.slug ?? "",
      context: row?.context ?? row?.subtitle ?? "",
      version: row?.version ?? "",
      updated_at: row?.updated_at ?? "",
    }))
    .filter((row) => row.id);
}

function isTextCapableDevice() {
  if (typeof navigator === "undefined") return false;
  const ua = navigator.userAgent || "";
  return /iPhone|iPad|Android|Macintosh/i.test(ua);
}

function buildAssetLabel(assetType, item) {
  if (!item) return "";
  if (assetType === "playlist_instance") {
    return item.display_title || item.instance_name || `Playlist Instance #${item.playlist_instance_id}`;
  }
  if (assetType === "saved_palette") {
    if (item.nickname) return item.nickname;
    if (item.brand) return String(item.brand).toUpperCase();
    if (item.id) return `Saved Palette #${item.id}`;
    if (item.palette_hash) return "Saved Palette";
    return "Saved Palette";
  }
  return item.title || item.handle || `Set #${item.id}`;
}

function buildAssetMeta(assetType, item) {
  if (!item) return "";
  if (assetType === "playlist_instance") {
    const parts = [`#${item.playlist_instance_id}`];
    if (item.audience && item.audience !== "any") parts.push(item.audience);
    if (item.instance_notes) parts.push(item.instance_notes);
    return parts.join(" · ");
  }
  if (assetType === "saved_palette") {
    const parts = [];
    if (item.id) parts.push(`#${item.id}`);
    if (item.brand) parts.push(String(item.brand).toUpperCase());
    if (item.palette_type) parts.push(item.palette_type);
    return parts.join(" · ");
  }
  const parts = [`#${item.id}`];
  if (item.handle) parts.push(item.handle);
  if (item.context) parts.push(item.context);
  return parts.join(" · ");
}

function buildShareLink(assetType, item) {
  if (!item || typeof window === "undefined") return "";

  if (assetType === "playlist_instance") {
    const slug = String(item.playlist_slug || item.slug || "").trim();
    const relativeUrl = item.player_url || (slug ? `/playlist/${encodeURIComponent(slug)}` : "");
    if (relativeUrl) {
      const url = new URL(relativeUrl, window.location.origin);
      if (item.audience && item.audience !== "any") {
        url.searchParams.set("aud", item.audience);
      }
      return url.toString();
    }

    const params = new URLSearchParams();
    params.set("id", String(item.playlist_instance_id));
    if (item.audience && item.audience !== "any") {
      params.set("aud", item.audience);
    }
    return `${window.location.origin}/share/playlist.php?${params.toString()}`;
  }

  if (assetType === "saved_palette") {
    if (!item.palette_hash) return "";
    return `${window.location.origin}/palette/${encodeURIComponent(item.palette_hash)}/share`;
  }

  if (!item.id) return "";
  const params = new URLSearchParams({ set: String(item.id) });
  if (item.version) params.set("set_v", String(item.version));
  return `${window.location.origin}/picker?${params.toString()}`;
}

function buildEmailDefaults(assetType, item, shareLink) {
  const label = buildAssetLabel(assetType, item);
  if (assetType === "playlist_instance") {
    return {
      subject: `ColorFix Playlist: ${label}`,
      message: `I wanted to share this ColorFix playlist with you.\n\n${shareLink}`,
    };
  }
  if (assetType === "saved_palette") {
    return {
      subject: `ColorFix Palette: ${label}`,
      message: `I wanted to share this ColorFix palette with you.\n\n${shareLink}`,
    };
  }
  return {
    subject: `ColorFix Playlist Set: ${label}`,
    message: `I wanted to share this ColorFix playlist set with you.\n\n${shareLink}`,
  };
}

function buildClientOptionLabel(client) {
  const name = client?.name || "Unnamed client";
  const parts = [client?.email, client?.phone].filter(Boolean);
  return parts.length ? `${name} - ${parts.join(" / ")}` : name;
}

export default function AdminSharePage() {
  const [assetType, setAssetType] = useState("playlist_instance");
  const [query, setQuery] = useState("");
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [selectedAsset, setSelectedAsset] = useState(null);
  const [recipient, setRecipient] = useState(emptyRecipient);
  const [clients, setClients] = useState([]);
  const [clientsLoading, setClientsLoading] = useState(false);
  const [status, setStatus] = useState({ error: "", success: "" });
  const [emailModal, setEmailModal] = useState({
    open: false,
    toEmail: "",
    subject: "",
    message: "",
    htmlBody: "",
    status: { loading: false, error: "", success: "" },
  });
  const [sendFormat, setSendFormat] = useState("text");
  const phoneInputRef = useRef(null);

  useEffect(() => {
    let cancelled = false;
    const controller = new AbortController();

    async function loadClients() {
      setClientsLoading(true);
      try {
        const params = new URLSearchParams();
        params.set("limit", "300");
        params.set("_", String(Date.now()));
        const res = await fetch(`${CLIENTS_URL}?${params.toString()}`, {
          credentials: "include",
          signal: controller.signal,
        });
        const data = await res.json();
        if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load clients");
        if (!cancelled) setClients(Array.isArray(data.items) ? data.items : []);
      } catch (err) {
        if (err?.name !== "AbortError" && !cancelled) setClients([]);
      } finally {
        if (!cancelled) setClientsLoading(false);
      }
    }

    void loadClients();
    return () => {
      cancelled = true;
      controller.abort();
    };
  }, []);

  useEffect(() => {
    let cancelled = false;
    const controller = new AbortController();

    async function loadItems() {
      setLoading(true);
      setError("");
      setItems([]);
      try {
        const params = new URLSearchParams();
        if (query.trim()) params.set("q", query.trim());
        params.set("limit", "50");
        params.set("_", String(Date.now()));

        let url = PLAYLIST_INSTANCES_URL;
        if (assetType === "saved_palette") {
          url = SAVED_PALETTES_URL;
        } else if (assetType === "playlist_instance_set") {
          url = PLAYLIST_INSTANCE_SETS_URL;
        }

        const res = await fetch(`${url}?${params.toString()}`, {
          credentials: "include",
          signal: controller.signal,
        });
        const data = await res.json();
        if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to load assets");
        if (cancelled) return;
        setItems(normalizeAssetItems(assetType, data.items));
      } catch (err) {
        if (err?.name === "AbortError") return;
        if (cancelled) return;
        setItems([]);
        setError(err?.message || "Failed to load assets");
      } finally {
        if (!cancelled) setLoading(false);
      }
    }

    void loadItems();
    return () => {
      cancelled = true;
      controller.abort();
    };
  }, [assetType, query]);

  useEffect(() => {
    if (!selectedAsset) return;
    const selectedKey = getAssetKey(assetType, selectedAsset);
    const nextMatch = items.find((item) => getAssetKey(assetType, item) === selectedKey);
    if (nextMatch) {
      setSelectedAsset(nextMatch);
    }
  }, [assetType, items, selectedAsset]);

  useEffect(() => {
    setSelectedAsset(null);
    setStatus({ error: "", success: "" });
  }, [assetType]);

  const shareLink = useMemo(
    () => buildShareLink(assetType, selectedAsset),
    [assetType, selectedAsset]
  );
  const sortedItems = useMemo(() => {
    return [...items].sort((a, b) =>
      buildAssetLabel(assetType, a).localeCompare(buildAssetLabel(assetType, b), undefined, {
        sensitivity: "base",
        numeric: true,
      })
    );
  }, [assetType, items]);
  const canText = isTextCapableDevice();
  const canSystemShare = canUseNativeShare();
  function handleRecipientField(field, value) {
    setRecipient((prev) => ({ ...prev, [field]: value }));
    setStatus({ error: "", success: "" });
  }

  function handleClientSelect(clientId) {
    if (!clientId) {
      setRecipient(emptyRecipient);
      setStatus({ error: "", success: "" });
      return;
    }
    const client = clients.find((item) => String(item.id) === String(clientId));
    if (!client) return;
    const nextRecipient = {
      id: client?.id ?? null,
      name: client?.name || "",
      email: client?.email || "",
      phone: client?.phone || "",
    };
    setRecipient(nextRecipient);
    setStatus({ error: "", success: "" });
  }

  function handleAssetSelect(assetKey) {
    const nextAsset = sortedItems.find((item) => getAssetKey(assetType, item) === assetKey) || null;
    setSelectedAsset(nextAsset);
    setStatus({ error: "", success: "" });
  }

  async function handleCopyLink() {
    if (!shareLink) return;
    const copied = await copyShareText(shareLink);
    setStatus({
      error: copied ? "" : "Copy failed.",
      success: copied ? "Share link copied." : "",
    });
  }

  async function handleSystemShare() {
    if (!shareLink || !canSystemShare) return;
    await openNativeShare({
      title: buildAssetLabel(assetType, selectedAsset),
      text: buildAssetLabel(assetType, selectedAsset),
      url: shareLink,
    });
  }

  function sendTextLink(phone) {
    if (!shareLink) return;
    const recipientPhone = (phone || "").trim();
    if (!recipientPhone) {
      setStatus({ error: "Enter a phone number, then click Text Link again.", success: "" });
      phoneInputRef.current?.focus();
      return;
    }
    const body = `${buildAssetLabel(assetType, selectedAsset)} ${shareLink}`;
    setRecipient(emptyRecipient);
    window.setTimeout(() => {
      openTextShare({ text: body, phone: recipientPhone }).catch(() => {});
    }, 0);
  }

  function handleTextLink() {
    if (!shareLink) return;
    if (!recipient.phone.trim()) {
      setStatus({
        error: "Enter a phone number in Recipient, or choose a saved client from the dropdown.",
        success: "",
      });
      phoneInputRef.current?.focus();
      return;
    }
    sendTextLink(recipient.phone);
  }

  function openEmailModal() {
    if (!selectedAsset || !shareLink) return;
    const defaults = buildEmailDefaults(assetType, selectedAsset, shareLink);
    setEmailModal({
      open: true,
      toEmail: recipient.email || "",
      subject: defaults.subject,
      message: defaults.message,
      htmlBody: "",
      status: { loading: false, error: "", success: "" },
    });
    setSendFormat("text");
  }

  function closeEmailModal() {
    setEmailModal((prev) => ({ ...prev, open: false }));
  }

  async function handleSendEmail() {
    if (!selectedAsset || !shareLink) return;
    const toEmail = emailModal.toEmail.trim();
    if (!toEmail) {
      setEmailModal((prev) => ({
        ...prev,
        status: { loading: false, error: "Recipient email required.", success: "" },
      }));
      return;
    }

    setEmailModal((prev) => ({
      ...prev,
      status: { loading: true, error: "", success: "" },
    }));

    try {
      const res = await fetch(SEND_EMAIL_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          asset_type: assetType,
          asset_id: getAssetId(assetType, selectedAsset),
          share_url: shareLink,
          title: buildAssetLabel(assetType, selectedAsset),
          to_email: toEmail,
          subject: emailModal.subject,
          message: emailModal.message,
          html_body: sendFormat === "html" ? emailModal.htmlBody : "",
        }),
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || "Failed to send email");
      setEmailModal((prev) => ({
        ...prev,
        status: { loading: false, error: "", success: "Email sent." },
      }));
      setStatus({ error: "", success: "Email sent from ColorFix." });
    } catch (err) {
      setEmailModal((prev) => ({
        ...prev,
        status: { loading: false, error: err?.message || "Failed to send email", success: "" },
      }));
    }
  }

  return (
    <div className="admin-share">
      <aside className="admin-share__sidebar">
        <div className="admin-share__sidebar-header">
          <h1>Admin Share</h1>
          <p>Pick a client, choose an asset, and send it from one place.</p>
        </div>

        <section className="admin-share__panel admin-share__panel--sidebar admin-share__panel--send">
          <div className="admin-share__panel-head">
            <div>
              <h2>Send</h2>
              <p>Copy, text, or email the selected asset.</p>
            </div>
          </div>

          <div className="admin-share__summary">
            <div className="admin-share__summary-label">Selected asset</div>
            <div className="admin-share__summary-title">
              {selectedAsset ? buildAssetLabel(assetType, selectedAsset) : "Nothing selected yet"}
            </div>
            <div className="admin-share__summary-meta">
              {selectedAsset ? buildAssetMeta(assetType, selectedAsset) : "Pick an asset from the list on the right."}
            </div>
          </div>

          <label className="admin-share__field">
            <span>Share link</span>
            <input type="text" readOnly value={shareLink} placeholder="Select an asset to build the link" />
          </label>

          <div className="admin-share__actions">
            {canSystemShare ? (
              <button type="button" className="admin-share__btn" onClick={handleSystemShare} disabled={!shareLink}>
                System Share
              </button>
            ) : null}
            {canText ? (
              <button type="button" className="admin-share__btn" onClick={handleTextLink} disabled={!shareLink}>
                Text Link
              </button>
            ) : null}
            <button type="button" className="admin-share__btn" onClick={handleCopyLink} disabled={!shareLink}>
              Copy Link
            </button>
            <button type="button" className="admin-share__btn admin-share__btn--primary" onClick={openEmailModal} disabled={!shareLink}>
              Email from ColorFix
            </button>
          </div>

          {status.error && <div className="admin-share__status admin-share__status--error">{status.error}</div>}
          {status.success && <div className="admin-share__status admin-share__status--success">{status.success}</div>}
        </section>

        <div className="admin-share__panel admin-share__panel--sidebar admin-share__panel--recipient">
          <div className="admin-share__panel-head admin-share__panel-head--recipient">
            <div>
              <h2>Recipient</h2>
              <p>Type a one-off recipient, or choose a saved client.</p>
            </div>
          </div>

          <label className="admin-share__field">
            <span>Saved client optional</span>
            <select
              value={recipient.id || ""}
              onChange={(e) => handleClientSelect(e.target.value)}
              disabled={clientsLoading}
            >
              <option value="">{clientsLoading ? "Loading clients..." : "No saved client"}</option>
              {clients.map((client) => (
                <option key={client.id} value={client.id}>
                  {buildClientOptionLabel(client)}
                </option>
              ))}
            </select>
          </label>

          <label className="admin-share__field">
            <span>Name</span>
            <input
              type="text"
              value={recipient.name}
              onChange={(e) => handleRecipientField("name", e.target.value)}
              placeholder="Optional"
            />
          </label>
          <label className="admin-share__field">
            <span>Email</span>
            <input
              type="email"
              value={recipient.email}
              onChange={(e) => handleRecipientField("email", e.target.value)}
              placeholder="Optional"
            />
          </label>
          <label className="admin-share__field">
            <span>Phone</span>
            <input
              ref={phoneInputRef}
              type="text"
              value={recipient.phone}
              onChange={(e) => handleRecipientField("phone", e.target.value)}
              placeholder="Optional"
            />
          </label>

          <div className="admin-share__actions">
            <button type="button" className="admin-share__btn" onClick={() => setRecipient(emptyRecipient)}>
              Clear
            </button>
          </div>
        </div>
      </aside>

      <main className="admin-share__main">
        <section className="admin-share__panel admin-share__panel--asset-browser">
          <div className="admin-share__panel-head">
            <div>
              <h2>Asset</h2>
              <p>Pick the type, then choose the exact thing.</p>
            </div>
          </div>

          <div className="admin-share__toolbar">
            <label className="admin-share__field">
              <span>Type</span>
              <select value={assetType} onChange={(e) => setAssetType(e.target.value)}>
                {ASSET_TYPE_OPTIONS.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
            </label>
            <label className="admin-share__field admin-share__field--grow">
              <span>Search</span>
              <input
                type="text"
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder="Title, name, handle, notes"
              />
            </label>
            <label className="admin-share__field admin-share__field--full">
              <span>Asset</span>
              <select
                value={getAssetKey(assetType, selectedAsset)}
                onChange={(e) => handleAssetSelect(e.target.value)}
                disabled={loading || sortedItems.length === 0}
              >
                <option value="">
                  {loading ? "Loading..." : sortedItems.length === 0 ? "No assets matched" : "Choose asset"}
                </option>
                {sortedItems.map((item) => {
                  const assetKey = getAssetKey(assetType, item);
                  const meta = buildAssetMeta(assetType, item);
                  return (
                    <option key={assetKey} value={assetKey}>
                      {meta ? `${buildAssetLabel(assetType, item)} - ${meta}` : buildAssetLabel(assetType, item)}
                    </option>
                  );
                })}
              </select>
            </label>
          </div>

          {error && <div className="admin-share__status admin-share__status--error">{error}</div>}
          {loading && <div className="admin-share__status">Loading assets…</div>}
        </section>
      </main>

      <EmailShareModal
        open={emailModal.open}
        title={`Send ${selectedAsset ? buildAssetLabel(assetType, selectedAsset) : "Link"}`}
        templates={[]}
        templateKey=""
        toEmail={emailModal.toEmail}
        onToEmailChange={(value) => setEmailModal((prev) => ({ ...prev, toEmail: value }))}
        subject={emailModal.subject}
        onSubjectChange={(value) => setEmailModal((prev) => ({ ...prev, subject: value }))}
        htmlBody={emailModal.htmlBody}
        onHtmlBodyChange={(value) => setEmailModal((prev) => ({ ...prev, htmlBody: value }))}
        message={emailModal.message}
        onMessageChange={(value) => setEmailModal((prev) => ({ ...prev, message: value }))}
        sendFormat={sendFormat}
        onSendFormatChange={setSendFormat}
        shareLink={shareLink}
        status={emailModal.status}
        onSend={handleSendEmail}
        onClose={closeEmailModal}
      />
    </div>
  );
}

function getAssetId(assetType, item) {
  if (!item) return "";
  if (assetType === "playlist_instance") return item.playlist_instance_id;
  return item.id;
}

function getAssetKey(assetType, item) {
  if (!item) return "";
  return `${assetType}:${getAssetId(assetType, item)}`;
}
