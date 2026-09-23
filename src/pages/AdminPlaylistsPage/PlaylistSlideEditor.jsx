import { useEffect, useMemo, useState } from "react";

import {
  AdminButton,
  AdminCheckboxRow,
  AdminField,
  AdminMetaText,
  AdminNotice,
  AdminPanel,
  AdminStack,
  AdminToolbar,
} from "@components/AdminLayout";

import PermissionStatus from "@components/PermissionStatus";
import UploadPhotoDialog from "@components/Dialogs/UploadPhotoDialog";
import FuzzySearchColorSelect from "@components/FuzzySearchColorSelect";
import fetchColorDetail from "@data/fetchColorDetail";
import { API_FOLDER } from "@helpers/config";

const PROJECT_SLIDE_PALETTE_URL =
  `${API_FOLDER}/v2/admin/playlist-items/project-palette.php`;

const DEFAULT_HUE_WHEEL_CONFIG = {
  items: [
    { hue: 145, label: "Green", color: "#6F8F72", animate: false },
    { hue: 355, label: "Red", color: "#A6403A", animate: true },
  ],
  animated: true,
  showLabels: false,
  showDots: false,
  pulseOnComplete: true,
  caption: "",
  size: 360,
  wheelFadeMs: 420,
  spokeStartRadius: 0,
  spokeEndRadius: 136,
  spokeDelayMs: 420,
  spokeStaggerMs: 260,
  spokeDurationMs: 800,
};

const DEFAULT_BRAND_BUMPER_CONFIG = {
  variant: "colorfix-signature",
  auto_advance: true,
  requires_tap: false,
  duration_ms: 4200,
  signatureRevealDelayMs: 760,
  signatureRevealDurationMs: 1750,
  signatureSound: {
    enabled: true,
    src: "",
    cueMs: 760,
    volume: 0.075,
    note: "uses synthetic pencil scratch unless src is provided",
  },
};

export const HUE_WHEEL_BODY_TEMPLATE =
  serializeHueWheelConfig(DEFAULT_HUE_WHEEL_CONFIG);

export const BRAND_BUMPER_BODY_TEMPLATE =
  JSON.stringify(DEFAULT_BRAND_BUMPER_CONFIG, null, 2);

