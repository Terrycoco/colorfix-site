import {
  CopyPlus,
  Trash2,
} from "lucide-react";

import {
  forwardRef,
  useCallback,
  useEffect,
  useImperativeHandle,
  useMemo,
  useRef,
  useState,
} from "react";

import {
  AdminButton,
  AdminDialog,
  AdminDetailPane,
  AdminEmptyState,
  AdminField,
  AdminMetaText,
  AdminNotice,
  AdminPanel,
  AdminSmartGrid,
  AdminStack,
  AdminToolbar,
  AdminWorkbenchDrawer,
} from "@components/AdminLayout";

import KickerDropdown from "@components/KickerDropdown";
import FetchRexButton from "@components/REX/FetchRexButton";

import {
  API_FOLDER,
} from "@helpers/config";



const PROJECT_PALETTES_URL =
  `${API_FOLDER}/v2/admin/projects/palettes/list.php`;

const PV_LIST_URL =
  `${API_FOLDER}/v2/admin/palettes/pvs/list.php`;

const PV_CREATE_URL =
  `${API_FOLDER}/v2/admin/palettes/pvs/create.php`;

const PV_DELETE_URL = `${API_FOLDER}/v2/admin/palettes/pvs/delete.php`;

const PV_ADMIN_URL =
  `${API_FOLDER}/v2/admin/palette-viewers.php`;

const PAINTER_PV_ADMIN_URL =
  `${API_FOLDER}/v2/admin/palettes/pvs/painter.php`;



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


