import {
  useEffect,
  useMemo,
  useState,
} from "react";

import {
  AdminButton,
  AdminCheckboxRow,
  AdminDataGrid,
  AdminDetailPane,
  AdminEmptyState,
  AdminField,
  AdminFieldRow,
  AdminListPane,
  AdminMasterDetail,
  AdminMediaPreview,
  AdminNotice,
  AdminObjectList,
  AdminObjectListItem,
  AdminPanel,
  AdminPhotoPickerField,
  AdminSectionHeader,
  AdminStack,
  AdminToast,
  AdminToolbar,
  useAdminDialog,
} from "@components/AdminLayout";

import {
  API_FOLDER,
} from "@helpers/config";


const SETS_LIST_URL =
  `${API_FOLDER}/v2/admin/playlist-sets/list.php`;

const SETS_GET_URL =
  `${API_FOLDER}/v2/admin/playlist-sets/get.php`;

const SETS_SAVE_URL =
  `${API_FOLDER}/v2/admin/playlist-sets/save.php`;

const SET_ITEMS_SAVE_URL =
  `${API_FOLDER}/v2/admin/playlist-set-items/save.php`;

const PLAYLISTS_LIST_URL =
  `${API_FOLDER}/v2/admin/playlists/list.php`;

const PLAYLIST_GET_URL =
  `${API_FOLDER}/v2/admin/playlists/get.php`;

const PLAYLIST_SET_PUBLIC_URL =
  `${API_FOLDER}/v2/admin/playlists/set-public.php`;


const EMPTY_SET = {
  id: null,
  handle: "",
  title: "",
  subtitle: "",
  context: "",
  cover_photo_library_id: null,
  cover_photo_url: "",
  end_cta_label: "Explore ColorFix",
  end_cta_url: "/",
  end_cta_enabled: true,
  is_retired: false,
};


function makeItemKey(
  item,
  index
) {
  if (
    item?.id !== null &&
    item?.id !== undefined
  ) {
    return `id-${item.id}`;
  }

  return [
    "new",
    item?.item_type || "playlist",
    item?.playlist_id || "",
    item?.target_set_id || "",
    index,
  ].join("-");
}


function normalizeItems(
  items
) {
  return (
    Array.isArray(items)
      ? items
      : []
  ).map(
    (
      item,
      index
    ) => ({
      ...item,

      item_type:
        item?.item_type === "set"
          ? "set"
          : "playlist",

      playlist_id:
        item?.playlist_id ?? null,

      target_set_id:
        item?.target_set_id ?? null,

      sort_order:
        Number(
          item?.sort_order
          ?? index + 1
        ),

      _key:
        makeItemKey(
          item,
          index
        ),
    })
  );
}


function buildSetPlayUrl(
  setId
) {
  const id =
    Number(
      setId || 0
    );

  if (id <= 0) {
    return "";
  }

  const params =
    new URLSearchParams({
      set:
        String(id),

      include_private:
        "1",

      src:
        "admin",

      close:
        "1",

      return_to:
        "/admin/playlist-sets",
    });

  return `/picker?${params.toString()}`;
}


