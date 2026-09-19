import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from "react";

import {
  useLocation,
  useNavigate,
  useParams,
} from "react-router-dom";

import {
  AdminButton,
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

import PlaylistEditor from "./PlaylistEditor";


const LIST_URL =
  `${API_FOLDER}/v2/admin/playlists/list.php`;


export default function AdminPlaylistsPage() {
  const navigate =
    useNavigate();

  const location =
    useLocation();

  const {
    playlistId,
  } = useParams();

  const editorRef =
    useRef(null);

  const isNewRoute =
    location.pathname.endsWith(
      "/playlists/new"
    );

  const routePlaylistId =
    /^\d+$/.test(
      String(
        playlistId ||
        ""
      )
    )
      ? Number(
          playlistId
        )
      : null;

  const [
    query,
    setQuery,
  ] = useState("");

  const [
    sortMode,
    setSortMode,
  ] = useState("title");

  const [
    playlists,
    setPlaylists,
  ] = useState([]);

  const [
    listLoading,
    setListLoading,
  ] = useState(false);

  const [
    listError,
    setListError,
  ] = useState("");


  const fetchPlaylists =
    useCallback(
      async (
        forceReload = false
      ) => {
        setListLoading(
          true
        );

        setListError(
          ""
        );

        try {
          const res =
            await fetch(
              LIST_URL,
              {
                credentials:
                  "include",

                cache:
                  forceReload
                    ? "reload"
                    : "default",
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
              "Failed to load playlists."
            );
          }

          setPlaylists(
            Array.isArray(
              data.items
            )
              ? data.items
              : []
          );

        } catch (err) {
          setListError(
            err?.message ||
            "Failed to load playlists."
          );

        } finally {
          setListLoading(
            false
          );
        }
      },
      []
    );


  useEffect(() => {
    fetchPlaylists();
  }, [
    fetchPlaylists,
  ]);


  const visiblePlaylists =
    useMemo(
      () => {
        const needle =
          query
            .trim()
            .toLowerCase();

        const rows =
          needle
            ? playlists.filter(
                (row) => {
                  const title =
                    String(
                      row?.title ||
                      ""
                    )
                      .toLowerCase();

                  const id =
                    String(
                      row
                        ?.playlist_id ||
                      ""
                    );

                  return (
                    title.includes(
                      needle
                    )
                    ||
                    id.includes(
                      needle
                    )
                  );
                }
              )
            : [
                ...playlists,
              ];

        rows.sort(
          (
            a,
            b
          ) => {
            if (
              sortMode ===
              "id"
            ) {
              return (
                Number(
                  b?.playlist_id ||
                  0
                )
                -
                Number(
                  a?.playlist_id ||
                  0
                )
              );
            }

            return String(
              a?.title ||
              ""
            ).localeCompare(
              String(
                b?.title ||
                ""
              )
            );
          }
        );

        return rows;
      },
      [
        playlists,
        query,
        sortMode,
      ]
    );


  useEffect(() => {
    if (
      isNewRoute
      ||
      routePlaylistId
      ||
      listLoading
      ||
      !visiblePlaylists.length
    ) {
      return;
    }

    navigate(
      `/admin/playlists/${visiblePlaylists[0].playlist_id}`,
      {
        replace:
          true,
      }
    );
  }, [
    isNewRoute,
    routePlaylistId,
    listLoading,
    visiblePlaylists,
    navigate,
  ]);


  async function commitCurrentEditor() {
    const editor =
      editorRef.current;

    if (
      !editor
      ||
      typeof editor.saveIfDirty !==
        "function"
    ) {
      return true;
    }

    return editor.saveIfDirty();
  }


  async function navigateToPlaylist(
    row
  ) {
    const id =
      Number(
        row?.playlist_id ||
        0
      );

    if (
      !id
      ||
      id === routePlaylistId
    ) {
      return;
    }

    if (
      !await commitCurrentEditor()
    ) {
      return;
    }

    navigate(
      `/admin/playlists/${id}`
    );
  }


  async function beginNewPlaylist() {
    if (
      !await commitCurrentEditor()
    ) {
      return;
    }

    navigate(
      "/admin/playlists/new"
    );
  }


  function handleSaved(
    id
  ) {
    fetchPlaylists(
      true
    );

    if (
      isNewRoute
      &&
      id
    ) {
      navigate(
        `/admin/playlists/${id}`,
        {
          replace:
            true,
        }
      );
    }
  }


  function handleDeleted() {
    fetchPlaylists(
      true
    );

    navigate(
      "/admin/playlists",
      {
        replace:
          true,
      }
    );
  }


  const copiedSlides =
    isNewRoute
    &&
    Array.isArray(
      location.state
        ?.copiedSlides
    )
      ? location.state.copiedSlides
      : [];


  return (
    <AdminMasterDetail
      storageKey="admin-playlists-list-width"
      defaultListWidth={
        300
      }

      list={
        <AdminListPane
          title="Playlists"

          searchValue={
            query
          }

          onSearchChange={
            setQuery
          }

          searchPlaceholder="Search playlists..."

          sortValue={
            sortMode
          }

          onSortChange={
            setSortMode
          }

          sortOptions={[
            {
              value:
                "title",
              label:
                "Title",
            },
            {
              value:
                "id",
              label:
                "ID",
            },
          ]}

          actions={
            <>
              <AdminButton
                type="button"
                onClick={
                  beginNewPlaylist
                }
              >
                New
              </AdminButton>

              <AdminButton
                type="button"
                variant="secondary"
                disabled={
                  listLoading
                }
                title="Refresh playlists"
                aria-label="Refresh playlists"
                onClick={() =>
                  fetchPlaylists(
                    true
                  )
                }
              >
                {
                  listLoading
                    ? "…"
                    : "↻"
                }
              </AdminButton>
            </>
          }
        >
          {
            listError
              ? (
                  <AdminNotice variant="danger">
                    {listError}
                  </AdminNotice>
                )
              : null
          }

          {
            listLoading
            &&
            !playlists.length
              ? (
                  <AdminEmptyState
                    title="Playlists"
                    message="Loading playlists..."
                  />
                )
              : (
                  <AdminObjectList ariaLabel="Playlists">
                    {
                      visiblePlaylists.map(
                        (row) => (
                          <AdminObjectListItem
                            key={
                              row.playlist_id
                            }

                            id={
                              row.playlist_id
                            }

                            title={
                              row.title ||
                              "Untitled"
                            }

                            meta={[
                              row.type ||
                              "Untyped",

                              Number(
                                row.is_active
                              ) !== 0
                                ? "Active"
                                : "Inactive",
                            ]}

                            selected={
                              Number(
                                row.playlist_id
                              )
                              ===
                              Number(
                                routePlaylistId
                              )
                            }

                            onSelect={() =>
                              navigateToPlaylist(
                                row
                              )
                            }
                          />
                        )
                      )
                    }
                  </AdminObjectList>
                )
          }
        </AdminListPane>
      }

      detail={
        <PlaylistEditor
          ref={
            editorRef
          }

          playlistId={
            routePlaylistId
          }

          initialCopiedSlides={
            copiedSlides
          }

          onSaved={
            handleSaved
          }

          onDeleted={
            handleDeleted
          }
        />
      }
    />
  );
}