export default function PlaylistSlideEditor({
  item,
  slideNumber,
  photoThumb = "",
  photoInfo = null,
  saving = false,
  saveMessage = "",
  saveError = "",
  onUpdate,
  onPickPhoto,
  onUploadPhoto,
  onClearPhoto,
  onRemove,
  onCommit,
}) {
  const [
    uploadOpen,
    setUploadOpen,
  ] = useState(false);

  const [
    projectPalettes,
    setProjectPalettes,
  ] = useState([]);

  const [
    slidePaletteId,
    setSlidePaletteId,
  ] = useState("");

  const [
    paletteLoading,
    setPaletteLoading,
  ] = useState(false);

  const [
    paletteSaving,
    setPaletteSaving,
  ] = useState(false);

  const [
    paletteError,
    setPaletteError,
  ] = useState("");

  const playlistItemId =
    Number(
      item?.playlist_item_id ||
      0
    );

  const selectedProjectPalette =
    useMemo(
      () =>
        projectPalettes.find(
          (palette) =>
            Number(
              palette?.saved_palette_id ||
              0
            ) ===
            Number(
              slidePaletteId ||
              0
            )
        )
        ||
        null,
      [
        projectPalettes,
        slidePaletteId,
      ]
    );

  useEffect(() => {
    let cancelled = false;

    if (playlistItemId <= 0) {
      setProjectPalettes([]);
      setSlidePaletteId("");
      setPaletteError("");
      setPaletteLoading(false);
      return undefined;
    }

    setPaletteLoading(true);
    setPaletteError("");

    (
      async () => {
        try {
          const params =
            new URLSearchParams({
              playlist_item_id:
                String(
                  playlistItemId
                ),
              _:
                String(
                  Date.now()
                ),
            });

          const response =
            await fetch(
              `${PROJECT_SLIDE_PALETTE_URL}?${params.toString()}`,
              {
                credentials:
                  "include",
                cache:
                  "no-store",
              }
            );

          const data =
            await readJsonResponse(
              response,
              "Failed to load project palettes"
            );

          if (cancelled) {
            return;
          }

          setProjectPalettes(
            Array.isArray(
              data?.palettes
            )
              ? data.palettes
              : []
          );

          setSlidePaletteId(
            Number(
              data?.saved_palette_id ||
              0
            ) > 0
              ? String(
                  data.saved_palette_id
                )
              : ""
          );

        } catch (error) {
          if (!cancelled) {
            setProjectPalettes([]);
            setSlidePaletteId("");
            setPaletteError(
              error?.message ||
              "Failed to load project palettes."
            );
          }

        } finally {
          if (!cancelled) {
            setPaletteLoading(false);
          }
        }
      }
    )();

    return () => {
      cancelled = true;
    };
  }, [
    playlistItemId,
  ]);

  async function setProjectPalette(
    value
  ) {
    if (
      playlistItemId <= 0
      ||
      paletteSaving
    ) {
      return;
    }

    const previousValue =
      slidePaletteId;

    const nextValue =
      String(
        value ||
        ""
      );

    setSlidePaletteId(
      nextValue
    );

    setPaletteSaving(
      true
    );

    setPaletteError(
      ""
    );

    try {
      const response =
        await fetch(
          PROJECT_SLIDE_PALETTE_URL,
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
                playlist_item_id:
                  playlistItemId,

                saved_palette_id:
                  nextValue
                    ? Number(
                        nextValue
                      )
                    : null,
              }),
          }
        );

      await readJsonResponse(
        response,
        "Failed to attach project palette"
      );

    } catch (error) {
      setSlidePaletteId(
        previousValue
      );

      setPaletteError(
        error?.message ||
        "Failed to attach project palette."
      );

    } finally {
      setPaletteSaving(
        false
      );
    }
  }

  const photoId = item?.photo_library_id || "";
  const hueConfig =
    item?.item_type === "hue-wheel"
      ? parseHueWheelBody(item.body)
      : null;

  return (
    <div onBlurCapture={() => onCommit?.()}>
      <AdminStack gap="md">
      {saveError ? (
        <AdminNotice variant="danger">
          {saveError}
        </AdminNotice>
      ) : null}

      <div className="admin-workbench-drawer__autosave-indicator">
        {saving ? "Saving..." : saveMessage || "Autosave"}
      </div>

      <AdminPanel title={`Slide ${slideNumber}`} compact>
        <AdminStack gap="sm">
          <AdminToolbar compact>
            <AdminField label="Type" compact>
              <select
                className="admin-field__control"
                value={item.item_type || "non-palette"}
                onChange={(event) =>
                  onUpdate("item_type", event.target.value)
                }
              >
                <option value="palette">palette</option>
                <option value="intro">intro</option>
                <option value="text">text</option>
                <option value="hue-wheel">hue wheel</option>
                <option value="brand-bumper">brand bumper</option>
                <option value="cover-image">cover image</option>
                <option value="non-palette">no palette</option>
              </select>
            </AdminField>

            <AdminField label="Analyzer Role" compact>
              <select
                className="admin-field__control"
                value={item.analyzer_role || "ignore"}
                onChange={(event) =>
                  onUpdate("analyzer_role", event.target.value)
                }
              >
                <option value="ignore">ignore</option>
                <option value="before">before</option>
                <option value="after">after</option>
                <option value="single">single</option>
                <option value="teaser">teaser</option>
              </select>
            </AdminField>

            <AdminField label="Finder Start" compact>
              <select
                className="admin-field__control"
                value={item.finder_start || "auto"}
                onChange={(event) =>
                  onUpdate("finder_start", event.target.value)
                }
              >
                <option value="auto">auto</option>
                <option value="this">this slide</option>
                <option value="previous">previous slide</option>
              </select>
            </AdminField>
          </AdminToolbar>

          <AdminField label="Title" compact>
            <input
              className="admin-field__control"
              type="text"
              value={item.title || ""}
              onChange={(event) => onUpdate("title", event.target.value)}
            />
          </AdminField>

          <AdminField label="Subtitle" compact>
            <input
              className="admin-field__control"
              type="text"
              value={item.subtitle || ""}
              onChange={(event) => onUpdate("subtitle", event.target.value)}
            />
          </AdminField>

          <AdminField label="Subtitle 2" compact>
            <input
              className="admin-field__control"
              type="text"
              value={item.subtitle_2 || ""}
              onChange={(event) => onUpdate("subtitle_2", event.target.value)}
            />
          </AdminField>
        </AdminStack>
      </AdminPanel>

      <AdminPanel title="Photo / Palette" compact>
        <AdminStack gap="sm">
          <div
            style={{
              display: "grid",
              gridTemplateColumns: "minmax(0, 1fr) 132px",
              gap: "12px",
              alignItems: "start",
            }}
          >
            <div>
              {photoThumb ? (
                <img
                  src={photoThumb}
                  alt=""
                  loading="lazy"
                  style={{
                    display: "block",
                    width: "100%",
                    maxWidth: "360px",
                    height: "auto",
                  }}
                />
              ) : (
                <AdminMetaText as="div">
                  No photo
                </AdminMetaText>
              )}
            </div>

            <AdminStack gap="sm">
              <AdminToolbar compact>
                <AdminMetaText as="span">
                  Photo #{photoId || "—"}
                </AdminMetaText>

                {photoId ? (
                  <PermissionStatus
                    status={photoInfo?.photoPermissionStatus || "unknown"}
                    photoLibraryId={photoId}
                    clientId={photoInfo?.clientId}
                    clientName={photoInfo?.clientName || ""}
                    clientEmail={photoInfo?.clientEmail || ""}
                  />
                ) : null}
              </AdminToolbar>

              <AdminButton
                type="button"
                variant="secondary"
                onClick={onPickPhoto}
                style={{ width: "100%" }}
              >
                Picker
              </AdminButton>

              <AdminButton
                type="button"
                variant="secondary"
                onClick={() => setUploadOpen(true)}
                style={{ width: "100%" }}
              >
                Upload
              </AdminButton>

              <AdminButton
                type="button"
                variant="secondary"
                disabled={!photoId && !item.image_url}
                onClick={onClearPhoto}
                style={{ width: "100%" }}
              >
                Remove
              </AdminButton>
            </AdminStack>
          </div>

          <AdminField label="Palette" compact>
            <select
              className="admin-field__control"
              value={slidePaletteId}
              disabled={
                playlistItemId <= 0
                ||
                paletteLoading
                ||
                paletteSaving
              }
              onChange={(event) =>
                setProjectPalette(
                  event.target.value
                )
              }
            >
              <option value="">
                {
                  playlistItemId <= 0
                    ? "Save playlist before attaching a palette"
                    : paletteLoading
                      ? "Loading project palettes..."
                      : projectPalettes.length
                        ? "No palette attached"
                        : "No project palettes"
                }
              </option>

              {
                projectPalettes.map(
                  (palette) => {
                    const paletteId =
                      Number(
                        palette?.saved_palette_id ||
                        0
                      );

                    const label =
                      String(
                        palette?.display_title
                        ||
                        palette?.nickname
                        ||
                        `Palette #${paletteId}`
                      ).trim();

                    return (
                      <option
                        key={paletteId}
                        value={String(paletteId)}
                      >
                        {label}
                      </option>
                    );
                  }
                )
              }
            </select>
          </AdminField>

          {
            selectedProjectPalette
              ?.colors
              ?.length
              ? (
                  <div
                    aria-label="Selected palette colors"
                    style={{
                      display: "flex",
                      gap: "2px",
                      alignItems: "stretch",
                    }}
                  >
                    {
                      selectedProjectPalette.colors.map(
                        (
                          color,
                          index
                        ) => (
                          <span
                            key={
                              color?.member_id
                              ||
                              `${color?.color_id || "color"}-${index}`
                            }
                            title={
                              [
                                color?.color_name,
                                color?.role
                                  ? `Used for: ${color.role}`
                                  : "",
                              ]
                                .filter(Boolean)
                                .join(" · ")
                            }
                            style={{
                              display: "block",
                              width: "46px",
                              height: "30px",
                              border:
                                "1px solid var(--admin-border)",
                              borderRadius:
                                "3px",
                              boxSizing:
                                "border-box",
                              backgroundColor:
                                normalizeProjectPaletteHex(
                                  color?.color_hex6
                                ),
                            }}
                          />
                        )
                      )
                    }
                  </div>
                )
              : null
          }

          {
            paletteError
              ? (
                  <AdminNotice variant="danger">
                    {paletteError}
                  </AdminNotice>
                )
              : null
          }
        </AdminStack>
      </AdminPanel>

      <AdminPanel title="Player Experience / Publisher" compact>
        <AdminStack gap="sm">
          <AdminToolbar compact>
            <AdminCheckboxRow
              checked={item.site !== false}
              onChange={(event) => onUpdate("site", event.target.checked)}
            >
              Site Playlist
            </AdminCheckboxRow>

            <AdminCheckboxRow
              checked={item.concept !== false}
              onChange={(event) => onUpdate("concept", event.target.checked)}
            >
              Concept
            </AdminCheckboxRow>

            <AdminCheckboxRow
              checked={item.client !== false}
              onChange={(event) => onUpdate("client", event.target.checked)}
            >
              Client
            </AdminCheckboxRow>
          </AdminToolbar>

          <AdminToolbar compact>
            <AdminCheckboxRow
              checked={item.pin !== false}
              onChange={(event) => onUpdate("pin", event.target.checked)}
            >
              Pin
            </AdminCheckboxRow>

            <AdminCheckboxRow
              checked={item.yt !== false}
              onChange={(event) => onUpdate("yt", event.target.checked)}
            >
              YT
            </AdminCheckboxRow>
          </AdminToolbar>
        </AdminStack>
      </AdminPanel>

      <AdminPanel title="Slide Settings" compact>
        <AdminStack gap="sm">
          <AdminToolbar compact>
            <AdminField label="Transition Into" compact>
              <select
                className="admin-field__control"
                value={item.transition || ""}
                onChange={(event) =>
                  onUpdate("transition", event.target.value)
                }
              >
                <option value="">default animation</option>
                <option value="animation">animation</option>
                <option value="cut">cut</option>
              </select>
            </AdminField>

            <AdminField label="Duration" compact>
              <input
                className="admin-field__control"
                type="number"
                value={item.duration_ms || ""}
                onChange={(event) =>
                  onUpdate("duration_ms", event.target.value)
                }
              />
            </AdminField>

            <AdminField label="Version" compact>
              <input
                className="admin-field__control"
                type="number"
                min="1"
                value={item.version_number || 1}
                onChange={(event) =>
                  onUpdate("version_number", event.target.value)
                }
              />
            </AdminField>
          </AdminToolbar>

          <AdminToolbar compact>
            <AdminField label="Layout" compact>
              <input
                className="admin-field__control"
                type="text"
                value={item.layout || ""}
                onChange={(event) => onUpdate("layout", event.target.value)}
              />
            </AdminField>

            <AdminField label="Title Mode" compact>
              <select
                className="admin-field__control"
                value={item.title_mode || ""}
                onChange={(event) =>
                  onUpdate("title_mode", event.target.value)
                }
              >
                <option value="">Select</option>
                <option value="static">static</option>
                <option value="animate">animate</option>
              </select>
            </AdminField>

            <AdminField label="AP ID" compact>
              <input
                className="admin-field__control"
                type="number"
                value={item.ap_id || ""}
                onChange={(event) => onUpdate("ap_id", event.target.value)}
              />
            </AdminField>
          </AdminToolbar>

          <AdminToolbar compact>
            <AdminCheckboxRow
              checked={Boolean(item.star)}
              onChange={(event) => onUpdate("star", event.target.checked)}
            >
              Star
            </AdminCheckboxRow>

            <AdminCheckboxRow
              checked={Boolean(item.exclude_from_thumbs)}
              onChange={(event) =>
                onUpdate("exclude_from_thumbs", event.target.checked)
              }
            >
              Hide from Thumbs
            </AdminCheckboxRow>

            <AdminCheckboxRow
              checked={Boolean(item.is_final)}
              onChange={(event) =>
                onUpdate("is_final", event.target.checked)
              }
            >
              Final
            </AdminCheckboxRow>

            <AdminCheckboxRow
              checked={Boolean(item.is_active)}
              onChange={(event) =>
                onUpdate("is_active", event.target.checked)
              }
            >
              Active
            </AdminCheckboxRow>
          </AdminToolbar>

        </AdminStack>
      </AdminPanel>

      {item.item_type === "hue-wheel" ? (
        <HueWheelFields
          config={hueConfig}
          onChange={(nextConfig) =>
            onUpdate("body", serializeHueWheelConfig(nextConfig))
          }
        />
      ) : item.item_type === "brand-bumper" ? (
        <AdminPanel title="Brand Bumper Config" compact>
          <AdminField label="JSON" compact>
            <textarea
              className="admin-field__control"
              rows={10}
              value={item.body || ""}
              onChange={(event) => onUpdate("body", event.target.value)}
            />
          </AdminField>
        </AdminPanel>
      ) : item.item_type === "text" ? (
        <AdminPanel title="Text Content" compact>
          <AdminField label="Text" compact>
            <textarea
              className="admin-field__control"
              rows={5}
              value={item.body || ""}
              onChange={(event) => onUpdate("body", event.target.value)}
            />
          </AdminField>
        </AdminPanel>
      ) : null}

      <AdminToolbar>
        <AdminButton type="button" variant="danger" onClick={onRemove}>
          Remove Slide
        </AdminButton>
      </AdminToolbar>

      <UploadPhotoDialog
        open={uploadOpen}
        onClose={() =>
          setUploadOpen(false)
        }
        onUploaded={(photo) => {
          onUploadPhoto?.(photo);
          setUploadOpen(false);
        }}
      />
      </AdminStack>
    </div>
  );
}

