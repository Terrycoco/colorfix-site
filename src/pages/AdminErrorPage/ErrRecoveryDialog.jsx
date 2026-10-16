import { useEffect, useRef, useState } from "react";
import { AdminDialog, AdminNotice } from "@components/AdminLayout";
import { API_FOLDER } from "@helpers/config";

const RECOVER_URL = `${API_FOLDER}/v2/admin/errors/recover.php`;
const STATUS_URL = `${API_FOLDER}/v2/admin/errors/status.php`;

async function readJson(response) {
  const data = await response.json();
  if (!response.ok || data?.ok === false) {
    throw new Error(data?.error || `Recovery request failed (HTTP ${response.status}).`);
  }
  return data;
}

function recoveryHeaders(sessionToken = "") {
  return {
    "Content-Type": "application/json",
    "X-ERR-Status-Session": sessionToken,
  };
}

async function recoveryRequest(body, sessionToken = "") {
  return readJson(await fetch(RECOVER_URL, {
    method: "POST", credentials: "include", cache: "no-store",
    headers: recoveryHeaders(sessionToken), body: JSON.stringify(body),
  }));
}

function statusesFromEvents(steps, events) {
  const next = steps.map(() => ({ status: "pending", detail: "" }));
  let lastKey = "";
  for (const event of events) {
    const explicitKey = String(event.detail || "").startsWith("step:")
      ? String(event.detail).slice(5) : "";
    if (event.status === "attempting") {
      lastKey = explicitKey || steps.find((s) => s.description === event.message)?.key || "";
      const i = steps.findIndex((s) => s.key === lastKey);
      if (i >= 0) next[i] = { status: "running", detail: "" };
    } else if (["success", "waiting", "failed"].includes(event.status) && explicitKey) {
      const i = steps.findIndex((s) => s.key === explicitKey);
      if (i >= 0) next[i] = { status: event.status, detail: event.message || "" };
    }
  }
  return next;
}

