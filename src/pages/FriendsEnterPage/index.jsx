import { useState } from "react";
import { isAdmin } from '@helpers/authHelper';
import { useAppState } from '@context/AppStateContext';
import FuzzySearchColorSelect from "@components/FuzzySearchColorSelect";
import { API_FOLDER } from "@helpers/config";
import "./friends.css";

export default function FriendsCaptureMobile() {
  const [selected, setSelected] = useState([]);
  const [submitting, setSubmitting] = useState(false);
  const { setMessage } = useAppState();
  const admin = isAdmin();

  function addSwatch(s) {
    if (!s?.id) return;
    setSelected(prev => (prev.some(p => p.id === s.id) || prev.length >= 6) ? prev : [...prev, s]);
  }

  function removeSwatch(id) {
    setSelected(prev => prev.filter(s => s.id !== id));
  }

  async function handleSubmit() {
    if (!admin || submitting || selected.length < 2) return;
    setSubmitting(true);
    setMessage("submitting...");
    try {
      const ids = selected.map(s => s.id);
      const res = await fetch(`${API_FOLDER}/friends-capture-pipeline.php`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ ids, source: "manual mobile", batch_pairs: 400, time_budget_ms: 3000 })
      });
      const data = await res.json();
      if (!res.ok || !data?.ok) throw new Error(data?.error || `HTTP ${res.status}`);
      const ins = data?.capture?.inserted_pairs ?? data?.capture?.inserted ?? 0;
      const att = data?.capture?.attempted_pairs ?? data?.capture?.attempted ?? 0;
      setMessage(`Added ${ins}/${att} pairs`);
      setSelected([]);
    } catch (e) {
      console.log(`${e.message}`);
      setMessage(`Error: ${e.message}`);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="friends-capture">
      <div className="fc-scroll">
        <div className="fc-list">
          {selected.map(s => {
            const l = Number(s.hcl_l) || 0;
            const text = l >= 60 ? "#111" : "#fff";
            const brand = s.brand_name || s.brand || "";
            return (
              <div
                key={s.id}
                className="fc-item"
                style={{ background: s.hex }}
                onClick={() => removeSwatch(s.id)}
                title="Tap to remove"
              >
                <div className="fc-item__text" style={{ color: text }}>
                  <strong className="fc-item__name">{s.name}</strong>
                  {brand ? <span className="fc-item__brand"> · {brand}</span> : null}
                </div>
              </div>
            );
          })}
        </div>

        <div className="fc-input">
          <FuzzySearchColorSelect
            placeholder="Enter a name or code…"
            autoFocus
            onSelect={addSwatch}
          />
        </div>
      </div>

      <div className="fc-bottom">
        <button
          className="fc-submit"
          disabled={!admin || submitting || selected.length < 2}
          onClick={handleSubmit}
        >
          {submitting ? "Saving…" : "Submit"}
        </button>
      </div>
    </div>
  );
}
