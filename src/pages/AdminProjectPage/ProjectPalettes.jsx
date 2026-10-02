import {

  Fragment,

  useCallback,

  useEffect,

  useMemo,

  useState,

} from "react";

import {

  AdminButton,

  AdminDetailPane,

  AdminEmptyState,

  AdminField,

  AdminMetaText,

  AdminNotice,

  AdminPanel,

  AdminSmartGrid,

  AdminStack,

  AdminToolbar,

  AdminToolbarSpacer,

  AdminWorkbenchDrawer,

} from "@components/AdminLayout";

import FuzzySearchColorSelect from "@components/FuzzySearchColorSelect";

import { useAppState } from "@context/AppStateContext.jsx";

import { API_FOLDER } from "@helpers/config";

const LIST_URL =

  `${API_FOLDER}/v2/admin/projects/palettes/list.php`;

const LINK_URL =

  `${API_FOLDER}/v2/admin/projects/palettes/link.php`;

const UNLINK_URL =

  `${API_FOLDER}/v2/admin/projects/palettes/unlink.php`;

const SET_FINAL_URL =

  `${API_FOLDER}/v2/admin/projects/palettes/set-final.php`;

const REORDER_URL =

  `${API_FOLDER}/v2/admin/projects/palettes/reorder.php`;

const SAVE_PALETTE_URL =

  `${API_FOLDER}/v2/admin/palettes/save.php`;

const SAVED_PALETTES_URL =

  `${API_FOLDER}/v2/admin/palettes/list.php`;

function cleanText(value) {

  return String(value ?? "").trim();

}

async function readJson(response, fallbackMessage) {

  const text = await response.text();

  let data = {};

  try {

    data = text.trim() ? JSON.parse(text) : {};

  } catch {

    throw new Error(`${fallbackMessage}: invalid JSON response`);

  }

  if (!response.ok || data?.ok === false) {

    throw new Error(data?.error || `HTTP ${response.status}`);

  }

  return data;

}

