import {
  forwardRef,
  useCallback,
  useEffect,
  useImperativeHandle,
  useMemo,
  useState,
} from "react";

import {
  useNavigate,
} from "react-router-dom";

import {
  AdminBadge,
  AdminButton,
  AdminDetailPane,
  AdminEditor,
  AdminEmptyState,
  AdminField,
  AdminMetaText,
  AdminNotice,
  AdminSmartGrid,
  AdminStack,
  AdminToolbar,
  AdminToolbarSpacer,
  useAdminDialog,
} from "@components/AdminLayout";

import PhotoPickerModal from "@components/PhotoPickerModal";
import ColorPlanPickerModal from "@components/ColorPlanPickerModal";

import {
  API_FOLDER,
} from "@helpers/config";

import {
  makePhotoRef,
  parsePhotoRef,
} from "@helpers/assetImage";

import PlaylistRecordEditor from "./PlaylistRecordEditor";
import SlideDefaultsDialog from "./SlideDefaultsDialog";
import PlaylistSlideEditor, {
  BRAND_BUMPER_BODY_TEMPLATE,
  HUE_WHEEL_BODY_TEMPLATE,
} from "./PlaylistSlideEditor";


const LIST_URL =
  `${API_FOLDER}/v2/admin/playlists/list.php`;

const GET_URL =
  `${API_FOLDER}/v2/admin/playlists/get.php`;

const SAVE_URL =
  `${API_FOLDER}/v2/admin/playlists/save.php`;

const SAVE_ITEMS_URL =
  `${API_FOLDER}/v2/admin/playlist-items/save.php`;

const DELETE_URL =
  `${API_FOLDER}/v2/admin/playlists/delete.php`;

const REX_PLAYLIST_URL =
  `${API_FOLDER}/v2/admin/rex/playlist-url.php`;

const SAVED_LIST_URL =
  `${API_FOLDER}/v2/admin/saved-palettes.php`;

const PHOTO_LIBRARY_LIST_URL =
  `${API_FOLDER}/v2/admin/photo-library/list.php`;

const SLIDE_PRESETS_LIST_URL =
  `${API_FOLDER}/v2/admin/playlist-slide-presets/list.php`;

const PERMISSION_STATUS_EVENT =
  "colorfix:photo-permission-status";

const SLIDE_CLIPBOARD_KEY =
  "colorfix:playlist-slide-clipboard";

const EMPTY_COPIED_SLIDES = [];


const DEFAULT_PLAYLIST_TYPES = [
  "teaching",
];

const ANALYZER_ROLES = [
  "ignore",
  "before",
  "after",
  "single",
  "teaser",
];

const FINDER_START_VALUES = [
  "auto",
  "this",
  "previous",
];


const emptyPlaylist = {
  playlist_id: null,
  title: "",
  type: "",
  is_active: true,
  is_public: false,
  slug: "",
  headline: "",
  page_title: "",
  meta_description: "",
  dek: "",
  intro_html: "",
  body_html: "",
  hero_image_id: "",
  hero_image_url: "",
  hero_alt: "",
  indexable: true,
  published_at: "",
};


const emptyItem = {
  _clientKey: "",
  playlist_item_id: null,
  ap_id: "",
  palette_hash: "",
  image_url: "",
  photo_library_id: "",
  saved_palette_set_id: "",
  title: "",
  subtitle: "",
  subtitle_2: "",
  body: "",
  item_type: "non-palette",
  layout: "default",
  title_mode: "",
  star: true,
  transition: "",
  duration_ms: "",
  exclude_from_thumbs: false,
  is_share_image: false,
  site: true,
  yt: true,
  concept: true,
  client: true,
  pin: true,
  color_plan_id: "",
  version_number: 1,
  is_final: false,
  analyzer_role: "ignore",
  finder_start: "auto",
  is_active: true,
};


