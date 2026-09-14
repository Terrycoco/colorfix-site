import {
  useEffect,
  useMemo,
  useState,
} from "react";

import {
  AdminCheckboxRow,
  AdminDialog,
  AdminField,
  AdminNotice,
  AdminPanel,
  AdminStack,
  AdminToolbar,
} from "@components/AdminLayout";

import {
  API_FOLDER,
} from "@helpers/config";


const SAVE_URL =
  `${API_FOLDER}/v2/admin/playlist-slide-presets/save.php`;


const INSERT_POSITIONS = [
  {
    value:
      "top",
    label:
      "Top",
  },
  {
    value:
      "bottom",
    label:
      "Bottom",
  },
  {
    value:
      "after_selected",
    label:
      "After selected",
  },
  {
    value:
      "before_selected",
    label:
      "Before selected",
  },
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


function clonePreset(
  preset
) {
  return {
    ...preset,

    default_title:
      preset
        ?.default_title
      ??
      "",

    default_subtitle:
      preset
        ?.default_subtitle
      ??
      "",

    default_subtitle_2:
      preset
        ?.default_subtitle_2
      ??
      "",

    default_layout:
      preset
        ?.default_layout
      ||
      "default",

    default_title_mode:
      preset
        ?.default_title_mode
      ??
      "",

    default_transition:
      preset
        ?.default_transition
      ??
      "",

    default_duration_ms:
      preset
        ?.default_duration_ms
      ??
      "",

    default_analyzer_role:
      preset
        ?.default_analyzer_role
      ||
      "ignore",

    default_finder_start:
      preset
        ?.default_finder_start
      ||
      "auto",
  };
}


export default function SlideDefaultsDialog({
  open,
  presets = [],
  onClose,
  onSaved,
}) {
  const [
    selectedKey,
    setSelectedKey,
  ] = useState("");

  const [
    draft,
    setDraft,
  ] = useState(null);

  const [
    saving,
    setSaving,
  ] = useState(false);

  const [
    error,
    setError,
  ] = useState("");


  const selectedPreset =
    useMemo(
      () =>
        presets.find(
          (row) =>
            row
              ?.preset_key ===
            selectedKey
        )
        ||
        presets[0]
        ||
        null,
      [
        presets,
        selectedKey,
      ]
    );


  useEffect(() => {
    if (
      !open
    ) {
      return;
    }

    if (
      !selectedKey
      ||
      !presets.some(
        (row) =>
          row
            ?.preset_key ===
          selectedKey
      )
    ) {
      setSelectedKey(
        presets[0]
          ?.preset_key
        ||
        ""
      );
    }
  }, [
    open,
    presets,
    selectedKey,
  ]);


  useEffect(() => {
    if (
      !open
      ||
      !selectedPreset
    ) {
      return;
    }

    setDraft(
      clonePreset(
        selectedPreset
      )
    );

    setError(
      ""
    );
  }, [
    open,
    selectedPreset,
  ]);


  function update(
    field,
    value
  ) {
    setDraft(
      (current) => ({
        ...current,

        [field]:
          value,
      })
    );
  }


  async function save() {
    if (
      !draft
      ||
      saving
    ) {
      return;
    }

    setSaving(
      true
    );

    setError(
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
              JSON.stringify(
                draft
              ),
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
          "Failed to save slide defaults."
        );
      }

      const saved =
        data.preset;

      setDraft(
        clonePreset(
          saved
        )
      );

      onSaved?.(
        saved
      );

    } catch (err) {
      setError(
        err?.message ||
        "Failed to save slide defaults."
      );

    } finally {
      setSaving(
        false
      );
    }
  }


  return (
    <AdminDialog
      open={
        open
      }

      title="Slide Defaults"

      width={
        760
      }

      dismissOnBackdrop={
        !saving
      }

      onClose={
        onClose
      }

      actions={[
        {
          key:
            "cancel",

          label:
            "Close",

          variant:
            "secondary",

          disabled:
            saving,

          onClick:
            onClose,
        },

        {
          key:
            "save",

          label:
            saving
              ? "Saving..."
              : "Save",

          disabled:
            saving
            ||
            !draft,

          autoFocus:
            false,

          onClick:
            save,
        },
      ]}
    >
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


        <AdminPanel
          title="Preset"
          compact
        >
          <AdminToolbar compact>
            <AdminField
              label="Slide Type"
              compact
            >
              <select
                className="admin-field__control"
                value={
                  selectedKey
                }
                onChange={(event) =>
                  setSelectedKey(
                    event.target.value
                  )
                }
              >
                {
                  presets.map(
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

            {
              draft
                ? (
                    <>
                      <AdminField
                        label="Insert Position"
                        compact
                      >
                        <select
                          className="admin-field__control"
                          value={
                            draft.insert_position
                            ||
                            "after_selected"
                          }
                          onChange={(event) =>
                            update(
                              "insert_position",
                              event.target.value
                            )
                          }
                        >
                          {
                            INSERT_POSITIONS.map(
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

                      <AdminCheckboxRow
                        checked={
                          draft.is_enabled !==
                          false
                        }
                        onChange={(event) =>
                          update(
                            "is_enabled",
                            event.target.checked
                          )
                        }
                      >
                        Enabled
                      </AdminCheckboxRow>
                    </>
                  )
                : null
            }
          </AdminToolbar>
        </AdminPanel>


        {
          draft
            ? (
                <>
                  <AdminPanel
                    title="Player Experience / Publisher"
                    compact
                  >
                    <AdminToolbar compact>
                      <AdminCheckboxRow
                        checked={
                          Boolean(
                            draft.default_site
                          )
                        }
                        onChange={(event) =>
                          update(
                            "default_site",
                            event.target.checked
                          )
                        }
                      >
                        Site Playlist
                      </AdminCheckboxRow>

                      <AdminCheckboxRow
                        checked={
                          Boolean(
                            draft.default_concept
                          )
                        }
                        onChange={(event) =>
                          update(
                            "default_concept",
                            event.target.checked
                          )
                        }
                      >
                        Concept
                      </AdminCheckboxRow>

                      <AdminCheckboxRow
                        checked={
                          Boolean(
                            draft.default_client
                          )
                        }
                        onChange={(event) =>
                          update(
                            "default_client",
                            event.target.checked
                          )
                        }
                      >
                        Client
                      </AdminCheckboxRow>

                      <AdminCheckboxRow
                        checked={
                          Boolean(
                            draft.default_pin
                          )
                        }
                        onChange={(event) =>
                          update(
                            "default_pin",
                            event.target.checked
                          )
                        }
                      >
                        Pin
                      </AdminCheckboxRow>

                      <AdminCheckboxRow
                        checked={
                          Boolean(
                            draft.default_yt
                          )
                        }
                        onChange={(event) =>
                          update(
                            "default_yt",
                            event.target.checked
                          )
                        }
                      >
                        YT
                      </AdminCheckboxRow>
                    </AdminToolbar>
                  </AdminPanel>


                  <AdminPanel
                    title="Slide Settings"
                    compact
                  >
                    <AdminStack gap="sm">
                      <AdminToolbar compact>
                        <AdminCheckboxRow
                          checked={
                            Boolean(
                              draft.default_is_active
                            )
                          }
                          onChange={(event) =>
                            update(
                              "default_is_active",
                              event.target.checked
                            )
                          }
                        >
                          Active
                        </AdminCheckboxRow>

                        <AdminCheckboxRow
                          checked={
                            Boolean(
                              draft.default_star
                            )
                          }
                          onChange={(event) =>
                            update(
                              "default_star",
                              event.target.checked
                            )
                          }
                        >
                          Star
                        </AdminCheckboxRow>

                        <AdminCheckboxRow
                          checked={
                            Boolean(
                              draft.default_exclude_from_thumbs
                            )
                          }
                          onChange={(event) =>
                            update(
                              "default_exclude_from_thumbs",
                              event.target.checked
                            )
                          }
                        >
                          Hide from Thumbs
                        </AdminCheckboxRow>

                        <AdminCheckboxRow
                          checked={
                            Boolean(
                              draft.default_is_share_image
                            )
                          }
                          onChange={(event) =>
                            update(
                              "default_is_share_image",
                              event.target.checked
                            )
                          }
                        >
                          Peek Photo
                        </AdminCheckboxRow>

                        <AdminCheckboxRow
                          checked={
                            Boolean(
                              draft.default_is_final
                            )
                          }
                          onChange={(event) =>
                            update(
                              "default_is_final",
                              event.target.checked
                            )
                          }
                        >
                          Final
                        </AdminCheckboxRow>
                      </AdminToolbar>

                      <AdminToolbar compact>
                        <AdminField
                          label="Transition Into"
                          compact
                        >
                          <select
                            className="admin-field__control"
                            value={
                              draft.default_transition
                              ||
                              ""
                            }
                            onChange={(event) =>
                              update(
                                "default_transition",
                                event.target.value
                              )
                            }
                          >
                            <option value="">
                              default animation
                            </option>
                            <option value="animation">
                              animation
                            </option>
                            <option value="cut">
                              cut
                            </option>
                          </select>
                        </AdminField>

                        <AdminField
                          label="Duration"
                          compact
                        >
                          <input
                            className="admin-field__control"
                            type="number"
                            min="0"
                            value={
                              draft.default_duration_ms
                              ??
                              ""
                            }
                            onChange={(event) =>
                              update(
                                "default_duration_ms",
                                event.target.value
                              )
                            }
                          />
                        </AdminField>

                        <AdminField
                          label="Layout"
                          compact
                        >
                          <input
                            className="admin-field__control"
                            type="text"
                            value={
                              draft.default_layout
                              ||
                              ""
                            }
                            onChange={(event) =>
                              update(
                                "default_layout",
                                event.target.value
                              )
                            }
                          />
                        </AdminField>

                        <AdminField
                          label="Title Mode"
                          compact
                        >
                          <select
                            className="admin-field__control"
                            value={
                              draft.default_title_mode
                              ||
                              ""
                            }
                            onChange={(event) =>
                              update(
                                "default_title_mode",
                                event.target.value
                              )
                            }
                          >
                            <option value="">
                              default
                            </option>
                            <option value="static">
                              static
                            </option>
                            <option value="animate">
                              animate
                            </option>
                          </select>
                        </AdminField>
                      </AdminToolbar>

                      <AdminToolbar compact>
                        <AdminField
                          label="Analyzer Role"
                          compact
                        >
                          <select
                            className="admin-field__control"
                            value={
                              draft.default_analyzer_role
                              ||
                              "ignore"
                            }
                            onChange={(event) =>
                              update(
                                "default_analyzer_role",
                                event.target.value
                              )
                            }
                          >
                            {
                              ANALYZER_ROLES.map(
                                (role) => (
                                  <option
                                    key={
                                      role
                                    }
                                    value={
                                      role
                                    }
                                  >
                                    {role}
                                  </option>
                                )
                              )
                            }
                          </select>
                        </AdminField>

                        <AdminField
                          label="Finder Start"
                          compact
                        >
                          <select
                            className="admin-field__control"
                            value={
                              draft.default_finder_start
                              ||
                              "auto"
                            }
                            onChange={(event) =>
                              update(
                                "default_finder_start",
                                event.target.value
                              )
                            }
                          >
                            {
                              FINDER_START_VALUES.map(
                                (value) => (
                                  <option
                                    key={
                                      value
                                    }
                                    value={
                                      value
                                    }
                                  >
                                    {value}
                                  </option>
                                )
                              )
                            }
                          </select>
                        </AdminField>
                      </AdminToolbar>
                    </AdminStack>
                  </AdminPanel>


                  <AdminPanel
                    title="Default Text"
                    compact
                  >
                    <AdminStack gap="sm">
                      <AdminField
                        label="Title"
                        compact
                      >
                        <input
                          className="admin-field__control"
                          type="text"
                          value={
                            draft.default_title
                            ||
                            ""
                          }
                          onChange={(event) =>
                            update(
                              "default_title",
                              event.target.value
                            )
                          }
                        />
                      </AdminField>

                      <AdminField
                        label="Subtitle"
                        compact
                      >
                        <input
                          className="admin-field__control"
                          type="text"
                          value={
                            draft.default_subtitle
                            ||
                            ""
                          }
                          onChange={(event) =>
                            update(
                              "default_subtitle",
                              event.target.value
                            )
                          }
                        />
                      </AdminField>

                      <AdminField
                        label="Subtitle 2"
                        compact
                      >
                        <input
                          className="admin-field__control"
                          type="text"
                          value={
                            draft.default_subtitle_2
                            ||
                            ""
                          }
                          onChange={(event) =>
                            update(
                              "default_subtitle_2",
                              event.target.value
                            )
                          }
                        />
                      </AdminField>
                    </AdminStack>
                  </AdminPanel>
                </>
              )
            : (
                <AdminNotice>
                  No slide defaults are available.
                </AdminNotice>
              )
        }
      </AdminStack>
    </AdminDialog>
  );
}
