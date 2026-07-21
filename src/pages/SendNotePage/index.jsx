import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { API_FOLDER } from "@helpers/config";
import "./send-note.css";

const SEND_NOTE_URL = `${API_FOLDER}/v2/site-comment/send.php`;

const emptyForm = {
  first_name: "",
  last_name: "",
  email: "",
  message: "",
  website: "",
};

function readJson(text) {
  try {
    return text ? JSON.parse(text) : {};
  } catch {
    return null;
  }
}

function isServerChallenge(text) {
  return /document\.cookie\s*=\s*"humans_[^"]+"/i.test(text || "");
}

function applyServerChallengeCookie(text) {
  if (typeof document === "undefined") {
    return false;
  }

  const match = (text || "").match(/document\.cookie\s*=\s*"([^"]+)"/i);
  if (!match?.[1]) {
    return false;
  }

  document.cookie = match[1];
  return true;
}

function getSafeErrorMessage(text) {
  if (isServerChallenge(text) || /<[^>]+>/.test(text || "")) {
    return "Please try sending the note again.";
  }

  return text || "Failed to send note";
}

export default function SendNotePage() {
  const navigate = useNavigate();
  const [form, setForm] = useState(emptyForm);
  const [status, setStatus] = useState({
    loading: false,
    error: "",
    success: "",
  });

  function updateField(field, value) {
    setForm((prev) => ({ ...prev, [field]: value }));
  }

  async function submitNote(payload, allowChallengeRetry = true) {
    const res = await fetch(SEND_NOTE_URL, {
      method: "POST",
      credentials: "include",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
      },
      body: JSON.stringify(payload),
    });

    const text = await res.text();
    const data = readJson(text);

    if (res.ok && data?.ok) {
      return data;
    }

    if (
      allowChallengeRetry &&
      isServerChallenge(text) &&
      applyServerChallengeCookie(text)
    ) {
      return submitNote(payload, false);
    }

    throw new Error(data?.error || getSafeErrorMessage(text));
  }

  async function handleSubmit(event) {
    event.preventDefault();
    setStatus({ loading: true, error: "", success: "" });

    try {
      await submitNote({
        ...form,
        source_url: typeof window !== "undefined" ? window.location.href : "",
      });

      setForm(emptyForm);
      setStatus({
        loading: false,
        error: "",
        success: "Note sent. Returning to ColorFix...",
      });
      window.setTimeout(() => {
        navigate("/", { replace: true });
      }, 1100);
    } catch (err) {
      setStatus({
        loading: false,
        error: err?.message || "Failed to send note",
        success: "",
      });
    }
  }

  return (
    <div className="send-note-page">
      <section className="send-note-hero">
        <h1>Contact Terry</h1>
        <p>
          Questions, comments, or a private project inquiry.
          <br />
          Private project requests are reviewed personally by Terry.
        </p>
      </section>

      <section className="send-note-card">
        <form className="send-note-form" onSubmit={handleSubmit}>
          <div className="send-note-grid">
            <div className="send-note-field">
              <label htmlFor="send-note-first-name">First Name</label>
              <input
                id="send-note-first-name"
                name="first_name"
                type="text"
                autoComplete="given-name"
                value={form.first_name}
                onChange={(event) => updateField("first_name", event.target.value)}
              />
            </div>

            <div className="send-note-field">
              <label htmlFor="send-note-last-name">Last Name</label>
              <input
                id="send-note-last-name"
                name="last_name"
                type="text"
                autoComplete="family-name"
                value={form.last_name}
                onChange={(event) => updateField("last_name", event.target.value)}
              />
            </div>
          </div>

          <div className="send-note-field">
            <label htmlFor="send-note-email">Email Address</label>
            <input
              id="send-note-email"
              name="email"
              type="email"
              autoComplete="email"
              value={form.email}
              onChange={(event) => updateField("email", event.target.value)}
              required
            />
          </div>

          <div className="send-note-field send-note-field--hidden">
            <label htmlFor="send-note-website">Website</label>
            <input
              id="send-note-website"
              name="website"
              type="text"
              autoComplete="off"
              tabIndex={-1}
              value={form.website}
              onChange={(event) => updateField("website", event.target.value)}
            />
          </div>

          <div className="send-note-field">
            <label htmlFor="send-note-message">Your Message</label>
            <textarea
              id="send-note-message"
              name="message"
              rows="8"
              value={form.message}
              onChange={(event) => updateField("message", event.target.value)}
              required
            />
          </div>

          <div className="send-note-actions">
            <button type="submit" disabled={status.loading}>
              {status.loading ? "Sending..." : "Send to Terry"}
            </button>
          </div>

          {status.error ? (
            <div className="send-note-status is-error">{status.error}</div>
          ) : null}

          {status.success ? (
            <div className="send-note-status is-success">{status.success}</div>
          ) : null}
        </form>
      </section>
    </div>
  );
}