const PlaylistEditor = forwardRef(function PlaylistEditor(
  {
    playlistId,
    initialCopiedSlides = EMPTY_COPIED_SLIDES,
    onSaved = null,
    onDeleted = null,
  },
  ref
) {
  const dialog =
    useAdminDialog();

  const navigate =
    useNavigate();

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

  const isNewRoute =
    routePlaylistId === null;


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


  const [
    playlist,
    setPlaylist,
  ] = useState(emptyPlaylist);

  const [
    items,
    setItems,
  ] = useState([]);

  const [
    detailLoading,
    setDetailLoading,
  ] = useState(false);

  const [
    detailError,
    setDetailError,
  ] = useState("");

  const [
    dirty,
    setDirty,
  ] = useState(false);

  const [
    saving,
    setSaving,
  ] = useState(false);

  const [
    deleting,
    setDeleting,
  ] = useState(false);

  const [
    saveMessage,
    setSaveMessage,
  ] = useState("");


  const [
    playlistTypes,
    setPlaylistTypes,
  ] = useState(
    DEFAULT_PLAYLIST_TYPES
  );

  const [
    savedOptions,
    setSavedOptions,
  ] = useState([]);

  const [
    selectedSlideKey,
    setSelectedSlideKey,
  ] = useState(null);

  const [
    batchSelectedKeys,
    setBatchSelectedKeys,
  ] = useState([]);

  const [
    slideClipboardCount,
    setSlideClipboardCount,
  ] = useState(
    () =>
      readSlideClipboard()
        .length
  );

  const [
    playlistEditorOpen,
    setPlaylistEditorOpen,
  ] = useState(false);

  const [
    playlistDraft,
    setPlaylistDraft,
  ] = useState(emptyPlaylist);


  const [
    slidePresets,
    setSlidePresets,
  ] = useState([]);

  const [
    slidePresetsLoading,
    setSlidePresetsLoading,
  ] = useState(false);

  const [
    slidePresetsError,
    setSlidePresetsError,
  ] = useState("");

  const [
    slideDefaultsOpen,
    setSlideDefaultsOpen,
  ] = useState(false);


  const [
    photoPickerKey,
    setPhotoPickerKey,
  ] = useState(null);

  const [
    heroPickerOpen,
    setHeroPickerOpen,
  ] = useState(false);

  const [
    colorPlanPickerKey,
    setColorPlanPickerKey,
  ] = useState(null);


  const [
    photoThumbs,
    setPhotoThumbs,
  ] = useState({});

  const [
    photoInfo,
    setPhotoInfo,
  ] = useState({});


  const makeClientItemKey =
    useCallback(
      () =>
        `pli-${Date.now()}-${Math.random()
          .toString(36)
          .slice(2, 10)}`,
      []
    );


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


  const fetchPlaylist =
    useCallback(
      async (
        id,
        preserveSelection = null
      ) => {
        if (!id) {
          return;
        }

        setDetailLoading(
          true
        );

        setDetailError(
          ""
        );

        try {
          const res =
            await fetch(
              `${GET_URL}?playlist_id=${encodeURIComponent(String(id))}&_=${Date.now()}`,
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
          ) {
            throw new Error(
              data?.error ||
              "Failed to load playlist."
            );
          }

          setPlaylist(
            normalizePlaylist(
              data.playlist
            )
          );

          const normalizedItems =
            (
              Array.isArray(
                data.items
              )
                ? data.items
                : []
            ).map(
              (item) =>
                normalizePlaylistItem(
                  item,
                  makeClientItemKey
                )
            );

          setItems(
            normalizedItems
          );

          let nextSelectedSlideKey =
            null;

          if (
            preserveSelection
          ) {
            const preservedId =
              Number(
                preserveSelection
                  ?.playlist_item_id ||
                0
              );

            const preservedIndex =
              Number(
                preserveSelection
                  ?.index
              );

            const preservedItem =
              (
                preservedId
                  ? normalizedItems.find(
                      (item) =>
                        Number(
                          item
                            ?.playlist_item_id ||
                          0
                        ) ===
                        preservedId
                    )
                  : null
              )
              ||
              (
                Number.isInteger(
                  preservedIndex
                )
                &&
                preservedIndex >=
                  0
                &&
                preservedIndex <
                  normalizedItems.length
                  ? normalizedItems[
                      preservedIndex
                    ]
                  : null
              );

            nextSelectedSlideKey =
              preservedItem
                ?._clientKey
              ||
              null;
          }

          setSelectedSlideKey(
            nextSelectedSlideKey
          );

          setBatchSelectedKeys([]);

          setDirty(
            false
          );

        } catch (err) {
          setDetailError(
            err?.message ||
            "Failed to load playlist."
          );

        } finally {
          setDetailLoading(
            false
          );
        }
      },
      [
        makeClientItemKey,
      ]
    );


  const fetchSlidePresets =
    useCallback(
      async () => {
        setSlidePresetsLoading(
          true
        );

        setSlidePresetsError(
          ""
        );

        try {
          const res =
            await fetch(
              `${SLIDE_PRESETS_LIST_URL}?_=${Date.now()}`,
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
          ) {
            throw new Error(
              data?.error ||
              "Failed to load slide defaults."
            );
          }

          setSlidePresets(
            Array.isArray(
              data.presets
            )
              ? data.presets
              : []
          );

        } catch (err) {
          setSlidePresetsError(
            err?.message ||
            "Failed to load slide defaults."
          );

        } finally {
          setSlidePresetsLoading(
            false
          );
        }
      },
      []
    );


  async function fetchPlaylistTypes() {
    try {
      const params =
        new URLSearchParams({
          limit:
            "500",
          _:
            String(
              Date.now()
            ),
        });

      const res =
        await fetch(
          `${LIST_URL}?${params.toString()}`,
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
      ) {
        return;
      }

      const types =
        Array.from(
          new Set(
            [
              ...DEFAULT_PLAYLIST_TYPES,

              ...(
                data.items ||
                []
              )
                .map(
                  (row) =>
                    String(
                      row
                        ?.type ||
                      ""
                    ).trim()
                )
                .filter(
                  Boolean
                ),
            ]
          )
        )
          .sort(
            (
              a,
              b
            ) =>
              a.localeCompare(
                b
              )
          );

      setPlaylistTypes(
        types
      );

    } catch {
      // Optional convenience list.
    }
  }


  async function fetchSavedPalettes() {
    try {
      const params =
        new URLSearchParams({
          limit:
            "500",
          with_photos:
            "1",
          _:
            String(
              Date.now()
            ),
        });

      const res =
        await fetch(
          `${SAVED_LIST_URL}?${params.toString()}`,
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
      ) {
        return;
      }

      const options =
        (
          data.items ||
          []
        )
          .filter(
            (row) =>
              row
                .palette_hash
          )
          .reduce(
            (
              result,
              row
            ) => {
              const hash =
                String(
                  row
                    .palette_hash ||
                  ""
                ).trim();

              if (
                !hash
                ||
                result.some(
                  (item) =>
                    item
                      .palette_hash ===
                    hash
                )
              ) {
                return result;
              }

              result.push({
                id:
                  row.id,

                palette_hash:
                  hash,

                label:
                  String(
                    row.nickname ||
                    ""
                  ).trim()
                  ||
                  `Saved #${row.id}`,
              });

              return result;
            },
            []
          );

      setSavedOptions(
        options
      );

    } catch {
      // Optional convenience list.
    }
  }


  useEffect(() => {
    fetchPlaylistTypes();
    fetchSavedPalettes();
    fetchSlidePresets();
  }, [
    fetchSlidePresets,
  ]);


  useEffect(() => {
    if (
      isNewRoute
    ) {
      setPlaylist({
        ...emptyPlaylist,
      });

      const copiedSlides =
        Array.isArray(
          initialCopiedSlides
        )
          ? initialCopiedSlides
          : [];

      setItems(
        copiedSlides.map(
          (item) =>
            clonePlaylistItemForNewPlaylist(
              item,
              makeClientItemKey()
            )
        )
      );

      setBatchSelectedKeys([]);

      setSelectedSlideKey(
        null
      );

      setDetailError(
        ""
      );

      setSaveMessage(
        ""
      );

      setDirty(
        false
      );

      setPlaylistDraft({
        ...emptyPlaylist,
      });

      setPlaylistEditorOpen(
        true
      );

      return;
    }

    if (
      routePlaylistId
    ) {
      setSaveMessage(
        ""
      );

      fetchPlaylist(
        routePlaylistId
      );

    }
  }, [
    isNewRoute,
    routePlaylistId,
    fetchPlaylist,
    initialCopiedSlides,
    makeClientItemKey,
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

            const byTitle =
              String(
                a?.title ||
                ""
              ).localeCompare(
                String(
                  b?.title ||
                  ""
                ),
                undefined,
                {
                  numeric: true,
                  sensitivity:
                    "base",
                }
              );

            if (
              byTitle !== 0
            ) {
              return byTitle;
            }

            return (
              Number(
                a?.playlist_id ||
                0
              )
              -
              Number(
                b?.playlist_id ||
                0
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
    const missingIds =
      Array.from(
        new Set(
          items
            .map(
              (item) => {
                const photoId =
                  String(
                    getPhotoLibraryId(
                      item
                    ) ||
                    ""
                  ).trim();

                if (
                  !photoId
                  ||
                  photoThumbs[
                    photoId
                  ]
                ) {
                  return "";
                }

                return photoId;
              }
            )
            .filter(
              Boolean
            )
        )
      );

    if (
      missingIds.length ===
      0
    ) {
      return;
    }

    let cancelled =
      false;

    (
      async () => {
        try {
          const params =
            new URLSearchParams();

          params.set(
            "photo_library_ids",
            missingIds.join(
              ","
            )
          );

          params.set(
            "limit",
            String(
              Math.max(
                missingIds.length,
                1
              )
            )
          );

          params.set(
            "_",
            Date.now().toString()
          );

          const res =
            await fetch(
              `${PHOTO_LIBRARY_LIST_URL}?${params.toString()}`,
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
            cancelled
          ) {
            return;
          }

          setPhotoThumbs(
            (current) => {
              const next = {
                ...current,
              };

              for (
                const row
                of data.items || []
              ) {
                const id =
                  String(
                    row
                      ?.photo_library_id ||
                    ""
                  ).trim();

                if (!id) {
                  continue;
                }

                const imageUrl =
                  String(
                    row
                      ?.image_url
                    ||
                    row
                      ?.rel_path
                    ||
                    ""
                  ).trim();

                if (
                  imageUrl
                ) {
                  next[id] =
                    imageUrl;
                }
              }

              return next;
            }
          );

          setPhotoInfo(
            (current) => {
              const next = {
                ...current,
              };

              for (
                const row
                of data.items || []
              ) {
                const id =
                  String(
                    row
                      ?.photo_library_id ||
                    ""
                  ).trim();

                if (!id) {
                  continue;
                }

                next[id] =
                  photoInfoFromRow(
                    row
                  );
              }

              return next;
            }
          );

        } catch {
          // Photo metadata is convenience data.
        }
      }
    )();

    return () => {
      cancelled =
        true;
    };
  }, [
    items,
    photoThumbs,
  ]);


  useEffect(() => {
    function onPermissionChange(
      event
    ) {
      const detail =
        event?.detail || {};

      const photoId =
        String(
          detail.photo_library_id ||
          ""
        ).trim();

      if (!photoId) {
        return;
      }

      const nextStatus =
        detail
          .permission
          ?.photo_permission_status
        ||
        detail
          .photo_permission_status
        ||
        "unknown";

      setPhotoInfo(
        (current) => {
          if (
            !current[
              photoId
            ]
          ) {
            return current;
          }

          return {
            ...current,

            [photoId]: {
              ...current[
                photoId
              ],

              photoPermissionStatus:
                nextStatus,
            },
          };
        }
      );
    }

    window.addEventListener(
      PERMISSION_STATUS_EVENT,
      onPermissionChange
    );

    return () =>
      window.removeEventListener(
        PERMISSION_STATUS_EVENT,
        onPermissionChange
      );
  }, []);


  const resolvedShareItemKey =
    useMemo(
      () => {
        const explicit =
          items.find(
            (item) =>
              Boolean(
                item
                  ?.is_share_image
              )
              &&
              hasItemPhoto(
                item
              )
          );

        if (
          explicit
        ) {
          return explicit
            ._clientKey;
        }

        const first =
          items.find(
            hasItemPhoto
          );

        return first
          ? first._clientKey
          : null;
      },
      [
        items,
      ]
    );


  function markDirty() {
    setDirty(
      true
    );

    setSaveMessage(
      ""
    );

    setDetailError(
      ""
    );
  }


  async function navigateToPlaylist(
    row
  ) {
    const id =
      Number(
        row
          ?.playlist_id ||
        0
      );

    if (!id) {
      return;
    }

    const currentId =
      Number(
        playlist
          ?.playlist_id ||
        0
      );

    if (
      id ===
      currentId
    ) {
      return;
    }

    /*
     * Normal playlist navigation commits pending work first.
     * There is no discard prompt in the ordinary workflow.
     */
    if (
      dirty
    ) {
      const savedId =
        await savePlaylist();

      if (
        !savedId
      ) {
        return;
      }
    }

    navigate(
      `/admin/playlists/${id}`
    );
  }


  async function beginNewPlaylist() {
    /*
     * Starting another playlist is also normal navigation:
     * save the current work first, then continue.
     */
    if (
      dirty
    ) {
      const savedId =
        await savePlaylist();

      if (
        !savedId
      ) {
        return;
      }
    }

    navigate(
      "/admin/playlists/new"
    );
  }


  function openPlaylistEditor() {
    setPlaylistDraft({
      ...playlist,
    });

    setPlaylistEditorOpen(
      true
    );
  }


  function updatePlaylistDraft(
    field,
    value
  ) {
    setPlaylistDraft(
      (current) => ({
        ...current,

        [field]:
          value,
      })
    );
  }


  async function savePlaylistDraftAndClose() {
    const savedId =
      await savePlaylist(
        playlistDraft
      );

    if (
      !savedId
    ) {
      return;
    }

    setPlaylist({
      ...playlistDraft,
      playlist_id:
        savedId,
    });

    setPlaylistEditorOpen(
      false
    );
  }


  function updateItem(
    clientKey,
    field,
    value
  ) {
    let nextValue =
      value;

    if (
      typeof nextValue ===
      "string"
    ) {
      nextValue =
        nextValue
          .replace(
            /&mdash;/gi,
            "—"
          )
          .replace(
            /--/g,
            "—"
          );
    }

    setItems(
      (current) =>
        current.map(
          (item) => {
            if (
              item
                ._clientKey !==
              clientKey
            ) {
              return item;
            }

            const nextItem = {
              ...item,

              [field]:
                nextValue,
            };

            if (
              field ===
                "item_type"
              &&
              [
                "hue-wheel",
                "brand-bumper",
              ].includes(
                nextValue
              )
            ) {
              if (
                !String(
                  item.body ||
                  ""
                ).trim()
              ) {
                nextItem.body =
                  nextValue ===
                    "brand-bumper"
                    ? BRAND_BUMPER_BODY_TEMPLATE
                    : HUE_WHEEL_BODY_TEMPLATE;
              }

              nextItem.ap_id =
                "";

              nextItem.palette_hash =
                "";

              nextItem.image_url =
                "";

              nextItem.photo_library_id =
                "";

              nextItem.saved_palette_set_id =
                "";

              nextItem.is_share_image =
                false;

              nextItem.star =
                false;

              if (
                nextValue ===
                "brand-bumper"
              ) {
                nextItem.title =
                  nextItem.title ||
                  "ColorFix";

                nextItem.subtitle =
                  nextItem.subtitle ||
                  "by Terry";

                nextItem.site =
                  true;

                nextItem.yt =
                  true;

                nextItem.concept =
                  true;

                nextItem.client =
                  true;

                nextItem.pin =
                  false;

                nextItem.analyzer_role =
                  "single";

                nextItem.duration_ms =
                  nextItem.duration_ms ||
                  "4200";
              }
            }

            return nextItem;
          }
        )
    );

    markDirty();
  }


  function addItem(
    presetKey
  ) {
    const preset =
      slidePresets.find(
        (row) =>
          row
            ?.preset_key ===
          presetKey
      );

    if (
      !preset
      ||
      preset.is_enabled ===
        false
    ) {
      setSlidePresetsError(
        "That slide default is not available."
      );

      return;
    }

    const item =
      buildPresetItem(
        preset,
        makeClientItemKey()
      );

    const insertPosition =
      String(
        preset
          .insert_position ||
        "after_selected"
      ).trim();

    setItems(
      (current) => {
        const activeIndex =
          current.findIndex(
            (row) =>
              row
                ._clientKey ===
              selectedSlideKey
          );

        let insertIndex =
          current.length;

        if (
          insertPosition ===
          "top"
        ) {
          insertIndex =
            0;

        } else if (
          insertPosition ===
          "bottom"
        ) {
          insertIndex =
            current.length;

        } else if (
          insertPosition ===
          "before_selected"
        ) {
          insertIndex =
            activeIndex >= 0
              ? activeIndex
              : 0;

        } else if (
          activeIndex >= 0
        ) {
          insertIndex =
            activeIndex + 1;
        }

        const next = [
          ...current,
        ];

        next.splice(
          insertIndex,
          0,
          item
        );

        return next;
      }
    );

    setSelectedSlideKey(
      item._clientKey
    );

    markDirty();
  }


  async function removeItem(
    clientKey
  ) {
    const item =
      items.find(
        (row) =>
          row
            ._clientKey ===
          clientKey
      );

    if (!item) {
      return false;
    }

    const confirmed =
      await dialog.confirm({
        title:
          "Remove slide?",

        message:
          `Remove ${
            item.title
              ? `"${item.title}"`
              : `slide #${itemIndex(items, item) + 1}`
          } from this playlist?`,

        confirmLabel:
          "Remove",

        cancelLabel:
          "Cancel",
      });

    if (
      !confirmed
    ) {
      return false;
    }

    setItems(
      (current) =>
        current.filter(
          (row) =>
            row
              ._clientKey !==
            clientKey
        )
    );

    setSelectedSlideKey(
      (current) =>
        current ===
          clientKey
          ? null
          : current
    );

    markDirty();

    return true;
  }


  function moveItem(
    clientKey,
    direction
  ) {
    setItems(
      (current) => {
        const index =
          current.findIndex(
            (row) =>
              row
                ._clientKey ===
              clientKey
          );

        const target =
          index +
          direction;

        if (
          index <
            0
          ||
          target <
            0
          ||
          target >=
            current.length
        ) {
          return current;
        }

        const next = [
          ...current,
        ];

        [
          next[index],
          next[target],
        ] = [
          next[target],
          next[index],
        ];

        return next;
      }
    );

    markDirty();
  }


  function clearItemPhoto(
    clientKey
  ) {
    setItems(
      (current) =>
        current.map(
          (item) =>
            item
              ._clientKey ===
            clientKey
              ? {
                  ...item,

                  photo_library_id:
                    "",

                  image_url:
                    "",

                  is_share_image:
                    false,
                }
              : item
        )
    );

    markDirty();
  }


  function setShareImage(
    clientKey
  ) {
    setItems(
      (current) =>
        current.map(
          (item) => ({
            ...item,

            is_share_image:
              item
                ._clientKey ===
                clientKey
              &&
              hasItemPhoto(
                item
              ),
          })
        )
    );

    markDirty();
  }


  function applyAttachedPaletteFromPhoto(
    clientKey,
    photoLibraryId,
    attachedInfo = null
  ) {
    const info =
      attachedInfo
      ||
      photoInfo[
        String(
          photoLibraryId ||
          ""
        ).trim()
      ];

    const attachedPaletteId =
      Number(
        info
          ?.attachedSavedPaletteId ||
        0
      );

    const match =
      attachedPaletteId
        ? savedOptions.find(
            (option) =>
              Number(
                option.id ||
                0
              ) ===
              attachedPaletteId
          )
        : null;

    setItems(
      (current) =>
        current.map(
          (item) =>
            item
              ._clientKey ===
            clientKey
              ? {
                  ...item,

                  ap_id:
                    "",

                  palette_hash:
                    match
                      ?.palette_hash ||
                    "",

                  saved_palette_set_id:
                    match
                    &&
                    info
                      ?.attachedSavedPaletteSetId
                      ? String(
                          info
                            .attachedSavedPaletteSetId
                        )
                      : "",
                }
              : item
        )
    );

    markDirty();
  }


  function copySelectedSlides() {
    if (
      batchSelectedKeys.length ===
      0
    ) {
      return;
    }

    const selected =
      new Set(
        batchSelectedKeys
      );

    const copiedSlides =
      items.filter(
        (item) =>
          selected.has(
            item._clientKey
          )
      );

    if (
      copiedSlides.length ===
      0
    ) {
      return;
    }

    const stored =
      writeSlideClipboard(
        copiedSlides,
        playlist
          ?.playlist_id
        ||
        null
      );

    setSlideClipboardCount(
      stored.length
    );

    setSaveMessage(
      `${stored.length} slide${stored.length === 1 ? "" : "s"} copied.`
    );
  }


  function pasteCopiedSlides() {
    const copiedSlides =
      readSlideClipboard();

    if (
      copiedSlides.length ===
      0
    ) {
      setSlideClipboardCount(
        0
      );

      return;
    }

    const pastedSlides =
      copiedSlides.map(
        (item) =>
          clonePlaylistItemForNewPlaylist(
            item,
            makeClientItemKey()
          )
      );

    const pastedKeys =
      pastedSlides.map(
        (item) =>
          item._clientKey
      );

    const anchorKey =
      selectedSlideKey
      ||
      (
        batchSelectedKeys.length ===
        1
          ? batchSelectedKeys[0]
          : null
      );

    setItems(
      (current) => {
        const anchorIndex =
          anchorKey
            ? current.findIndex(
                (item) =>
                  item
                    ._clientKey ===
                  anchorKey
              )
            : -1;

        const insertIndex =
          anchorIndex >= 0
            ? anchorIndex + 1
            : current.length;

        const next = [
          ...current,
        ];

        next.splice(
          insertIndex,
          0,
          ...pastedSlides
        );

        return next;
      }
    );

    setBatchSelectedKeys(
      pastedKeys
    );

    setSelectedSlideKey(
      null
    );

    markDirty();

    setSaveMessage(
      `${pastedSlides.length} slide${pastedSlides.length === 1 ? "" : "s"} pasted. Save to keep ${pastedSlides.length === 1 ? "it" : "them"}.`
    );
  }


  async function createPlaylistFromSelected() {
    if (
      batchSelectedKeys.length ===
      0
    ) {
      return;
    }

    const selected =
      new Set(
        batchSelectedKeys
      );

    const copiedSlides =
      items.filter(
        (item) =>
          selected.has(
            item._clientKey
          )
      );

    if (
      copiedSlides.length ===
      0
    ) {
      return;
    }

    if (
      dirty
    ) {
      const continueCreate =
        await dialog.confirm({
          title:
            "Create playlist from selected slides?",

          message:
            "The selected slides will be copied exactly as they are now. Other unsaved changes in this playlist will be discarded when you leave it.",

          confirmLabel:
            "Continue",

          cancelLabel:
            "Stay",
        });

      if (
        !continueCreate
      ) {
        return;
      }
    }

    navigate(
      "/admin/playlists/new",
      {
        state: {
          copiedSlides,
          sourcePlaylistId:
            playlist
              .playlist_id
            ||
            null,
        },
      }
    );
  }


  async function deleteSelectedSlides() {
    if (
      batchSelectedKeys.length ===
      0
    ) {
      return;
    }

    const selected =
      new Set(
        batchSelectedKeys
      );

    const nextItems =
      items.filter(
        (item) =>
          !selected.has(
            item._clientKey
          )
      );

    const deleteCount =
      items.length -
      nextItems.length;

    if (
      deleteCount ===
      0
    ) {
      return;
    }

    const confirmed =
      await dialog.confirm({
        title:
          `Delete ${deleteCount} selected slide${deleteCount === 1 ? "" : "s"}?`,

        message:
          "The selected slides will be removed from this playlist. Their photos and other source records are not deleted.",

        confirmLabel:
          "Delete",

        cancelLabel:
          "Cancel",
      });

    if (
      !confirmed
    ) {
      return;
    }

    const savedId =
      await savePlaylist(
        null,
        nextItems
      );

    if (
      !savedId
    ) {
      return;
    }

    setBatchSelectedKeys([]);
    setSelectedSlideKey(null);
  }


  async function savePlaylist(
    playlistOverride = null,
    itemsOverride = null
  ) {
    const hasPlaylistOverride =
      playlistOverride
      &&
      typeof playlistOverride ===
        "object"
      &&
      Object.prototype.hasOwnProperty.call(
        playlistOverride,
        "title"
      );

    const playlistToSave =
      hasPlaylistOverride
        ? playlistOverride
        : playlist;

    const itemsToSave =
      Array.isArray(
        itemsOverride
      )
        ? itemsOverride
        : items;

    const selectedItemIndex =
      itemsToSave.findIndex(
        (item) =>
          item
            ._clientKey ===
          selectedSlideKey
      );

    const selectedItem =
      selectedItemIndex >=
        0
        ? itemsToSave[
            selectedItemIndex
          ]
        : null;

    const preserveSelection =
      selectedItem
        ? {
            playlist_item_id:
              selectedItem
                .playlist_item_id
              ??
              null,

            index:
              selectedItemIndex,
          }
        : null;

    setSaving(
      true
    );

    setDetailError(
      ""
    );

    setSaveMessage(
      ""
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
                playlist_id:
                  playlistToSave.playlist_id,

                title:
                  playlistToSave.title,

                type:
                  playlistToSave.type,

                is_active:
                  Boolean(
                    playlistToSave.is_active
                  ),

                is_public:
                  Boolean(
                    playlistToSave.is_public
                  ),

                slug:
                  playlistToSave.slug,

                headline:
                  playlistToSave.headline,

                page_title:
                  playlistToSave.page_title,

                meta_description:
                  playlistToSave.meta_description,

                dek:
                  playlistToSave.dek,

                intro_html:
                  playlistToSave.intro_html,

                body_html:
                  playlistToSave.body_html,

                hero_image_id:
                  playlistToSave.hero_image_id
                    ? playlistToSave.hero_image_id
                    : null,

                hero_image_url:
                  playlistToSave.hero_image_url,

                hero_alt:
                  playlistToSave.hero_alt,

                indexable:
                  Boolean(
                    playlistToSave.indexable
                  ),

                published_at:
                  playlistToSave.published_at
                    ? playlistToSave.published_at
                    : null,
              }),
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
          "Failed to save playlist."
        );
      }

      const id =
        Number(
          data
            .playlist_id ||
          playlist
            .playlist_id ||
          0
        );

      if (!id) {
        throw new Error(
          "Playlist save returned no playlist ID."
        );
      }

      const itemsRes =
        await fetch(
          SAVE_ITEMS_URL,
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
                  id,

                items:
                  itemsToSave.map(
                    serializePlaylistItem
                  ),
              }),
          }
        );

      const itemsData =
        await itemsRes.json();

      if (
        !itemsRes.ok
        ||
        !itemsData?.ok
      ) {
        throw new Error(
          itemsData?.error ||
          "Failed to save playlist items."
        );
      }

      setDirty(
        false
      );

      setPlaylists(
        (current) =>
          upsertPlaylistSummary(
            current,
            {
              playlist_id:
                id,

              title:
                playlistToSave.title,

              type:
                playlistToSave.type,

              is_active:
                playlistToSave.is_active
                  ? 1
                  : 0,

              is_public:
                playlistToSave.is_public
                  ? 1
                  : 0,
            }
          )
      );

      await fetchPlaylist(
        id,
        preserveSelection
      );

      setSaveMessage(
        "Saved"
      );

      if (
        typeof onSaved ===
        "function"
      ) {
        onSaved(
          id
        );
      }

      return id;

    } catch (err) {
      setDetailError(
        err?.message ||
        "Save failed."
      );

      return null;

    } finally {
      setSaving(
        false
      );
    }
  }


  async function analyzePlaylist() {
    const id =
      await savePlaylist();

    if (!id) {
      return;
    }

    navigate(
      `/admin/pub?stage=analyze&playlist_id=${encodeURIComponent(String(id))}`
    );
  }


  async function saveAndPlay() {
    const id =
      await savePlaylist();

    if (!id) {
      return;
    }

    try {
      const res =
        await fetch(
          `${REX_PLAYLIST_URL}?playlist_id=${encodeURIComponent(String(id))}&_=${Date.now()}`,
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
      ) {
        throw new Error(
          data?.error
          ||
          "Could not load the playlist REX."
        );
      }

      const publicUrl =
        String(
          data
            ?.item
            ?.public_url
          ||
          ""
        ).trim();

      if (!publicUrl) {
        throw new Error(
          "Saved, but this playlist has no Public REX URL."
        );
      }

      const playerUrl =
        new URL(
          publicUrl,
          window.location.origin
        );

      playerUrl.searchParams.set(
        "fresh",
        "1"
      );

      playerUrl.searchParams.set(
        "src",
        "admin"
      );

      playerUrl.searchParams.set(
        "close",
        "1"
      );

      playerUrl.searchParams.set(
        "_",
        String(
          Date.now()
        )
      );

      playerUrl.searchParams.set(
        "return_to",
        `${window.location.pathname}${window.location.search}`
      );

      window.open(
        playerUrl.toString(),
        "_blank",
        "noopener"
      );

    } catch (err) {
      setDetailError(
        err?.message
        ||
        "Saved, but the playlist could not be opened."
      );
    }
  }


  async function deletePlaylist() {
    const id =
      Number(
        playlist
          ?.playlist_id ||
        0
      );

    if (!id) {
      return;
    }

    const confirmed =
      await dialog.confirm({
        title:
          "Delete playlist?",

        message:
          `Delete playlist #${id} and its playlist items?`,

        confirmLabel:
          "Delete",

        cancelLabel:
          "Cancel",
      });

    if (
      !confirmed
    ) {
      return;
    }

    setDeleting(
      true
    );

    setDetailError(
      ""
    );

    try {
      const res =
        await fetch(
          DELETE_URL,
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
                  id,
              }),
          }
        );

      const text =
        await res.text();

      if (
        !res.ok
      ) {
        throw new Error(
          `HTTP ${res.status}: ${text.slice(0, 200)}`
        );
      }

      const data =
        JSON.parse(
          text
        );

      if (
        !data?.ok
      ) {
        throw new Error(
          data?.error ||
          "Delete failed."
        );
      }

      setPlaylistEditorOpen(
        false
      );

      setDirty(
        false
      );

      if (
        typeof onDeleted ===
        "function"
      ) {
        onDeleted(
          id
        );
      } else {
        navigate(
          "/admin/playlists",
          {
            replace:
              true,
          }
        );
      }

    } catch (err) {
      setDetailError(
        err?.message ||
        "Delete failed."
      );

    } finally {
      setDeleting(
        false
      );
    }
  }


  const slideColumns =
    useMemo(
      () => [
        {
          key:
            "__move",

          label:
            "",

          sortable:
            false,

          render:
            (item) => {
              const index =
                itemIndex(
                  items,
                  item
                );

              return (
                <>
                  <button
                    type="button"
                    className="admin-smart-grid__edit-button"
                    title="Move up"
                    aria-label="Move slide up"
                    disabled={
                      index <=
                      0
                    }
                    onClick={(
                      event
                    ) => {
                      event
                        .stopPropagation();

                      moveItem(
                        item
                          ._clientKey,
                        -1
                      );
                    }}
                  >
                    ↑
                  </button>

                  <button
                    type="button"
                    className="admin-smart-grid__edit-button"
                    title="Move down"
                    aria-label="Move slide down"
                    disabled={
                      index <
                        0
                      ||
                      index >=
                        items.length -
                          1
                    }
                    onClick={(
                      event
                    ) => {
                      event
                        .stopPropagation();

                      moveItem(
                        item
                          ._clientKey,
                        1
                      );
                    }}
                  >
                    ↓
                  </button>
                </>
              );
            },
        },

        {
          key:
            "__order",

          label:
            "#",

          sortable:
            false,

          value:
            (item) =>
              itemIndex(
                items,
                item
              ) + 1,
        },

        {
          key:
            "photo",

          label:
            "Photo",

          sortable:
            false,

          render:
            (item) => {
              const src =
                getItemPhotoThumb(
                  item,
                  photoThumbs
                );

              return src
                ? (
                    <img
                      src={
                        src
                      }
                      alt=""
                      width="64"
                      loading="lazy"
                    />
                  )
                : "—";
            },
        },

        {
          key:
            "item_type",

          label:
            "Type",

          sortable:
            false,

          value:
            (item) =>
              humanize(
                item
                  .item_type
              ),
        },

        {
          key:
            "title",

          label:
            "Title",

          sortable:
            false,

          value:
            (item) =>
              item.title ||
              "—",
        },

        {
          key:
            "analyzer_role",

          label:
            "Role",

          sortable:
            false,

          value:
            (item) =>
              humanize(
                item
                  .analyzer_role
              ),
        },

        {
          key:
            "venues",

          label:
            "Use",

          sortable:
            false,

          value:
            (item) =>
              slideUsageLabel(
                item
              ),
        },

        {
          key:
            "active",

          label:
            "Active",

          sortable:
            false,

          render:
            (item) => (
              <AdminBadge
                variant={
                  item
                    .is_active
                    ? "success"
                    : "neutral"
                }
              >
                {
                  item
                    .is_active
                    ? "Yes"
                    : "No"
                }
              </AdminBadge>
            ),
        },

      ],
      [
        items,
        photoThumbs,
      ]
    );


  const currentTitle =
    playlist.title
    ||
    (
      isNewRoute
        ? "New Playlist"
        : "Playlist"
    );


  useImperativeHandle(
    ref,
    () => ({
      isDirty: () =>
        dirty,

      saveIfDirty:
        async () => {
          if (!dirty) {
            return true;
          }

          return Boolean(
            await savePlaylist()
          );
        },
    }),
    [
      dirty,
      playlist,
      items,
      selectedSlideKey,
    ]
  );


  return (
    <>
      <AdminDetailPane ariaLabel="Playlist">
        {
          detailLoading
          &&
          !isNewRoute
            ? (
                <AdminEmptyState
                  title="Playlist"
                  message="Loading playlist..."
                />
              )
            : (
                <AdminStack gap="sm">
                  <AdminToolbar>
                    <strong>
                      {currentTitle}
                    </strong>

                    {
                      playlist
                        .playlist_id
                        ? (
                            <AdminMetaText as="span">
                              #{playlist.playlist_id}
                            </AdminMetaText>
                          )
                        : null
                    }

                    {
                      playlist
                        .type
                        ? (
                            <AdminBadge variant="neutral">
                              {playlist.type}
                            </AdminBadge>
                          )
                        : null
                    }

                    <AdminBadge
                      variant={
                        playlist
                          .is_active
                          ? "success"
                          : "neutral"
                      }
                    >
                      {
                        playlist
                          .is_active
                          ? "Active"
                          : "Inactive"
                      }
                    </AdminBadge>

                    {
                      dirty
                        ? (
                            <AdminBadge variant="warning">
                              Unsaved
                            </AdminBadge>
                          )
                        : null
                    }

                    <AdminButton
                      type="button"
                      variant="secondary"
                      title="Edit playlist"
                      aria-label="Edit playlist"
                      onClick={
                        openPlaylistEditor
                      }
                    >
                      ✎
                    </AdminButton>

                    <AdminToolbarSpacer />

                    <AdminButton
                      type="button"
                      variant="secondary"
                      disabled={
                        slidePresetsLoading
                        ||
                        slidePresets.length ===
                        0
                      }
                      onClick={() =>
                        setSlideDefaultsOpen(
                          true
                        )
                      }
                    >
                      Slide Defaults
                    </AdminButton>

                    <AdminButton
                      type="button"
                      variant="secondary"
                      disabled={
                        saving
                        ||
                        !items.length
                      }
                      onClick={
                        analyzePlaylist
                      }
                    >
                      Analyzer
                    </AdminButton>

                    <AdminButton
                      type="button"
                      variant="secondary"
                      disabled={
                        saving
                      }
                      onClick={
                        saveAndPlay
                      }
                    >
                      Save & Play
                    </AdminButton>

                    <AdminButton
                      type="button"
                      disabled={
                        saving
                      }
                      onClick={() =>
                        savePlaylist()
                      }
                    >
                      {
                        saving
                          ? "Saving..."
                          : "Save"
                      }
                    </AdminButton>
                  </AdminToolbar>


                  {
                    detailError
                      ? (
                          <AdminNotice variant="danger">
                            {detailError}
                          </AdminNotice>
                        )
                      : null
                  }


                  {
                    saveMessage
                      ? (
                          <AdminNotice variant="success">
                            {saveMessage}
                          </AdminNotice>
                        )
                      : null
                  }


                  <AdminToolbar compact>
                    <AdminField
                      label="Insert"
                      compact
                    >
                      <select
                        className="admin-field__control"
                        value=""
                        onChange={(
                          event
                        ) => {
                          const preset =
                            event
                              .target
                              .value;

                          if (
                            preset
                          ) {
                            addItem(
                              preset
                            );
                          }
                        }}
                      >
                        <option value="">
                          {
                            slidePresetsLoading
                              ? "Loading defaults…"
                              : "Insert New…"
                          }
                        </option>

                        {
                          slidePresets
                            .filter(
                              (preset) =>
                                preset
                                  ?.is_enabled !==
                                false
                            )
                            .map(
                              (preset) => (
                                <option
                                  key={
                                    preset.preset_key
                                  }
                                  value={
                                    preset.preset_key
                                  }
                                >
                                  {
                                    preset.label
                                    ||
                                    preset.preset_key
                                  }
                                </option>
                              )
                            )
                        }
                      </select>
                    </AdminField>

                    <AdminField
                      label="Selection"
                      compact
                    >
                      <AdminToolbar compact>
                        <AdminButton
                          type="button"
                          variant="secondary"
                          disabled={
                            batchSelectedKeys.length ===
                            0
                          }
                          onClick={
                            copySelectedSlides
                          }
                        >
                          Copy
                        </AdminButton>

                        <AdminButton
                          type="button"
                          variant="secondary"
                          disabled={
                            slideClipboardCount ===
                            0
                            ||
                            saving
                          }
                          onClick={
                            pasteCopiedSlides
                          }
                        >
                          Paste
                          {
                            slideClipboardCount
                              ? ` (${slideClipboardCount})`
                              : ""
                          }
                        </AdminButton>

                        <AdminButton
                          type="button"
                          variant="secondary"
                          disabled={
                            batchSelectedKeys.length ===
                            0
                          }
                          onClick={
                            createPlaylistFromSelected
                          }
                        >
                          New Playlist
                        </AdminButton>

                        <AdminButton
                          type="button"
                          variant="secondary"
                          disabled={
                            batchSelectedKeys.length ===
                            0
                            ||
                            saving
                          }
                          onClick={
                            deleteSelectedSlides
                          }
                        >
                          Delete
                        </AdminButton>

                        {
                          batchSelectedKeys.length
                            ? (
                                <AdminMetaText as="div">
                                  {
                                    batchSelectedKeys.length
                                  } selected
                                </AdminMetaText>
                              )
                            : null
                        }
                      </AdminToolbar>
                    </AdminField>

                    <AdminMetaText as="div">
                      {
                        items.length
                      } slide
                      {
                        items.length ===
                        1
                          ? ""
                          : "s"
                      }
                    </AdminMetaText>

                    <AdminToolbarSpacer />
                  </AdminToolbar>


                  {
                    slidePresetsError
                      ? (
                          <AdminNotice variant="danger">
                            {slidePresetsError}
                          </AdminNotice>
                        )
                      : null
                  }


                  {
                    items.length
                      ? (
                          <AdminSmartGrid
                            items={
                              items
                            }

                            columns={
                              slideColumns
                            }

                            getRowKey={(
                              item
                            ) =>
                              item
                                ._clientKey
                            }

                            multiSelect

                            batchSelectedKeys={
                              batchSelectedKeys
                            }

                            onBatchSelectionChange={(
                              keys
                            ) =>
                              setBatchSelectedKeys(
                                keys
                              )
                            }

                            selectedKey={
                              selectedSlideKey
                            }

                            onSelectionChange={(
                              item
                            ) =>
                              setSelectedSlideKey(
                                item
                                  ?._clientKey
                                ||
                                null
                              )
                            }

                            drawer={{
                              title:
                                (item) =>
                                  `Slide ${
                                    itemIndex(
                                      items,
                                      item
                                    ) + 1
                                  } · ${
                                    item.title
                                    ||
                                    humanize(
                                      item.item_type
                                    )
                                  }`,

                              width:
                                620,

                              padded:
                                true,

                              render:
                                ({
                                  item,
                                  close,
                                }) => {
                                  const liveItem =
                                    items.find(
                                      (row) =>
                                        row
                                          ._clientKey ===
                                        item
                                          ._clientKey
                                    );

                                  if (
                                    !liveItem
                                  ) {
                                    return (
                                      <AdminEmptyState
                                        title="Slide removed"
                                        message="This slide is no longer in the playlist."
                                      />
                                    );
                                  }

                                  const photoId =
                                    getPhotoLibraryId(
                                      liveItem
                                    );

                                  const info =
                                    photoInfo[
                                      String(
                                        photoId ||
                                        ""
                                      )
                                    ]
                                    ||
                                    null;

                                  return (
                                    <PlaylistSlideEditor
                                      item={
                                        liveItem
                                      }

                                      slideNumber={
                                        itemIndex(
                                          items,
                                          liveItem
                                        ) + 1
                                      }

                                      photoThumb={
                                        getItemPhotoThumb(
                                          liveItem,
                                          photoThumbs
                                        )
                                      }

                                      photoInfo={
                                        info
                                      }

                                      attachedPalette={
                                        getDisplayPaletteInfo(
                                          liveItem,
                                          photoInfo
                                        )
                                      }

                                      shareImageActive={
                                        resolvedShareItemKey ===
                                        liveItem
                                          ._clientKey
                                      }

                                      onUpdate={(
                                        field,
                                        value
                                      ) =>
                                        updateItem(
                                          liveItem
                                            ._clientKey,
                                          field,
                                          value
                                        )
                                      }

                                      onPickPhoto={() =>
                                        setPhotoPickerKey(
                                          liveItem
                                            ._clientKey
                                        )
                                      }

                                      onClearPhoto={() =>
                                        clearItemPhoto(
                                          liveItem
                                            ._clientKey
                                        )
                                      }

                                      onSetShareImage={() =>
                                        setShareImage(
                                          liveItem
                                            ._clientKey
                                        )
                                      }

                                      onPickColorPlan={() =>
                                        setColorPlanPickerKey(
                                          liveItem
                                            ._clientKey
                                        )
                                      }

                                      onRemove={
                                        async () => {
                                          const removed =
                                            await removeItem(
                                              liveItem
                                                ._clientKey
                                            );

                                          if (
                                            removed
                                          ) {
                                            close();
                                          }
                                        }
                                      }

                                      saving={
                                        saving
                                      }

                                      saveError={
                                        detailError
                                      }

                                      onSave={() =>
                                        savePlaylist()
                                      }
                                    />
                                  );
                                },
                            }}

                            ariaLabel="Playlist slides"
                          />
                        )
                      : (
                          <AdminEmptyState
                            title="No slides"
                            message="Use Insert New to add the first slide."
                          />
                        )
                  }
                </AdminStack>
              )
        }
      </AdminDetailPane>

      <AdminEditor
        open={
          playlistEditorOpen
        }

        title={
          playlistDraft
            .playlist_id
            ? `Edit Playlist #${playlistDraft.playlist_id}`
            : "New Playlist"
        }

        meta={
          playlistDraft
            .type
            ? playlistDraft.type
            : null
        }

        width={
          920
        }

        busy={
          saving
          ||
          deleting
        }

        onClose={() =>
          setPlaylistEditorOpen(
            false
          )
        }
      >
        <PlaylistRecordEditor
          playlist={
            playlistDraft
          }

          playlistTypes={
            playlistTypes
          }

          busy={
            saving
            ||
            deleting
          }

          onChange={
            updatePlaylistDraft
          }

          onPickHero={() =>
            setHeroPickerOpen(
              true
            )
          }

          onClearHero={() => {
            updatePlaylistDraft(
              "hero_image_id",
              ""
            );

            updatePlaylistDraft(
              "hero_image_url",
              ""
            );
          }}

          onGenerateSlug={() =>
            updatePlaylistDraft(
              "slug",
              slugifyPlaylistValue(
                playlistDraft.headline
                ||
                playlistDraft.title
              )
            )
          }

          onClose={() =>
            setPlaylistEditorOpen(
              false
            )
          }

          onSaveAndClose={
            savePlaylistDraftAndClose
          }

          onDelete={
            playlistDraft
              .playlist_id
              ? deletePlaylist
              : null
          }
        />
      </AdminEditor>


      <SlideDefaultsDialog
        open={
          slideDefaultsOpen
        }

        presets={
          slidePresets
        }

        onClose={() =>
          setSlideDefaultsOpen(
            false
          )
        }

        onSaved={(
          saved
        ) => {
          setSlidePresets(
            (current) => {
              const exists =
                current.some(
                  (row) =>
                    row
                      ?.preset_key ===
                    saved
                      ?.preset_key
                );

              const next =
                exists
                  ? current.map(
                      (row) =>
                        row
                          ?.preset_key ===
                        saved
                          ?.preset_key
                          ? saved
                          : row
                    )
                  : [
                      ...current,
                      saved,
                    ];

              return next.sort(
                (
                  a,
                  b
                ) => {
                  const byOrder =
                    Number(
                      a
                        ?.sort_order ||
                      0
                    )
                    -
                    Number(
                      b
                        ?.sort_order ||
                      0
                    );

                  if (
                    byOrder !==
                    0
                  ) {
                    return byOrder;
                  }

                  return String(
                    a
                      ?.label ||
                    a
                      ?.preset_key ||
                    ""
                  ).localeCompare(
                    String(
                      b
                        ?.label ||
                      b
                        ?.preset_key ||
                      ""
                    )
                  );
                }
              );
            }
          );

          setSlidePresetsError(
            ""
          );
        }}
      />


      <PhotoPickerModal
        open={
          photoPickerKey !=
          null
        }

        onClose={() =>
          setPhotoPickerKey(
            null
          )
        }

        onPick={(
          picked
        ) => {
          if (
            !photoPickerKey
            ||
            !picked
              ?.photo_library_id
          ) {
            return;
          }

          const pid =
            String(
              picked
                .photo_library_id
            );

          const info =
            photoInfoFromRow(
              picked
            );

          setPhotoInfo(
            (current) => ({
              ...current,

              [pid]:
                info,
            })
          );

          updateItem(
            photoPickerKey,
            "photo_library_id",
            pid
          );

          updateItem(
            photoPickerKey,
            "image_url",
            makePhotoRef(
              pid,
              picked
                .image_url ||
              ""
            )
          );

          applyAttachedPaletteFromPhoto(
            photoPickerKey,
            pid,
            info
          );

          if (
            picked
              .image_url
          ) {
            setPhotoThumbs(
              (current) => ({
                ...current,

                [pid]:
                  picked
                    .image_url,
              })
            );
          }

          setPhotoPickerKey(
            null
          );
        }}
      />


      <PhotoPickerModal
        open={
          heroPickerOpen
        }

        title="Pick Hero Photo"

        onClose={() =>
          setHeroPickerOpen(
            false
          )
        }

        onPick={(
          picked
        ) => {
          if (
            !picked
              ?.photo_library_id
          ) {
            return;
          }

          updatePlaylistDraft(
            "hero_image_id",
            String(
              picked
                .photo_library_id
            )
          );

          updatePlaylistDraft(
            "hero_image_url",
            picked
              .image_url ||
            ""
          );

          if (
            !String(
              playlistDraft
                .hero_alt ||
              ""
            ).trim()
          ) {
            updatePlaylistDraft(
              "hero_alt",
              playlistDraft.headline
              ||
              playlistDraft.title
            );
          }

          setHeroPickerOpen(
            false
          );
        }}
      />


      <ColorPlanPickerModal
        open={
          colorPlanPickerKey !=
          null
        }

        currentPlanId={
          colorPlanPickerKey
            ? items.find(
                (item) =>
                  item
                    ._clientKey ===
                  colorPlanPickerKey
              )
                ?.color_plan_id
              ||
              null
            : null
        }

        onClose={() =>
          setColorPlanPickerKey(
            null
          )
        }

        onSave={({
          colorPlanId,
        }) => {
          if (
            !colorPlanPickerKey
          ) {
            return;
          }

          updateItem(
            colorPlanPickerKey,
            "color_plan_id",
            String(
              colorPlanId
            )
          );

          setColorPlanPickerKey(
            null
          );
        }}
      />
    </>
  );
});

