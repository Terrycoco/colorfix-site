import {
  useCallback,
  useEffect,
  useMemo,
  useState,
} from "react";

import {
  AdminButton,
  AdminDialog,
  AdminEmptyState,
  AdminField,
  AdminMetaText,
  AdminNotice,
  AdminSmartGrid,
  AdminStack,
  AdminToolbar,
  AdminToolbarSpacer,
} from "@components/AdminLayout";

import {
  API_FOLDER,
} from "@helpers/config";


const PROJECT_PALETTES_URL =
  `${API_FOLDER}/v2/admin/projects/palettes/list.php`;

const PV_LIST_URL =
  `${API_FOLDER}/v2/admin/palettes/pvs/list.php`;

const PV_CREATE_URL =
  `${API_FOLDER}/v2/admin/palettes/pvs/create.php`;


const EXPERIENCE_OPTIONS = [
  {
    value:
      "public",
    label:
      "Public",
  },
  {
    value:
      "concept",
    label:
      "Concept",
  },
  {
    value:
      "client",
    label:
      "Client",
  },
  {
    value:
      "painter",
    label:
      "Painter",
  },
];


function cleanText(
  value
) {
  return String(
    value ?? ""
  ).trim();
}


function paletteLabel(
  row
) {
  return cleanText(
    row?.display_title
    ||
    row?.nickname
    ||
    row?.palette_name
    ||
    (
      row?.saved_palette_id
        ? `Palette #${row.saved_palette_id}`
        : ""
    )
  );
}


function experienceLabel(
  value
) {
  const text =
    cleanText(
      value
    ).toLowerCase();

  if (!text) {
    return "—";
  }

  return (
    text.charAt(0).toUpperCase()
    +
    text.slice(1)
  );
}


function derivedHandle(
  item
) {
  const title =
    cleanText(
      item?.title
    )
    ||
    "Untitled";

  const experience =
    experienceLabel(
      item?.format
      ??
      item?.experience_key
    );

  return `${title} - ${experience}`;
}


async function readJson(
  response,
  fallbackMessage
) {
  const text =
    await response.text();

  let data = {};

  try {
    data =
      text.trim()
        ? JSON.parse(
            text
          )
        : {};
  } catch {
    throw new Error(
      `${fallbackMessage}: invalid JSON response`
    );
  }

  if (
    !response.ok
    ||
    data?.ok === false
  ) {
    throw new Error(
      data?.error
      ||
      `HTTP ${response.status}`
    );
  }

  return data;
}


