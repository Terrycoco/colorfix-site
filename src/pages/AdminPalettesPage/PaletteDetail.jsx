import { useEffect, useRef, useState } from "react";

import EditableSwatch from "@components/EditableSwatch";

function cleanText(value) {
  return String(value ?? "").trim();
}

function memberToEditorRow(member, index) {
  const color = {
    id: member?.color_id ?? member?.color?.id ?? null,
    name: member?.color_name ?? member?.color?.name ?? "",
    brand: member?.color_brand ?? member?.color?.brand ?? "",
    brand_name:
      member?.color_brand_name
      ?? member?.color?.brand_name
      ?? "",
    code: member?.color_code ?? member?.color?.code ?? "",
    hex6: member?.color_hex6 ?? member?.color?.hex6 ?? "",
    hcl_h: member?.color_hcl_h ?? member?.color?.hcl_h ?? null,
    hcl_c: member?.color_hcl_c ?? member?.color?.hcl_c ?? null,
    hcl_l: member?.color_hcl_l ?? member?.color?.hcl_l ?? null,
    int_only:
      member?.color_int_only
      ?? member?.color?.int_only
      ?? 0,
    chip_num:
      member?.color_chip_num
      ?? member?.color?.chip_num
      ?? null,
    cluster_id:
      member?.color_cluster_id
      ?? member?.color?.cluster_id
      ?? null,
    hue_cats:
      member?.color_hue_cats
      ?? member?.color?.hue_cats
      ?? null,
    neutral_cats:
      member?.color_neutral_cats
      ?? member?.color?.neutral_cats
      ?? null,
  };

  return {
    key:
      member?.id
      ?? member?.key
      ?? `member-${index}`,
    color,
    role: cleanText(member?.role),
    sheen: cleanText(member?.sheen),
    note: cleanText(member?.note),
  };
}

function editorValue(form, members) {
  return {
    nickname: cleanText(form?.nickname),
    palette_type: cleanText(form?.palette_type) || "exterior",
    is_public: Boolean(form?.is_public),
    private_notes: cleanText(form?.private_notes),
    pv_title: cleanText(form?.pv_title),
    pv_description: cleanText(form?.pv_description),
    members: (Array.isArray(members) ? members : []).map(
      (member, index) => ({
        color_id: Number(member?.color?.id || 0),
        role: cleanText(member?.role),
        sheen: cleanText(member?.sheen),
        note: cleanText(member?.note),
        order_index: index,
      })
    ),
  };
}

function editorSignature(form, members) {
  return JSON.stringify(editorValue(form, members));
}