function HueWheelFields({ config, onChange }) {
  if (!config) return null;

  function updateField(field, value) {
    onChange({ ...config, [field]: value });
  }

  function updateMarker(index, field, value) {
    onChange({
      ...config,
      items: config.items.map((marker, markerIndex) =>
        markerIndex === index ? { ...marker, [field]: value } : marker
      ),
    });
  }

  async function applyColor(index, pickedColor) {
    const colorId = pickedColor?.id ?? pickedColor?.color_id;
    let color = pickedColor || {};

    if (colorId) {
      try {
        await fetchColorDetail(colorId, (detail) => {
          color = detail || color;
        });
      } catch {
        // Search rows normally contain enough data.
      }
    }

    const hexRaw =
      color.hex6 ||
      color.hex ||
      pickedColor?.hex6 ||
      pickedColor?.hex ||
      "";

    const hex = String(hexRaw).trim().replace(/^#/, "");
    const hue = Number(
      color.hcl_h ??
        color.h ??
        pickedColor?.hcl_h ??
        pickedColor?.h
    );

    onChange({
      ...config,
      items: config.items.map((marker, markerIndex) =>
        markerIndex === index
          ? {
              ...marker,
              hue: Number.isFinite(hue)
                ? Number(hue.toFixed(2))
                : marker.hue,
              label:
                color.name ||
                color.color_name ||
                pickedColor?.name ||
                marker.label,
              color: hex ? `#${hex.toUpperCase()}` : marker.color,
            }
          : marker
      ),
    });
  }

  return (
    <AdminPanel title="Hue Wheel" compact>
      <AdminStack gap="sm">
        <AdminToolbar compact>
          <AdminCheckboxRow
            checked={config.animated !== false}
            onChange={(event) =>
              updateField("animated", event.target.checked)
            }
          >
            Animated
          </AdminCheckboxRow>

          <AdminCheckboxRow
            checked={config.showLabels === true}
            onChange={(event) =>
              updateField("showLabels", event.target.checked)
            }
          >
            Show Labels
          </AdminCheckboxRow>

          <AdminCheckboxRow
            checked={config.showDots === true}
            onChange={(event) =>
              updateField("showDots", event.target.checked)
            }
          >
            Show Dots
          </AdminCheckboxRow>

          <AdminCheckboxRow
            checked={config.pulseOnComplete !== false}
            onChange={(event) =>
              updateField("pulseOnComplete", event.target.checked)
            }
          >
            Finish Pulse
          </AdminCheckboxRow>
        </AdminToolbar>

        <AdminField label="Caption" compact>
          <input
            className="admin-field__control"
            type="text"
            value={config.caption || ""}
            onChange={(event) => updateField("caption", event.target.value)}
          />
        </AdminField>

        <AdminToolbar compact>
          {[
            ["size", "Size"],
            ["spokeStartRadius", "Start Radius"],
            ["spokeEndRadius", "End Radius"],
            ["wheelFadeMs", "Wheel Fade"],
            ["spokeDelayMs", "First Spoke Delay"],
            ["spokeStaggerMs", "Stagger"],
            ["spokeDurationMs", "Duration"],
          ].map(([key, label]) => (
            <AdminField key={key} label={label} compact>
              <input
                className="admin-field__control"
                type="number"
                value={config[key] ?? ""}
                onChange={(event) => updateField(key, event.target.value)}
              />
            </AdminField>
          ))}
        </AdminToolbar>

        <AdminPanel
          title="Markers"
          compact
          actions={
            <AdminButton
              type="button"
              variant="secondary"
              onClick={() =>
                onChange({
                  ...config,
                  items: [
                    ...config.items,
                    normalizeHueWheelItem({
                      hue: 0,
                      label: "",
                      color: "#111111",
                      animate: true,
                    }),
                  ],
                })
              }
            >
              Add Marker
            </AdminButton>
          }
        >
          <AdminStack gap="sm">
            {config.items.map((marker, index) => (
              <AdminPanel
                key={`marker-${index}`}
                title={`Marker ${index + 1}`}
                compact
                actions={
                  <AdminButton
                    type="button"
                    variant="danger"
                    onClick={() =>
                      onChange({
                        ...config,
                        items: config.items.filter(
                          (_, markerIndex) => markerIndex !== index
                        ),
                      })
                    }
                  >
                    Remove
                  </AdminButton>
                }
              >
                <AdminStack gap="sm">
                  <AdminField label="Pick Color" compact>
                    <FuzzySearchColorSelect
                      onSelect={(color) => applyColor(index, color)}
                      autoFocus={false}
                      preventAutoFocus
                      compact
                      showLabel={false}
                      mobileBreakpoint={0}
                    />
                  </AdminField>

                  <AdminToolbar compact>
                    <AdminField label="Hue" compact>
                      <input
                        className="admin-field__control"
                        type="number"
                        min="0"
                        max="360"
                        step="0.1"
                        value={marker.hue}
                        onChange={(event) =>
                          updateMarker(index, "hue", event.target.value)
                        }
                      />
                    </AdminField>

                    <AdminField label="Label" compact>
                      <input
                        className="admin-field__control"
                        type="text"
                        value={marker.label}
                        onChange={(event) =>
                          updateMarker(index, "label", event.target.value)
                        }
                      />
                    </AdminField>

                    <AdminField label="Color" compact>
                      <input
                        className="admin-field__control"
                        type="color"
                        value={
                          /^#[0-9a-f]{6}$/i.test(marker.color || "")
                            ? marker.color
                            : "#111111"
                        }
                        onChange={(event) =>
                          updateMarker(index, "color", event.target.value)
                        }
                      />
                    </AdminField>

                    <AdminCheckboxRow
                      checked={marker.animate !== false}
                      onChange={(event) =>
                        updateMarker(index, "animate", event.target.checked)
                      }
                    >
                      Animate
                    </AdminCheckboxRow>
                  </AdminToolbar>

                  <AdminToolbar compact>
                    {[
                      ["delayMs", "Delay"],
                      ["durationMs", "Duration"],
                      ["startRadius", "Start Radius"],
                      ["endRadius", "End Radius"],
                    ].map(([key, label]) => (
                      <AdminField key={key} label={label} compact>
                        <input
                          className="admin-field__control"
                          type="number"
                          value={marker[key] ?? ""}
                          placeholder="default"
                          onChange={(event) =>
                            updateMarker(index, key, event.target.value)
                          }
                        />
                      </AdminField>
                    ))}
                  </AdminToolbar>
                </AdminStack>
              </AdminPanel>
            ))}
          </AdminStack>
        </AdminPanel>
      </AdminStack>
    </AdminPanel>
  );
}


async function readJsonResponse(
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


function normalizeProjectPaletteHex(
  value
) {
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

function parseHueWheelBody(rawBody) {
  const raw = String(rawBody || "").trim();

  if (!raw) {
    return {
      ...DEFAULT_HUE_WHEEL_CONFIG,
      items: DEFAULT_HUE_WHEEL_CONFIG.items.map(normalizeHueWheelItem),
    };
  }

  try {
    const parsed = JSON.parse(raw);
    const config = Array.isArray(parsed) ? { items: parsed } : parsed;

    if (!config || typeof config !== "object") {
      throw new Error("Invalid hue-wheel config");
    }

    return {
      ...DEFAULT_HUE_WHEEL_CONFIG,
      ...config,
      items: (Array.isArray(config.items) ? config.items : []).map(
        normalizeHueWheelItem
      ),
      animated: config.animated !== false,
      showLabels: config.showLabels === true,
      showDots: config.showDots === true,
      pulseOnComplete: config.pulseOnComplete !== false,
    };
  } catch {
    return {
      ...DEFAULT_HUE_WHEEL_CONFIG,
      items: DEFAULT_HUE_WHEEL_CONFIG.items.map(normalizeHueWheelItem),
    };
  }
}

function normalizeHueWheelItem(item = {}) {
  return {
    hue: item.hue ?? "",
    label: item.label ?? "",
    color: item.color || "#111111",
    animate: item.animate !== false,
    delayMs: item.delayMs ?? "",
    durationMs: item.durationMs ?? "",
    startRadius: item.startRadius ?? "",
    endRadius: item.endRadius ?? "",
  };
}

function serializeHueWheelConfig(config) {
  const cleanedItems = (Array.isArray(config.items) ? config.items : [])
    .map((item) => {
      const next = {
        hue: item.hue === "" ? "" : Number(item.hue),
        label: String(item.label || ""),
        color: String(item.color || ""),
        animate: item.animate !== false,
      };

      ["delayMs", "durationMs", "startRadius", "endRadius"].forEach((key) => {
        if (item[key] !== "" && item[key] != null) {
          next[key] = Number(item[key]);
        }
      });

      return next;
    })
    .filter((item) => item.hue !== "" && Number.isFinite(item.hue));

  return JSON.stringify(
    {
      items: cleanedItems,
      animated: config.animated !== false,
      showLabels: config.showLabels === true,
      showDots: config.showDots === true,
      pulseOnComplete: config.pulseOnComplete !== false,
      caption: String(config.caption || ""),
      size: Number(config.size) || DEFAULT_HUE_WHEEL_CONFIG.size,
      wheelFadeMs: Number(
        config.wheelFadeMs ?? DEFAULT_HUE_WHEEL_CONFIG.wheelFadeMs
      ),
      spokeStartRadius: Number(
        config.spokeStartRadius ?? DEFAULT_HUE_WHEEL_CONFIG.spokeStartRadius
      ),
      spokeEndRadius: Number(
        config.spokeEndRadius ?? DEFAULT_HUE_WHEEL_CONFIG.spokeEndRadius
      ),
      spokeDelayMs: Number(
        config.spokeDelayMs ?? DEFAULT_HUE_WHEEL_CONFIG.spokeDelayMs
      ),
      spokeStaggerMs: Number(
        config.spokeStaggerMs ?? DEFAULT_HUE_WHEEL_CONFIG.spokeStaggerMs
      ),
      spokeDurationMs: Number(
        config.spokeDurationMs ?? DEFAULT_HUE_WHEEL_CONFIG.spokeDurationMs
      ),
    },
    null,
    2
  );
}
