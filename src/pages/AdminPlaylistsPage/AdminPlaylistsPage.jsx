import { filterAdminRecords } from "@components/AdminLayout/adminSearch";
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

const EMPTY_COPIED_SLIDES = [];





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



  const [activityFilter, setActivityFilter] = useState("active");

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

        // A search covers active AND inactive playlists.
        // With no query, retain the selected activity filter.
        const source = query.trim()
          ? playlists
          : playlists.filter(
              (row) => (Number(row.is_active) !== 0) === (activityFilter === "active")
            );
        const rows = filterAdminRecords(source, query, {
          idField: "playlist_id",
          textFields: ["title"],
        });

        rows.sort(

          (

            a,

            b

          ) => {

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

        activityFilter,

        query,


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





  async function jumpToPlaylistId(value) {
    const id = Number(value);
    if (!Number.isSafeInteger(id) || id <= 0) return false;

    // The list is the authority for available playlist IDs.
    if (!playlists.some((row) => Number(row?.playlist_id) === id)) {
      return false;
    }

    if (id === routePlaylistId) return true;

    // Preserve the existing navigation guard for unsaved slide changes.
    if (!await commitCurrentEditor()) {
      throw new Error("Save your playlist changes before jumping to another playlist.");
    }

    setQuery("");
    navigate(`/admin/playlists/${id}`);
    return true;
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

      : EMPTY_COPIED_SLIDES;





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



          actions={
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
          }

        >
          <div className="admin-list-pane__toolbar">
            <div className="admin-list-pane__sort" role="group" aria-label="Playlist activity">
              {["active", "inactive"].map((value) => (
                <button
                  key={value}
                  type="button"
                  className={`admin-list-pane__sort-button${activityFilter === value ? " is-active" : ""}`}
                  aria-pressed={activityFilter === value}
                  onClick={() => setActivityFilter(value)}
                >
                  {value === "active" ? "Active" : "Inactive"}
                </button>
              ))}
            </div>
          </div>
          {!listLoading && !listError && visiblePlaylists.length === 0 && (
            <AdminEmptyState
              title="Playlists"
              message={query.trim() ? "No playlists match your search." : `No ${activityFilter} playlists.`}
            />
          )}

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

        (routePlaylistId || isNewRoute) ? <PlaylistEditor

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

        
          onNew={

            beginNewPlaylist

          }
          onJumpPlaylistId={jumpToPlaylistId}

        /> : <AdminEmptyState title="Playlists" message={listLoading ? "Loading playlists..." : "Select a playlist."} />

      }

    />

  );

}