export default PlaylistEditor;



function normalizePlaylist(
  raw = {}
) {
  return {
    ...emptyPlaylist,
    ...raw,

    playlist_id:
      raw.playlist_id
      ??
      null,

    title:
      raw.title
      ??
      "",

    type:
      raw.type
      ??
      "",

    is_active:
      raw.is_active == null
        ? true
        : Boolean(
            Number(
              raw.is_active
            )
          ),

    is_public:
      raw.is_public == null
        ? false
        : Boolean(
            Number(
              raw.is_public
            )
          ),

    slug:
      raw.slug
      ??
      "",

    headline:
      raw.headline
      ??
      "",

    page_title:
      raw.page_title
      ??
      "",

    meta_description:
      raw.meta_description
      ??
      "",

    dek:
      raw.dek
      ??
      "",

    intro_html:
      raw.intro_html
      ??
      "",

    body_html:
      raw.body_html
      ??
      "",

    hero_image_id:
      raw.hero_image_id
      ??
      "",

    hero_image_url:
      raw.hero_image_url
      ??
      "",

    hero_alt:
      raw.hero_alt
      ??
      "",

    indexable:
      raw.indexable == null
        ? true
        : Boolean(
            Number(
              raw.indexable
            )
          ),

    published_at:
      toDatetimeLocal(
        raw.published_at
        ??
        ""
      ),
  };
}


