import {
  useCallback,
  useEffect,
  useMemo,
  useState,
} from "react";

import {
  AdminButton,
  AdminEmptyState,
  AdminMetaText,
  AdminNotice,
  AdminSmartGrid,
  AdminToolbar,
  AdminToolbarSpacer,
} from "@components/AdminLayout";

import {
  API_FOLDER,
} from "@helpers/config";


const LIST_URL =
  `${API_FOLDER}/v2/admin/projects/palettes/list.php`;


function normalizeHex(value) {
  const raw =
    String(
      value ||
      ""
    )
      .trim()
      .replace(
        /^#/,
        ""
      );

  return /^[0-9a-f]{6}$/i.test(
    raw
  )
    ? `#${raw.toUpperCase()}`
    : "#E5E5E5";
}


function paletteLabel(row) {
  return String(
    row?.display_title
    ||
    row?.nickname
    ||
    `Palette #${row?.saved_palette_id || ""}`
  );
}


function PaletteStrip({
  colors = [],
}) {
  const visibleColors =
    Array.isArray(colors)
      ? colors
      : [];

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
      {
        visibleColors.map(
          (
            color,
            index
          ) => {
            const colorName =
              String(
                color?.color_name
                ||
                `Color ${index + 1}`
              );

            const brand =
              String(
                color?.color_brand_name
                ||
                color?.color_brand
                ||
                ""
              );

            const role =
              String(
                color?.role
                ||
                ""
              );

            const title =
              [
                colorName,
                brand,
                role
                  ? `Used for: ${role}`
                  : "",
              ]
                .filter(Boolean)
                .join(" · ");

            return (
              <span
                key={
                  color?.member_id
                  ||
                  `${color?.color_id || "color"}-${index}`
                }
                className="admin-project-palettes__swatch"
                style={{
                  backgroundColor:
                    normalizeHex(
                      color?.color_hex6
                    ),
                }}
                title={title}
                aria-label={title}
              />
            );
          }
        )
      }
    </div>
  );
}


export default function ProjectPalettes({
  projectId,
  onNewPalette = null,
  onOpenPalette = null,
}) {
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


  const loadPalettes =
    useCallback(
      async () => {
        const id =
          Number(
            projectId ||
            0
          );

        if (id <= 0) {
          setItems([]);
          setLoading(false);
          setError("");
          return;
        }

        setLoading(true);
        setError("");

        try {
          const params =
            new URLSearchParams({
              project_id:
                String(id),
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

          const text =
            await res.text();

          let data = null;

          try {
            data =
              JSON.parse(
                text
              );
          } catch {
            throw new Error(
              `HTTP ${res.status}: ${text.slice(0, 250)}`
            );
          }

          if (
            !res.ok
            ||
            !data?.ok
          ) {
            throw new Error(
              data?.error
              ||
              "Failed to load project palettes."
            );
          }

          setItems(
            Array.isArray(
              data.items
            )
              ? data.items
              : []
          );

        } catch (err) {
          setItems([]);
          setError(
            err?.message
            ||
            "Failed to load project palettes."
          );

        } finally {
          setLoading(false);
        }
      },
      [
        projectId,
      ]
    );


  useEffect(
    () => {
      void loadPalettes();
    },
    [
      loadPalettes,
    ]
  );


  const columns =
    useMemo(
      () => [
        {
          key:
            "name",

          label:
            "Name",

          sortValue:
            paletteLabel,

          render:
            (row) => (
              <strong>
                {
                  paletteLabel(
                    row
                  )
                }
              </strong>
            ),
        },

        {
          key:
            "palette",

          label:
            "Palette",

          sortable:
            false,

          render:
            (row) => (
              <PaletteStrip
                colors={
                  row?.colors
                  ||
                  []
                }
              />
            ),
        },
      ],
      []
    );


  const toolbar =
    (
      <AdminToolbar compact>
        <AdminButton
          type="button"
          onClick={() =>
            onNewPalette?.({
              projectId:
                Number(
                  projectId
                ),
              reload:
                loadPalettes,
            })
          }
        >
          New Palette
        </AdminButton>

        <AdminToolbarSpacer />

        <AdminMetaText as="div">
          {
            items.length
          } palette
          {
            items.length ===
            1
              ? ""
              : "s"
          }
        </AdminMetaText>
      </AdminToolbar>
    );


  if (loading) {
    return (
      <>
        {toolbar}

        <AdminEmptyState
          title="Palettes"
          message="Loading project palettes..."
        />
      </>
    );
  }


  if (error) {
    return (
      <>
        {toolbar}

        <AdminNotice variant="danger">
          {error}
        </AdminNotice>
      </>
    );
  }


  if (!items.length) {
    return (
      <>
        {toolbar}

        <AdminEmptyState
          title="No palettes yet"
          message="Create the first palette for this project."
        />
      </>
    );
  }


  return (
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
        row
      ) =>
        Number(
          row
            ?.saved_palette_id
        )
      }

      selectedKey={
        selectedKey
      }

      onSelectionChange={(
        row,
        key
      ) =>
        setSelectedKey(
          key
          ??
          row
            ?.saved_palette_id
          ??
          null
        )
      }

      onRowDoubleClick={(
        row
      ) =>
        onOpenPalette?.({
          projectId:
            Number(
              projectId
            ),
          palette:
            row,
          reload:
            loadPalettes,
        })
      }

      defaultSortKey="name"
      defaultSortDirection="asc"
      ariaLabel="Project palettes"
      verticalAlign="middle"
    />
  );
}
