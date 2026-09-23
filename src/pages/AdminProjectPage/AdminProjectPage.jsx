import {
  useEffect,
  useState,
} from "react";

import {
  useLocation,
  useParams,
} from "react-router-dom";

import {
  useAppState,
} from "@context/AppStateContext.jsx";

import {
  AdminButton,
  AdminDetailPane,
  AdminEmptyState,
  AdminListPane,
  AdminMasterDetail,
  AdminNotice,
  AdminObjectList,
  AdminObjectListItem,
} from "@components/AdminLayout";

import ProjectSelect from "@components/Project/ProjectSelect";

import {
  API_FOLDER,
} from "@helpers/config";

import PlaylistEditor from "@pages/AdminPlaylistsPage/PlaylistEditor";
import ProjectPalettes from "./ProjectPalettes";
import ProjectPVPage from "./ProjectPVPage";
import ProjectSetup from "./ProjectSetup";

import "./admin-project.css";


const GET_URL =
  `${API_FOLDER}/v2/admin/projects/get.php`;

const SAVE_URL =
  `${API_FOLDER}/v2/admin/projects/save.php`;

const LIST_URL =
  `${API_FOLDER}/v2/admin/projects/list.php`;

const PROJECT_SECTION_STORAGE_KEY =
  "admin-project-active-section";

const PROJECT_SECTIONS =
  new Set([
    "setup",
    "playlist",
    "palettes",
    "pvs",
  ]);

function readLastProjectSection() {
  if (typeof window === "undefined") {
    return "playlist";
  }

  try {
    const saved =
      window.localStorage.getItem(
        PROJECT_SECTION_STORAGE_KEY
      );

    return PROJECT_SECTIONS.has(saved)
      ? saved
      : "playlist";
  } catch {
    return "playlist";
  }
}