function normalizeHex(value) {

  const raw = cleanText(value).replace(/^#/, "");

  return /^[0-9a-f]{6}$/i.test(raw)

    ? `#${raw.toUpperCase()}`

    : "#E5E5E5";

}

function paletteLabel(row) {

  return cleanText(

    row?.display_title

    || row?.nickname

    || `Palette #${row?.saved_palette_id || ""}`

  );

}

function colorLabel(color) {

  const name = cleanText(

    color?.name

    || color?.color_name

  );

  const code = cleanText(

    color?.code

    || color?.color_code

  );

  return [name, code]

    .filter(Boolean)

    .join(" · ");

}

function normalizePickedColor(color) {

  const id = Number(

    color?.color_id

    || color?.id

    || 0

  );

  return {

    id,

    name:

      color?.name

      || color?.color_name

      || "",

    brand:

      color?.brand

      || color?.color_brand

      || "",

    brand_name:

      color?.brand_name

      || color?.color_brand_name

      || "",

    code:

      color?.code

      || color?.color_code

      || "",

    hex6:

      color?.hex6

      || color?.hex

      || color?.color_hex6

      || "",

  };

}

function memberFromProjectColor(member, index) {

  return {

    key:

      member?.member_id

      || `${member?.color_id || "color"}-${index}`,

    color: {

      id: Number(member?.color_id || 0),

      name: member?.color_name || "",

      brand: member?.color_brand || "",

      brand_name: member?.color_brand_name || "",

      code: member?.color_code || "",

      hex6: member?.color_hex6 || "",

    },

    role: cleanText(member?.role),

    sheen: cleanText(member?.sheen),

    note: cleanText(member?.note),

  };

}

function PaletteStrip({ colors = [] }) {

  const visibleColors = Array.isArray(colors) ? colors : [];

  if (!visibleColors.length) {

    return (

      <AdminMetaText as="span">

        No colors

      </AdminMetaText>

    );

  }

  return (

    <div

      className="admin-project-palettes__swatches"

      aria-label={`${visibleColors.length} palette colors`}

    >

      {visibleColors.map((color, index) => {

        const title = [

          color?.color_name || `Color ${index + 1}`,

          color?.color_brand_name || color?.color_brand || "",

          color?.role ? `Used for: ${color.role}` : "",

        ]

          .filter(Boolean)

          .join(" · ");

        return (

          <span

            key={

              color?.member_id

              || `${color?.color_id || "color"}-${index}`

            }

            className="admin-project-palettes__swatch"

            style={{

              backgroundColor: normalizeHex(color?.color_hex6),

            }}

            title={title}

            aria-label={title}

          />

        );

      })}

    </div>

  );

}

function LargeSwatch({ color }) {

  const title = colorLabel(color) || "Color";

  return (

    <span

      title={title}

      aria-label={title}

      style={{

        display: "block",

        width: 46,

        height: 34,

        border: "1px solid var(--admin-border)",

        borderRadius: 3,

        boxSizing: "border-box",

        backgroundColor: normalizeHex(color?.hex6),

      }}

    />

  );

}

export default function ProjectPalettes({

  projectId,

  projectName = "",

}) {

  const {

    palette: myPalette,

  } = useAppState();

  const [items, setItems] = useState([]);

  const [loading, setLoading] = useState(true);

  const [error, setError] = useState("");

  const [selectedKey, setSelectedKey] = useState(null);

  const [finalSavingKey, setFinalSavingKey] = useState(null);

  const [draggingPaletteId, setDraggingPaletteId] = useState(null);

  const [reorderSaving, setReorderSaving] = useState(false);

  const [drawerOpen, setDrawerOpen] = useState(false);

  const [drawerMode, setDrawerMode] = useState("new");

  const [drawerPalette, setDrawerPalette] = useState(null);

  const [draftName, setDraftName] = useState("");

  const [draftAreaLabel, setDraftAreaLabel] = useState("");

  const [draftNote, setDraftNote] = useState("");

  const [draftMembers, setDraftMembers] = useState([]);

  const [saving, setSaving] = useState(false);

  const [drawerError, setDrawerError] = useState("");

  const [drawerStatus, setDrawerStatus] = useState("");

  const [savedPaletteOptions, setSavedPaletteOptions] = useState([]);

  const [savedPaletteChoice, setSavedPaletteChoice] = useState("");

  const [savedPalettesLoading, setSavedPalettesLoading] = useState(false);

  const [savedPalettesError, setSavedPalettesError] = useState("");

  const [sourceSavedPaletteId, setSourceSavedPaletteId] = useState(null);

  const loadPalettes = useCallback(async () => {

    const id = Number(projectId || 0);

    if (id <= 0) {

      setItems([]);

      setLoading(false);

      setError("");

      return [];

    }

    setLoading(true);

    setError("");

    try {

      const params = new URLSearchParams({

        project_id: String(id),

        _: String(Date.now()),

      });

      const data = await readJson(

        await fetch(

          `${LIST_URL}?${params.toString()}`,

          {

            credentials: "include",

            cache: "no-store",

          }

        ),

        "Failed to load project palettes"

      );

      const nextItems = Array.isArray(data?.items)

        ? data.items

        : [];

      setItems(nextItems);

      return nextItems;

    } catch (err) {

      setItems([]);

      setError(

        err?.message

        || "Failed to load project palettes."

      );

      return [];

    } finally {

      setLoading(false);

    }

  }, [projectId]);

  useEffect(() => {

    void loadPalettes();

  }, [loadPalettes]);

  const loadSavedPaletteOptions = useCallback(async () => {

    setSavedPalettesLoading(true);

    setSavedPalettesError("");

    try {

      const data = await readJson(

        await fetch(

          `${SAVED_PALETTES_URL}?limit=500&_=${Date.now()}`,

          {

            credentials: "include",

            cache: "no-store",

          }

        ),

        "Failed to load saved palettes"

      );

      const nextOptions = Array.isArray(data?.items)

        ? data.items

        : [];

      setSavedPaletteOptions(nextOptions);

      return nextOptions;

    } catch (err) {

      setSavedPaletteOptions([]);

      setSavedPalettesError(

        err?.message

        || "Failed to load saved palettes."

      );

      return [];

    } finally {

      setSavedPalettesLoading(false);

    }

  }, []);

  useEffect(() => {

    if (!drawerOpen || drawerMode !== "new") {

      return;

    }

    void loadSavedPaletteOptions();

  }, [

    drawerOpen,

    drawerMode,

    loadSavedPaletteOptions,

  ]);

  useEffect(() => {

    setSelectedKey(null);

    setDrawerOpen(false);

    setDrawerPalette(null);

    setDraftName("");

    setDraftAreaLabel("");

    setDraftNote("");

    setDraftMembers([]);

    setDrawerError("");

    setDrawerStatus("");

    setSavedPaletteChoice("");

    setSavedPalettesError("");

    setSourceSavedPaletteId(null);

    setDraggingPaletteId(null);

    setReorderSaving(false);

  }, [projectId]);

  const setPaletteFinal = useCallback(

    async (row, isFinal) => {

      const savedPaletteId = Number(

        row?.saved_palette_id || 0

      );

      if (!savedPaletteId || finalSavingKey !== null) {

        return;

      }

      setFinalSavingKey(savedPaletteId);

      setError("");

      try {

        await readJson(

          await fetch(SET_FINAL_URL, {

            method: "POST",

            credentials: "include",

            headers: {

              "Content-Type": "application/json",

            },

            body: JSON.stringify({

              project_id: Number(projectId),

              saved_palette_id: savedPaletteId,

              is_final: Boolean(isFinal),

            }),

          }),

          "Failed to update final palette status"

        );

        await loadPalettes();

      } catch (err) {

        setError(

          err?.message

          || "Failed to update final palette status."

        );

      } finally {

        setFinalSavingKey(null);

      }

    },

    [

      projectId,

      loadPalettes,

      finalSavingKey,

    ]

  );

  const movePalette = useCallback(

    async (targetRow) => {

      const sourceId = Number(draggingPaletteId || 0);

      const targetId = Number(targetRow?.saved_palette_id || 0);



      if (

        !sourceId

        || !targetId

        || sourceId === targetId

        || reorderSaving

      ) {

        return;

      }



      const sourceIndex = items.findIndex(

        (row) => Number(row?.saved_palette_id || 0) === sourceId

      );

      const targetIndex = items.findIndex(

        (row) => Number(row?.saved_palette_id || 0) === targetId

      );



      if (sourceIndex < 0 || targetIndex < 0) {

        setDraggingPaletteId(null);

        return;

      }



      const nextItems = [...items];

      const [moved] = nextItems.splice(sourceIndex, 1);

      nextItems.splice(targetIndex, 0, moved);



      setItems(nextItems);

      setDraggingPaletteId(null);

      setReorderSaving(true);

      setError("");



      try {

        await readJson(

          await fetch(REORDER_URL, {

            method: "POST",

            credentials: "include",

            headers: {

              "Content-Type": "application/json",

            },

            body: JSON.stringify({

              project_id: Number(projectId),

              saved_palette_ids: nextItems.map(

                (row) => Number(row?.saved_palette_id || 0)

              ),

            }),

          }),

          "Failed to save palette order"

        );



        await loadPalettes();

      } catch (err) {

        setError(

          err?.message

          || "Failed to save palette order."

        );



        await loadPalettes();

      } finally {

        setReorderSaving(false);

      }

    },

    [

      draggingPaletteId,

      items,

      projectId,

      reorderSaving,

      loadPalettes,

    ]

  );



  const paletteColumns = useMemo(

    () => [

      {

        key: "order",

        label: "",

        sortable: false,

        render: (row) => {

          const savedPaletteId = Number(

            row?.saved_palette_id || 0

          );



          return (

            <span

              role="button"

              tabIndex={0}

              draggable={!reorderSaving && items.length > 1}

              title="Drag to reorder"

              aria-label={`Drag ${paletteLabel(row)} to reorder`}

              onClick={(event) => event.stopPropagation()}

              onDoubleClick={(event) => event.stopPropagation()}

              onDragStart={(event) => {

                event.stopPropagation();

                event.dataTransfer.effectAllowed = "move";

                event.dataTransfer.setData(

                  "text/plain",

                  String(savedPaletteId)

                );

                setDraggingPaletteId(savedPaletteId);

              }}

              onDragOver={(event) => {

                if (

                  !reorderSaving

                  && draggingPaletteId

                  && draggingPaletteId !== savedPaletteId

                ) {

                  event.preventDefault();

                  event.dataTransfer.dropEffect = "move";

                }

              }}

              onDrop={(event) => {

                event.preventDefault();

                event.stopPropagation();

                void movePalette(row);

              }}

              onDragEnd={() => {

                setDraggingPaletteId(null);

              }}

              style={{

                display: "inline-block",

                cursor: reorderSaving ? "default" : "grab",

                fontSize: 18,

                lineHeight: 1,

                userSelect: "none",

                opacity:

                  draggingPaletteId === savedPaletteId

                    ? 0.45

                    : 1,

              }}

            >

              ☰

            </span>

          );

        },

      },

      {

        key: "name",

        label: "Name",

        sortable: false,

        render: (row) => (

          <strong>{paletteLabel(row)}</strong>

        ),

      },

      {

        key: "palette",

        label: "Palette",

        sortable: false,

        render: (row) => (

          <PaletteStrip colors={row?.colors || []} />

        ),

      },

      {

        key: "final",

        label: "Final",

        sortable: false,

        render: (row) => {

          const savedPaletteId = Number(

            row?.saved_palette_id || 0

          );



          return (

            <input

              type="checkbox"

              checked={Number(row?.is_final ?? 0) === 1}

              disabled={finalSavingKey === savedPaletteId}

              aria-label={`Mark ${paletteLabel(row)} final`}

              onClick={(event) => event.stopPropagation()}

              onDoubleClick={(event) => event.stopPropagation()}

              onChange={(event) => {

                event.stopPropagation();

                void setPaletteFinal(

                  row,

                  event.target.checked

                );

              }}

            />

          );

        },

      },

    ],

    [

      draggingPaletteId,

      reorderSaving,

      items.length,

      movePalette,

      finalSavingKey,

      setPaletteFinal,

    ]

  );



  function updateMember(memberKey, patch) {

    setDraftMembers((current) =>

      current.map((member) =>

        member.key === memberKey

          ? { ...member, ...patch }

          : member

      )

    );

  }

  function removeMember(memberKey) {

    setDraftMembers((current) =>

      current.filter((member) => member.key !== memberKey)

    );

  }

  const colorColumns = [

    {

      key: "swatch",

      label: "",

      sortable: false,

      render: (row) => (

        <LargeSwatch color={row.color} />

      ),

    },

    {

      key: "color",

      label: "Color",

      sortable: false,

      render: (row) => (

        <div>

          <div>

            {colorLabel(row.color) || "Unnamed color"}

          </div>

          <label
            style={{
              display: "block",
              marginTop: 8,
            }}
          >

            <span
              style={{
                display: "block",
                marginBottom: 4,
                fontSize: 11,
                fontWeight: 600,
              }}
            >
              Painter's Note
            </span>

            <input
              className="admin-field__control"
              type="text"
              value={row.note || ""}
              placeholder="Special instruction for this color…"
              onClick={(event) => event.stopPropagation()}
              onDoubleClick={(event) => event.stopPropagation()}
              onChange={(event) =>
                updateMember(row.key, {
                  note: event.target.value,
                })
              }
            />

          </label>

        </div>

      ),

    },

    {

      key: "placement",

      label: "Placement",

      sortable: false,

      render: (row) => (

        <input

          className="admin-field__control"

          type="text"

          value={row.role || ""}

          placeholder="walls, fireplace, trim…"

          onClick={(event) => event.stopPropagation()}

          onDoubleClick={(event) => event.stopPropagation()}

          onChange={(event) =>

            updateMember(row.key, {

              role: event.target.value,

            })

          }

        />

      ),

    },

    {

      key: "sheen",

      label: "Sheen",

      sortable: false,

      render: (row) => (

        <input

          className="admin-field__control"

          type="text"

          value={row.sheen || ""}

          placeholder="flat, eggshell, satin…"

          onClick={(event) => event.stopPropagation()}

          onDoubleClick={(event) => event.stopPropagation()}

          onChange={(event) =>

            updateMember(row.key, {

              sheen: event.target.value,

            })

          }

        />

      ),

    },

    {

      key: "remove",

      label: "",

      sortable: false,

      render: (row) => (

        <AdminButton

          type="button"

          variant="danger"

          onClick={(event) => {

            event.stopPropagation();

            removeMember(row.key);

          }}

        >

          ×

        </AdminButton>

      ),

    },

  ];

  function openNewPalette() {

    setSelectedKey(null);

    setDrawerMode("new");

    setDrawerPalette(null);

    setDraftName("");

    setDraftAreaLabel("");

    setDraftNote("");

    setDraftMembers([]);

    setDrawerError("");

    setDrawerStatus("");

    setSavedPaletteChoice("");

    setSavedPalettesError("");

    setSourceSavedPaletteId(null);

    setDrawerOpen(true);

  }

  function openExistingPalette(row) {

    const key = Number(row?.saved_palette_id || 0);

    setSelectedKey(key > 0 ? key : null);

    setDrawerMode("edit");

    setDrawerPalette(row);

    setDraftName(paletteLabel(row));

    setDraftAreaLabel(cleanText(row?.area_label));

    setDraftNote(cleanText(row?.note));

    setDraftMembers(

      (Array.isArray(row?.colors) ? row.colors : [])

        .map(memberFromProjectColor)

    );

    setDrawerError("");

    setDrawerStatus("");

    setDrawerOpen(true);

  }

  function closeDrawer() {

    if (saving) return;

    setDrawerOpen(false);

  }

  function addPickedColor(picked) {

    const color = normalizePickedColor(picked);

    if (!color.id) {

      setDrawerError("That color does not have a valid color ID.");

      return;

    }

    setDrawerError("");

    setDrawerStatus("");

    setDraftMembers((current) => {

      if (

        current.some(

          (member) => Number(member?.color?.id || 0) === color.id

        )

      ) {

        return current;

      }

      return [

        ...current,

        {

          key: `new-${color.id}-${Date.now()}`,

          color,

          role: "",

          sheen: "",

          note: "",

        },

      ];

    });

  }

  function buildImportedMembers(colors, sourceLabel) {

    const seen = new Set();

    return (Array.isArray(colors) ? colors : [])

      .map((item, index) => {

        const sourceColor =

          item?.color

          ?? item;

        const color = normalizePickedColor(sourceColor);

        const colorId = Number(color?.id || 0);

        if (!colorId || seen.has(colorId)) {

          return null;

        }

        seen.add(colorId);

        return {

          key: `import-${sourceLabel}-${colorId}-${index}`,

          color,

          role: cleanText(

            item?.role

            ?? item?.role_name

          ),

          sheen: cleanText(item?.sheen),

          note: cleanText(item?.note),

        };

      })

      .filter(Boolean);

  }

  function loadCurrentMyPalette() {

    const imported = buildImportedMembers(

      myPalette,

      "mypalette"

    );

    if (!imported.length) {

      setDrawerError("MyPalette is empty.");

      setDrawerStatus("");

      return;

    }

    setDraftMembers(imported);

    setSourceSavedPaletteId(null);

    setSavedPaletteChoice("");

    setDrawerError("");

    setDrawerStatus(

      `Loaded ${imported.length} color${imported.length === 1 ? "" : "s"} from MyPalette.`

    );

  }

  function loadSelectedSavedPalette() {

    const savedPaletteId = Number(savedPaletteChoice || 0);

    if (!savedPaletteId) {

      setDrawerError("Choose a saved palette first.");

      setDrawerStatus("");

      return;

    }

    const source = savedPaletteOptions.find(

      (palette) => Number(palette?.id || 0) === savedPaletteId

    );

    if (!source) {

      setDrawerError("That saved palette could not be found.");

      setDrawerStatus("");

      return;

    }

    const imported = buildImportedMembers(

      source?.members,

      `saved-${savedPaletteId}`

    );

    if (!imported.length) {

      setDrawerError("That saved palette has no colors.");

      setDrawerStatus("");

      return;

    }

    const sourceName =

      cleanText(

        source?.nickname

        || source?.display_title

      )

      || `Palette #${savedPaletteId}`;

    const projectSuffix =

      cleanText(projectName)

      || `Project ${projectId}`;

    setDraftMembers(imported);

    setSourceSavedPaletteId(savedPaletteId);

    if (!cleanText(draftName)) {

      setDraftName(`${sourceName} — ${projectSuffix}`);

    }

    setDrawerError("");

    setDrawerStatus(

      `Loaded ${imported.length} color${imported.length === 1 ? "" : "s"} from ${sourceName}.`

    );

  }

  async function linkPalette(savedPaletteId, note, areaLabel) {

    await readJson(

      await fetch(LINK_URL, {

        method: "POST",

        credentials: "include",

        headers: {

          "Content-Type": "application/json",

        },

        body: JSON.stringify({

          project_id: Number(projectId),

          saved_palette_id: Number(savedPaletteId),

          note: cleanText(note) || null,

        area_label: cleanText(areaLabel) || null,

        }),

      }),

      "Failed to link palette to project"

    );

  }

  async function savePalette() {

    const name = cleanText(draftName);

    if (!name) {

      setDrawerError("Enter a palette name.");

      return;

    }

    if (!draftMembers.length) {

      setDrawerError("Add at least one color.");

      return;

    }

    if (saving) return;

    setSaving(true);

    setDrawerError("");

    setDrawerStatus("");

    try {

      const paletteId =

        drawerMode === "edit"

          ? Number(drawerPalette?.saved_palette_id || 0)

          : null;

      const payload = {

        id: paletteId || null,

        nickname: name,

        palette_type:

          cleanText(drawerPalette?.palette_type)

          || "exterior",

        is_public:

          drawerMode === "edit"

            ? Number(drawerPalette?.is_public ?? 0) === 1

            : false,

        private_notes: null,

        members: draftMembers.map((member, index) => ({

          color_id: Number(member?.color?.id || 0),

          role: cleanText(member?.role) || null,

          sheen: cleanText(member?.sheen) || null,

          note: cleanText(member?.note) || null,

          order_index: index,

        })),

      };

      // New Project palettes are always independent copies.

      // sourceSavedPaletteId is deliberately NOT sent as `id`,

      // so importing a Saved Palette can never overwrite the source.

      if (drawerMode === "new" && sourceSavedPaletteId) {

        payload.id = null;

        payload.is_public = false;

      }

      const saveData = await readJson(

        await fetch(SAVE_PALETTE_URL, {

          method: "POST",

          credentials: "include",

          headers: {

            "Content-Type": "application/json",

          },

          body: JSON.stringify(payload),

        }),

        "Failed to save palette"

      );

      const savedId = Number(saveData?.item?.id || 0);

      if (!savedId) {

        throw new Error("Palette save did not return an ID.");

      }

      await linkPalette(savedId, draftNote, draftAreaLabel);

      const refreshed = await loadPalettes();

      const savedRow = refreshed.find(

        (row) => Number(row?.saved_palette_id || 0) === savedId

      );

      setSelectedKey(savedId);

      setDrawerMode("edit");

      setDrawerPalette(savedRow || {

        saved_palette_id: savedId,

        nickname: name,

        palette_type: payload.palette_type,

        is_public: payload.is_public ? 1 : 0,

        note: cleanText(draftNote) || null,

      area_label: cleanText(draftAreaLabel) || null,

        colors: draftMembers.map((member, index) => ({

          member_id: member.key,

          color_id: member.color.id,

          role: member.role,

          sheen: member.sheen,

          note: member.note,

          order_index: index,

          color_name: member.color.name,

          color_brand: member.color.brand,

          color_brand_name: member.color.brand_name,

          color_code: member.color.code,

          color_hex6: member.color.hex6,

        })),

      });

      setDrawerStatus("Saved.");

      setSourceSavedPaletteId(null);

      setSavedPaletteChoice("");

      setDrawerOpen(false);

    } catch (err) {

      setDrawerError(

        err?.message

        || "Failed to save palette."

      );

    } finally {

      setSaving(false);

    }

  }

  async function removeFromProject() {

    const savedPaletteId = Number(

      drawerPalette?.saved_palette_id || 0

    );

    if (!savedPaletteId || saving) return;

    if (

      !window.confirm(

        `Remove "${paletteLabel(drawerPalette)}" from this project?`

      )

    ) {

      return;

    }

    setSaving(true);

    setDrawerError("");

    setDrawerStatus("");

    try {

      await readJson(

        await fetch(UNLINK_URL, {

          method: "POST",

          credentials: "include",

          headers: {

            "Content-Type": "application/json",

          },

          body: JSON.stringify({

            project_id: Number(projectId),

            saved_palette_id: savedPaletteId,

          }),

        }),

        "Failed to remove palette from project"

      );

      setSelectedKey(null);

      setDrawerOpen(false);

      await loadPalettes();

    } catch (err) {

      setDrawerError(

        err?.message

        || "Failed to remove palette from project."

      );

    } finally {

      setSaving(false);

    }

  }

  const detailActions = (

    <>

      <AdminButton

        type="button"

        onClick={openNewPalette}

      >

        New Palette

      </AdminButton>

      <AdminMetaText as="div">

        {items.length} palette{items.length === 1 ? "" : "s"}

      </AdminMetaText>

    </>

  );

  return (

    <>

      <AdminDetailPane

        ariaLabel="Project palettes"

        title={`${cleanText(projectName) || `Project #${projectId}`} Palettes`}

        actions={detailActions}

      >

        {error ? (

          <AdminNotice variant="danger">

            {error}

          </AdminNotice>

        ) : null}

        <AdminSmartGrid

          items={items}

          columns={paletteColumns}

          getRowKey={(row) => Number(row?.saved_palette_id)}

          selectedKey={selectedKey}

          onSelectionChange={(row, key) =>

            setSelectedKey(

              key

              ?? row?.saved_palette_id

              ?? null

            )

          }

          onRowDoubleClick={openExistingPalette}

          defaultSortKey=""

          ariaLabel="Project palettes"

          verticalAlign="middle"

        />

        {loading && !items.length ? (

          <AdminEmptyState

            title="Palettes"

            message="Loading project palettes..."

          />

        ) : null}

        {!loading && !error && !items.length ? (

          <AdminEmptyState

            title="No palettes yet"

            message="Click New Palette to create the first palette for this project."

          />

        ) : null}

      </AdminDetailPane>

      <AdminWorkbenchDrawer

        open={drawerOpen}

        width={720}

        title={

          drawerMode === "new"

            ? "New Palette"

            : paletteLabel(drawerPalette)

        }

        onClose={closeDrawer}

        portal

        padded

      >

        <AdminStack gap="md">

          {drawerError ? (

            <AdminNotice variant="danger">

              {drawerError}

            </AdminNotice>

          ) : null}

          {drawerStatus ? (

            <AdminNotice>

              {drawerStatus}

            </AdminNotice>

          ) : null}

          {drawerMode === "new" ? (

            <>

              <AdminToolbar>

                <AdminButton

                  type="button"

                  variant="secondary"

                  disabled={

                    saving

                    || !(Array.isArray(myPalette) && myPalette.length)

                  }

                  onClick={loadCurrentMyPalette}

                >

                  Load MyPalette

                </AdminButton>

                <AdminToolbarSpacer />

                <select

                  className="admin-field__control"

                  value={savedPaletteChoice}

                  disabled={saving || savedPalettesLoading}

                  aria-label="Saved palette"

                  onChange={(event) => {

                    setSavedPaletteChoice(event.target.value);

                    setDrawerError("");

                    setDrawerStatus("");

                  }}

                >

                  <option value="">

                    {savedPalettesLoading

                      ? "Loading saved palettes..."

                      : "Choose Saved Palette"}

                  </option>

                  {savedPaletteOptions.map((palette) => (

                    <option

                      key={palette.id}

                      value={palette.id}

                    >

                      {cleanText(

                        palette?.nickname

                        || palette?.display_title

                      ) || `Palette #${palette.id}`}

                    </option>

                  ))}

                </select>

                <AdminButton

                  type="button"

                  variant="secondary"

                  disabled={

                    saving

                    || savedPalettesLoading

                    || !savedPaletteChoice

                  }

                  onClick={loadSelectedSavedPalette}

                >

                  Load Saved Palette

                </AdminButton>

              </AdminToolbar>

              {savedPalettesError ? (

                <AdminNotice variant="danger">

                  {savedPalettesError}

                </AdminNotice>

              ) : null}

            </>

          ) : null}

          <AdminField label="Palette Name" compact>

            <input

              className="admin-field__control"

              type="text"

              value={draftName}

              placeholder="Wine Room Teal"

              onChange={(event) => {

                setDraftName(event.target.value);

                setDrawerStatus("");

              }}

            />

          </AdminField>

          <AdminField label="Area / Room" compact>

          <input

            className="admin-field__control"

            type="text"

            value={draftAreaLabel}

            placeholder="Living / Dining Room"

            onChange={(event) => {

              setDraftAreaLabel(event.target.value);

              setDrawerStatus("");

            }}

          />

        </AdminField>

        <AdminField label="Project Note" compact>

            <textarea

              className="admin-field__control"

              rows={3}

              value={draftNote}

              placeholder="Other colors to try, ideas, reminders…"

              onChange={(event) => {

                setDraftNote(event.target.value);

                setDrawerStatus("");

              }}

            />

          </AdminField>

          <FuzzySearchColorSelect

            className="full-width"

            autoFocus={false}

            preventAutoFocus

            onSelect={addPickedColor}

          />

          {draftMembers.length ? (

            <div style={{ width: "100%", overflowX: "auto" }}>
              <table
                style={{
                  width: "100%",
                  borderCollapse: "collapse",
                  tableLayout: "fixed",
                }}
              >
                <thead>
                  <tr>
                    <th style={{ width: 68 }} />
                    <th
                      style={{
                        textAlign: "left",
                        padding: "8px",
                        fontSize: 12,
                      }}
                    >
                      COLOR
                    </th>
                    <th
                      style={{
                        width: 190,
                        textAlign: "left",
                        padding: "8px",
                        fontSize: 12,
                      }}
                    >
                      PLACEMENT
                    </th>
                    <th
                      style={{
                        width: 180,
                        textAlign: "left",
                        padding: "8px",
                        fontSize: 12,
                      }}
                    >
                      SHEEN
                    </th>
                    <th style={{ width: 52 }} />
                  </tr>
                </thead>

                <tbody>
                  {draftMembers.map((member) => (
                    <Fragment key={member.key}>
                      <tr>
                        <td style={{ padding: "10px 8px", verticalAlign: "middle" }}>
                          <LargeSwatch color={member.color} />
                        </td>

                        <td style={{ padding: "10px 8px", verticalAlign: "middle" }}>
                          {colorLabel(member.color) || "Unnamed color"}
                        </td>

                        <td style={{ padding: "10px 8px", verticalAlign: "middle" }}>
                          <input
                            className="admin-field__control"
                            type="text"
                            value={member.role || ""}
                            placeholder="walls, fireplace, trim…"
                            onChange={(event) =>
                              updateMember(member.key, {
                                role: event.target.value,
                              })
                            }
                          />
                        </td>

                        <td style={{ padding: "10px 8px", verticalAlign: "middle" }}>
                          <input
                            className="admin-field__control"
                            type="text"
                            value={member.sheen || ""}
                            placeholder="flat, eggshell, satin…"
                            onChange={(event) =>
                              updateMember(member.key, {
                                sheen: event.target.value,
                              })
                            }
                          />
                        </td>

                        <td style={{ padding: "10px 8px", verticalAlign: "middle" }}>
                          <AdminButton
                            type="button"
                            variant="danger"
                            onClick={() => removeMember(member.key)}
                          >
                            ×
                          </AdminButton>
                        </td>
                      </tr>

                      <tr>
                        <td colSpan={5} style={{ padding: "0 8px 12px 8px" }}>
                          <label
                            style={{
                              display: "grid",
                              gridTemplateColumns: "auto minmax(0, 1fr)",
                              alignItems: "center",
                              gap: 8,
                              width: "100%",
                            }}
                          >
                            <span
                              style={{
                                fontSize: 12,
                                fontWeight: 600,
                                whiteSpace: "nowrap",
                              }}
                            >
                              Painter's Note:
                            </span>

                            <input
                              className="admin-field__control"
                              type="text"
                              value={member.note || ""}
                              placeholder="Special instruction for this color…"
                              style={{ width: "100%" }}
                              onChange={(event) =>
                                updateMember(member.key, {
                                  note: event.target.value,
                                })
                              }
                            />
                          </label>
                        </td>
                      </tr>
                    </Fragment>
                  ))}
                </tbody>
              </table>
            </div>

          ) : (

            <AdminEmptyState

              title="No colors yet"

              message="Use Add Color to build this palette."

            />

          )}

          <AdminToolbar>

            <AdminToolbarSpacer />

            <AdminButton

              type="button"

              variant="secondary"

              disabled={saving}

              onClick={closeDrawer}

            >

              Cancel

            </AdminButton>

            <AdminButton

              type="button"

              disabled={

                saving

                || !cleanText(draftName)

                || !draftMembers.length

              }

              onClick={savePalette}

            >

              {saving ? "Saving..." : "Save & Close"}

            </AdminButton>

          </AdminToolbar>

          {drawerMode === "edit" ? (

            <>

              <div

                aria-hidden="true"

                style={{

                  minHeight: 260,

                }}

              />

              <AdminPanel

                title="Danger Zone"

                compact

              >

                <AdminButton

                  type="button"

                  variant="danger"

                  disabled={saving}

                  onClick={removeFromProject}

                >

                  Remove from Project

                </AdminButton>

              </AdminPanel>

            </>

          ) : null}

        </AdminStack>

      </AdminWorkbenchDrawer>

    </>

  );

}
