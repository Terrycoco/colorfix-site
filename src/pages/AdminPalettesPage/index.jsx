import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from "react";

import { API_FOLDER } from "@helpers/config";
import {
  AdminButton,
  AdminDetailPane,
  AdminEmptyState,
  AdminListPane,
  AdminMasterDetail,
} from "@components/AdminLayout";

import PaletteDetail from "./PaletteDetail";
import PaletteFilters from "./PaletteFilters";
import PaletteList from "./PaletteList";
import PalettePhotosDrawer from "./PalettePhotosDrawer";
import "./admin-palettes.css";

const NEUTRAL_FAMILIES = new Set([
  "white",
  "black",
  "gray",
  "grey",
  "greige",
  "beige",
  "brown",
]);

function cleanText(value) {
  return String(value ?? "").trim();
}

function withReturnTo(url, returnTo) {
  const rawUrl = String(url || "").trim();

  if (!rawUrl) return "";

  try {
    const parsed = new URL(
      rawUrl,
      window.location.origin
    );

    parsed.searchParams.set(
      "return_to",
      returnTo
    );

    if (
      parsed.origin === window.location.origin
    ) {
      return `${parsed.pathname}${parsed.search}${parsed.hash}`;
    }

    return parsed.toString();
  } catch {
    const separator = rawUrl.includes("?")
      ? "&"
      : "?";

    return `${rawUrl}${separator}return_to=${encodeURIComponent(returnTo)}`;
  }
}

function normalizeCategoryText(value) {
  return cleanText(value).toLowerCase();
}

function paletteMatchesFamily(
  palette,
  family
) {
  if (!family) return true;

  const wanted =
    family === "gray"
      ? ["gray", "grey"]
      : [family];

  const members = Array.isArray(
    palette?.members
  )
    ? palette.members
    : [];

  const neutralFamily =
    NEUTRAL_FAMILIES.has(family);

  return members.some((member) => {
    const hueCats = normalizeCategoryText(
      member?.color_hue_cats
      ?? member?.hue_cats
      ?? member?.color?.hue_cats
    );

    const neutralCats =
      normalizeCategoryText(
        member?.color_neutral_cats
        ?? member?.neutral_cats
        ?? member?.color?.neutral_cats
      );

    if (neutralFamily) {
      return wanted.some((token) =>
        neutralCats.includes(token)
      );
    }

    if (neutralCats !== "") {
      return false;
    }

    return wanted.some((token) =>
      hueCats.includes(token)
    );
  });
}

function paletteMatchesQuery(
  palette,
  query
) {
  const needle =
    cleanText(query).toLowerCase();

  if (!needle) return true;

  const members = Array.isArray(
    palette?.members
  )
    ? palette.members
    : [];

  const haystack = [
    palette?.nickname,
    palette?.display_title,
    palette?.palette_type,
    ...members.flatMap((member) => [
      member?.color_name,
      member?.color_code,
      member?.role,
      member?.sheen,
    ]),
  ]
    .map((value) =>
      cleanText(value).toLowerCase()
    )
    .filter(Boolean)
    .join(" ");

  return haystack.includes(needle);
}

async function readJson(
  response,
  fallbackMessage
) {
  const text = await response.text();
  let data = {};

  try {
    data = text.trim()
      ? JSON.parse(text)
      : {};
  } catch {
    throw new Error(
      `${fallbackMessage}: invalid JSON response`
    );
  }

  if (!response.ok || data?.ok === false) {
    throw new Error(
      data?.error
      || `HTTP ${response.status}`
    );
  }

  return data;
}