function normalizePlaylistItem(
  raw = {},
  makeClientItemKey
) {
  const analyzerRole =
    String(
      raw
        .analyzer_role ||
      ""
    )
      .trim()
      .toLowerCase();

  const finderStart =
    String(
      raw
        .finder_start ||
      ""
    )
      .trim()
      .toLowerCase();

  return {
    ...emptyItem,
    ...raw,

    _clientKey:
      raw
        .playlist_item_id
        ? `existing-${raw.playlist_item_id}`
        : makeClientItemKey(),

    playlist_item_id:
      raw
        .playlist_item_id
      ??
      null,

    ap_id:
      raw.ap_id
      ??
      "",

    palette_hash:
      raw
        .palette_hash
      ??
      "",

    image_url:
      raw
        .image_url
      ??
      "",

    photo_library_id:
      raw
        .photo_library_id
      ??
      parsePhotoRef(
        raw
          .image_url ||
        ""
      )
        .photoId
      ??
      "",

    saved_palette_set_id:
      raw
        .saved_palette_set_id
      ??
      "",

    title:
      raw.title
      ??
      "",

    subtitle:
      raw.subtitle
      ??
      "",

    subtitle_2:
      raw
        .subtitle_2
      ??
      "",

    body:
      raw.body
      ??
      "",

    item_type:
      raw
        .item_type
      ??
      "non-palette",

    layout:
      raw.layout
      ??
      "default",

    title_mode:
      raw
        .title_mode
      ??
      "",

    star:
      raw.star ===
        null
        ? true
        : Boolean(
            raw.star
          ),

    transition:
      raw.transition
      ??
      "",

    duration_ms:
      raw
        .duration_ms
      ??
      "",

    exclude_from_thumbs:
      Boolean(
        raw
          .exclude_from_thumbs
      ),

    is_share_image:
      Boolean(
        raw
          .is_share_image
      ),

    site:
      raw.site ==
        null
        ? true
        : Boolean(
            Number(
              raw.site
            )
          ),

    yt:
      raw.yt ==
        null
        ? true
        : Boolean(
            Number(
              raw.yt
            )
          ),

    concept:
      raw.concept ==
        null
        ? true
        : Boolean(
            Number(
              raw.concept
            )
          ),

    client:
      raw.client ==
        null
        ? true
        : Boolean(
            Number(
              raw.client
            )
          ),

    pin:
      raw.pin ==
        null
        ? true
        : Boolean(
            Number(
              raw.pin
            )
          ),

    color_plan_id:
      raw
        .color_plan_id
      ??
      "",

    version_number:
      Math.max(
        1,
        Number(
          raw
            .version_number ||
          1
        )
      ),

    is_final:
      Boolean(
        Number(
          raw
            .is_final ||
          0
        )
      ),

    analyzer_role:
      ANALYZER_ROLES.includes(
        analyzerRole
      )
        ? analyzerRole
        : "ignore",

    finder_start:
      FINDER_START_VALUES.includes(
        finderStart
      )
        ? finderStart
        : "auto",

    is_active:
      raw
        .is_active ===
        null
        ? true
        : Boolean(
            raw
              .is_active
          ),
  };
}


