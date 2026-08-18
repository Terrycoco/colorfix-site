import { useEffect, useState } from "react";
import { createPortal } from "react-dom";
import { API_FOLDER } from "@helpers/config";

const LIBRARY_URL =
  `${API_FOLDER}/v2/admin/marketing/library.php`;

const CREATE_GROUP_URL =
  `${API_FOLDER}/v2/admin/marketing/groups/create.php`;

const UPDATE_GROUP_URL =
  `${API_FOLDER}/v2/admin/marketing/groups/update.php`;

const DELETE_GROUP_URL =
  `${API_FOLDER}/v2/admin/marketing/groups/delete.php`;

const CREATE_TERM_URL =
  `${API_FOLDER}/v2/admin/marketing/terms/create.php`;

const UPDATE_TERM_URL =
  `${API_FOLDER}/v2/admin/marketing/terms/update.php`;

const DELETE_TERM_URL =
  `${API_FOLDER}/v2/admin/marketing/terms/delete.php`;

export default function MarketingModal({
  open,
  onClose,
  title = "Marketing",
}) {
  const [groups, setGroups] = useState([]);
  const [selectedGroupId, setSelectedGroupId] =
    useState(null);

  const [selectedTermId, setSelectedTermId] =
    useState(null);

  const [loading, setLoading] =
    useState(false);

  const [error, setError] =
    useState("");

  useEffect(() => {
    if (!open) return;

    loadLibrary();
  }, [open]);

  async function loadLibrary() {
    setLoading(true);
    setError("");

    try {
      const res = await fetch(
        `${LIBRARY_URL}?_=${Date.now()}`,
        {
          credentials: "include",
        }
      );

      const data = await res.json();

      if (!res.ok || !data?.ok) {
        throw new Error(
          data?.error ||
          "Failed to load Marketing library"
        );
      }

      const nextGroups =
        Array.isArray(data.groups)
          ? data.groups
          : [];

      setGroups(nextGroups);

      setSelectedGroupId((current) => {
        if (
          current &&
          nextGroups.some(
            (group) =>
              group.marketing_term_group_id ===
              current
          )
        ) {
          return current;
        }

        return (
          nextGroups[0]
            ?.marketing_term_group_id ??
          null
        );
      });

    } catch (err) {
      setError(
        err?.message ||
        "Failed to load Marketing library"
      );
    } finally {
      setLoading(false);
    }
  }

  const selectedGroup =
    groups.find(
      (group) =>
        group.marketing_term_group_id ===
        selectedGroupId
    ) || null;

  const selectedTerm =
    (selectedGroup?.terms || []).find(
      (term) =>
        term.marketing_term_id ===
        selectedTermId
    ) || null;

  function selectGroup(groupId) {
    setSelectedGroupId(groupId);
    setSelectedTermId(null);
  }

  async function postAndReload(
    url,
    payload
  ) {
    setError("");

    try {
      const res = await fetch(
        url,
        {
          method: "POST",
          credentials: "include",
          headers: {
            "Content-Type":
              "application/json",
          },
          body: JSON.stringify(payload),
        }
      );

      const data = await res.json();

      if (!res.ok || !data?.ok) {
        throw new Error(
          data?.error ||
          "Marketing update failed"
        );
      }

      await loadLibrary();

    } catch (err) {
      setError(
        err?.message ||
        "Marketing update failed"
      );
    }
  }

  async function addGroup() {
    const label =
      window.prompt("Group name");

    if (!label?.trim()) {
      return;
    }

    /*
     * Find the next unused group token rather
     * than assuming groups.length + 1 is free.
     */
    const usedTokens =
      new Set(
        groups.map(
          (group) => group.group_token
        )
      );

    let number = 1;

    while (
      usedTokens.has(`group${number}`)
    ) {
      number += 1;
    }

    await postAndReload(
      CREATE_GROUP_URL,
      {
        group_token:
          `group${number}`,

        label:
          label.trim(),

        sort_order:
          groups.length + 1,
      }
    );
  }

  async function editSelectedGroup() {
    if (!selectedGroup) {
      return;
    }

    const label =
      window.prompt(
        "Group name",
        selectedGroup.label || ""
      );

    if (
      label === null ||
      !label.trim()
    ) {
      return;
    }

    await postAndReload(
      UPDATE_GROUP_URL,
      {
        marketing_term_group_id:
          selectedGroup
            .marketing_term_group_id,

        label:
          label.trim(),
      }
    );
  }

  async function deleteSelectedGroup() {
    if (!selectedGroup) {
      return;
    }

    const name =
      selectedGroup.label ||
      selectedGroup.group_token;

    if (
      !window.confirm(
        `Delete "${name}" and all its terms?`
      )
    ) {
      return;
    }

    setSelectedTermId(null);

    await postAndReload(
      DELETE_GROUP_URL,
      {
        marketing_term_group_id:
          selectedGroup
            .marketing_term_group_id,
      }
    );
  }

  async function addTerm() {
    if (!selectedGroup) {
      return;
    }

    const singular =
      window.prompt("Singular");

    if (!singular?.trim()) {
      return;
    }

    const plural =
      window.prompt(
        "Plural",
        singular.trim()
      );

    if (plural === null) {
      return;
    }

    await postAndReload(
      CREATE_TERM_URL,
      {
        marketing_term_group_id:
          selectedGroup
            .marketing_term_group_id,

        /*
         * Existing DB/API still expects term.
         * It is internal only. Singular is the
         * canonical value for our UI.
         */
        term:
          singular.trim(),

        singular_term:
          singular.trim(),

        plural_term:
          plural.trim(),

        sort_order:
          (selectedGroup.terms?.length || 0)
          + 1,
      }
    );
  }

  async function editSelectedTerm() {
    if (!selectedTerm) {
      return;
    }

    const singular =
      window.prompt(
        "Singular",
        selectedTerm.singular_term ||
          selectedTerm.term ||
          ""
      );

    if (
      singular === null ||
      !singular.trim()
    ) {
      return;
    }

    const plural =
      window.prompt(
        "Plural",
        selectedTerm.plural_term ||
          singular.trim()
      );

    if (plural === null) {
      return;
    }

    await postAndReload(
      UPDATE_TERM_URL,
      {
        marketing_term_id:
          selectedTerm.marketing_term_id,

        term:
          singular.trim(),

        singular_term:
          singular.trim(),

        plural_term:
          plural.trim(),
      }
    );
  }

  async function deleteSelectedTerm() {
    if (!selectedTerm) {
      return;
    }

    const name =
      selectedTerm.singular_term ||
      selectedTerm.term;

    if (
      !window.confirm(
        `Delete "${name}"?`
      )
    ) {
      return;
    }

    setSelectedTermId(null);

    await postAndReload(
      DELETE_TERM_URL,
      {
        marketing_term_id:
          selectedTerm.marketing_term_id,
      }
    );
  }

  if (!open) {
    return null;
  }

  return createPortal(
    <div style={overlayStyle}>
      <div style={modalStyle}>

        {/* HEADER */}
        <div style={headerStyle}>
          <div>
            <div style={eyebrowStyle}>
              MARKETING
            </div>

            <div style={titleStyle}>
              {title}
            </div>
          </div>

          <button
            type="button"
            onClick={onClose}
          >
            Close
          </button>
        </div>

        {error ? (
          <div style={errorStyle}>
            {error}
          </div>
        ) : null}

        {loading ? (
          <div style={loadingStyle}>
            Loading Marketing...
          </div>
        ) : (
          <div style={workspaceStyle}>

            {/* GROUP LIST */}
            <div style={paneStyle}>
              <div style={toolbarStyle}>
                <strong>Groups</strong>

                <div style={buttonBarStyle}>
                  <button
                    type="button"
                    onClick={addGroup}
                  >
                    Add
                  </button>

                  <button
                    type="button"
                    disabled={!selectedGroup}
                    onClick={
                      editSelectedGroup
                    }
                  >
                    Edit
                  </button>

                  <button
                    type="button"
                    disabled={!selectedGroup}
                    onClick={
                      deleteSelectedGroup
                    }
                  >
                    Delete
                  </button>
                </div>
              </div>

              <div style={listStyle}>
                {groups.map((group) => {
                  const selected =
                    group.marketing_term_group_id ===
                    selectedGroupId;

                  return (
                    <div
                      key={
                        group
                          .marketing_term_group_id
                      }
                      style={{
                        ...groupRowStyle,
                        ...(selected
                          ? selectedRowStyle
                          : {}),
                      }}
                      onClick={() =>
                        selectGroup(
                          group
                            .marketing_term_group_id
                        )
                      }
                      onDoubleClick={
                        editSelectedGroup
                      }
                    >
                      <span>
                        {group.label ||
                          group.group_token}
                      </span>

                      <span
                        style={tokenStyle}
                      >
                        {group.group_token}
                      </span>
                    </div>
                  );
                })}

                {!groups.length ? (
                  <div style={emptyStyle}>
                    No groups
                  </div>
                ) : null}
              </div>
            </div>

            {/* TERM LIST */}
            <div style={termsPaneStyle}>
              <div style={toolbarStyle}>
                <strong>
                  {selectedGroup
                    ? selectedGroup.label
                    : "Terms"}
                </strong>

                <div style={buttonBarStyle}>
                  <button
                    type="button"
                    disabled={!selectedGroup}
                    onClick={addTerm}
                  >
                    Add
                  </button>

                  <button
                    type="button"
                    disabled={!selectedTerm}
                    onClick={
                      editSelectedTerm
                    }
                  >
                    Edit
                  </button>

                  <button
                    type="button"
                    disabled={!selectedTerm}
                    onClick={
                      deleteSelectedTerm
                    }
                  >
                    Delete
                  </button>
                </div>
              </div>

              <div style={termHeaderStyle}>
                <div>Singular</div>
                <div>Plural</div>
              </div>

              <div style={listStyle}>
                {!selectedGroup ? (
                  <div style={emptyStyle}>
                    Select a group
                  </div>
                ) : (
                  <>
                    {(selectedGroup.terms || [])
                      .map((term) => {
                        const selected =
                          term.marketing_term_id ===
                          selectedTermId;

                        return (
                          <div
                            key={
                              term
                                .marketing_term_id
                            }
                            style={{
                              ...termRowStyle,
                              ...(selected
                                ? selectedRowStyle
                                : {}),
                            }}
                            onClick={() =>
                              setSelectedTermId(
                                term
                                  .marketing_term_id
                              )
                            }
                            onDoubleClick={
                              editSelectedTerm
                            }
                          >
                            <div>
                              {term.singular_term ||
                                term.term}
                            </div>

                            <div>
                              {term.plural_term ||
                                term.singular_term ||
                                term.term}
                            </div>
                          </div>
                        );
                      })}

                    {!selectedGroup.terms
                      ?.length ? (
                      <div style={emptyStyle}>
                        No terms
                      </div>
                    ) : null}
                  </>
                )}
              </div>
            </div>
          </div>
        )}
      </div>
    </div>,
    document.body
  );
}

