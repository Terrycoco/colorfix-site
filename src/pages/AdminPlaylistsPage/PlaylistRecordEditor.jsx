import ProjectSelect from "@components/Project/ProjectSelect";

import {
  AdminButton,
  AdminCheckboxRow,
  AdminEditorBody,
  AdminEditorFooter,
  AdminEditorForm,
  AdminField,
  AdminPanel,
  AdminStack,
  AdminToolbar,
} from "@components/AdminLayout";

export default function PlaylistRecordEditor({
  playlist,
  playlistTypes = [],
  busy = false,
  onChange,
  onPickHero,
  onClearHero,
  onGenerateSlug,
  onClose,
  onSaveAndClose,
  onDelete,
}) {
  function handleSubmit(event) {
    event.preventDefault();

    if (busy) {
      return;
    }

    onSaveAndClose?.();
  }

  return (
    <AdminEditorForm onSubmit={handleSubmit}>
      <AdminEditorBody>
        <AdminStack gap="md">
          <AdminPanel title="Playlist" compact>
            <AdminStack gap="sm">
              <AdminField label="Title" compact>
                <input
                  className="admin-field__control"
                  type="text"
                  autoFocus={
                    !playlist.playlist_id
                  }
                  placeholder={
                    playlist.playlist_id
                      ? ""
                      : "[New]"
                  }
                  value={playlist.title || ""}
                  onChange={(event) =>
                    onChange("title", event.target.value)
                  }
                />
              </AdminField>

              <AdminField label="Type" compact>
                <input
                  className="admin-field__control"
                  type="text"
                  list="admin-playlist-type-options"
                  value={playlist.type || ""}
                  onChange={(event) =>
                    onChange("type", event.target.value)
                  }
                />

                <datalist id="admin-playlist-type-options">
                  {playlistTypes.map((type) => (
                    <option key={type} value={type} />
                  ))}
                </datalist>
              </AdminField>

              <AdminField label="Project" compact>
                <ProjectSelect
                  value={
                    playlist.project_id
                    ??
                    ""
                  }
                  disabled={
                    busy
                  }
                  includeNone
                  noneLabel="None"
                  onChange={
                    (value) =>
                      onChange(
                        "project_id",
                        value
                      )
                  }
                />
              </AdminField>

              <AdminToolbar compact>
                <AdminCheckboxRow
                  checked={Boolean(playlist.is_active)}
                  onChange={(event) =>
                    onChange("is_active", event.target.checked)
                  }
                >
                  Active
                </AdminCheckboxRow>

                <AdminCheckboxRow
                  checked={Boolean(playlist.is_public)}
                  onChange={(event) =>
                    onChange("is_public", event.target.checked)
                  }
                >
                  Public discovery / watch next
                </AdminCheckboxRow>
              </AdminToolbar>
            </AdminStack>
          </AdminPanel>

          <AdminPanel title="Landing Page / SEO" compact>
            <AdminStack gap="sm">
              <AdminToolbar compact>
                <AdminField label="Slug" compact>
                  <input
                    className="admin-field__control"
                    type="text"
                    value={playlist.slug || ""}
                    onChange={(event) =>
                      onChange("slug", event.target.value)
                    }
                  />
                </AdminField>

                <AdminButton
                  type="button"
                  variant="secondary"
                  onClick={onGenerateSlug}
                >
                  Generate
                </AdminButton>
              </AdminToolbar>

              <AdminField label="Headline" compact>
                <input
                  className="admin-field__control"
                  type="text"
                  value={playlist.headline || ""}
                  onChange={(event) =>
                    onChange("headline", event.target.value)
                  }
                />
              </AdminField>

              <AdminField label="Page Title" compact>
                <input
                  className="admin-field__control"
                  type="text"
                  value={playlist.page_title || ""}
                  onChange={(event) =>
                    onChange("page_title", event.target.value)
                  }
                />
              </AdminField>

              <AdminField label="Meta Description" compact>
                <textarea
                  className="admin-field__control"
                  rows={2}
                  value={playlist.meta_description || ""}
                  onChange={(event) =>
                    onChange("meta_description", event.target.value)
                  }
                />
              </AdminField>

              <AdminField label="Dek" compact>
                <textarea
                  className="admin-field__control"
                  rows={2}
                  value={playlist.dek || ""}
                  onChange={(event) =>
                    onChange("dek", event.target.value)
                  }
                />
              </AdminField>

              <AdminToolbar compact>
                <AdminField label="Published At" compact>
                  <input
                    className="admin-field__control"
                    type="datetime-local"
                    value={playlist.published_at || ""}
                    onChange={(event) =>
                      onChange("published_at", event.target.value)
                    }
                  />
                </AdminField>

                <AdminCheckboxRow
                  checked={Boolean(playlist.indexable)}
                  onChange={(event) =>
                    onChange("indexable", event.target.checked)
                  }
                >
                  Indexable
                </AdminCheckboxRow>
              </AdminToolbar>

              <AdminField label="Hero Alt" compact>
                <input
                  className="admin-field__control"
                  type="text"
                  value={playlist.hero_alt || ""}
                  onChange={(event) =>
                    onChange("hero_alt", event.target.value)
                  }
                />
              </AdminField>

              <AdminField label="Hero Image URL" compact>
                <AdminToolbar compact>
                  <input
                    className="admin-field__control"
                    type="text"
                    value={playlist.hero_image_url || ""}
                    onChange={(event) =>
                      onChange("hero_image_url", event.target.value)
                    }
                  />

                  <AdminButton
                    type="button"
                    variant="secondary"
                    onClick={onPickHero}
                  >
                    Pick Hero
                  </AdminButton>

                  <AdminButton
                    type="button"
                    variant="secondary"
                    disabled={
                      !playlist.hero_image_id &&
                      !playlist.hero_image_url
                    }
                    onClick={onClearHero}
                  >
                    Clear
                  </AdminButton>
                </AdminToolbar>
              </AdminField>

              {playlist.hero_image_url ? (
                <img
                  src={playlist.hero_image_url}
                  alt=""
                  width="320"
                  loading="lazy"
                />
              ) : null}

              <AdminField label="Intro HTML" compact>
                <textarea
                  className="admin-field__control"
                  rows={6}
                  value={playlist.intro_html || ""}
                  onChange={(event) =>
                    onChange("intro_html", event.target.value)
                  }
                />
              </AdminField>

              <AdminField label="Body HTML" compact>
                <textarea
                  className="admin-field__control"
                  rows={10}
                  value={playlist.body_html || ""}
                  onChange={(event) =>
                    onChange("body_html", event.target.value)
                  }
                />
              </AdminField>
            </AdminStack>
          </AdminPanel>
        </AdminStack>
      </AdminEditorBody>

      <AdminEditorFooter
        leading={
          onDelete ? (
            <AdminButton
              type="button"
              variant="danger"
              disabled={busy}
              onClick={onDelete}
            >
              Delete Playlist
            </AdminButton>
          ) : null
        }
      >
        <AdminButton
          type="button"
          variant="secondary"
          disabled={busy}
          onClick={onClose}
        >
          Close
        </AdminButton>

        <AdminButton
          type="submit"
          disabled={busy}
        >
          {busy ? "Saving..." : "Save & Close"}
        </AdminButton>
      </AdminEditorFooter>
    </AdminEditorForm>
  );
}