function buildPresetItem(
  preset,
  clientKey
) {
  const itemType =
    String(
      preset
        ?.item_type ||
      "non-palette"
    ).trim()
    ||
    "non-palette";

  return {
    ...emptyItem,

    _clientKey:
      clientKey,

    item_type:
      itemType,

    body:
      itemType ===
        "hue-wheel"
        ? HUE_WHEEL_BODY_TEMPLATE
        : itemType ===
            "brand-bumper"
          ? BRAND_BUMPER_BODY_TEMPLATE
          : "",

    title:
      preset
        ?.default_title
      ??
      "",

    subtitle:
      preset
        ?.default_subtitle
      ??
      "",

    subtitle_2:
      preset
        ?.default_subtitle_2
      ??
      "",

    layout:
      preset
        ?.default_layout
      ||
      "default",

    title_mode:
      preset
        ?.default_title_mode
      ??
      "",

    star:
      preset
        ?.default_star
      ??
      true,

    transition:
      preset
        ?.default_transition
      ??
      "",

    duration_ms:
      preset
        ?.default_duration_ms
      ??
      "",

    is_active:
      preset
        ?.default_is_active
      ??
      true,

    exclude_from_thumbs:
      preset
        ?.default_exclude_from_thumbs
      ??
      false,

    is_share_image:
      preset
        ?.default_is_share_image
      ??
      false,

    site:
      preset
        ?.default_site
      ??
      true,

    yt:
      preset
        ?.default_yt
      ??
      true,

    pin:
      preset
        ?.default_pin
      ??
      true,

    concept:
      preset
        ?.default_concept
      ??
      true,

    client:
      preset
        ?.default_client
      ??
      true,

    analyzer_role:
      preset
        ?.default_analyzer_role
      ||
      "ignore",

    finder_start:
      preset
        ?.default_finder_start
      ||
      "auto",

    version_number:
      Math.max(
        1,
        Number(
          preset
            ?.default_version_number
          ||
          1
        )
      ),

    is_final:
      preset
        ?.default_is_final
      ??
      false,
  };
}