export default function AdminPalettesPage() {
  const [palettes, setPalettes] = useState([]);
  const [
    selectedPaletteId,
    setSelectedPaletteId,
  ] = useState(null);

  const [
    selectedDetail,
    setSelectedDetail,
  ] = useState(null);

  const [mode, setMode] = useState("empty");
  const [loading, setLoading] = useState(true);
  const [
    detailLoading,
    setDetailLoading,
  ] = useState(false);

  const [loadError, setLoadError] = useState("");
  const [
    actionError,
    setActionError,
  ] = useState("");

  const [saving, setSaving] = useState(false);

  const [
    detailDirty,
    setDetailDirty,
  ] = useState(false);

  const [
    photosDirty,
    setPhotosDirty,
  ] = useState(false);

  const [
    editorValue,
    setEditorValue,
  ] = useState(null);

  const photosRef = useRef(null);

  const [query, setQuery] = useState("");
  const [
    visibility,
    setVisibility,
  ] = useState("all");

  const [
    paletteType,
    setPaletteType,
  ] = useState("");

  const [
    colorFamily,
    setColorFamily,
  ] = useState("");

  const loadPalettes = useCallback(
    async () => {
      setLoading(true);
      setLoadError("");

      try {
        const response = await fetch(
          `${API_FOLDER}/v2/admin/palettes/list.php?limit=500&_=${Date.now()}`,
          {
            credentials: "include",
            cache: "no-store",
          }
        );

        const data = await readJson(
          response,
          "Failed to load palettes"
        );

        const items = Array.isArray(data?.items)
          ? data.items
          : [];

        setPalettes(items);

        return items;
      } catch (error) {
        setPalettes([]);
        setLoadError(
          error?.message
          || "Failed to load palettes."
        );

        return [];
      } finally {
        setLoading(false);
      }
    },
    []
  );

  useEffect(() => {
    loadPalettes();
  }, [loadPalettes]);

  async function loadPaletteDetail(
    paletteId
  ) {
    const id = Number(paletteId);

    if (!id) {
      setSelectedDetail(null);
      return null;
    }

    setDetailLoading(true);
    setActionError("");

    try {
      const data = await readJson(
        await fetch(
          `${API_FOLDER}/v2/admin/palettes/detail.php?id=${encodeURIComponent(id)}&_=${Date.now()}`,
          {
            credentials: "include",
            cache: "no-store",
          }
        ),
        "Failed to load palette detail"
      );

      const item = data?.item || null;

      setSelectedDetail(item);

      return item;
    } catch (error) {
      setSelectedDetail(null);
      setActionError(
        error?.message
        || "Failed to load palette detail."
      );

      return null;
    } finally {
      setDetailLoading(false);
    }
  }

  const paletteTypes = useMemo(
    () =>
      Array.from(
        new Set(
          palettes
            .map((palette) =>
              cleanText(
                palette?.palette_type
              ).toLowerCase()
            )
            .filter(Boolean)
        )
      ).sort((a, b) =>
        a.localeCompare(b)
      ),
    [palettes]
  );

  const filteredPalettes = useMemo(
    () =>
      palettes.filter((palette) => {
        if (
          !paletteMatchesQuery(
            palette,
            query
          )
        ) {
          return false;
        }

        const isPublic =
          Number(
            palette?.is_public ?? 0
          ) === 1;

        if (
          visibility === "public"
          && !isPublic
        ) {
          return false;
        }

        if (
          visibility === "private"
          && isPublic
        ) {
          return false;
        }

        if (
          paletteType
          && cleanText(
            palette?.palette_type
          ).toLowerCase()
            !== paletteType
        ) {
          return false;
        }

        return paletteMatchesFamily(
          palette,
          colorFamily
        );
      }),
    [
      palettes,
      query,
      visibility,
      paletteType,
      colorFamily,
    ]
  );

  const selectedListPalette = useMemo(
    () =>
      palettes.find(
        (palette) =>
          Number(palette?.id)
          === Number(selectedPaletteId)
      ) ?? null,
    [palettes, selectedPaletteId]
  );

  const selectedPalette =
    mode === "edit"
      ? (
          selectedDetail
          ?? selectedListPalette
        )
      : null;

  function beginNewPalette() {
    setSelectedPaletteId(null);
    setSelectedDetail(null);
    setEditorValue(null);
    setMode("new");
    setDetailDirty(false);
    setPhotosDirty(false);
    setActionError("");
  }

  function selectPalette(paletteId) {
    const id = Number(paletteId);

    setSelectedPaletteId(id);
    setSelectedDetail(null);
    setEditorValue(null);
    setMode("edit");
    setDetailDirty(false);
    setPhotosDirty(false);
    setActionError("");

    loadPaletteDetail(id);
  }

  function clearFilters() {
    setQuery("");
    setVisibility("all");
    setPaletteType("");
    setColorFamily("");
  }

  async function handleSave() {
    if (
      saving
      || !editorValue
      || !cleanText(editorValue.nickname)
    ) {
      return;
    }

    setSaving(true);
    setActionError("");

    try {
      if (
        photosDirty
        && photosRef.current?.save
      ) {
        const photoSaved =
          await photosRef.current.save();

        if (photoSaved === false) {
          throw new Error(
            "Photos were not saved."
          );
        }
      }

      const payload = {
        ...editorValue,
        id:
          mode === "edit"
            ? Number(selectedPaletteId)
            : null,
      };

      const data = await readJson(
        await fetch(
          `${API_FOLDER}/v2/admin/palettes/save.php`,
          {
            method: "POST",
            credentials: "include",
            headers: {
              "Content-Type":
                "application/json",
            },
            body: JSON.stringify(payload),
          }
        ),
        "Failed to save palette"
      );

      const saved = data?.item;

      if (!saved?.id) {
        throw new Error(
          "Save did not return a palette ID."
        );
      }

      setSelectedPaletteId(
        Number(saved.id)
      );

      setSelectedDetail(saved);
      setMode("edit");
      setDetailDirty(false);
      setPhotosDirty(false);

      await loadPalettes();
    } catch (error) {
      setActionError(
        error?.message
        || "Failed to save palette."
      );
    } finally {
      setSaving(false);
    }
  }

  async function openPV() {
    const viewerId = Number(
      selectedPalette?.palette_viewer_id
      || 0
    );

    if (!viewerId) {
      setActionError(
        "This palette does not have a PV yet."
      );
      return;
    }

    setActionError("");

    try {
      const response = await fetch(
        `${API_FOLDER}/v2/admin/palette-viewers.php?id=${encodeURIComponent(viewerId)}&_=${Date.now()}`,
        {
          credentials: "include",
          cache: "no-store",
        }
      );

      const data = await readJson(
        response,
        "Failed to load PV"
      );

      const rexUrl =
        data?.item?.rex?.public_url
        || data?.item?.rex?.url
        || data?.item?.rex?.href
        || "";

      if (!rexUrl) {
        throw new Error(
          "This PV does not currently have a REX URL."
        );
      }

      window.location.assign(
        withReturnTo(
          rexUrl,
          "/admin/palettes"
        )
      );
    } catch (error) {
      setActionError(
        error?.message
        || "Could not open PV."
      );
    }
  }

  const isDirty =
    detailDirty || photosDirty;

  const detailValid =
    cleanText(editorValue?.nickname) !== "";

  const detailTitle =
    mode === "new"
      ? "New Palette"
      : selectedPalette?.nickname
        || "Palette";

  return (
    <AdminMasterDetail
      selectedId={selectedPaletteId}
      drawer={
        <PalettePhotosDrawer
          ref={photosRef}
          palette={selectedPalette}
          onDirtyChange={setPhotosDirty}
          onChanged={() => {
            if (selectedPaletteId) {
              loadPaletteDetail(
                selectedPaletteId
              );
              loadPalettes();
            }
          }}
        />
      }
      storageKey="admin-palettes-list-width"
      defaultListWidth={320}
      minListWidth={260}
      maxListWidth={460}
      list={
        <AdminListPane
          title="Palettes"
          actions={
            <AdminButton
              type="button"
              onClick={beginNewPalette}
            >
              New
            </AdminButton>
          }
        >
          <PaletteFilters
            query={query}
            onQueryChange={setQuery}
            visibility={visibility}
            onVisibilityChange={
              setVisibility
            }
            paletteType={paletteType}
            onPaletteTypeChange={
              setPaletteType
            }
            colorFamily={colorFamily}
            onColorFamilyChange={
              setColorFamily
            }
            paletteTypes={paletteTypes}
            resultCount={
              filteredPalettes.length
            }
            totalCount={palettes.length}
            onClear={clearFilters}
          />

          {loadError ? (
            <AdminEmptyState
              title="Could not load palettes"
              message={loadError}
            />
          ) : (
            <PaletteList
              palettes={filteredPalettes}
              selectedId={selectedPaletteId}
              onSelect={selectPalette}
              loading={loading}
              emptyMessage="No palettes match those filters."
            />
          )}
        </AdminListPane>
      }
      detail={
        <AdminDetailPane
          ariaLabel="Palette detail"
          title={
            mode === "empty"
              ? ""
              : detailTitle
          }
          actions={
            mode === "empty"
              ? null
              : (
                <>
                  <AdminButton
                    type="button"
                    onClick={handleSave}
                    disabled={
                      !isDirty
                      || !detailValid
                      || saving
                    }
                  >
                    {saving
                      ? "Saving…"
                      : "Save"}
                  </AdminButton>

                  {mode === "edit" ? (
                    <AdminButton
                      type="button"
                      onClick={openPV}
                    >
                      Open PV
                    </AdminButton>
                  ) : null}

                  {mode === "edit" ? (
                    <AdminButton
                      type="button"
                      disabled
                    >
                      Delete
                    </AdminButton>
                  ) : null}
                </>
              )
          }
        >
          {actionError ? (
            <div className="admin-palette-detail__viewer-error">
              {actionError}
            </div>
          ) : null}

          {mode === "new" ? (
            <PaletteDetail
              mode="new"
              palette={null}
              onDirtyChange={
                setDetailDirty
              }
              onValueChange={
                setEditorValue
              }
            />
          ) : detailLoading ? (
            <AdminEmptyState
              title="Loading palette"
              message="Loading the current Palette and Public PV copy."
            />
          ) : selectedPalette ? (
            <PaletteDetail
              mode="edit"
              palette={selectedPalette}
              onDirtyChange={
                setDetailDirty
              }
              onValueChange={
                setEditorValue
              }
            />
          ) : (
            <AdminEmptyState
              title="Select a palette"
              message="Choose a palette on the left, or click New."
            />
          )}
        </AdminDetailPane>
      }
    />
  );
}