export default function AdminProjectPage() {
  const {
    projectId,
  } = useParams();

  const location = useLocation();

  const {
    activeProjectId,
    setActiveProjectId,
    setWorkingPlaylistId,
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
    Number(new URLSearchParams(location.search).get("project_id") || 0)
    ||
    Number(activeProjectId || 0);

  const [
    project,
    setProject,
  ] = useState(null);

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
  ] = useState(
    readLastProjectSection
  );

  const [
    creatingPlaylist,
    setCreatingPlaylist,
  ] = useState(false);


  useEffect(
    () => {
      if (
        !PROJECT_SECTIONS.has(
          activeSection
        )
      ) {
        return;
      }

      try {
        window.localStorage.setItem(
          PROJECT_SECTION_STORAGE_KEY,
          activeSection
        );
      } catch {
        // Storage can be unavailable in private/restricted browser modes.
      }
    },
    [
      activeSection,
    ]
  );


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

          setWorkingPlaylistId(
            Number(
              data.project.playlist_id ||
              0
            ) > 0
              ? Number(
                  data.project.playlist_id
                )
              : null
          );

          setCreatingPlaylist(
            false
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


  function beginNewPlaylist() {
    if (!project?.id) {
      return;
    }

    setCreatingPlaylist(
      true
    );

    setWorkingPlaylistId(
      null
    );

    setActiveSection(
      "playlist"
    );
  }


  async function handleNewPlaylistSaved(
    playlistId
  ) {
    const id =
      Number(
        playlistId ||
        0
      );

    if (
      !project?.id
      ||
      id <= 0
    ) {
      return;
    }

    setWorkingPlaylistId(
      id
    );

    setProject(
      (current) =>
        current
          ? {
              ...current,
              playlist_id:
                id,
            }
          : current
    );

    setCreatingPlaylist(
      false
    );

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
                project_id:
                  Number(
                    project.id
                  ),

                project_name:
                  project.project_name
                  ??
                  null,

                client_id:
                  project.client_id
                  ??
                  null,

                property_id:
                  project.property_id
                  ??
                  null,

                playlist_id:
                  id,
              }),
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
          "Playlist was created, but the Project could not be updated."
        );
      }

      if (
        data?.project
      ) {
        setProject(
          data.project
        );
      }

    } catch (err) {
      setError(
        err?.message ||
        "Playlist was created, but the Project could not be updated."
      );
    }
  }



  const list =
    (
      <AdminListPane
        title={
          <ProjectSelect
            value={
              project?.id
                ? String(project.id)
                : ""
            }
            includeNone={false}
            onChange={
              (value) => {
                const nextId =
                  Number(
                    value || 0
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

                  setWorkingPlaylistId(
                    null
                  );

                  setCreatingPlaylist(
                    false
                  );
                }
              }
            }
            maxWidth="100%"
          />
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
            title="Playlists"
            meta={[
              creatingPlaylist
                ? "New playlist"
                : project?.playlist_id
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

          <AdminObjectListItem
            id="palettes"
            title="Palettes"
            selected={
              activeSection ===
              "palettes"
            }
            onSelect={() =>
              setActiveSection(
                "palettes"
              )
            }
          />

          <AdminObjectListItem
            id="pvs"
            title="PVs"
            selected={
              activeSection ===
              "pvs"
            }
            onSelect={() =>
              setActiveSection(
                "pvs"
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
        <AdminDetailPane
          ariaLabel="Project"
          title="Project"
        >
          <AdminEmptyState
            title="Project"
            message="Loading project..."
          />
        </AdminDetailPane>
      );

  } else if (error) {
    detail =
      (
        <AdminDetailPane
          ariaLabel="Project"
          title="Project"
        >
          <AdminNotice variant="danger">
            {error}
          </AdminNotice>
        </AdminDetailPane>
      );

  } else if (!project) {
    detail =
      (
        <AdminDetailPane
          ariaLabel="Project"
          title="Project"
        >
          <AdminEmptyState
            title="Project"
            message="Project not found."
          />
        </AdminDetailPane>
      );

  } else if (
    activeSection ===
    "setup"
  ) {
    detail =
      (
        <AdminDetailPane
          ariaLabel="Project setup"
          title={`Project #${project.id} Setup`}
          actions={
            <AdminButton
              type="submit"
              form="admin-project-setup-form"
            >
              Save
            </AdminButton>
          }
        >
          <ProjectSetup
            project={project}
            onNewPlaylist={
              beginNewPlaylist
            }
            onSaved={
              (savedProject) => {
                setProject(
                  savedProject
                );

                setWorkingPlaylistId(
                  Number(
                    savedProject?.playlist_id ||
                    0
                  ) > 0
                    ? Number(
                        savedProject.playlist_id
                      )
                    : null
                );
              }
            }
          />
        </AdminDetailPane>
      );

  } else if (
    activeSection ===
    "palettes"
  ) {
    detail =
      (
        <ProjectPalettes
          projectId={
            Number(
              project.id
            )
          }
        />
      );

  } else if (
    activeSection ===
    "pvs"
  ) {
    detail =
      (
        <ProjectPVPage
          projectId={
            Number(
              project.id
            )
          }
          playlistId={
            Number(
              project.playlist_id
              ||
              0
            )
            ||
            null
          }
        />
      );

  } else if (
    creatingPlaylist
  ) {
    detail =
      (
        <PlaylistEditor
          playlistId={
            null
          }
          initialProjectId={
            Number(
              project.id
            )
          }
          onSaved={
            handleNewPlaylistSaved
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
          onSaved={
            (playlistId) =>
              setWorkingPlaylistId(
                Number(
                  playlistId
                )
              )
          }
        />
      );

  } else {
    detail =
      (
        <AdminDetailPane
          ariaLabel="Playlist"
          title="Playlist"
        >
          <AdminEmptyState
            title="No playlist assigned"
            message="Choose a Playlist in Setup first."
          />
        </AdminDetailPane>
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