function readSlideClipboard() {
  try {
    const raw =
      window
        .sessionStorage
        .getItem(
          SLIDE_CLIPBOARD_KEY
        );

    if (!raw) {
      return [];
    }

    const parsed =
      JSON.parse(
        raw
      );

    return Array.isArray(
      parsed
        ?.slides
    )
      ? parsed.slides
      : [];

  } catch {
    return [];
  }
}


function writeSlideClipboard(
  slides,
  sourcePlaylistId
) {
  const cleanSlides =
    (
      Array.isArray(
        slides
      )
        ? slides
        : []
    ).map(
      (item) => {
        const {
          _clientKey,
          playlist_item_id,
          playlist_id,
          sort_order,
          position,
          ...copy
        } = item || {};

        void _clientKey;
        void playlist_item_id;
        void playlist_id;
        void sort_order;
        void position;

        return copy;
      }
    );

  try {
    window
      .sessionStorage
      .setItem(
        SLIDE_CLIPBOARD_KEY,
        JSON.stringify({
          version:
            1,

          source_playlist_id:
            sourcePlaylistId,

          slides:
            cleanSlides,
        })
      );
  } catch {
    // Keep the editor usable even if browser storage is unavailable.
  }

  return cleanSlides;
}


function clonePlaylistItemForNewPlaylist(
  item,
  clientKey
) {
  const {
    _clientKey,
    playlist_item_id,
    playlist_id,
    sort_order,
    position,
    ...copy
  } = item || {};

  void _clientKey;
  void playlist_item_id;
  void playlist_id;
  void sort_order;
  void position;

  return {
    ...emptyItem,
    ...copy,

    _clientKey:
      clientKey,

    playlist_item_id:
      null,
  };
}


