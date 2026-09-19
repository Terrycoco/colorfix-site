import {
  useEffect,
  useMemo,
  useState,
} from "react";

import {
  useParams,
} from "react-router-dom";

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

import "./admin-project.css";


const GET_URL =
  `${API_FOLDER}/v2/admin/projects/get.php`;


export default function AdminProjectPage() {
  const {
    projectId,
  } = useParams();

  const numericProjectId =
    /^\d+$/.test(
      String(
        projectId ||
        ""
      )
    )
      ? Number(
          projectId
        )
      : 0;

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
  ] = useState("playlist");


  useEffect(() => {
    let cancelled =
      false;

    async function loadProject() {
      if (
        numericProjectId <= 0
      ) {
        setProject(
          null
        );

        setError(
          "Valid project ID required."
        );

        setLoading(
          false
        );

        return;
      }

      setLoading(
        true
      );

      setError(
        ""
      );

      try {
        const res =
          await fetch(
            `${GET_URL}?project_id=${encodeURIComponent(
              numericProjectId
            )}`,
            {
              credentials:
                "include",
            }
          );

        const data =
          await res.json();

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
          setProject(
            data.project
          );
        }

      } catch (err) {
        if (!cancelled) {
          setProject(
            null
          );

          setError(
            err?.message ||
            "Failed to load project."
          );
        }

      } finally {
        if (!cancelled) {
          setLoading(
            false
          );
        }
      }
    }

    loadProject();

    return () => {
      cancelled =
        true;
    };
  }, [
    numericProjectId,
  ]);


  const projectLabel =
    useMemo(
      () => {
        if (!project) {
          return "Project";
        }

        const clientName =
          String(
            project.client_name ||
            ""
          ).trim();

        const propertyName =
          String(
            project.property_name ||
            ""
          ).trim();

        if (
          clientName
          &&
          propertyName
        ) {
          return `${clientName} · ${propertyName}`;
        }

        return (
          clientName
          ||
          propertyName
          ||
          `Project #${project.id}`
        );
      },
      [
        project,
      ]
    );


  const list =
    (
      <AdminListPane
        title={
          projectLabel
        }
      >
        <AdminObjectList ariaLabel="Project">
          <AdminObjectListItem
            id="overview"
            title="Overview"
            selected={
              activeSection ===
              "overview"
            }
            onSelect={() =>
              setActiveSection(
                "overview"
              )
            }
          />

          <AdminObjectListItem
            id="playlist"
            title="Playlist"
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


  let detail =
    null;

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
    "playlist"
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
        <ProjectOverview
          project={
            project
          }
        />
      );
  }


  return (
    <AdminMasterDetail
      storageKey="admin-project-list-width"
      defaultListWidth={
        240
      }
      list={
        list
      }
      detail={
        detail
      }
    />
  );
}


function ProjectOverview({
  project,
}) {
  return (
    <section className="admin-project-overview">
      <header className="admin-project-overview__header">
        <div>
          <h1>
            Project #{project.id}
          </h1>

          <p>
            Project workspace
          </p>
        </div>
      </header>

      <dl className="admin-project-overview__facts">
        <div>
          <dt>
            Client
          </dt>

          <dd>
            {
              project.client_name
              ||
              `Client #${project.client_id}`
            }
          </dd>
        </div>

        <div>
          <dt>
            Property
          </dt>

          <dd>
            {
              project.property_name
              ||
              `Property #${project.property_id}`
            }
          </dd>
        </div>

        <div>
          <dt>
            Playlist
          </dt>

          <dd>
            {
              project.playlist_title
              ||
              `Playlist #${project.playlist_id}`
            }
          </dd>
        </div>
      </dl>
    </section>
  );
}
