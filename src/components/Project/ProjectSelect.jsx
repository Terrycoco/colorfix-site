import {
  useEffect,
  useMemo,
  useState,
} from "react";

import {
  API_FOLDER,
} from "@helpers/config";


const PROJECTS_LIST_URL =
  `${API_FOLDER}/v2/admin/projects/list.php`;


export default function ProjectSelect({
  value = "",
  onChange,
  disabled = false,
  includeNone = true,
  noneLabel = "None",
  maxWidth = "360px",
}) {
  const [
    projects,
    setProjects,
  ] = useState([]);

  const [
    loading,
    setLoading,
  ] = useState(true);

  const [
    error,
    setError,
  ] = useState("");


  useEffect(() => {
    let active =
      true;

    async function loadProjects() {
      setLoading(true);
      setError("");

      try {
        const res =
          await fetch(
            `${PROJECTS_LIST_URL}?_=${Date.now()}`,
            {
              credentials:
                "include",

              cache:
                "no-store",
            }
          );

        const text =
          await res.text();

        let data =
          null;

        try {
          data =
            JSON.parse(
              text
            );
        } catch {
          throw new Error(
            `Projects list returned invalid JSON: ${text.slice(0, 160)}`
          );
        }

        if (
          !res.ok
          ||
          data?.ok !== true
        ) {
          throw new Error(
            data?.error
            ||
            `Projects list failed with HTTP ${res.status}.`
          );
        }

        if (!active) {
          return;
        }

        setProjects(
          Array.isArray(
            data.items
          )
            ? data.items
            : []
        );

      } catch (err) {
        if (!active) {
          return;
        }

        setProjects([]);
        setError(
          err?.message
          ||
          "Failed to load projects."
        );

      } finally {
        if (active) {
          setLoading(false);
        }
      }
    }

    void loadProjects();

    return () => {
      active =
        false;
    };
  }, []);


  const sortedProjects =
    useMemo(
      () =>
        [...projects].sort(
          (
            a,
            b
          ) =>
            projectLabel(a)
              .localeCompare(
                projectLabel(b),
                undefined,
                {
                  sensitivity:
                    "base",

                  numeric:
                    true,
                }
              )
        ),
      [
        projects,
      ]
    );


  return (
    <div
      style={{
        maxWidth,
      }}
    >
      <select
        className="admin-field__control"
        value={
          value ??
          ""
        }
        disabled={
          disabled
          ||
          loading
        }
        onChange={
          (event) =>
            onChange?.(
              event.target.value
            )
        }
      >
        {
          includeNone
            ? (
                <option value="">
                  {
                    loading
                      ? "Loading projects…"
                      : noneLabel
                  }
                </option>
              )
            : null
        }

        {
          sortedProjects.map(
            (project) => (
              <option
                key={
                  project.id
                }
                value={
                  project.id
                }
              >
                {
                  projectLabel(
                    project
                  )
                }
              </option>
            )
          )
        }
      </select>

      {
        error
          ? (
              <div
                style={{
                  marginTop:
                    "4px",

                  fontSize:
                    "12px",

                  color:
                    "#a32020",
                }}
              >
                {error}
              </div>
            )
          : null
      }
    </div>
  );
}


function projectLabel(
  project
) {
  return String(
    project?.project_name
    ||
    project?.name
    ||
    `Project #${project?.id || ""}`
  ).trim();
}