const overlayStyle = {
  position: "fixed",
  inset: 0,
  zIndex: 2147483647,
  background: "rgba(0,0,0,.45)",
  display: "grid",
  placeItems: "center",
  padding: 16,
};

const modalStyle = {
  width: "min(1050px, 96vw)",
  height: "calc(100vh - 32px)",
  background: "#fff",
  color: "#1f2933",
  border: "1px solid #aeb6bf",
  borderRadius: 4,
  boxShadow:
    "0 12px 40px rgba(0,0,0,.30)",
  display: "flex",
  flexDirection: "column",
  overflow: "hidden",
  fontFamily: "Arial, sans-serif",
  fontSize: 13,
};

const headerStyle = {
  height: 58,
  flexShrink: 0,
  display: "flex",
  alignItems: "center",
  justifyContent: "space-between",
  padding: "0 14px",
  borderBottom:
    "1px solid #bfc6cd",
};

const eyebrowStyle = {
  color: "#66717c",
  fontSize: 9,
  fontWeight: 700,
  letterSpacing: ".08em",
};

const titleStyle = {
  marginTop: 2,
  fontSize: 17,
  fontWeight: 700,
};

const workspaceStyle = {
  display: "grid",
  gridTemplateColumns:
    "300px minmax(0, 1fr)",
  flex: 1,
  minHeight: 0,
};