function isPainterPV(
  value
) {
  return cleanText(
    value?.format
    ??
    value?.experience_key
    ??
    value?.viewer?.format
  ).toLowerCase() === "painter";
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






function rexUrlFromDetail(
  value
) {
  return cleanText(
    value?.rex?.public_url
    ||
    value?.rex?.url
    ||
    value?.rex?.href
  );
}


function rexAdminUrl(
  value
) {
  const raw =
    cleanText(
      value
    );

  if (!raw) {
    return "";
  }

  try {
    const url =
      new URL(
        raw,
        window.location.origin
      );

    url.searchParams.set(
      "back",
      "1"
    );

    return url.toString();
  } catch {
    return raw;
  }
}


function normalizePVDetail(
  payload,
  row
) {
  const viewer =
    payload?.viewer
    ||
    {};

  return {
    viewer: {
      ...viewer,

      palette_viewer_id:
        Number(
          viewer?.palette_viewer_id
          ??
          row?.palette_viewer_id
          ??
          0
        ),

      saved_palette_id:
        String(
          viewer?.saved_palette_id
          ??
          row?.saved_palette_id
          ??
          ""
        ),

      project_id:
        Number(
          viewer?.project_id
          ??
          row?.project_id
          ??
          0
        )
        ||
        null,

      format:
        cleanText(
          viewer?.format
          ??
          row?.format
          ??
          row?.experience_key
        ).toLowerCase(),

      kicker_text:
        cleanText(
          viewer?.kicker_text
          ??
          row?.kicker_text
        ),

      title:
        cleanText(
          viewer?.title
          ??
          row?.title
        ),

      intro:
        String(
          viewer?.intro
          ??
          row?.intro
          ??
          ""
        ),

      notes:
        String(
          viewer?.notes
          ??
          ""
        ),

      cta_label:
        String(
          viewer?.cta_label
          ??
          ""
        ),

      is_active:
        Number(
          viewer?.is_active
          ??
          row?.is_active
          ??
          1
        )
          ? 1
          : 0,
    },

    project_palette_ids:
      (
        Array.isArray(
          payload?.project_palettes
        )
          ? payload.project_palettes
          : []
      )
        .map(
          (palette) =>
            Number(
              palette?.project_palette_id
              ||
              0
            )
        )
        .filter(
          (id) =>
            id > 0
        ),

    project_palettes:
      Array.isArray(
        payload?.project_palettes
      )
        ? payload.project_palettes
        : [],


    rex:
      payload?.rex
      ||
      null,
  };
}


const PVDrawerEditor = forwardRef(function PVDrawerEditor({
  item,
  palettes,
  onSaved,
  initialDetail = null,
  existingItems = [],
  onSavingChange,
}, ref) {
  const [
    detail,
    setDetail,
  ] = useState(null);

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
    status,
    setStatus,
  ] = useState("");


  const pvId =
    Number(
      item?.palette_viewer_id
      ||
      0
    );

  const draftMode = Boolean(initialDetail);
  useEffect(() => { onSavingChange?.(saving); }, [saving, onSavingChange]);


  useEffect(
    () => {
      let cancelled =
        false;

      if (initialDetail) {
        setDetail(initialDetail);
        setLoading(false);
        setError("");
        return undefined;
      }

      if (pvId <= 0) {
        setDetail(null);
        setLoading(false);
        setError(
          "This PV does not have a valid ID."
        );
        return undefined;
      }

      setLoading(true);
      setError("");
      setStatus("");

      (
        async () => {
          try {
            const data =
              await readJson(
                await fetch(
                  `${
                    isPainterPV(item)
                      ? PAINTER_PV_ADMIN_URL
                      : PV_ADMIN_URL
                  }?id=${encodeURIComponent(
                    pvId
                  )}&_=${Date.now()}`,
                  {
                    credentials:
                      "include",
                    cache:
                      "no-store",
                  }
                ),
                "Failed to load PV"
              );

            if (
              cancelled
            ) {
              return;
            }

            setDetail(
              normalizePVDetail(
                data?.item,
                item
              )
            );

          } catch (err) {
            if (
              !cancelled
            ) {
              setDetail(
                null
              );

              setError(
                err?.message
                ||
                "Failed to load PV."
              );
            }

          } finally {
            if (
              !cancelled
            ) {
              setLoading(
                false
              );
            }
          }
        }
      )();

      return () => {
        cancelled =
          true;
      };
    },
    [
      pvId,
      item,
      initialDetail,
    ]
  );




  function updateViewer(
    field,
    value
  ) {
    setDetail(
      (current) =>
        current
          ? {
              ...current,
              viewer: {
                ...current.viewer,
                [field]:
                  value,
              },
            }
          : current
    );

    setStatus(
      ""
    );
  }




  async function savePV() {
    if (
      !detail
      ||
      saving
    ) {
      return;
    }

    const viewer =
      detail.viewer;

    if (existingItems.some((row) => Number(row.palette_viewer_id) !== pvId
      && cleanText(row.title).toLowerCase() === cleanText(viewer.title).toLowerCase()
      && cleanText(row.format || row.experience_key).toLowerCase() === cleanText(viewer.format).toLowerCase())) {
      setError("A PV with this title and experience already exists. Change the title or experience.");
      return;
    }

    const painter =
      isPainterPV(viewer);

    if (
      !painter
      &&
      Number(
        viewer
          ?.saved_palette_id
        ||
        0
      ) <= 0
    ) {
      setError(
        "Choose a palette."
      );
      return;
    }

    if (
      painter
      &&
      !(
        Array.isArray(
          detail?.project_palette_ids
        )
        &&
        detail.project_palette_ids.length
      )
    ) {
      setError(
        "Mark at least one project palette FINAL."
      );
      return;
    }

    if (
      !cleanText(
        viewer?.format
      )
    ) {
      setError(
        "Choose an experience."
      );
      return;
    }

    if (
      !cleanText(
        viewer?.title
      )
    ) {
      setError(
        "Enter a title."
      );
      return;
    }


    setSaving(
      true
    );

    setError(
      ""
    );

    setStatus(
      ""
    );

    try {
      const painterPayload = {
        viewer: {
          palette_viewer_id:
            Number(
              viewer?.palette_viewer_id
              ||
              pvId
            ),

          project_id:
            Number(
              viewer?.project_id
              ||
              item?.project_id
              ||
              0
            ),

          format:
            "painter",

          kicker_text:
            cleanText(
              viewer?.kicker_text
            )
            ||
            null,

          title:
            cleanText(
              viewer?.title
            ),

          intro:
            String(
              viewer?.intro
              ??
              ""
            ),

          notes:
            String(
              viewer?.notes
              ??
              ""
            ),

          cta_label: cleanText(viewer?.cta_label) || null,

          is_active:
            Number(
              viewer?.is_active
              ??
              1
            )
              ? 1
              : 0,
        },

        project_palette_ids:
          detail.project_palette_ids,
      };


      const normalPayload = {
              viewer: {
                ...viewer,
      
                saved_palette_id:
                  Number(
                    viewer
                      .saved_palette_id
                  ),
      
                format:
                  cleanText(
                    viewer
                      .format
                  ).toLowerCase(),
      
                kicker_text:
                  cleanText(
                    viewer
                      .kicker_text
                  )
                  ||
                  null,
      
                title:
                  cleanText(
                    viewer
                      .title
                  ),
      
                intro:
                  String(
                    viewer
                      .intro
                    ??
                    ""
                  ),
      
                is_active:
                  Number(
                    viewer
                      .is_active
                    ??
                    1
                  )
                    ? 1
                    : 0,
              },
      
            };

      const payload =
        painter
          ? painterPayload
          : normalPayload;

      const createPayload = {
        saved_palette_id: Number(viewer.saved_palette_id || 0),
        project_id: Number(viewer.project_id || 0),
        experience: viewer.format,
        title: cleanText(viewer.title),
        project_palette_ids: detail.project_palette_ids,
        viewer_fields: { ...viewer, template_key: viewer.format === initialDetail?.viewer.format
          ? viewer.template_key : viewer.format === "public" ? "full_palette" : viewer.format },
      };

      const data =
        await readJson(
          await fetch(
            draftMode ? PV_CREATE_URL : painter
              ? PAINTER_PV_ADMIN_URL
              : PV_ADMIN_URL,
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
                  draftMode ? createPayload : payload
                ),
            }
          ),

          "Failed to save PV"
        );

      const saved =
        normalizePVDetail(
          draftMode ? { ...detail, viewer: { ...viewer, ...data.item } } : data?.item,
          item
        );


      setDetail(
        saved
      );

      setStatus(
        "Saved."
      );

      onSaved?.(
        saved
      );

      return saved;

    } catch (err) {
      setError(
        err?.message
        ||
        "Failed to save PV."
      );

      return null;

    } finally {
      setSaving(
        false
      );
    }
  }


  useImperativeHandle(
    ref,
    () => ({
      save: savePV,
    }),
    [detail, saving]
  );



  if (
    loading
  ) {
    return (
      <AdminEmptyState
        title="PV"
        message="Loading PV..."
      />
    );
  }

  if (
    !detail
  ) {
    return (
      <AdminEmptyState
        title="PV could not load"
        message={
          error
        }
      />
    );
  }


  return (
    <>
      <AdminStack gap="md">
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
          status
            ? (
                <AdminNotice variant="success">
                  {status}
                </AdminNotice>
              )
            : null
        }


        <AdminPanel
          title="PV"
          compact
        >
          <AdminStack gap="sm">
            <AdminToolbar compact>
              {
                isPainterPV(
                  detail?.viewer
                )
                  ? (
                      <AdminField
                        label="Areas"
                        compact
                      >
                        <AdminMetaText as="div">
                          {
                            (
                              detail?.project_palettes
                              ||
                              []
                            )
                              .map(
                                (palette) =>
                                  cleanText(
                                    palette?.area_label
                                  )
                                  ||
                                  paletteLabel(
                                    palette
                                  )
                              )
                              .filter(Boolean)
                              .join(", ")
                            ||
                            `${detail?.project_palette_ids?.length || 0} areas`
                          }
                        </AdminMetaText>
                      </AdminField>
                    )
                  : (
                      <AdminField
                        label="Palette"
                        compact
                      >
                        <select
                          className="admin-field__control"
                          value={
                            detail
                              .viewer
                              .saved_palette_id
                          }
                          disabled={
                            saving
                          }
                          onChange={(
                            event
                          ) =>
                            updateViewer(
                              "saved_palette_id",
                              event
                                .target
                                .value
                            )
                          }
                        >
                          <option value="">
                            Choose a palette...
                          </option>

                          {
                            palettes.map(
                              (
                                palette
                              ) => (
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
                    )
              }

              <AdminField
                label="Experience"
                compact
              >
                <select
                  className="admin-field__control"
                  value={
                    detail
                      .viewer
                      .format
                  }
                  disabled={
                    saving
                  }
                  onChange={(
                    event
                  ) =>
                    updateViewer(
                      "format",
                      event
                        .target
                        .value
                    )
                  }
                >
                  {
                    EXPERIENCE_OPTIONS.map(
                      (
                        option
                      ) => (
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
            </AdminToolbar>

            <AdminToolbar compact>
              <AdminField
                label="Kicker"
                compact
              >
                <KickerDropdown
                  textValue={
                    detail
                      .viewer
                      .kicker_text
                    ||
                    ""
                  }
                  blankLabel="Saved kickers..."
                  onChange={(
                    _,
                    kicker
                  ) =>
                    updateViewer(
                      "kicker_text",
                      kicker
                        ?.display_text
                      ||
                      ""
                    )
                  }
                />
              </AdminField>

              <AdminField
                label="Custom"
                compact
              >
                <input
                  className="admin-field__control"
                  type="text"
                  value={
                    detail
                      .viewer
                      .kicker_text
                    ||
                    ""
                  }
                  disabled={
                    saving
                  }
                  onChange={(
                    event
                  ) =>
                    updateViewer(
                      "kicker_text",
                      event
                        .target
                        .value
                    )
                  }
                />
              </AdminField>
            </AdminToolbar>

            <AdminField
              label="Title"
              compact
            >
              <input
                className="admin-field__control"
                type="text"
                value={
                  detail
                    .viewer
                    .title
                  ||
                  ""
                }
                disabled={
                  saving
                }
                onChange={(
                  event
                ) =>
                  updateViewer(
                    "title",
                    event
                      .target
                      .value
                  )
                }
              />
            </AdminField>

            <AdminField
              label="Intro"
              compact
            >
              <textarea
                className="admin-field__control"
                rows={
                  3
                }
                value={
                  detail
                    .viewer
                    .intro
                  ||
                  ""
                }
                disabled={
                  saving
                }
                onChange={(
                  event
                ) =>
                  updateViewer(
                    "intro",
                    event
                      .target
                      .value
                  )
                }
              />
            </AdminField>
          </AdminStack>
        </AdminPanel>

        <AdminField label="Notes" compact>
          <textarea className="admin-field__control" rows={3} value={detail.viewer.notes || ""}
            disabled={saving} onChange={(event) => updateViewer("notes", event.target.value)} />
        </AdminField>
        <AdminField label="CTA Label" compact>
          <input className="admin-field__control" value={detail.viewer.cta_label || ""}
            disabled={saving} onChange={(event) => updateViewer("cta_label", event.target.value)} />
        </AdminField>
        <label><input type="checkbox" checked={Boolean(detail.viewer.is_active)} disabled={saving}
          onChange={(event) => updateViewer("is_active", event.target.checked ? 1 : 0)} /> Active</label>


      </AdminStack>


    </>
  );
});


export default function ProjectPVPage({
  projectId,
  projectName = "",
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

  const pvEditorRef =
    useRef(null);

  const copyEditorRef = useRef(null);
  const [copyDraft, setCopyDraft] = useState(null);
  const [copyBusy, setCopyBusy] = useState(false);
  const [copySaving, setCopySaving] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState(null);
  const [deleting, setDeleting] = useState(false);


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
              project_id:
                String(
                  id
                ),

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
      setCopyDraft(null);
      setDeleteTarget(null);

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


      ],
      []
    );


  async function viewPVFromGrid(
    item
  ) {
    const pvId =
      Number(
        item?.palette_viewer_id
        ||
        0
      );

    if (
      pvId <= 0
    ) {
      setError(
        "This PV does not have a valid ID."
      );

      return;
    }

    setError(
      ""
    );

    try {
      const data =
        await readJson(
          await fetch(
            `${
              isPainterPV(item)
                ? PAINTER_PV_ADMIN_URL
                : PV_ADMIN_URL
            }?id=${encodeURIComponent(
              pvId
            )}&_=${Date.now()}`,
            {
              credentials:
                "include",

              cache:
                "no-store",
            }
          ),

          "Failed to load PV"
        );

      const rexUrl =
        rexUrlFromDetail(
          data?.item
        );

      if (
        !rexUrl
      ) {
        throw new Error(
          "This PV does not currently have a viewer URL."
        );
      }

      window.location.href =
        rexAdminUrl(
          rexUrl
        );

    } catch (err) {
      setError(
        err?.message
        ||
        "Could not open PV."
      );
    }
  }


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

  async function copyPV(item) {
    setCopyBusy(true);
    setError("");
    try {
      const url = isPainterPV(item) ? PAINTER_PV_ADMIN_URL : PV_ADMIN_URL;
      const data = await readJson(await fetch(`${url}?id=${Number(item.palette_viewer_id)}&_=${Date.now()}`, {
        credentials: "include", cache: "no-store",
      }), "Failed to load the PV to copy");
      const detail = normalizePVDetail(data.item, item);
      const base = cleanText(detail.viewer.title) || "Untitled";
      let title = `${base} (copy)`;
      let count = 2;
      while (items.some((row) => cleanText(row.title).toLowerCase() === title.toLowerCase()
        && cleanText(row.format || row.experience_key) === detail.viewer.format)) {
        title = `${base} (copy ${count++})`;
      }
      const finalPalettes = palettes.filter((palette) => Number(palette.is_final));
      setCopyDraft({
        ...detail, rex: null,
        viewer: { ...detail.viewer, palette_viewer_id: 0, title, project_id: Number(projectId) },
        project_palettes: finalPalettes,
        project_palette_ids: finalPalettes.map((palette) => Number(palette.project_palette_id)),
      });
    } catch (err) { setError(err.message || "Could not copy PV."); }
    finally { setCopyBusy(false); }
  }

  async function deletePV() {
    if (!deleteTarget || deleting) return;
    setDeleting(true);
    setError("");
    try {
      await readJson(await fetch(PV_DELETE_URL, {
        method: "POST", credentials: "include", headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ project_id: Number(projectId), palette_viewer_id: Number(deleteTarget.palette_viewer_id) }),
      }), "Failed to delete PV");
      setDeleteTarget(null);
      setSelectedKey(null);
      await loadPage();
    } catch (err) { setError(err.message || "Could not delete PV."); }
    finally { setDeleting(false); }
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


  const selectedPV =
    items.find(
      (item) =>
        Number(
          item?.palette_viewer_id
          ||
          0
        ) ===
        Number(
          selectedKey
          ||
          0
        )
    )
    ||
    null;


  const detailActions = (
    <>
      <AdminButton
        type="button"
        onClick={openNewPV}
        disabled={loading}
      >
        New PV
      </AdminButton>

      <AdminButton
        type="button"
        variant="secondary"
        disabled={!selectedPV}
        onClick={() => {
          if (selectedPV) {
            void viewPVFromGrid(
              selectedPV
            );
          }
        }}
      >
        View
      </AdminButton>

      <FetchRexButton
        buttonLabel="R↗"
        disabled={!selectedPV}
        request={
          selectedPV
            ? {
                label:
                  cleanText(
                    selectedPV?.title
                  )
                  ||
                  derivedHandle(
                    selectedPV
                  ),

                resolverKey:
                  "viewer",

                resourceType:
                  "palette_viewer",

                resourceId:
                  Number(
                    selectedPV?.palette_viewer_id
                    ||
                    0
                  ),

                context: {
                  format:
                    cleanText(
                      selectedPV?.format
                      ??
                      selectedPV?.experience_key
                    )
                    ||
                    "public",
                },
              }
            : null
        }
        resolveExistingUrl={async () => {
          const pvId =
            Number(
              selectedPV?.palette_viewer_id
              ||
              0
            );

          if (pvId <= 0) {
            return "";
          }

          const data =
            await readJson(
              await fetch(
                `${
                  isPainterPV(selectedPV)
                    ? PAINTER_PV_ADMIN_URL
                    : PV_ADMIN_URL
                }?id=${encodeURIComponent(
                  pvId
                )}&_=${Date.now()}`,
                {
                  credentials:
                    "include",

                  cache:
                    "no-store",
                }
              ),

              "Failed to load PV"
            );

          return rexUrlFromDetail(
            data?.item
          );
        }}
        onCreated={() => {
          void loadPage();
        }}
      />

      <AdminButton type="button" variant="secondary" disabled={!selectedPV || copyBusy || deleting || Boolean(copyDraft)}
        onClick={() => void copyPV(selectedPV)}>
        <CopyPlus size={16} /> {copyBusy ? "Copying..." : "Copy Into New"}
      </AdminButton>
      <AdminButton type="button" variant="secondary" title="Delete selected PV" aria-label="Delete selected PV"
        disabled={!selectedPV || deleting || copyBusy || Boolean(copyDraft)} onClick={() => { setError(""); setDeleteTarget(selectedPV); }}>
        <Trash2 size={16} />
      </AdminButton>

      <AdminMetaText as="div">
        {items.length} PV{items.length === 1 ? "" : "s"}
      </AdminMetaText>
    </>
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
      <AdminDetailPane
        ariaLabel="Project PVs"
        title={`${cleanText(projectName) || `Project #${projectId}`} PVs`}
        actions={detailActions}
      >
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
            720,

          padded:
            true,

          closeLabel:
            "Save & Close",

          beforeClose:
            async () => {
              const saved =
                await pvEditorRef.current?.save?.();

              if (!saved) {
                return false;
              }

              // The drawer is the single commit point.
              // Reload so the PV list and editor both come back from the DB.
              window.location.reload();

              return false;
            },

          footer:
            ({
              close,
              closing,
            }) => (
              <button
                type="button"
                className="admin-button admin-button--primary"
                disabled={closing}
                onClick={close}
              >
                {closing
                  ? "Saving..."
                  : "Save & Close"}
              </button>
            ),

          render:
            ({
              item,
            }) => (
              <PVDrawerEditor
                existingItems={items}
                ref={pvEditorRef}
                item={
                  item
                }
                palettes={
                  palettes
                }
                onSaved={() => {
                  void loadPage();
                }}
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
      </AdminDetailPane>


      <AdminDialog
        open={Boolean(deleteTarget)}
        title="Delete PV?"
        message={`Delete "${deleteTarget?.title || "this PV"}" and its REX links? Shared palettes and project photos will be kept.`}
        confirmLabel={deleting ? "Deleting..." : "Delete PV"}
        onConfirm={() => void deletePV()}
        onCancel={() => { if (!deleting) setDeleteTarget(null); }}
        dismissOnBackdrop={!deleting}
      >
        {error ? <AdminNotice variant="danger">{error}</AdminNotice> : null}
      </AdminDialog>

      <AdminWorkbenchDrawer open={Boolean(copyDraft)} portal padded width="min(720px, 100vw)" title="Copy Into New PV"
        onClose={async () => {
          const saved = await copyEditorRef.current?.save?.();
          if (!saved) return;
          setCopyDraft(null);
          setSelectedKey(Number(saved.viewer.palette_viewer_id));
          await loadPage();
        }}
        footer={<AdminButton type="button" variant="secondary" disabled={copySaving} onClick={() => setCopyDraft(null)}>Cancel</AdminButton>}>
        {copyDraft ? <PVDrawerEditor ref={copyEditorRef} item={copyDraft.viewer} palettes={palettes}
          initialDetail={copyDraft} existingItems={items} onSavingChange={setCopySaving} /> : null}
      </AdminWorkbenchDrawer>

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
 