export default function PaletteDetail({
  mode = "edit",
  palette = null,
  onDirtyChange,
  onValueChange,
}) {
  const [form, setForm] = useState({
    nickname: "",
    palette_type: "exterior",
    is_public: true,
    private_notes: "",
    pv_title: "",
    pv_description: "",
  });

  const [members, setMembers] = useState([]);
  const baselineRef = useRef("");

  useEffect(() => {
    const nextForm = {
      nickname: cleanText(palette?.nickname),
      palette_type:
        cleanText(palette?.palette_type)
        || "exterior",
      is_public:
        Number(palette?.is_public ?? 1) === 1,
      private_notes: cleanText(palette?.private_notes),
      pv_title: cleanText(palette?.pv_title),
      pv_description: cleanText(palette?.pv_description),
    };

    const sourceMembers = Array.isArray(palette?.members)
      ? palette.members
      : [];

    const nextMembers = sourceMembers.map(
      (member, index) =>
        memberToEditorRow(member, index)
    );

    baselineRef.current =
      editorSignature(nextForm, nextMembers);

    setForm(nextForm);
    setMembers(nextMembers);

    onDirtyChange?.(false);
    onValueChange?.(
      editorValue(nextForm, nextMembers)
    );
  }, [palette, mode, onDirtyChange, onValueChange]);

  useEffect(() => {
    const value = editorValue(form, members);

    onValueChange?.(value);

    if (!baselineRef.current) {
      return;
    }

    onDirtyChange?.(
      JSON.stringify(value) !== baselineRef.current
    );
  }, [
    form,
    members,
    onDirtyChange,
    onValueChange,
  ]);

  function setField(name, value) {
    setForm((current) => ({
      ...current,
      [name]: value,
    }));
  }

  function updateMember(index, patch) {
    setMembers((current) =>
      current.map((member, memberIndex) =>
        memberIndex === index
          ? { ...member, ...patch }
          : member
      )
    );
  }

  function moveMember(index, direction) {
    const targetIndex = index + direction;

    if (
      targetIndex < 0
      || targetIndex >= members.length
    ) {
      return;
    }

    setMembers((current) => {
      const next = [...current];
      const [moved] = next.splice(index, 1);

      next.splice(targetIndex, 0, moved);

      return next;
    });
  }

  function removeMember(index) {
    setMembers((current) =>
      current.filter(
        (_, memberIndex) => memberIndex !== index
      )
    );
  }

  return (
    <div className="admin-palette-detail">
      <section className="admin-palette-detail__meta">
        <div className="admin-palette-detail__field">
          <label htmlFor="palette-nickname">
            Internal Palette Name
          </label>

          <input
            id="palette-nickname"
            type="text"
            value={form.nickname}
            onChange={(event) =>
              setField(
                "nickname",
                event.target.value
              )
            }
            placeholder="Unique internal name"
          />
        </div>

        <div className="admin-palette-detail__field">
          <label htmlFor="palette-pv-title">
            Title for Public PV
          </label>

          <input
            id="palette-pv-title"
            type="text"
            value={form.pv_title}
            onChange={(event) =>
              setField(
                "pv_title",
                event.target.value
              )
            }
          />
        </div>

        <div className="admin-palette-detail__field">
          <label htmlFor="palette-type">
            Palette Type
          </label>

          <select
            id="palette-type"
            value={form.palette_type}
            onChange={(event) =>
              setField(
                "palette_type",
                event.target.value
              )
            }
          >
            <option value="exterior">Exterior</option>
            <option value="interior">Interior</option>
            <option value="hoa">HOA</option>
          </select>
        </div>

        <div className="admin-palette-detail__field admin-palette-detail__field--check">
          <label>
            <input
              type="checkbox"
              checked={form.is_public}
              onChange={(event) =>
                setField(
                  "is_public",
                  event.target.checked
                )
              }
            />
            Public
          </label>
        </div>

        <div className="admin-palette-detail__field admin-palette-detail__field--wide">
          <label htmlFor="palette-pv-description">
            Description for Public PV
          </label>

          <textarea
            id="palette-pv-description"
            rows={3}
            value={form.pv_description}
            onChange={(event) =>
              setField(
                "pv_description",
                event.target.value
              )
            }
          />
        </div>
      </section>

      <section className="admin-palette-detail__colors">
        <div className="admin-palette-detail__section-head">
          <h2>Colors</h2>

          <button
            type="button"
            className="admin-button"
            disabled
          >
            Add Color
          </button>
        </div>

        {members.length > 0 ? (
          <div className="admin-palette-color-editor">
            {members.map((member, index) => (
              <div
                className="admin-palette-color-editor__row"
                key={member.key ?? index}
              >
                <div className="admin-palette-color-editor__order">
                  <button
                    type="button"
                    className="admin-palette-color-editor__move"
                    onClick={() =>
                      moveMember(index, -1)
                    }
                    disabled={index === 0}
                    aria-label="Move color up"
                  >
                    ↑
                  </button>

                  <button
                    type="button"
                    className="admin-palette-color-editor__move"
                    onClick={() =>
                      moveMember(index, 1)
                    }
                    disabled={
                      index === members.length - 1
                    }
                    aria-label="Move color down"
                  >
                    ↓
                  </button>
                </div>

                <div className="admin-palette-color-editor__swatch">
                  <EditableSwatch
                    value={member.color}
                    onChange={(color) =>
                      updateMember(index, { color })
                    }
                    showName
                    size="sm"
                    placement="top"
                  />
                </div>

                <label className="admin-palette-color-editor__field">
                  <span>Role / Placement</span>

                  <input
                    type="text"
                    value={member.role}
                    onChange={(event) =>
                      updateMember(index, {
                        role: event.target.value,
                      })
                    }
                    placeholder="body, trim, front door…"
                  />
                </label>

                <label className="admin-palette-color-editor__field">
                  <span>Sheen</span>

                  <input
                    type="text"
                    value={member.sheen}
                    onChange={(event) =>
                      updateMember(index, {
                        sheen: event.target.value,
                      })
                    }
                    placeholder="Flat, Eggshell…"
                  />
                </label>

                <label className="admin-palette-color-editor__field">
                  <span>Note</span>

                  <input
                    type="text"
                    value={member.note}
                    onChange={(event) =>
                      updateMember(index, {
                        note: event.target.value,
                      })
                    }
                  />
                </label>

                <button
                  type="button"
                  className="admin-palette-color-editor__remove"
                  onClick={() => removeMember(index)}
                  aria-label="Remove color"
                >
                  ×
                </button>
              </div>
            ))}
          </div>
        ) : (
          <div className="admin-palette-detail__empty-colors">
            No colors yet.
          </div>
        )}
      </section>

      <section className="admin-palette-detail__private-notes">
        <div className="admin-palette-detail__field admin-palette-detail__field--wide">
          <label htmlFor="palette-private-notes">
            Private Notes
          </label>

          <textarea
            id="palette-private-notes"
            rows={2}
            value={form.private_notes}
            onChange={(event) =>
              setField(
                "private_notes",
                event.target.value
              )
            }
          />
        </div>
      </section>
    </div>
  );
}