function serializePlaylistItem(
  item
) {
  const {
    _clientKey,
    ...rest
  } = item;

  void _clientKey;

  const analyzerRole =
    String(
      item
        .analyzer_role ||
      ""
    )
      .trim()
      .toLowerCase();

  const finderStart =
    String(
      item
        .finder_start ||
      ""
    )
      .trim()
      .toLowerCase();

  return {
    ...rest,

    ap_id:
      item.ap_id ===
        ""
        ? null
        : item.ap_id,

    palette_hash:
      item
        .palette_hash ===
        ""
        ? null
        : item
            .palette_hash,

    saved_palette_set_id:
      item
        .saved_palette_set_id ===
        ""
        ? null
        : item
            .saved_palette_set_id,

    duration_ms:
      item
        .duration_ms ===
        ""
        ? null
        : item
            .duration_ms,

    is_share_image:
      Boolean(
        item
          .is_share_image
      ),

    site:
      Boolean(
        item.site
      ),

    yt:
      Boolean(
        item.yt
      ),

    concept:
      item.concept !==
      false,

    client:
      item.client !==
      false,

    pin:
      item.pin !==
      false,

    analyzer_role:
      ANALYZER_ROLES.includes(
        analyzerRole
      )
        ? analyzerRole
        : "ignore",

    finder_start:
      FINDER_START_VALUES.includes(
        finderStart
      )
        ? finderStart
        : "auto",
  };
}