export default function ProjectPVPage({
  projectId,
  onRex = null,
}) {
  const [
    palettes,
    setPalettes,
  ] = useState([]);

  const [
    items,
    setItems,
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
    selectedKey,
    setSelectedKey,
  ] = useState(null);


  const [
    newOpen,
    setNewOpen,
  ] = useState(false);

  const [
    newPaletteId,
    setNewPaletteId,
  ] = useState("");

  const [
    newExperience,
    setNewExperience,
  ] = useState("");

  const [
    newTitle,
    setNewTitle,
  ] = useState("");

  const [
    createError,
    setCreateError,
  ] = useState("");

  const [
    creating,
    setCreating,
  ] = useState(false);


  const loadPage =
    useCallback(
      async () => {
        const id =
          Number(
            projectId ||
            0
          );

        if (id <= 0) {
          setPalettes([]);
          setItems([]);
          setLoading(false);
          setError("");
          return;
        }

        setLoading(true);
        setError("");

        try {
          const paletteParams =
            new URLSearchParams({
              project_id:
                String(
                  id
                ),

              _:
                String(
                  Date.now()
                ),
            });

          const paletteData =
            await readJson(
              await fetch(
                `${PROJECT_PALETTES_URL}?${paletteParams.toString()}`,
                {
                  credentials:
                    "include",

                  cache:
                    "no-store",
                }
              ),

              "Failed to load project palettes"
            );

          const projectPalettes =
            Array.isArray(
              paletteData?.items
            )
              ? paletteData.items
              : [];

          setPalettes(
            projectPalettes
          );

          const paletteIds =
            projectPalettes
              .map(
                (row) =>
                  Number(
                    row?.saved_palette_id
                    ||
                    0
                  )
              )
              .filter(
                (value) =>
                  value > 0
              );

          if (!paletteIds.length) {
            setItems([]);
            return;
          }

          const pvParams =
            new URLSearchParams({
              saved_palette_ids:
                paletteIds.join(
                  ","
                ),

              _:
                String(
                  Date.now()
                ),
            });

          const pvData =
            await readJson(
              await fetch(
                `${PV_LIST_URL}?${pvParams.toString()}`,
                {
                  credentials:
                    "include",

                  cache:
                    "no-store",
                }
              ),

              "Failed to load PVs"
            );

          setItems(
            Array.isArray(
              pvData?.items
            )
              ? pvData.items
              : []
          );

        } catch (err) {
          setPalettes([]);
          setItems([]);

          setError(
            err?.message
            ||
            "Failed to load PVs."
          );

        } finally {
          setLoading(
            false
          );
        }
      },
      [
        projectId,
      ]
    );


  useEffect(
    () => {
      void loadPage();
    },
    [
      loadPage,
    ]
  );


  useEffect(
    () => {
      setSelectedKey(
        null
      );

      setNewOpen(
        false
      );

      setNewPaletteId(
        ""
      );

      setNewExperience(
        ""
      );

      setNewTitle(
        ""
      );

      setCreateError(
        ""
      );
    },
    [
      projectId,
    ]
  );


  const columns =
    useMemo(
      () => [
        {
          key:
            "handle",

          label:
            "Handle",

          sortable:
            true,

          value:
            (item) =>
              cleanText(
                item?.handle
              )
              ||
              derivedHandle(
                item
              ),
        },

        {
          key:
            "palette",

          label:
            "Palette",

          sortable:
            true,

          value:
            (item) =>
              cleanText(
                item?.palette_name
              )
              ||
              `Palette #${item?.saved_palette_id || ""}`,
        },

        {
          key:
            "experience",

          label:
            "Experience",

          sortable:
            true,

          value:
            (item) =>
              experienceLabel(
                item?.format
                ??
                item?.experience_key
              ),
        },

        {
          key:
            "photos",

          label:
            "Photos",

          sortable:
            true,

          value:
            (item) =>
              Number(
                item?.photo_count
                ??
                0
              ),
        },

        {
          key:
            "rex",

          label:
            "REX",

          sortable:
            false,

          render:
            (item) => (
              <button
                type="button"
                className="admin-smart-grid__edit-button"
                title="Outside Link / REX"
                aria-label={`Outside Link / REX for ${derivedHandle(item)}`}
                disabled={
                  typeof onRex !==
                  "function"
                }
                onClick={(
                  event
                ) => {
                  event.stopPropagation();

                  if (
                    typeof onRex ===
                    "function"
                  ) {
                    onRex(
                      item
                    );
                  }
                }}
              >
                R↗
              </button>
            ),
        },
      ],
      [
        onRex,
      ]
    );


  function openNewPV() {
    const onePalette =
      palettes.length === 1
        ? palettes[0]
        : null;

    setNewPaletteId(
      onePalette
        ? String(
            onePalette
              .saved_palette_id
          )
        : ""
    );

    setNewExperience(
      ""
    );

    setNewTitle(
      onePalette
        ? paletteLabel(
            onePalette
          )
        : ""
    );

    setCreateError(
      ""
    );

    setNewOpen(
      true
    );
  }


  function closeNewPV() {
    if (creating) {
      return;
    }

    setNewOpen(
      false
    );

    setCreateError(
      ""
    );
  }


  function handlePaletteChange(
    value
  ) {
    const nextValue =
      String(
        value ||
        ""
      );

    const previousPalette =
      palettes.find(
        (row) =>
          String(
            row?.saved_palette_id
            ??
            ""
          ) ===
          String(
            newPaletteId
          )
      );

    const nextPalette =
      palettes.find(
        (row) =>
          String(
            row?.saved_palette_id
            ??
            ""
          ) ===
          nextValue
      );

    const previousAutoTitle =
      previousPalette
        ? paletteLabel(
            previousPalette
          )
        : "";

    setNewPaletteId(
      nextValue
    );

    if (
      !cleanText(
        newTitle
      )
      ||
      (
        previousAutoTitle
        &&
        cleanText(
          newTitle
        ) ===
        previousAutoTitle
      )
    ) {
      setNewTitle(
        nextPalette
          ? paletteLabel(
              nextPalette
            )
          : ""
      );
    }

    setCreateError(
      ""
    );
  }


  const canCreate =
    Number(
      newPaletteId ||
      0
    ) > 0
    &&
    cleanText(
      newExperience
    ) !== ""
    &&
    cleanText(
      newTitle
    ) !== ""
    &&
    !creating;


  async function createPV() {
    if (!canCreate) {
      return;
    }

    setCreating(
      true
    );

    setCreateError(
      ""
    );

    try {
      const data =
        await readJson(
          await fetch(
            PV_CREATE_URL,
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
                  saved_palette_id:
                    Number(
                      newPaletteId
                    ),

                  experience:
                    cleanText(
                      newExperience
                    ),

                  title:
                    cleanText(
                      newTitle
                    ),
                }),
            }
          ),

          "Failed to create PV"
        );

      const created =
        data?.item
        ||
        null;

      if (
        !created
        ||
        Number(
          created
            ?.palette_viewer_id
          ||
          0
        ) <= 0
      ) {
        throw new Error(
          "PV create did not return a valid PV."
        );
      }

      setItems(
        (current) => [
          ...current.filter(
            (row) =>
              Number(
                row?.palette_viewer_id
                ||
                0
              ) !==
              Number(
                created
                  .palette_viewer_id
              )
          ),

          created,
        ]
      );

      setSelectedKey(
        Number(
          created
            .palette_viewer_id
        )
      );

      setNewOpen(
        false
      );

      setNewPaletteId(
        ""
      );

      setNewExperience(
        ""
      );

      setNewTitle(
        ""
      );

    } catch (err) {
      setCreateError(
        err?.message
        ||
        "Failed to create PV."
      );

    } finally {
      setCreating(
        false
      );
    }
  }


  const toolbar =
    (
      <AdminToolbar compact>
        <AdminButton
          type="button"
          onClick={
            openNewPV
          }
          disabled={
            loading
          }
        >
          + New PV
        </AdminButton>

        <AdminToolbarSpacer />

        <AdminMetaText as="div">
          {
            items.length
          } PV
          {
            items.length === 1
              ? ""
              : "s"
          }
        </AdminMetaText>
      </AdminToolbar>
    );


  const dialogActions = [
    {
      key:
        "cancel",

      label:
        "Cancel",

      variant:
        "secondary",

      disabled:
        creating,

      onClick:
        closeNewPV,
    },

    {
      key:
        "create",

      label:
        creating
          ? "Creating…"
          : "Create",

      variant:
        "primary",

      disabled:
        !canCreate,

      onClick:
        createPV,
    },
  ];


  return (
    <>
      {
        error
          ? (
              <AdminNotice variant="danger">
                {error}
              </AdminNotice>
            )
          : null
      }

      <AdminSmartGrid
        toolbar={
          toolbar
        }

        items={
          items
        }

        columns={
          columns
        }

        getRowKey={(
          item
        ) =>
          Number(
            item?.palette_viewer_id
            ||
            0
          )
        }

        selectedKey={
          selectedKey
        }

        onSelectionChange={(
          item,
          key
        ) =>
          setSelectedKey(
            key
            ??
            item?.palette_viewer_id
            ??
            null
          )
        }

        defaultSortKey="handle"
        defaultSortDirection="asc"
        ariaLabel="Project PVs"
        verticalAlign="middle"

        drawer={{
          title:
            (item) =>
              cleanText(
                item?.handle
              )
              ||
              derivedHandle(
                item
              ),

          width:
            620,

          padded:
            true,

          render:
            ({
              item,
            }) => (
              <AdminEmptyState
                title={
                  cleanText(
                    item?.handle
                  )
                  ||
                  derivedHandle(
                    item
                  )
                }
                message="PV editor comes next."
              />
            ),
        }}
      />

      {
        loading
        &&
        !items.length
          ? (
              <AdminEmptyState
                title="PVs"
                message="Loading project PVs..."
              />
            )
          : null
      }

      {
        !loading
        &&
        !error
        &&
        !items.length
          ? (
              <AdminEmptyState
                title="No PVs yet"
                message="Click New PV to create the first PV for this project."
              />
            )
          : null
      }


      <AdminDialog
        open={
          newOpen
        }

        title="New PV"

        width={
          480
        }

        actions={
          dialogActions
        }

        onCancel={
          closeNewPV
        }

        onClose={
          closeNewPV
        }

        dismissOnBackdrop={
          !creating
        }
      >
        <AdminStack gap="md">
          {
            createError
              ? (
                  <AdminNotice variant="danger">
                    {createError}
                  </AdminNotice>
                )
              : null
          }

          {
            !palettes.length
              ? (
                  <AdminNotice variant="warning">
                    This project has no palettes yet.
                  </AdminNotice>
                )
              : null
          }

          <AdminField label="Palette">
            <select
              className="admin-field__control"
              value={
                newPaletteId
              }
              disabled={
                creating
                ||
                !palettes.length
              }
              onChange={(
                event
              ) =>
                handlePaletteChange(
                  event.target.value
                )
              }
            >
              <option value="">
                Choose a palette...
              </option>

              {
                palettes.map(
                  (palette) => (
                    <option
                      key={
                        palette
                          .saved_palette_id
                      }
                      value={
                        palette
                          .saved_palette_id
                      }
                    >
                      {
                        paletteLabel(
                          palette
                        )
                      }
                    </option>
                  )
                )
              }
            </select>
          </AdminField>

          <AdminField label="Experience">
            <select
              className="admin-field__control"
              value={
                newExperience
              }
              disabled={
                creating
              }
              onChange={(
                event
              ) => {
                setNewExperience(
                  event.target.value
                );

                setCreateError(
                  ""
                );
              }}
            >
              <option value="">
                Choose an experience...
              </option>

              {
                EXPERIENCE_OPTIONS.map(
                  (option) => (
                    <option
                      key={
                        option.value
                      }
                      value={
                        option.value
                      }
                    >
                      {
                        option.label
                      }
                    </option>
                  )
                )
              }
            </select>
          </AdminField>

          <AdminField label="Title">
            <input
              className="admin-field__control"
              type="text"
              value={
                newTitle
              }
              disabled={
                creating
              }
              onChange={(
                event
              ) => {
                setNewTitle(
                  event.target.value
                );

                setCreateError(
                  ""
                );
              }}
              onKeyDown={(
                event
              ) => {
                if (
                  event.key ===
                  "Enter"
                  &&
                  canCreate
                ) {
                  event.preventDefault();

                  void createPV();
                }
              }}
            />
          </AdminField>
        </AdminStack>
      </AdminDialog>
    </>
  );
}