const paneStyle = {
  display: "flex",
  flexDirection: "column",
  minHeight: 0,
  borderRight:
    "1px solid #bfc6cd",
};

const termsPaneStyle = {
  display: "flex",
  flexDirection: "column",
  minHeight: 0,
};

const toolbarStyle = {
  height: 40,
  flexShrink: 0,
  display: "flex",
  alignItems: "center",
  justifyContent: "space-between",
  gap: 8,
  padding: "0 8px",
  borderBottom:
    "1px solid #cbd1d6",
  background: "#f3f4f5",
};

const buttonBarStyle = {
  display: "flex",
  gap: 4,
};

const listStyle = {
  flex: 1,
  minHeight: 0,
  overflowY: "auto",
  background: "#fff",
};

const groupRowStyle = {
  height: 30,
  boxSizing: "border-box",
  display: "flex",
  alignItems: "center",
  justifyContent: "space-between",
  gap: 8,
  padding: "0 8px",
  borderBottom:
    "1px solid #eceff1",
  cursor: "default",
  userSelect: "none",
};

const tokenStyle = {
  color: "#8a939c",
  fontSize: 10,
};

const termHeaderStyle = {
  height: 28,
  flexShrink: 0,
  boxSizing: "border-box",
  display: "grid",
  gridTemplateColumns: "1fr 1fr",
  alignItems: "center",
  padding: "0 8px",
  background: "#fafafa",
  borderBottom:
    "1px solid #cbd1d6",
  color: "#68717a",
  fontSize: 11,
  fontWeight: 700,
};

const termRowStyle = {
  height: 30,
  boxSizing: "border-box",
  display: "grid",
  gridTemplateColumns: "1fr 1fr",
  alignItems: "center",
  padding: "0 8px",
  borderBottom:
    "1px solid #eceff1",
  cursor: "default",
  userSelect: "none",
};

const selectedRowStyle = {
  background: "#dcecf6",
};

const emptyStyle = {
  padding: "10px 8px",
  color: "#7a838c",
  fontSize: 12,
};

const loadingStyle = {
  padding: 16,
};

const errorStyle = {
  padding: "8px 12px",
  background: "#fff3f3",
  color: "#9b1c1c",
  borderBottom:
    "1px solid #f1caca",
};