export default function ErrRecoveryDialog({ workingId, onClose, onComplete }) {
  const [plan, setPlan] = useState(null);
  const [loading, setLoading] = useState(true);
  const [started, setStarted] = useState(false);
  const [running, setRunning] = useState(false);
  const [failure, setFailure] = useState("");
  const [outcome, setOutcome] = useState(null);
  const [events, setEvents] = useState([]);
  const [operationId, setOperationId] = useState("");
  const [recoverySession, setRecoverySession] = useState("");
  const mounted = useRef(true);

  useEffect(() => { mounted.current = true; return () => { mounted.current = false; }; }, []);

  useEffect(() => {
    if (!workingId) return undefined;
    let active = true;
    setLoading(true);
    setPlan(null);
    setFailure("");
    recoveryRequest({ action: "plan", working_id: workingId })
      .then((data) => { if (active) setPlan(data); })
      .catch((error) => { if (active) setFailure(error.message); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [workingId]);

  async function refreshProgress(id, token) {
    const response = await fetch(`${STATUS_URL}?operation_id=${encodeURIComponent(id)}&_=${Date.now()}`, {
      credentials: "include", cache: "no-store",
      headers: { "X-ERR-Status-Session": token },
    });
    const data = await readJson(response);
    setEvents(Array.isArray(data.events) ? data.events : []);
  }

  useEffect(() => {
    if (!running || !operationId) return undefined;
    const poll = () => { void refreshProgress(operationId, recoverySession).catch(() => {}); };
    poll();
    const timer = window.setInterval(poll, 700);
    return () => window.clearInterval(timer);
  }, [running, operationId, recoverySession]);

  async function startRecovery() {
    if (!plan || running || started) return;
    const id = `err-recover-${crypto.randomUUID()}`;
    const token = crypto.randomUUID().replaceAll("-", "");
    setRecoverySession(token);
    setOperationId(id);
    setEvents([]);
    setStarted(true);
    setRunning(true);
    setFailure("");
    const beganAt = Date.now();
    let reachedTimeout = false;
    const sleep = (ms) => new Promise((resolve) => window.setTimeout(resolve, ms));
    try {
      let result = await recoveryRequest({
        action: "start", working_id: workingId, operation_id: id,
      }, token);
      await refreshProgress(id, token);
      // WAITING is not the end. Ask ERR to *check*, never execute again.
      // ERR uses the department's read-only completion checker.
      while (mounted.current && result.status === "waiting" && result.next_working_id && result.can_check_next) {
        if (Date.now() - beganAt > 180000) {
          reachedTimeout = true;
          setFailure("Still awaiting confirmation after three minutes. No new publish attempt was started. The recovery remains Waiting; check PUB before trying anything else.");
          break;
        }
        await sleep(2000);
        if (!mounted.current) break;
        result = await recoveryRequest({
          action: "check", working_id: result.next_working_id, operation_id: id,
        }, token);
        await refreshProgress(id, token);
      }
      if (!mounted.current) return;
      if (result.status !== "waiting") {
        setOutcome(result);
        await onComplete?.(result);
      } else if (!reachedTimeout) {
        // A department step without an asynchronous checker may remain waiting.
        setOutcome({ status: "waiting", message: result.message });
      }
    } catch (error) {
      await refreshProgress(id, token).catch(() => {});
      if (mounted.current) setFailure(error.message || "Recovery failed.");
    } finally {
      if (mounted.current) setRunning(false);
    }
  }

  const statuses = statusesFromEvents(plan?.steps || [], events);
  return (
    <AdminDialog
      open={Boolean(workingId)}
      title={plan ? `Recovery — Error #${plan.error_number}` : "Recovery"}
      width={650}
      dismissOnBackdrop={!running}
      onCancel={running ? undefined : onClose}
      actions={[
        { key: "close", label: started ? "Close" : "Cancel", variant: "secondary", disabled: running, onClick: onClose },
        !started && plan ? { key: "start", label: "Start Recovery", onClick: startRecovery, autoFocus: true } : null,
      ].filter(Boolean)}
    >
      <div style={{ display: "grid", gap: 14 }}>
        {loading ? <p>Loading registered recovery steps…</p> : null}
        {failure ? <AdminNotice variant="danger">{failure}</AdminNotice> : null}
        {plan ? (
          <>
            <div>
              <strong>{plan.department}</strong>
              {plan.resource_type && plan.resource_id ? ` · ${plan.resource_type} #${plan.resource_id}` : ""}
              <div style={{ fontSize: 12, opacity: 0.7 }}>Registered handler: {plan.handler_key}</div>
            </div>
            <div style={{ fontSize: 13 }}>
              {started ? "Recovery is executing the registered steps in order." : "Review the registered steps. No action runs until you select Start Recovery."}
            </div>
            <ol style={{ paddingLeft: 28, margin: 0, display: "grid", gap: 16 }}>
              {plan.steps.map((step, index) => (
                <li key={step.key}>
                  <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 12 }}>
                    <strong>{step.key.replaceAll("_", " ")}</strong>
                    <span role="status" style={{ fontSize: 12, textTransform: "capitalize", whiteSpace: "nowrap", color: statuses[index]?.status === "success" ? "#16803b" : statuses[index]?.status === "failed" ? "#b3261e" : "inherit" }}>
                      {statuses[index]?.status === "success" ? "✓ Successful"
                        : statuses[index]?.status === "running" ? "◌ Executing…"
                        : statuses[index]?.status === "waiting" ? "◌ Awaiting confirmation…"
                        : statuses[index]?.status === "failed" ? "✕ Failed"
                        : "○ Pending"}
                    </span>
                  </div>
                  <div style={{ fontSize: 13 }}>{step.description}</div>
                  {statuses[index]?.detail ? (
                    <div style={{ fontSize: 12, marginTop: 5, overflowWrap: "anywhere" }}>{statuses[index].detail}</div>
                  ) : null}
                </li>
              ))}
            </ol>
            {running ? <p role="status">Recovery in progress…</p> : null}
            {outcome ? (
              <AdminNotice variant={outcome.status === "resolved" ? "success" : "warning"}>
                {outcome.status}: {outcome.message}
              </AdminNotice>
            ) : null}
          </>
        ) : null}
      </div>
    </AdminDialog>
  );
}
