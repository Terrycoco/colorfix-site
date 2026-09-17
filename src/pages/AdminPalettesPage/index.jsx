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
  AdminDialog,
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
    saveAsNewOpen,
    setSaveAsNewOpen,
  ] = useState(false);

  const [
    saveAsNewName,
    setSaveAsNewName,
  ] = useState("");

  const [
    saveAsNewError,
    setSaveAsNewError,
  ] = useState("");

  const [
    saveAsNewCopyPhotos,
    setSaveAsNewCopyPhotos,
  ] = useState(true);

  const [
    saveAsNewNameStatus,
    setSaveAsNewNameStatus,
  ] = useState("idle");

  const [
    saveAsNewSaving,
    setSaveAsNewSaving,
  ] = useState(false);

  const [
    deleteOpen,
    setDeleteOpen,
  ] = useState(false);

  const [
    deleting,
    setDeleting,
  ] = useState(false);

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

  useEffect(() => {
    if (!saveAsNewOpen) {
      return undefined;
    }

    const nickname = cleanText(saveAsNewName);

    if (!nickname) {
      setSaveAsNewNameStatus("idle");
      return undefined;
    }

    setSaveAsNewNameStatus("checking");
    setSaveAsNewError("");

    const timer = window.setTimeout(
      async () => {
        try {
          const data = await readJson(
            await fetch(
              `${API_FOLDER}/v2/admin/palettes/check-name.php?nickname=${encodeURIComponent(nickname)}&_=${Date.now()}`,
              {
                credentials: "include",
                cache: "no-store",
              }
            ),
            "Failed to check Internal Palette Name"
          );

          setSaveAsNewNameStatus(
            data?.available
              ? "available"
              : "duplicate"
          );
        } catch (error) {
          setSaveAsNewNameStatus("error");
          setSaveAsNewError(
            error?.message
            || "Could not check that name."
          );
        }
      },
      300
    );

    return () => {
      window.clearTimeout(timer);
    };
  }, [saveAsNewOpen, saveAsNewName]);

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

  function openSaveAsNewDialog() {
    setSaveAsNewName("");
    setSaveAsNewError("");
    setSaveAsNewCopyPhotos(true);
    setSaveAsNewNameStatus("idle");
    setSaveAsNewOpen(true);
  }

  function closeSaveAsNewDialog() {
    if (saveAsNewSaving) {
      return;
    }

    setSaveAsNewOpen(false);
    setSaveAsNewError("");
    setSaveAsNewNameStatus("idle");
  }

  async function continueSaveAsNew() {
    const name = cleanText(saveAsNewName);

    if (
      saveAsNewSaving
      || !editorValue
      || !selectedPaletteId
    ) {
      return;
    }

    if (!name) {
      setSaveAsNewError(
        "Enter a new Internal Palette Name."
      );
      return;
    }

    if (saveAsNewNameStatus === "duplicate") {
      setSaveAsNewError(
        "That Internal Palette Name is already in use."
      );
      return;
    }

    if (saveAsNewNameStatus !== "available") {
      return;
    }

    setSaveAsNewSaving(true);
    setSaveAsNewError("");

    try {
      const data = await readJson(
        await fetch(
          `${API_FOLDER}/v2/admin/palettes/save-as-new.php`,
          {
            method: "POST",
            credentials: "include",
            headers: {
              "Content-Type":
                "application/json",
            },
            body: JSON.stringify({
              ...editorValue,
              source_palette_id:
                Number(selectedPaletteId),
              new_nickname: name,
              copy_photos:
                Boolean(saveAsNewCopyPhotos),
            }),
          }
        ),
        "Failed to save as new"
      );

      const saved = data?.item;

      if (!saved?.id) {
        throw new Error(
          "Save as New did not return a Palette ID."
        );
      }

      setSaveAsNewOpen(false);
      setSaveAsNewName("");
      setSaveAsNewError("");
      setSaveAsNewNameStatus("idle");

      setSelectedPaletteId(
        Number(saved.id)
      );
      setSelectedDetail(saved);
      setEditorValue(null);
      setMode("edit");
      setDetailDirty(false);
      setPhotosDirty(false);

      await loadPalettes();
    } catch (error) {
      const message =
        error?.message
        || "Failed to save as new.";

      setSaveAsNewError(message);

      if (
        message
          .toLowerCase()
          .includes("already in use")
      ) {
        setSaveAsNewNameStatus("duplicate");
      }
    } finally {
      setSaveAsNewSaving(false);
    }
  }

  function openDeleteDialog() {
    if (
      mode !== "edit"
      || !selectedPaletteId
      || deleting
    ) {
      return;
    }

    setActionError("");
    setDeleteOpen(true);
  }

  function closeDeleteDialog() {
    if (deleting) {
      return;
    }

    setDeleteOpen(false);
  }

  async function confirmDeletePalette() {
    const paletteId = Number(
      selectedPaletteId
    );

    if (!paletteId || deleting) {
      return;
    }

    setDeleting(true);
    setActionError("");

    try {
      await readJson(
        await fetch(
          `${API_FOLDER}/v2/admin/palettes/delete.php`,
          {
            method: "POST",
            credentials: "include",
            headers: {
              "Content-Type":
                "application/json",
            },
            body: JSON.stringify({
              palette_id: paletteId,
            }),
          }
        ),
        "Failed to delete Palette"
      );

      setDeleteOpen(false);
      setSelectedPaletteId(null);
      setSelectedDetail(null);
      setEditorValue(null);
      setMode("empty");
      setDetailDirty(false);
      setPhotosDirty(false);

      await loadPalettes();
    } catch (error) {
      setDeleteOpen(false);
      setActionError(
        error?.message
        || "Failed to delete Palette."
      );
    } finally {
      setDeleting(false);
    }
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
    const paletteId = Number(
      selectedPaletteId
      || selectedPalette?.id
      || 0
    );

    if (!paletteId) {
      setActionError(
        "Save this Palette before opening its PV."
      );
      return;
    }

    setActionError("");

    try {
      const data = await readJson(
        await fetch(
          `${API_FOLDER}/v2/admin/palettes/open-pv.php`,
          {
            method: "POST",
            credentials: "include",
            headers: {
              "Content-Type":
                "application/json",
            },
            body: JSON.stringify({
              palette_id: paletteId,
            }),
          }
        ),
        "Could not open PV"
      );

      const rexUrl =
        data?.item?.public_url
        || "";

      if (!rexUrl) {
        throw new Error(
          "Open PV did not return a REX URL."
        );
      }

      if (
        data?.item?.palette_viewer_id
        && selectedPalette
      ) {
        setSelectedDetail((current) =>
          current
            ? {
                ...current,
                palette_viewer_id:
                  Number(
                    data.item.palette_viewer_id
                  ),
              }
            : current
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
    <>
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
                      onClick={openSaveAsNewDialog}
                    >
                      Save as New
                    </AdminButton>
                  ) : null}

                  {mode === "edit" ? (
                    <AdminButton
                      type="button"
                      onClick={openDeleteDialog}
                      disabled={deleting}
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

      <AdminDialog
        open={saveAsNewOpen}
        title="Save as New"
        onCancel={closeSaveAsNewDialog}
        onClose={closeSaveAsNewDialog}
        width={460}
        actions={[
          {
            key: "cancel",
            label: "Cancel",
            variant: "secondary",
            onClick: closeSaveAsNewDialog,
          },
          {
            key: "continue",
            label: saveAsNewSaving
              ? "Saving…"
              : "Continue",
            variant: "primary",
            autoFocus: true,
            disabled:
              saveAsNewSaving
              || saveAsNewNameStatus !== "available",
            onClick: continueSaveAsNew,
          },
        ]}
      >
        <div className="admin-palette-save-as-new">
          <label htmlFor="save-as-new-palette-name">
            New Internal Palette Name
          </label>

          <input
            id="save-as-new-palette-name"
            type="text"
            value={saveAsNewName}
            onChange={(event) => {
              setSaveAsNewName(event.target.value);
              setSaveAsNewNameStatus("idle");

              if (saveAsNewError) {
                setSaveAsNewError("");
              }
            }}
            onKeyDown={(event) => {
              if (event.key === "Enter") {
                event.preventDefault();
                continueSaveAsNew();
              }
            }}
            placeholder="Enter a unique internal name"
          />

          <div className="admin-palette-save-as-new__help">
            Used only inside ColorFix to identify this palette.
            Clients will not see this name.
          </div>

          {saveAsNewNameStatus === "checking" ? (
            <div className="admin-palette-save-as-new__status">
              Checking name…
            </div>
          ) : null}

          {saveAsNewNameStatus === "available" ? (
            <div className="admin-palette-save-as-new__status admin-palette-save-as-new__status--available">
              Name is available.
            </div>
          ) : null}

          {saveAsNewNameStatus === "duplicate" ? (
            <div className="admin-palette-save-as-new__error">
              That Internal Palette Name is already in use.
            </div>
          ) : null}

          <label className="admin-palette-save-as-new__copy-photos">
            <input
              type="checkbox"
              checked={saveAsNewCopyPhotos}
              onChange={(event) =>
                setSaveAsNewCopyPhotos(
                  event.target.checked
                )
              }
            />
            <span>Copy photos also</span>
          </label>

          {saveAsNewError ? (
            <div className="admin-palette-save-as-new__error">
              {saveAsNewError}
            </div>
          ) : null}
        </div>
      </AdminDialog>

      <AdminDialog
        open={deleteOpen}
        mode="danger"
        title="Delete Palette?"
        onCancel={closeDeleteDialog}
        onClose={closeDeleteDialog}
        width={460}
        dismissOnBackdrop={!deleting}
        actions={[
          {
            key: "cancel",
            label: "Cancel",
            variant: "secondary",
            autoFocus: true,
            disabled: deleting,
            onClick: closeDeleteDialog,
          },
          {
            key: "confirm",
            label: deleting
              ? "Deleting…"
              : "Confirm",
            variant: "danger",
            disabled: deleting,
            onClick: confirmDeletePalette,
          },
        ]}
      >
        <div>
          <p>
            Permanently delete
            {" "}
            <strong>
              {selectedPalette?.nickname || "this Palette"}
            </strong>
            {" "}
            and all of its PVs?
          </p>

          <p>
            PV photo links and REX reservations will also be removed.
            Photo Library images themselves will not be deleted.
          </p>

          <p>
            This cannot be undone.
          </p>
        </div>
      </AdminDialog>
    </>
  );
}
