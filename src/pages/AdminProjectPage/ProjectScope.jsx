import {
  useEffect,
  useState,
} from "react";

import {
  AdminField,
  AdminNotice,
  AdminStack,
} from "@components/AdminLayout";

import {
  API_FOLDER,
} from "@helpers/config";


const GET_URL =
  `${API_FOLDER}/v2/admin/projects/scope/get.php`;

const SAVE_URL =
  `${API_FOLDER}/v2/admin/projects/scope/save.php`;


function emptyForm() {
  return {
    scope_id:
      null,

    title:
      "Original Scope",

    project_goal:
      "",

    areas_covered:
      "",

    scope_fee:
      "",

    status:
      "draft",
  };
}


function formFromScope(scope) {
  if (!scope) {
    return emptyForm();
  }

  return {
    scope_id:
      Number(
        scope.id ||
        0
      ) || null,

    title:
      String(
        scope.title ||
        "Original Scope"
      ),

    project_goal:
      String(
        scope.project_goal ||
        ""
      ),

    areas_covered:
      String(
        scope.areas_covered ||
        ""
      ),

    scope_fee:
      scope.scope_fee === null
      ||
      scope.scope_fee === undefined
        ? ""
        : String(
            scope.scope_fee
          ),

    status:
      String(
        scope.status ||
        "draft"
      ),
  };
}


export default function ProjectScope({
  projectId,
}) {
  const [
    form,
    setForm,
  ] = useState(
    emptyForm
  );

  const [
    loading,
    setLoading,
  ] = useState(true);

  const [
    saving,
    setSaving,
  ] = useState(false);

  const [
    error,
    setError,
  ] = useState("");

  const [
    statusMessage,
    setStatusMessage,
  ] = useState("");


  useEffect(() => {
    let active = true;

    async function loadScope() {
      setLoading(true);
      setError("");
      setStatusMessage("");

      try {
        const res =
          await fetch(
            `${GET_URL}?project_id=${encodeURIComponent(
              Number(projectId)
            )}&_=${Date.now()}`,
            {
              credentials:
                "include",
            }
          );

        const text =
          await res.text();

        let data = null;

        try {
          data =
            JSON.parse(
              text
            );
        } catch {
          throw new Error(
            `HTTP ${res.status}: ${text.slice(0, 250)}`
          );
        }

        if (
          !res.ok
          ||
          !data?.ok
        ) {
          throw new Error(
            data?.error ||
            "Failed to load Project Scope."
          );
        }

        if (active) {
          setForm(
            formFromScope(
              data.scope ||
              null
            )
          );
        }

      } catch (err) {
        if (active) {
          setError(
            err?.message ||
            "Failed to load Project Scope."
          );
        }

      } finally {
        if (active) {
          setLoading(false);
        }
      }
    }

    if (
      Number(projectId || 0) > 0
    ) {
      loadScope();
    } else {
      setForm(
        emptyForm()
      );
      setLoading(false);
    }

    return () => {
      active = false;
    };
  }, [
    projectId,
  ]);


  function setField(
    field,
    value
  ) {
    setForm(
      (current) => ({
        ...current,
        [field]:
          value,
      })
    );

    setError("");
    setStatusMessage("");
  }


  async function saveScope(
    event
  ) {
    event.preventDefault();

    if (
      Number(projectId || 0) <= 0
    ) {
      return;
    }

    setSaving(true);
    setError("");
    setStatusMessage("");

    try {
      const res =
        await fetch(
          SAVE_URL,
          {
            method:
              "POST",

            credentials:
              "include",

            headers: {
              "Content-Type":
                "application/json",
            },

            body:
              JSON.stringify({
                scope_id:
                  form.scope_id,

                project_id:
                  Number(
                    projectId
                  ),

                scope_kind:
                  "main",

                title:
                  String(
                    form.title ||
                    ""
                  ).trim()
                  || "Original Scope",

                project_goal:
                  String(
                    form.project_goal ||
                    ""
                  ).trim()
                  || null,

                areas_covered:
                  String(
                    form.areas_covered ||
                    ""
                  ).trim()
                  || null,

                scope_fee:
                  form.scope_fee === ""
                    ? null
                    : form.scope_fee,

                status:
                  String(
                    form.status ||
                    "draft"
                  ),
              }),
          }
        );

      const text =
        await res.text();

      let data = null;

      try {
        data =
          JSON.parse(
            text
          );
      } catch {
        throw new Error(
          `HTTP ${res.status}: ${text.slice(0, 250)}`
        );
      }

      if (
        !res.ok
        ||
        !data?.ok
        ||
        !data?.scope
      ) {
        throw new Error(
          data?.error ||
          "Failed to save Project Scope."
        );
      }

      setForm(
        formFromScope(
          data.scope
        )
      );

      setStatusMessage(
        "Project Scope saved."
      );

    } catch (err) {
      setError(
        err?.message ||
        "Failed to save Project Scope."
      );

    } finally {
      setSaving(false);
    }
  }


  return (
    <form
      id="admin-project-scope-form"
      onSubmit={saveScope}
    >
      <AdminStack>
        {
          error
            ? (
                <AdminNotice variant="danger">
                  {error}
                </AdminNotice>
              )
            : null
        }

        {
          statusMessage
            ? (
                <AdminNotice variant="success">
                  {statusMessage}
                </AdminNotice>
              )
            : null
        }

        {
          loading
            ? (
                <AdminNotice>
                  Loading Project Scope...
                </AdminNotice>
              )
            : null
        }

        <AdminField
          label="Title"
        >
          <input
            className="admin-field__control"
            type="text"
            value={
              form.title
            }
            disabled={
              loading ||
              saving
            }
            onChange={
              (event) =>
                setField(
                  "title",
                  event.target.value
                )
            }
          />
        </AdminField>

        <AdminField
          label="Project Goal"
        >
          <textarea
            className="admin-field__control"
            rows={4}
            value={
              form.project_goal
            }
            disabled={
              loading ||
              saving
            }
            onChange={
              (event) =>
                setField(
                  "project_goal",
                  event.target.value
                )
            }
          />
        </AdminField>

        <AdminField
          label="Areas Covered"
        >
          <textarea
            className="admin-field__control"
            rows={6}
            value={
              form.areas_covered
            }
            disabled={
              loading ||
              saving
            }
            onChange={
              (event) =>
                setField(
                  "areas_covered",
                  event.target.value
                )
            }
          />
        </AdminField>

        <AdminField
          label="Scope Fee"
        >
          <input
            className="admin-field__control"
            type="number"
            min="0"
            step="0.01"
            value={
              form.scope_fee
            }
            disabled={
              loading ||
              saving
            }
            onChange={
              (event) =>
                setField(
                  "scope_fee",
                  event.target.value
                )
            }
          />
        </AdminField>

        <AdminField
          label="Status"
        >
          <select
            className="admin-field__control"
            value={
              form.status
            }
            disabled={
              loading ||
              saving
            }
            onChange={
              (event) =>
                setField(
                  "status",
                  event.target.value
                )
            }
          >
            <option value="draft">
              Draft
            </option>

            <option value="active">
              Active
            </option>

            <option value="superseded">
              Superseded
            </option>
          </select>
        </AdminField>
      </AdminStack>
    </form>
  );
}
