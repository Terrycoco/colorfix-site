import {
  useEffect,
  useState,
} from "react";

import {
  useParams,
} from "react-router-dom";

import {
  useAppState,
} from "@context/AppStateContext.jsx";

import {
  AdminEmptyState,
  AdminListPane,
  AdminMasterDetail,
  AdminNotice,
  AdminObjectList,
  AdminObjectListItem,
} from "@components/AdminLayout";

import {
  API_FOLDER,
} from "@helpers/config";

import PlaylistEditor from "@pages/AdminPlaylistsPage/PlaylistEditor";
import ProjectSetup from "./ProjectSetup";

import "./admin-project.css";


const GET_URL =
  `${API_FOLDER}/v2/admin/projects/get.php`;

const LIST_URL =
  `${API_FOLDER}/v2/admin/projects/list.php`;


export default function AdminProjectPage() {
  const {
    projectId,
  } = useParams();

  const {
    activeProjectId,
    setActiveProjectId,
  } = useAppState();

  const routeProjectId =
    /^\d+$/.test(
      String(projectId || "")
    )
      ? Number(projectId)
      : 0;

  const numericProjectId =
    routeProjectId
    ||
    Number(activeProjectId || 0);

  const [
    project,
    setProject,
  ] = useState(null);


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

  const [
    activeSection,
    setActiveSection,
  ] = useState("setup");


  useEffect(() => {
    let cancelled = false;

    async function loadProjects() {
      try {
        const res =
          await fetch(
            `${LIST_URL}?_=${Date.now()}`,
            {
              credentials: "include",
            }
          );

        const data =
          await res.json();

        if (
          !res.ok
          ||
          !data?.ok
        ) {
          throw new Error(
            data?.error ||
            "Failed to load projects."
          );
        }

        if (!cancelled) {
          setProjects(
            Array.isArray(data.items)
              ? data.items
              : []
          );
        }

      } catch {
        if (!cancelled) {
          setProjects([]);
        }
      }
    }

    loadProjects();

    return () => {
      cancelled = true;
    };
  }, []);


  useEffect(() => {
    let cancelled = false;

    async function loadProject() {
      setLoading(true);
      setError("");

      try {
        let resolvedProjectId =
          numericProjectId;

        if (
          resolvedProjectId <= 0
        ) {
          const listRes =
            await fetch(
              `${LIST_URL}?_=${Date.now()}`,
              {
                credentials: "include",
              }
            );

          const listText =
            await listRes.text();

          let listData = null;

          try {
            listData =
              JSON.parse(
                listText
              );
          } catch {
            throw new Error(
              `HTTP ${listRes.status}: ${listText.slice(0, 250)}`
            );
          }

          if (
            !listRes.ok
            ||
            !listData?.ok
          ) {
            throw new Error(
              listData?.error ||
              "Failed to load projects."
            );
          }

          const firstProject =
            Array.isArray(
              listData.items
            )
              ? listData.items[0]
              : null;

          resolvedProjectId =
            Number(
              firstProject?.id ||
              0
            );

          if (
            resolvedProjectId <= 0
          ) {
            throw new Error(
              "No projects found."
            );
          }
        }

        const res =
          await fetch(
            `${GET_URL}?project_id=${encodeURIComponent(
              resolvedProjectId
            )}&_=${Date.now()}`,
            {
              credentials: "include",
            }
          );

        const text =
          await res.text();

        let data = null;

        try {
          data =
            JSON.parse(text);
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
          !data?.project
        ) {
          throw new Error(
            data?.error ||
            "Failed to load project."
          );
        }

        if (!cancelled) {
          setProject(data.project);

          setActiveProjectId(
            Number(
              data.project.id
            )
          );
        }

      } catch (err) {
        if (!cancelled) {
          setProject(null);
          setError(
            err?.message ||
            "Failed to load project."
          );
        }

      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    }

    loadProject();

    return () => {
      cancelled = true;
    };
  }, [
    numericProjectId,
    routeProjectId,
  ]);


  const list =
    (
      <AdminListPane
        title={
          <select
            className="admin-field__control"
            value={
              project?.id
                ? String(project.id)
                : ""
            }
            onChange={
              (event) => {
                const nextId =
                  Number(
                    event.target.value || 0
                  );

                if (
                  nextId > 0
                  &&
                  nextId !==
                    Number(project?.id || 0)
                ) {
                  setActiveProjectId(
                    nextId
                  );

                  setActiveSection(
                    "setup"
                  );
                }
              }
            }
          >
            {
              projects.map(
                (row) => {
                  const label =
                    String(
                      row?.project_name ||
                      ""
                    ).trim()
                    ||
                    `Project #${row.id}`;

                  return (
                    <option
                      key={row.id}
                      value={row.id}
                    >
                      {label}
                    </option>
                  );
                }
              )
            }
          </select>
        }
      >
        <AdminObjectList ariaLabel="Project">
          <AdminObjectListItem
            id="setup"
            title="Setup"
            selected={
              activeSection ===
              "setup"
            }
            onSelect={() =>
              setActiveSection(
                "setup"
              )
            }
          />

          <AdminObjectListItem
            id="playlist"
            title="Playlist"
            meta={[
              project?.playlist_id
                ? (
                    project.playlist_title
                    || `Playlist #${project.playlist_id}`
                  )
                : "Not assigned",
            ]}
            selected={
              activeSection ===
              "playlist"
            }
            onSelect={() =>
              setActiveSection(
                "playlist"
              )
            }
          />
        </AdminObjectList>
      </AdminListPane>
    );


  let detail = null;

  if (loading) {
    detail =
      (
        <AdminEmptyState
          title="Project"
          message="Loading project..."
        />
      );

  } else if (error) {
    detail =
      (
        <AdminNotice variant="danger">
          {error}
        </AdminNotice>
      );

  } else if (!project) {
    detail =
      (
        <AdminEmptyState
          title="Project"
          message="Project not found."
        />
      );

  } else if (
    activeSection ===
    "setup"
  ) {
    detail =
      (
        <ProjectSetup
          project={project}
          onSaved={
            (savedProject) => {
              setProject(
                savedProject
              );

              setProjects(
                (current) =>
                  current.map(
                    (row) =>
                      Number(row.id) ===
                      Number(savedProject.id)
                        ? {
                            ...row,
                            ...savedProject,
                          }
                        : row
                  )
              );
            }
          }
        />
      );

  } else if (
    project.playlist_id
  ) {
    detail =
      (
        <PlaylistEditor
          playlistId={
            Number(
              project.playlist_id
            )
          }
        />
      );

  } else {
    detail =
      (
        <AdminEmptyState
          title="No playlist assigned"
          message="Choose a Playlist in Setup first."
        />
      );
  }


  return (
    <AdminMasterDetail
      storageKey="admin-project-list-width"
      defaultListWidth={240}
      list={list}
      detail={detail}
    />
  );
}