function upsertPlaylistSummary(
  current,
  row
) {
  const id =
    Number(
      row
        ?.playlist_id ||
      0
    );

  if (!id) {
    return current;
  }

  const exists =
    current.some(
      (item) =>
        Number(
          item
            ?.playlist_id ||
          0
        ) === id
    );

  if (
    exists
  ) {
    return current.map(
      (item) =>
        Number(
          item
            ?.playlist_id ||
          0
        ) === id
          ? {
              ...item,
              ...row,
            }
          : item
    );
  }

  return [
    ...current,
    row,
  ];
}


function getPhotoLibraryId(
  item
) {
  if (
    item
      ?.photo_library_id
  ) {
    return item
      .photo_library_id;
  }

  return (
    parsePhotoRef(
      item
        ?.image_url ||
      ""
    )
      .photoId
    ||
    ""
  );
}


function hasItemPhoto(
  item
) {
  return Boolean(
    String(
      getPhotoLibraryId(
        item
      ) ||
      ""
    ).trim()
    ||
    String(
      item
        ?.image_url ||
      ""
    ).trim()
  );
}


function getItemPhotoThumb(
  item,
  photoThumbs
) {
  const photoId =
    String(
      getPhotoLibraryId(
        item
      ) ||
      ""
    ).trim();

  if (
    photoId
    &&
    photoThumbs[
      photoId
    ]
  ) {
    return photoThumbs[
      photoId
    ];
  }

  const parsed =
    parsePhotoRef(
      item
        ?.image_url ||
      ""
    );

  if (
    parsed.url
  ) {
    return parsed.url;
  }

  if (
    item
      ?.image_url
    &&
    !String(
      item.image_url
    ).startsWith(
      "photo:"
    )
  ) {
    return item
      .image_url;
  }

  return "";
}


function getDisplayPaletteInfo(
  item,
  photoInfo
) {
  const photoId =
    String(
      getPhotoLibraryId(
        item
      ) ||
      ""
    ).trim();

  const info =
    photoInfo[
      photoId
    ]
    ||
    null;

  if (!info) {
    return null;
  }

  if (
    String(
      info
        .attachedSavedPalettePhotoType ||
      ""
    ).toLowerCase() ===
    "before"
  ) {
    return null;
  }

  return info;
}


function photoInfoFromRow(
  row = {}
) {
  return {
    attachedSavedPaletteId:
      row
        ?.attached_saved_palette_id
      ??
      null,

    attachedSavedPaletteLabel:
      row
        ?.attached_saved_palette_label
      ||
      "",

    attachedSavedPaletteSetId:
      row
        ?.attached_saved_palette_set_id
      ??
      null,

    attachedSavedPaletteSetLabel:
      row
        ?.attached_saved_palette_set_label
      ||
      "",

    attachedSavedPalettePhotoType:
      row
        ?.attached_saved_palette_photo_type
      ||
      "",

    clientId:
      row
        ?.client_id
      ??
      null,

    clientName:
      row
        ?.client_name
      ||
      "",

    clientEmail:
      row
        ?.client_email
      ||
      "",

    photoPermissionStatus:
      row
        ?.photo_permission_status
      ||
      "unknown",
  };
}


function itemIndex(
  items,
  item
) {
  return items.findIndex(
    (row) =>
      row
        ._clientKey ===
      item
        ?._clientKey
  );
}


function slideUsageLabel(
  item
) {
  const values = [];

  if (
    item.site !==
    false
  ) {
    values.push(
      "Site"
    );
  }

  if (
    item.concept !==
    false
  ) {
    values.push(
      "Concept"
    );
  }

  if (
    item.client !==
    false
  ) {
    values.push(
      "Client"
    );
  }

  if (
    item.pin !==
    false
  ) {
    values.push(
      "Pin"
    );
  }

  if (
    item.yt !==
    false
  ) {
    values.push(
      "YT"
    );
  }

  return (
    values.join(
      " · "
    )
    ||
    "—"
  );
}


function slugifyPlaylistValue(
  value
) {
  return String(
    value ||
    ""
  )
    .toLowerCase()
    .trim()
    .replace(
      /[^a-z0-9]+/g,
      "-"
    )
    .replace(
      /-+/g,
      "-"
    )
    .replace(
      /^-|-$/g,
      ""
    );
}


function toDatetimeLocal(
  value
) {
  const text =
    String(
      value ||
      ""
    ).trim();

  if (!text) {
    return "";
  }

  return text
    .replace(
      " ",
      "T"
    )
    .slice(
      0,
      16
    );
}


function humanize(
  value
) {
  const raw =
    String(
      value ||
      ""
    )
      .trim();

  if (!raw) {
    return "—";
  }

  return raw
    .replace(
      /[_-]+/g,
      " "
    )
    .replace(
      /\b\w/g,
      (
        character
      ) =>
        character
          .toUpperCase()
    );
}
