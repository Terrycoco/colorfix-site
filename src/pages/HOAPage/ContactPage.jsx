import React, { useState } from "react";
import { useNavigate } from "react-router-dom";
import CTASection from "@components/CTASection";
import "@components/CTA/cta.css";
import "./hoa.css";

export default function HOAContactPage() {
  const navigate = useNavigate();
  const [status, setStatus] = useState("idle"); // idle | sending | sent | error
  const [error, setError] = useState("");
  const [name, setName] = useState("");
  const [role, setRole] = useState("HOA Board");
  const [company, setCompany] = useState("");
  const [companyUrl, setCompanyUrl] = useState("");
  const [email, setEmail] = useState("");
  const [note, setNote] = useState("");

  async function onSubmit(e) {
    e.preventDefault();
    setStatus("sending");
    setError("");

    try {
      const params = new URLSearchParams({
        name,
        role,
        company,
        company_url: companyUrl,
        email,
        note,
      });
      const res = await fetch("/api/v2/hoa-contact/send.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded", Accept: "application/json" },
        body: params.toString(),
      });
      const data = await res.json().catch(() => null);
      if (!res.ok || !data?.ok) {
        throw new Error(data?.error || "Failed to send");
      }
      setStatus("sent");
      setName("");
      setCompany("");
      setCompanyUrl("");
      setEmail("");
      setNote("");
    } catch (err) {
      setStatus("error");
      setError(err?.message || "Failed to send");
    }
  }

  const handleCtaClick = (cta) => {
    if (cta?.key === "back_overview") {
      navigate("/hoa");
    }
  };

  return (
    <main className="hoa-page">
      <section id="contact" className="hoa-section hoa-contact">
        <h1>Contact Terry</h1>
        <p>
          Tell me a bit about your HOA or management company. I’ll respond by email.
        </p>

        <form onSubmit={onSubmit} className="hoa-form">
          <div className="hoa-form-honeypot" aria-hidden="true">
            <label>
              Company URL
              <input
                type="text"
                tabIndex={-1}
                autoComplete="off"
                value={companyUrl}
                onChange={(e) => setCompanyUrl(e.target.value)}
              />
            </label>
          </div>
          <label>
            Name
            <input
              type="text"
              required
              value={name}
              onChange={(e) => setName(e.target.value)}
            />
          </label>

          <label>
            Role
            <select value={role} onChange={(e) => setRole(e.target.value)}>
              <option>HOA Board</option>
              <option>Architectural Review Committee</option>
              <option>Property Management</option>
              <option>Other</option>
            </select>
          </label>

          <label>
            HOA / Company
            <input
              type="text"
              required
              value={company}
              onChange={(e) => setCompany(e.target.value)}
            />
          </label>

          <label>
            Email
            <input
              type="email"
              required
              value={email}
              onChange={(e) => setEmail(e.target.value)}
            />
          </label>

          <label>
            Optional note
            <textarea
              rows="4"
              value={note}
              onChange={(e) => setNote(e.target.value)}
            />
          </label>

          <button
            className="cta-button cta-button--primary"
            type="submit"
            disabled={status === "sending"}
          >
            {status === "sending" ? "Sending…" : "Send"}
          </button>

          {status === "sent" && (
            <div className="hoa-form-status">
              Thanks — your message was sent.
            </div>
          )}
          {status === "error" && (
            <div className="hoa-form-status">
              {error || "Message failed. Please try again."}
            </div>
          )}
        </form>
      </section>

      <CTASection
        className="cta-section--transparent cta-section--left cta-section--desktop-row-split"
        ctas={[
          {
            cta_id: "hoa-back-overview",
            label: "Back to overview",
            key: "back_overview",
            variant: "link",
            enabled: true,
            params: {},
          },
        ]}
        onCtaClick={handleCtaClick}
      />

      <footer className="hoa-footer">
        © {new Date().getFullYear()} ColorFix
      </footer>
    </main>
  );
}
