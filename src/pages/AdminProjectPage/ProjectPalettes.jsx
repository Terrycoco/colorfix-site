import {
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
  AdminSmartGrid,
  AdminStack,
  AdminToolbar,
  AdminToolbarSpacer,
  AdminWorkbenchDrawer,
} from "@components/AdminLayout";

import FuzzySearchColorSelect from "@components/FuzzySearchColorSelect";
import { API_FOLDER } from "@helpers/config";

const LIST_URL =
  `${API_FOLDER}/v2/admin/projects/palettes/list.php`;
const LINK_URL =
  `${API_FOLDER}/v2/admin/projects/palettes/link.php`;
const UNLINK_URL =
  `${API_FOLDER}/v2/admin/projects/palettes/unlink.php`;
const SAVE_PALETTE_URL =
  `${API_FOLDER}/v2/admin/palettes/save.php`;

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

  const brand = cleanText(
    color?.brand_name
    || color?.color_brand_name
    || color?.brand
    || color?.color_brand
  );

  const code = cleanText(
    color?.code
    || color?.color_code
  );

  return [name, brand, code]
    .filter(Boolean)
    .join(" · ");
}

function normalizePickedColor(color) {
  const id = Number(
    color?.id
    || color?.color_id
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
}) {
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [selectedKey, setSelectedKey] = useState(null);

  const [drawerOpen, setDrawerOpen] = useState(false);
  const [drawerMode, setDrawerMode] = useState("new");
  const [drawerPalette, setDrawerPalette] = useState(null);
  const [draftName, setDraftName] = useState("");
  const [draftNote, setDraftNote] = useState("");
  const [draftMembers, setDraftMembers] = useState([]);
  const [colorPickerKey, setColorPickerKey] = useState(0);
  const [saving, setSaving] = useState(false);
  const [drawerError, setDrawerError] = useState("");
  const [drawerStatus, setDrawerStatus] = useState("");

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

  useEffect(() => {
    setSelectedKey(null);
    setDrawerOpen(false);
    setDrawerPalette(null);
    setDraftName("");
    setDraftNote("");
    setDraftMembers([]);
    setColorPickerKey((current) => current + 1);
    setDrawerError("");
    setDrawerStatus("");
  }, [projectId]);

  const paletteColumns = useMemo(
    () => [
      {
        key: "name",
        label: "Name",
        sortValue: paletteLabel,
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
    ],
    []
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
          {colorLabel(row.color) || "Unnamed color"}
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
    setDraftNote("");
    setDraftMembers([]);
    setDrawerError("");
    setDrawerStatus("");
    setDrawerOpen(true);
  }

  function openExistingPalette(row) {
    const key = Number(row?.saved_palette_id || 0);

    setSelectedKey(key > 0 ? key : null);
    setDrawerMode("edit");
    setDrawerPalette(row);
    setDraftName(paletteLabel(row));
    setDraftNote(cleanText(row?.note));
    setDraftMembers(
      (Array.isArray(row?.colors) ? row.colors : [])
        .map(memberFromProjectColor)
    );
    setColorPickerKey((current) => current + 1);
    setDrawerError("");
    setDrawerStatus("");
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
        },
      ];
    });

    setColorPickerKey((current) => current + 1);
  }

  async function linkPalette(savedPaletteId, note) {
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
          sheen: null,
          note: null,
          order_index: index,
        })),
      };

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

      await linkPalette(savedId, draftNote);

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
        colors: draftMembers.map((member, index) => ({
          member_id: member.key,
          color_id: member.color.id,
          role: member.role,
          order_index: index,
          color_name: member.color.name,
          color_brand: member.color.brand,
          color_brand_name: member.color.brand_name,
          color_code: member.color.code,
          color_hex6: member.color.hex6,
        })),
      });
      setDrawerStatus("Saved.");
    } catch (err) {
      setDrawerError(
        err?.message
        || "Failed to save palette."
      );
    } finally {
      setSaving(false);
    }
  }

  async function removeFromProject(close = null) {
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

      if (typeof close === "function") {
        close();
      } else {
        setDrawerOpen(false);
      }

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

  function renderPaletteEditor(close) {
    return (
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
          key={colorPickerKey}
          className="full-width"
          autoFocus={false}
          preventAutoFocus
          onSelect={addPickedColor}
        />

        {draftMembers.length ? (
          <AdminSmartGrid
            items={draftMembers}
            columns={colorColumns}
            getRowKey={(row) => row.key}
            ariaLabel="Palette colors"
            verticalAlign="middle"
          />
        ) : (
          <AdminEmptyState
            title="No colors yet"
            message="Use Enter A Color to build this palette."
          />
        )}

        <AdminToolbar>
          {drawerMode === "edit" ? (
            <AdminButton
              type="button"
              variant="danger"
              disabled={saving}
              onClick={() => removeFromProject(close)}
            >
              Remove from Project
            </AdminButton>
          ) : null}

          <AdminToolbarSpacer />

          <AdminButton
            type="button"
            variant="secondary"
            disabled={saving}
            onClick={close}
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
            {saving ? "Saving..." : "Save Palette"}
          </AdminButton>
        </AdminToolbar>
      </AdminStack>
    );
  }

  const projectPaletteActions = (
    <AdminToolbar>
      <AdminButton
        type="button"
        onClick={openNewPalette}
      >
        New Palette
      </AdminButton>

      <AdminToolbarSpacer />

      <AdminMetaText as="span">
        {items.length} palette{items.length === 1 ? "" : "s"}
      </AdminMetaText>
    </AdminToolbar>
  );

  return (
    <>
      <AdminDetailPane
        ariaLabel="Project palettes"
        title="Palettes"
        actions={projectPaletteActions}
      >
        <AdminStack gap="sm">
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
            onSelectionChange={(row, key) => {
              setSelectedKey(
                key
                ?? row?.saved_palette_id
                ?? null
              );

              if (row) {
                openExistingPalette(row);
              }
            }}
            drawer={{
              title: (row) => paletteLabel(row),
              width: 720,
              padded: true,
              portal: true,
              render: ({ item, close }) =>
                renderPaletteEditor(close),
            }}
            defaultSortKey="name"
            defaultSortDirection="asc"
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
        </AdminStack>
      </AdminDetailPane>

      <AdminWorkbenchDrawer
        open={drawerOpen}
        width={720}
        title="New Palette"
        onClose={closeDrawer}
        portal
        padded
      >
        {renderPaletteEditor(closeDrawer)}
      </AdminWorkbenchDrawer>
    </>
  );

}