export default function AdminPlaylistSetsPage() {
  const dialog =
    useAdminDialog();

  const [
    sets,
    setSets,
  ] = useState([]);

  const [
    playlists,
    setPlaylists,
  ] = useState([]);

  const [
    activeSetId,
    setActiveSetId,
  ] = useState(null);

  const [
    editingNew,
    setEditingNew,
  ] = useState(false);

  const [
    setForm,
    setSetForm,
  ] = useState(EMPTY_SET);

  const [
    setItems,
    setSetItems,
  ] = useState([]);

  const [
    query,
    setQuery,
  ] = useState("");

  const [
    loading,
    setLoading,
  ] = useState(false);

  const [
    error,
    setError,
  ] = useState("");

  const [
    toast,
    setToast,
  ] = useState("");

  const [
    newItemType,
    setNewItemType,
  ] = useState("playlist");

  const [
    newPlaylistId,
    setNewPlaylistId,
  ] = useState("");

  const [
    newTargetSetId,
    setNewTargetSetId,
  ] = useState("");


  useEffect(
    () => {
      void fetchSets();
      void fetchPlaylists();
    },
    []
  );


  useEffect(
    () => {
      if (
        !activeSetId
        || editingNew
      ) {
        return;
      }

      void fetchSet(
        activeSetId
      );
    },
    [
      activeSetId,
      editingNew,
    ]
  );


  async function fetchSets() {
    try {
      const response =
        await fetch(
          `${SETS_LIST_URL}?_=${Date.now()}`,
          {
            credentials:
              "include",
          }
        );

      const data =
        await response.json();

      if (
        !response.ok
        || !data?.ok
      ) {
        throw new Error(
          data?.error
          || "Failed to load playlist sets."
        );
      }

      setSets(
        Array.isArray(
          data.sets
        )
          ? data.sets
          : []
      );

    } catch (err) {
      setError(
        err?.message
        || "Failed to load playlist sets."
      );
    }
  }


  async function fetchPlaylists() {
    try {
      const response =
        await fetch(
          `${PLAYLISTS_LIST_URL}?_=${Date.now()}`,
          {
            credentials:
              "include",
          }
        );

      const data =
        await response.json();

      if (
        !response.ok
        || !data?.ok
      ) {
        throw new Error(
          data?.error
          || "Failed to load playlists."
        );
      }

      const rows =
        Array.isArray(
          data.items
        )
          ? data.items
          : Array.isArray(
              data.playlists
            )
            ? data.playlists
            : [];

      setPlaylists(
        rows
      );

    } catch (err) {
      setError(
        err?.message
        || "Failed to load playlists."
      );
    }
  }


  async function fetchSet(
    id
  ) {
    setLoading(
      true
    );

    setError(
      ""
    );

    try {
      const response =
        await fetch(
          `${SETS_GET_URL}?id=${id}&_=${Date.now()}`,
          {
            credentials:
              "include",
          }
        );

      const data =
        await response.json();

      if (
        !response.ok
        || !data?.ok
      ) {
        throw new Error(
          data?.error
          || "Failed to load playlist set."
        );
      }

      const nextSet =
        data.set || {};

      setSetForm({
        id:
          nextSet.id
          ?? null,

        handle:
          nextSet.handle
          ?? "",

        title:
          nextSet.title
          ?? "",

        subtitle:
          nextSet.subtitle
          ?? "",

        context:
          nextSet.context
          ?? "",

        cover_photo_library_id:
          nextSet.cover_photo_library_id
          ?? null,

        cover_photo_url:
          nextSet.cover_photo_url
          ?? "",

        end_cta_label:
          nextSet.end_cta_label
          || "Explore ColorFix",

        end_cta_url:
          nextSet.end_cta_url
          || "/",

        end_cta_enabled:
          nextSet.end_cta_enabled
          !== false,

        is_retired:
          nextSet.is_retired === true
          || Number(
            nextSet.is_retired
          ) === 1,
      });

      setSetItems(
        normalizeItems(
          data.items
        )
      );

    } catch (err) {
      setError(
        err?.message
        || "Failed to load playlist set."
      );

    } finally {
      setLoading(
        false
      );
    }
  }


  function selectSet(
    id
  ) {
    setEditingNew(
      false
    );

    setActiveSetId(
      Number(id)
    );

    setError(
      ""
    );

    setToast(
      ""
    );
  }


  function newSet() {
    setEditingNew(
      true
    );

    setActiveSetId(
      null
    );

    setSetForm({
      ...EMPTY_SET,
    });

    setSetItems(
      []
    );

    setNewItemType(
      "playlist"
    );

    setNewPlaylistId(
      ""
    );

    setNewTargetSetId(
      ""
    );

    setError(
      ""
    );

    setToast(
      ""
    );
  }


  function updateSet(
    field,
    value
  ) {
    setSetForm(
      (
        current
      ) => ({
        ...current,
        [field]:
          value,
      })
    );

    setToast(
      ""
    );
  }


  async function publishPlaylist(
    playlistId
  ) {
    const response =
      await fetch(
        PLAYLIST_SET_PUBLIC_URL,
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
              playlist_id:
                playlistId,

              is_public:
                true,
            }),
        }
      );

    const data =
      await response.json();

    if (
      !response.ok
      || !data?.ok
    ) {
      throw new Error(
        data?.error
        || "Failed to make playlist public."
      );
    }

    setPlaylists(
      (
        current
      ) =>
        current.map(
          (
            playlist
          ) =>
            Number(
              playlist.playlist_id
            ) ===
            Number(
              playlistId
            )
              ? {
                  ...playlist,
                  is_public:
                    1,
                }
              : playlist
        )
    );
  }


  async function ensurePlaylistPublic(
    playlist
  ) {
    if (
      !playlist
      || Number(
        playlist.is_public
      ) === 1
    ) {
      return true;
    }

    const confirmed =
      await dialog.confirm({
        title:
          playlist.title
          || "Private Playlist",

        message:
          "This playlist is marked private. Change it to public before adding it to the set?",

        confirmLabel:
          "Make Public",

        cancelLabel:
          "Cancel",
      });

    if (!confirmed) {
      return false;
    }

    await publishPlaylist(
      Number(
        playlist.playlist_id
      )
    );

    return true;
  }


  async function loadPlaylistRepresentation(
    playlistId
  ) {
    const response =
      await fetch(
        `${PLAYLIST_GET_URL}?playlist_id=${playlistId}&_=${Date.now()}`,
        {
          credentials:
            "include",
        }
      );

    const data =
      await response.json();

    if (
      !response.ok
      || !data?.ok
    ) {
      throw new Error(
        data?.error
        || "Failed to load playlist cover."
      );
    }

    const playlist =
      data.playlist
      || {};

    const items =
      Array.isArray(
        data.items
      )
        ? data.items
        : Array.isArray(
            playlist.items
          )
          ? playlist.items
          : [];

    const cover =
      items.find(
        (
          item
        ) =>
          String(
            item?.item_type
            || ""
          )
            .trim()
            .toLowerCase()
          === "cover-image"
          &&
          Number(
            item?.is_active
            ?? 1
          ) !== 0
      )
      || null;

    const rawImage =
      String(
        cover?.image_url
        || ""
      ).trim();

    const photoUrl =
      rawImage.startsWith(
        "photo:"
      )
        ? (
            rawImage.split(
              "|",
              2
            )[1]
            || ""
          ).trim()
        : rawImage;

    return {
      title:
        String(
          cover?.title
          || playlist?.title
          || ""
        ).trim(),

      photo_library_id:
        Number(
          cover?.photo_library_id
          || 0
        )
        || null,

      photo_url:
        photoUrl,
    };
  }


  async function addItem() {
    setError(
      ""
    );

    if (
      newItemType ===
      "set"
    ) {
      const targetSetId =
        Number(
          newTargetSetId
          || 0
        );

      if (!targetSetId) {
        setError(
          "Choose a playlist set."
        );

        return;
      }

      const target =
        sets.find(
          (
            set
          ) =>
            Number(
              set.id
            ) ===
            targetSetId
        );

      setSetItems(
        (
          current
        ) =>
          normalizeItems([
            ...current,

            {
              id:
                null,

              item_type:
                "set",

              playlist_id:
                null,

              target_set_id:
                targetSetId,

              title:
                target?.title
                || target?.handle
                || `Set #${targetSetId}`,

              subtitle:
                target?.subtitle
                || "",

              photo_library_id:
                target?.cover_photo_library_id
                || null,

              photo_url:
                target?.cover_photo_url
                || "",

              sort_order:
                current.length
                + 1,
            },
          ])
      );

      setNewTargetSetId(
        ""
      );

      return;
    }


    const playlistId =
      Number(
        newPlaylistId
        || 0
      );

    if (!playlistId) {
      setError(
        "Choose a playlist."
      );

      return;
    }

    const playlist =
      playlists.find(
        (
          item
        ) =>
          Number(
            item.playlist_id
          ) ===
          playlistId
      );

    try {
      const allowed =
        await ensurePlaylistPublic(
          playlist
        );

      if (!allowed) {
        return;
      }

      const representation =
        await loadPlaylistRepresentation(
          playlistId
        );

      setSetItems(
        (
          current
        ) =>
          normalizeItems([
            ...current,

            {
              id:
                null,

              item_type:
                "playlist",

              playlist_id:
                playlistId,

              target_set_id:
                null,

              title:
                representation.title
                || playlist?.title
                || `Playlist #${playlistId}`,

              subtitle:
                "",

              photo_library_id:
                representation.photo_library_id,

              photo_url:
                representation.photo_url,

              sort_order:
                current.length
                + 1,
            },
          ])
      );

      setNewPlaylistId(
        ""
      );

    } catch (err) {
      setError(
        err?.message
        || "Could not add playlist."
      );
    }
  }


  function moveItem(
    index,
    delta
  ) {
    setSetItems(
      (
        current
      ) => {
        const target =
          index + delta;

        if (
          target < 0
          || target >= current.length
        ) {
          return current;
        }

        const next =
          [
            ...current,
          ];

        const [
          moved,
        ] =
          next.splice(
            index,
            1
          );

        next.splice(
          target,
          0,
          moved
        );

        return normalizeItems(
          next
        );
      }
    );
  }


  function removeItem(
    index
  ) {
    setSetItems(
      (
        current
      ) =>
        normalizeItems(
          current.filter(
            (
              _item,
              itemIndex
            ) =>
              itemIndex !==
              index
          )
        )
    );
  }


  async function saveAll() {
    const payload = {
      id:
        setForm.id
        ?? null,

      handle:
        String(
          setForm.handle
          || ""
        ).trim(),

      title:
        String(
          setForm.title
          || ""
        ).trim(),

      subtitle:
        String(
          setForm.subtitle
          || ""
        ).trim(),

      context:
        String(
          setForm.context
          || ""
        ).trim(),

      cover_photo_library_id:
        Number(
          setForm.cover_photo_library_id
          || 0
        )
        || null,

      end_cta_label:
        String(
          setForm.end_cta_label
          || "Explore ColorFix"
        ).trim(),

      end_cta_url:
        String(
          setForm.end_cta_url
          || "/"
        ).trim(),

      end_cta_enabled:
        setForm.end_cta_enabled
        !== false,

      is_retired:
        Boolean(
          setForm.is_retired
        ),
    };


    if (!payload.handle) {
      setError(
        "Handle required."
      );

      return;
    }

    if (!payload.title) {
      setError(
        "Title required."
      );

      return;
    }


    setLoading(
      true
    );

    setError(
      ""
    );

    setToast(
      ""
    );


    try {
      const setResponse =
        await fetch(
          SETS_SAVE_URL,
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
              JSON.stringify(
                payload
              ),
          }
        );

      const setData =
        await setResponse.json();

      if (
        !setResponse.ok
        || !setData?.ok
      ) {
        throw new Error(
          setData?.error
          || "Failed to save playlist set."
        );
      }

      const savedSetId =
        Number(
          setData?.set?.id
          || payload.id
          || 0
        );

      if (!savedSetId) {
        throw new Error(
          "Playlist set saved without an id."
        );
      }


      const itemsPayload =
        setItems.map(
          (
            item,
            index
          ) => ({
            item_type:
              item.item_type ===
              "set"
                ? "set"
                : "playlist",

            playlist_id:
              item.item_type ===
              "set"
                ? null
                : Number(
                    item.playlist_id
                    || 0
                  )
                  || null,

            target_set_id:
              item.item_type ===
              "set"
                ? Number(
                    item.target_set_id
                    || 0
                  )
                  || null
                : null,

            sort_order:
              index + 1,
          })
        );


      const itemsResponse =
        await fetch(
          SET_ITEMS_SAVE_URL,
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
                set_id:
                  savedSetId,

                items:
                  itemsPayload,
              }),
          }
        );

      const itemsData =
        await itemsResponse.json();

      if (
        !itemsResponse.ok
        || !itemsData?.ok
      ) {
        throw new Error(
          itemsData?.error
          || "Failed to save set items."
        );
      }


      setEditingNew(
        false
      );

      setActiveSetId(
        savedSetId
      );

      await fetchSets();

      await fetchSet(
        savedSetId
      );

      setToast(
        "Playlist set saved."
      );

    } catch (err) {
      setError(
        err?.message
        || "Save failed."
      );

    } finally {
      setLoading(
        false
      );
    }
  }


  const sortedSets =
    useMemo(
      () =>
        [
          ...sets,
        ].sort(
          (
            a,
            b
          ) =>
            String(
              a.title
              || a.handle
              || ""
            ).localeCompare(
              String(
                b.title
                || b.handle
                || ""
              ),
              undefined,
              {
                numeric:
                  true,

                sensitivity:
                  "base",
              }
            )
        ),
      [
        sets,
      ]
    );


  const filteredSets =
    useMemo(
      () => {
        const value =
          query
            .trim()
            .toLowerCase();

        if (!value) {
          return sortedSets;
        }

        return sortedSets.filter(
          (
            set
          ) =>
            [
              set.id,
              set.handle,
              set.title,
              set.subtitle,
              set.context,
            ]
              .join(" ")
              .toLowerCase()
              .includes(
                value
              )
        );
      },
      [
        query,
        sortedSets,
      ]
    );


  const playlistOptions =
    useMemo(
      () =>
        [
          ...playlists,
        ]
          .filter(
            (
              playlist
            ) =>
              Number(
                playlist.is_active
                ?? 1
              ) !== 0
          )
          .sort(
            (
              a,
              b
            ) =>
              String(
                a.title
                || ""
              ).localeCompare(
                String(
                  b.title
                  || ""
                ),
                undefined,
                {
                  numeric:
                    true,

                  sensitivity:
                    "base",
                }
              )
          ),
      [
        playlists,
      ]
    );


  const targetSetOptions =
    useMemo(
      () =>
        sortedSets.filter(
          (
            set
          ) =>
            Number(
              set.id
            ) !==
            Number(
              setForm.id
              || 0
            )
        ),
      [
        sortedSets,
        setForm.id,
      ]
    );


  const playUrl =
    buildSetPlayUrl(
      setForm.id
    );


  const columns = [
    {
      key:
        "reorder",

      label:
        "",

      sortable:
        false,

      render: (
        item
      ) => {
        const index =
          setItems.findIndex(
            (
              candidate
            ) =>
              candidate._key ===
              item._key
          );

        return (
          <AdminToolbar
            compact
          >
            <AdminButton
              type="button"
              variant="secondary"
              size="sm"
              disabled={
                index <= 0
              }
              onClick={(event) => {
                event.stopPropagation();

                moveItem(
                  index,
                  -1
                );
              }}
            >
              ↑
            </AdminButton>

            <AdminButton
              type="button"
              variant="secondary"
              size="sm"
              disabled={
                index < 0
                || index >=
                  setItems.length - 1
              }
              onClick={(event) => {
                event.stopPropagation();

                moveItem(
                  index,
                  1
                );
              }}
            >
              ↓
            </AdminButton>
          </AdminToolbar>
        );
      },
    },

    {
      key:
        "order",

      label:
        "#",

      sortable:
        false,

      render: (
        item
      ) =>
        setItems.findIndex(
          (
            candidate
          ) =>
            candidate._key ===
            item._key
        ) + 1,
    },

    {
      key:
        "preview",

      label:
        "Cover",

      sortable:
        false,

      render: (
        item
      ) => (
        <AdminMediaPreview
          imageUrl={
            item.photo_url
            || ""
          }
          placeholder="No Image"
        />
      ),
    },

    {
      key:
        "title",

      label:
        "Title",

      sortable:
        false,

      render: (
        item
      ) =>
        item.title
        || (
          item.item_type ===
          "set"
            ? `Set #${item.target_set_id}`
            : `Playlist #${item.playlist_id}`
        ),
    },

    {
      key:
        "type",

      label:
        "Type",

      sortable:
        false,

      render: (
        item
      ) =>
        item.item_type ===
        "set"
          ? "Set"
          : "Playlist",
    },

    {
      key:
        "source_id",

      label:
        "Source",

      sortable:
        false,

      render: (
        item
      ) =>
        item.item_type ===
        "set"
          ? `#${item.target_set_id}`
          : `#${item.playlist_id}`,
    },

    {
      key:
        "actions",

      label:
        "",

      sortable:
        false,

      render: (
        item
      ) => {
        const index =
          setItems.findIndex(
            (
              candidate
            ) =>
              candidate._key ===
              item._key
          );

        return (
          <AdminToolbar
            compact
          >
            {
              item.item_type === "playlist"
                && item.playlist_id
                  ? (
                      <AdminButton
                        type="button"
                        variant="secondary"
                        size="sm"
                        onClick={(event) => {
                          event.stopPropagation();

                          window.open(
                            `/admin/playlists/${item.playlist_id}`,
                            "_blank",
                            "noopener"
                          );
                        }}
                      >
                        Edit Playlist
                      </AdminButton>
                    )
                  : null
            }

            <AdminButton
              type="button"
              variant="danger"
              size="sm"
              onClick={(event) => {
                event.stopPropagation();

                removeItem(
                  index
                );
              }}
            >
              Remove
            </AdminButton>
          </AdminToolbar>
        );
      },
    }
  ];


  const showEditor =
    editingNew
    || Boolean(
      activeSetId
    );


  return (
    <>
      <AdminMasterDetail
        storageKey="playlist-sets-admin-list-width"
        list={
          <AdminListPane
            title="Playlist Sets"
            actions={
              <AdminButton
                type="button"
                onClick={
                  newSet
                }
              >
                New Set
              </AdminButton>
            }
            searchValue={
              query
            }
            onSearchChange={
              setQuery
            }
            searchPlaceholder="Search sets..."
          >
            <AdminObjectList
              ariaLabel="Playlist sets"
            >
              {
                filteredSets.map(
                  (
                    set
                  ) => (
                    <AdminObjectListItem
                      key={
                        set.id
                      }
                      id={
                        set.id
                      }
                      title={
                        set.title
                        || set.handle
                        || `Set #${set.id}`
                      }
                      meta={[
                        set.handle,
                        set.subtitle,
                        Number(
                          set.is_retired
                        ) === 1
                          ? "Retired"
                          : null,
                      ]}
                      selected={
                        !editingNew
                        && Number(
                          activeSetId
                        ) ===
                        Number(
                          set.id
                        )
                      }
                      onSelect={() =>
                        selectSet(
                          set.id
                        )
                      }
                    />
                  )
                )
              }
            </AdminObjectList>
          </AdminListPane>
        }
        detail={
          <AdminDetailPane
            ariaLabel="Playlist set detail"
          >
            {
              !showEditor
                ? (
                    <AdminEmptyState
                      title="Select a playlist set"
                      message="Choose a set from the list or create a new one."
                    />
                  )
                : (
                    <AdminStack
                      gap="lg"
                      fill
                    >
                      <AdminSectionHeader
                        title={
                          setForm.title
                          || (
                            editingNew
                              ? "New Playlist Set"
                              : "Playlist Set"
                          )
                        }
                        meta={
                          setForm.id
                            ? `#${setForm.id} · ${setForm.handle || "No handle"}`
                            : "New set"
                        }
                        actions={
                          <AdminToolbar
                            compact
                          >
                            <AdminButton
                              type="button"
                              variant="secondary"
                              disabled={
                                !playUrl
                              }
                              onClick={() => {
                                if (
                                  playUrl
                                ) {
                                  window.open(
                                    playUrl,
                                    "_blank",
                                    "noopener"
                                  );
                                }
                              }}
                            >
                              Play
                            </AdminButton>

                            <AdminButton
                              type="button"
                              disabled={
                                loading
                              }
                              onClick={
                                saveAll
                              }
                            >
                              {
                                loading
                                  ? "Saving..."
                                  : "Save All"
                              }
                            </AdminButton>
                          </AdminToolbar>
                        }
                      />

                      {
                        error
                          ? (
                              <AdminNotice
                                variant="danger"
                              >
                                {error}
                              </AdminNotice>
                            )
                          : null
                      }

                      <AdminPanel
                        title="Set Details"
                      >
                        <AdminStack
                          gap="md"
                        >
                          <AdminFieldRow>
                            <AdminField
                              label="Handle"
                              grow
                            >
                              <input
                                className="admin-field__control"
                                type="text"
                                value={
                                  setForm.handle
                                }
                                onChange={(event) =>
                                  updateSet(
                                    "handle",
                                    event.target.value
                                  )
                                }
                              />
                            </AdminField>

                            <AdminField
                              label="Context"
                              grow
                            >
                              <input
                                className="admin-field__control"
                                type="text"
                                value={
                                  setForm.context
                                  || ""
                                }
                                onChange={(event) =>
                                  updateSet(
                                    "context",
                                    event.target.value
                                  )
                                }
                              />
                            </AdminField>
                          </AdminFieldRow>

                          <AdminField
                            label="Title"
                          >
                            <input
                              className="admin-field__control admin-field__control--full"
                              type="text"
                              value={
                                setForm.title
                              }
                              onChange={(event) =>
                                updateSet(
                                  "title",
                                  event.target.value
                                )
                              }
                            />
                          </AdminField>

                          <AdminField
                            label="Subtitle"
                          >
                            <input
                              className="admin-field__control admin-field__control--full"
                              type="text"
                              value={
                                setForm.subtitle
                                || ""
                              }
                              onChange={(event) =>
                                updateSet(
                                  "subtitle",
                                  event.target.value
                                )
                              }
                            />
                          </AdminField>
                        </AdminStack>
                      </AdminPanel>

                      <AdminPhotoPickerField
                        title="Set Cover"
                        meta="This image represents the collection itself. Playlist cards use each playlist's own cover-image."
                        imageUrl={
                          setForm.cover_photo_url
                          || ""
                        }
                        photoLibraryId={
                          setForm.cover_photo_library_id
                          || null
                        }
                        placeholder="No set cover selected"
                        pickerTitle="Pick Set Cover"
                        pickLabel="Pick Set Cover"
                        onClear={() =>
                          setSetForm(
                            (
                              current
                            ) => ({
                              ...current,

                              cover_photo_library_id:
                                null,

                              cover_photo_url:
                                "",
                            })
                          )
                        }
                        onPick={(photo) =>
                          setSetForm(
                            (
                              current
                            ) => ({
                              ...current,

                              cover_photo_library_id:
                                Number(
                                  photo.photo_library_id
                                  || 0
                                )
                                || null,

                              cover_photo_url:
                                photo.image_url
                                || photo.rel_path
                                || "",
                            })
                          )
                        }
                      />

                      <AdminPanel
                        title="Playlists in this Set"
                        meta="Playlist titles and images are derived from the playlist itself. Membership stores only playlist/set identity and order."
                      >
                        <AdminStack
                          gap="md"
                        >
                          {
                            setItems.length
                              ? (
                                  <AdminDataGrid
                                    ariaLabel="Playlist set items"
                                    items={
                                      setItems
                                    }
                                    columns={
                                      columns
                                    }
                                    getRowKey={(
                                      item
                                    ) =>
                                      item._key
                                    }
                                    bordered
                                    verticalAlign="middle"
                                  />
                                )
                              : (
                                  <AdminEmptyState
                                    title="No items yet"
                                    message="Add a playlist or nested set below."
                                  />
                                )
                          }

                          <AdminPanel
                            title="Add Item"
                            compact
                          >
                            <AdminStack
                              gap="sm"
                            >
                              <AdminFieldRow>
                                <AdminField
                                  label="Type"
                                  compact
                                >
                                  <select
                                    className="admin-field__control"
                                    value={
                                      newItemType
                                    }
                                    onChange={(event) => {
                                      setNewItemType(
                                        event.target.value
                                      );

                                      setNewPlaylistId(
                                        ""
                                      );

                                      setNewTargetSetId(
                                        ""
                                      );
                                    }}
                                  >
                                    <option value="playlist">
                                      Playlist
                                    </option>

                                    <option value="set">
                                      Nested Set
                                    </option>
                                  </select>
                                </AdminField>

                                {
                                  newItemType ===
                                  "set"
                                    ? (
                                        <AdminField
                                          label="Playlist Set"
                                          grow
                                        >
                                          <select
                                            className="admin-field__control"
                                            value={
                                              newTargetSetId
                                            }
                                            onChange={(event) =>
                                              setNewTargetSetId(
                                                event.target.value
                                              )
                                            }
                                          >
                                            <option value="">
                                              Choose a set...
                                            </option>

                                            {
                                              targetSetOptions.map(
                                                (
                                                  set
                                                ) => (
                                                  <option
                                                    key={
                                                      set.id
                                                    }
                                                    value={
                                                      set.id
                                                    }
                                                  >
                                                    {
                                                      set.title
                                                      || set.handle
                                                      || `Set #${set.id}`
                                                    }
                                                  </option>
                                                )
                                              )
                                            }
                                          </select>
                                        </AdminField>
                                      )
                                    : (
                                        <AdminField
                                          label="Playlist"
                                          grow
                                        >
                                          <select
                                            className="admin-field__control"
                                            value={
                                              newPlaylistId
                                            }
                                            onChange={(event) =>
                                              setNewPlaylistId(
                                                event.target.value
                                              )
                                            }
                                          >
                                            <option value="">
                                              Choose a playlist...
                                            </option>

                                            {
                                              playlistOptions.map(
                                                (
                                                  playlist
                                                ) => (
                                                  <option
                                                    key={
                                                      playlist.playlist_id
                                                    }
                                                    value={
                                                      playlist.playlist_id
                                                    }
                                                  >
                                                    {
                                                      playlist.title
                                                      || `Playlist #${playlist.playlist_id}`
                                                    }
                                                    {
                                                      Number(
                                                        playlist.is_public
                                                      ) === 1
                                                        ? " · Public"
                                                        : " · Private"
                                                    }
                                                  </option>
                                                )
                                              )
                                            }
                                          </select>
                                        </AdminField>
                                      )
                                }

                                <AdminButton
                                  type="button"
                                  variant="secondary"
                                  onClick={
                                    addItem
                                  }
                                >
                                  Add
                                </AdminButton>
                              </AdminFieldRow>
                            </AdminStack>
                          </AdminPanel>
                        </AdminStack>
                      </AdminPanel>

                      <AdminPanel
                        title="End of Set"
                      >
                        <AdminStack
                          gap="md"
                        >
                          <AdminFieldRow>
                            <AdminField
                              label="CTA Label"
                              grow
                            >
                              <input
                                className="admin-field__control"
                                type="text"
                                value={
                                  setForm.end_cta_label
                                  || ""
                                }
                                onChange={(event) =>
                                  updateSet(
                                    "end_cta_label",
                                    event.target.value
                                  )
                                }
                              />
                            </AdminField>

                            <AdminField
                              label="CTA URL"
                              grow
                            >
                              <input
                                className="admin-field__control"
                                type="text"
                                value={
                                  setForm.end_cta_url
                                  || ""
                                }
                                onChange={(event) =>
                                  updateSet(
                                    "end_cta_url",
                                    event.target.value
                                  )
                                }
                              />
                            </AdminField>
                          </AdminFieldRow>

                          <AdminCheckboxRow
                            checked={
                              setForm.end_cta_enabled
                              !== false
                            }
                            onChange={(event) =>
                              updateSet(
                                "end_cta_enabled",
                                event.target.checked
                              )
                            }
                          >
                            Show end-set CTA
                          </AdminCheckboxRow>

                          <AdminCheckboxRow
                            checked={
                              Boolean(
                                setForm.is_retired
                              )
                            }
                            onChange={(event) =>
                              updateSet(
                                "is_retired",
                                event.target.checked
                              )
                            }
                          >
                            Retired
                          </AdminCheckboxRow>
                        </AdminStack>
                      </AdminPanel>

                      <AdminToolbar
                        spread
                      >
                        <span />

                        <AdminButton
                          type="button"
                          disabled={
                            loading
                          }
                          onClick={
                            saveAll
                          }
                        >
                          {
                            loading
                              ? "Saving..."
                              : "Save All"
                          }
                        </AdminButton>
                      </AdminToolbar>
                    </AdminStack>
                  )
            }
          </AdminDetailPane>
        }
      />

      <AdminToast
        open={
          Boolean(
            toast
          )
        }
        variant="success"
        message={
          toast
        }
        onClose={() =>
          setToast("")
        }
      />
    </>
  );
}
