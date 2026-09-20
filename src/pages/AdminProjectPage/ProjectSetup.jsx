import {
  useEffect,
  useMemo,
  useState,
} from "react";

import {
  AdminField,
  AdminNotice,
  AdminStack,
} from "@components/AdminLayout";

import {
  API_FOLDER,
} from "@helpers/config";

import {
  useAppState,
} from "@context/AppStateContext";


const SAVE_URL =
  `${API_FOLDER}/v2/admin/projects/save.php`;

const CLIENTS_URL =
  `${API_FOLDER}/v2/admin/clients/list.php`;

const PROPERTIES_URL =
  `${API_FOLDER}/v2/admin/properties/list.php`;

const PLAYLISTS_URL =
  `${API_FOLDER}/v2/admin/playlists/list.php`;


function nullableSelectValue(value) {
  if (
    value === null
    ||
    value === undefined
    ||
    value === ""
  ) {
    return "";
  }

  return String(value);
}


function nullableId(value) {
  const id =
    Number(value || 0);

  return id > 0
    ? id
    : null;
}


function rowsFromResponse(
  data,
  preferredKeys = []
) {
  for (const key of preferredKeys) {
    if (Array.isArray(data?.[key])) {
      return data[key];
    }
  }

  if (Array.isArray(data?.items)) {
    return data.items;
  }

  if (Array.isArray(data?.rows)) {
    return data.rows;
  }

  return [];
}


function clientLabel(client) {
  const first =
    String(
      client?.first_name ||
      ""
    ).trim();

  const last =
    String(
      client?.last_name ||
      ""
    ).trim();

  const combined =
    [first, last]
      .filter(Boolean)
      .join(" ")
      .trim();

  return (
    combined
    ||
    String(
      client?.name ||
      client?.email ||
      `Client #${client?.id || ""}`
    )
  );
}


function propertyLabel(property) {
  return String(
    property?.name
    ||
    property?.address_label
    ||
    property?.full_address
    ||
    `Property #${property?.id || ""}`
  );
}


function playlistLabel(playlist) {
  return String(
    playlist?.title
    ||
    playlist?.slug
    ||
    `Playlist #${playlist?.playlist_id || ""}`
  );
}


async function fetchJson(url) {
  const res =
    await fetch(
      `${url}?_=${Date.now()}`,
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
      JSON.parse(text);
  } catch {
    throw new Error(
      `HTTP ${res.status}: ${text.slice(0, 250)}`
    );
  }

  if (
    !res.ok
    ||
    data?.ok === false
  ) {
    throw new Error(
      data?.error ||
      `HTTP ${res.status}`
    );
  }

  return data;
}


export default function ProjectSetup({
  project,
  onSaved,
  onNewPlaylist,
}) {
  const {
    workingPlaylistId,
    setWorkingPlaylistId,
    setAdminExitPath,
  } = useAppState();

  const [
    form,
    setForm,
  ] = useState({
    project_name:
      String(
        project?.project_name ||
        ""
      ),

    client_id:
      nullableSelectValue(
        project?.client_id
      ),

    property_id:
      nullableSelectValue(
        project?.property_id
      ),

    playlist_id:
      nullableSelectValue(
        project?.playlist_id
      ),
  });

  const [
    clients,
    setClients,
  ] = useState([]);

  const [
    properties,
    setProperties,
  ] = useState([]);

  const [
    playlists,
    setPlaylists,
  ] = useState([]);

  const [
    optionsLoading,
    setOptionsLoading,
  ] = useState(true);

  const [
    saving,
    setSaving,
  ] = useState(false);

  const [
    error,
    setError,
  ] = useState("");

  const [
    status,
    setStatus,
  ] = useState("");


  useEffect(() => {
    setForm({
      project_name:
        String(
          project?.project_name ||
          ""
        ),

      client_id:
        nullableSelectValue(
          project?.client_id
        ),

      property_id:
        nullableSelectValue(
          project?.property_id
        ),

      playlist_id:
        nullableSelectValue(
          project?.playlist_id
        ),
    });
  }, [
    project?.id,
    project?.project_name,
    project?.client_id,
    project?.property_id,
    project?.playlist_id,
  ]);


  useEffect(() => {
    let active = true;

    async function loadOptions() {
      setOptionsLoading(true);
      setError("");

      try {
        const [
          clientsData,
          propertiesData,
          playlistsData,
        ] =
          await Promise.all([
            fetchJson(
              CLIENTS_URL
            ),

            fetchJson(
              PROPERTIES_URL
            ),

            fetchJson(
              PLAYLISTS_URL
            ),
          ]);

        if (!active) {
          return;
        }

        setClients(
          rowsFromResponse(
            clientsData,
            [
              "clients",
            ]
          )
        );

        setProperties(
          rowsFromResponse(
            propertiesData,
            [
              "properties",
            ]
          )
        );

        setPlaylists(
          rowsFromResponse(
            playlistsData,
            [
              "playlists",
            ]
          )
        );

      } catch (err) {
        if (active) {
          setError(
            err?.message ||
            "Failed to load Setup choices."
          );
        }

      } finally {
        if (active) {
          setOptionsLoading(false);
        }
      }
    }

    loadOptions();

    return () => {
      active = false;
    };
  }, []);


  const sortedClients =
    useMemo(
      () =>
        [...clients].sort(
          (a, b) =>
            clientLabel(a)
              .localeCompare(
                clientLabel(b),
                undefined,
                {
                  sensitivity:
                    "base",
                }
              )
        ),
      [
        clients,
      ]
    );


  const sortedProperties =
    useMemo(
      () =>
        [...properties].sort(
          (a, b) =>
            propertyLabel(a)
              .localeCompare(
                propertyLabel(b),
                undefined,
                {
                  sensitivity:
                    "base",
                }
              )
        ),
      [
        properties,
      ]
    );


  const sortedPlaylists =
    useMemo(
      () =>
        playlists
          .filter(
            (playlist) =>
              Number(
                playlist?.project_id ||
                0
              ) ===
              Number(
                project?.id ||
                0
              )
          )
          .sort(
            (a, b) =>
              playlistLabel(a)
                .localeCompare(
                  playlistLabel(b),
                  undefined,
                  {
                    sensitivity:
                      "base",
                  }
                )
          ),
      [
        playlists,
        project?.id,
      ]
    );


  useEffect(() => {
    const workingId =
      Number(
        workingPlaylistId ||
        0
      );

    const workingBelongsToProject =
      workingId > 0
      &&
      sortedPlaylists.some(
        (playlist) =>
          Number(
            playlist?.playlist_id ||
            0
          ) === workingId
      );

    if (workingBelongsToProject) {
      return;
    }

    const savedProjectPlaylistId =
      Number(
        project?.playlist_id ||
        0
      );

    const savedPlaylistBelongsToProject =
      savedProjectPlaylistId > 0
      &&
      sortedPlaylists.some(
        (playlist) =>
          Number(
            playlist?.playlist_id ||
            0
          ) === savedProjectPlaylistId
      );

    setWorkingPlaylistId(
      savedPlaylistBelongsToProject
        ? savedProjectPlaylistId
        : null
    );
  }, [
    project?.id,
    project?.playlist_id,
    sortedPlaylists,
    workingPlaylistId,
    setWorkingPlaylistId,
  ]);


  function selectWorkingPlaylist(
    playlistId
  ) {
    const id =
      nullableId(
        playlistId
      );

    setWorkingPlaylistId(
      id
    );

    setField(
      "playlist_id",
      id
        ? String(id)
        : ""
    );
  }


  function projectReturnPath() {
    if (typeof window === "undefined") {
      return "/admin/project#setup";
    }

    return `${window.location.pathname}${window.location.search || ""}#setup`;
  }


  function openClientAdmin() {
    const clientId =
      nullableId(
        form.client_id
      );

    setAdminExitPath(
      projectReturnPath()
    );

    const params =
      new URLSearchParams();

    if (clientId) {
      params.set(
        "client_id",
        String(clientId)
      );
    } else {
      params.set(
        "action",
        "new"
      );
    }

    window.location.assign(
      `/admin/clients?${params.toString()}`
    );
  }


  function openPropertyAdmin() {
    const propertyId =
      nullableId(
        form.property_id
      );

    setAdminExitPath(
      projectReturnPath()
    );

    const params =
      new URLSearchParams();

    if (propertyId) {
      params.set(
        "property_id",
        String(propertyId)
      );
    } else {
      params.set(
        "action",
        "new"
      );

      const clientId =
        nullableId(
          form.client_id
        );

      if (clientId) {
        params.set(
          "client_id",
          String(clientId)
        );
      }
    }

    window.location.assign(
      `/admin/properties?${params.toString()}`
    );
  }


  function setField(
    field,
    value
  ) {
    setForm(
      (current) => ({
        ...current,
        [field]:
          value,
      })
    );

    setStatus("");
    setError("");
  }


  async function saveSetup(
    event
  ) {
    event.preventDefault();

    setSaving(true);
    setError("");
    setStatus("");

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
              JSON.stringify({
                project_id:
                  Number(
                    project.id
                  ),

                project_name:
                  String(
                    form.project_name ||
                    ""
                  ).trim()
                  || null,

                client_id:
                  nullableId(
                    form.client_id
                  ),

                property_id:
                  nullableId(
                    form.property_id
                  ),

                playlist_id:
                  nullableId(
                    form.playlist_id
                  ),
              }),
          }
        );

      const text =
        await res.text();

      let data = null;

      try {
        data =
          JSON.parse(text);
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
          data?.error ||
          "Failed to save Project Setup."
        );
      }

      const savedProject =
        data?.project
        || {
          ...project,

          project_name:
            String(
              form.project_name ||
              ""
            ).trim()
            || null,

          client_id:
            nullableId(
              form.client_id
            ),

          property_id:
            nullableId(
              form.property_id
            ),

          playlist_id:
            nullableId(
              form.playlist_id
            ),
        };

      setWorkingPlaylistId(
        nullableId(
          savedProject?.playlist_id
        )
      );

      onSaved?.(
        savedProject
      );

      setStatus(
        "Project Setup saved."
      );

    } catch (err) {
      setError(
        err?.message ||
        "Failed to save Project Setup."
      );

    } finally {
      setSaving(false);
    }
  }


  return (
    <form
      id="admin-project-setup-form"
      className="admin-project-setup"
      onSubmit={saveSetup}
    >
      <AdminStack>
          {
            error
              ? (
                  <AdminNotice variant="danger">
                    {error}
                  </AdminNotice>
                )
              : null
          }

          {
            status
              ? (
                  <AdminNotice variant="success">
                    {status}
                  </AdminNotice>
                )
              : null
          }

          <AdminField
            label="Project Name"
          >
            <input
              className="admin-field__control"
              style={{
                maxWidth:
                  "620px",
              }}
              type="text"
              value={
                form.project_name
              }
              onChange={
                (event) =>
                  setField(
                    "project_name",
                    event.target.value
                  )
              }
            />
          </AdminField>

          <AdminField
            label="Client"
          >
            <div
              style={{
                display:
                  "flex",
                alignItems:
                  "center",
                gap:
                  "8px",
                maxWidth:
                  "620px",
              }}
            >
              <select
                className="admin-field__control"
                style={{
                  flex:
                    "1 1 auto",
                  minWidth:
                    0,
                }}
                value={
                  form.client_id
                }
                disabled={
                  optionsLoading
                }
                onChange={
                  (event) =>
                    setField(
                      "client_id",
                      event.target.value
                    )
                }
              >
                <option value="">
                  No client assigned
                </option>

                {
                  sortedClients.map(
                    (client) => (
                      <option
                        key={
                          client.id
                        }
                        value={
                          client.id
                        }
                      >
                        {
                          clientLabel(
                            client
                          )
                        }
                      </option>
                    )
                  )
                }
              </select>

              <button
                type="button"
                title={
                  form.client_id
                    ? "Edit client"
                    : "New client"
                }
                aria-label={
                  form.client_id
                    ? "Edit client"
                    : "New client"
                }
                onClick={
                  openClientAdmin
                }
                style={{
                  flex:
                    "0 0 36px",
                  width:
                    "36px",
                  height:
                    "36px",
                  border:
                    "1px solid #bbb",
                  borderRadius:
                    "4px",
                  background:
                    form.client_id
                      ? "#fff"
                      : "#e8f5e9",
                  color:
                    form.client_id
                      ? "inherit"
                      : "#1b7f32",
                  cursor:
                    "pointer",
                  fontSize:
                    "18px",
                  lineHeight:
                    1,
                }}
              >
                {
                  form.client_id
                    ? "✎"
                    : "+"
                }
              </button>
            </div>
          </AdminField>

          <AdminField
            label="Property"
          >
            <div
              style={{
                display:
                  "flex",
                alignItems:
                  "center",
                gap:
                  "8px",
                maxWidth:
                  "620px",
              }}
            >
              <select
                className="admin-field__control"
                style={{
                  flex:
                    "1 1 auto",
                  minWidth:
                    0,
                }}
                value={
                  form.property_id
                }
                disabled={
                  optionsLoading
                }
                onChange={
                  (event) =>
                    setField(
                      "property_id",
                      event.target.value
                    )
                }
              >
                <option value="">
                  No property assigned
                </option>

                {
                  sortedProperties.map(
                    (property) => (
                      <option
                        key={
                          property.id
                        }
                        value={
                          property.id
                        }
                      >
                        {
                          propertyLabel(
                            property
                          )
                        }
                      </option>
                    )
                  )
                }
              </select>

              <button
                type="button"
                title={
                  form.property_id
                    ? "Edit property"
                    : "New property"
                }
                aria-label={
                  form.property_id
                    ? "Edit property"
                    : "New property"
                }
                onClick={
                  openPropertyAdmin
                }
                style={{
                  flex:
                    "0 0 36px",
                  width:
                    "36px",
                  height:
                    "36px",
                  border:
                    "1px solid #bbb",
                  borderRadius:
                    "4px",
                  background:
                    form.property_id
                      ? "#fff"
                      : "#e8f5e9",
                  color:
                    form.property_id
                      ? "inherit"
                      : "#1b7f32",
                  cursor:
                    "pointer",
                  fontSize:
                    "18px",
                  lineHeight:
                    1,
                }}
              >
                {
                  form.property_id
                    ? "✎"
                    : "+"
                }
              </button>
            </div>
          </AdminField>

          <AdminField
            label="Working Playlist"
          >
            <div
              style={{
                display:
                  "flex",
                alignItems:
                  "flex-start",
                gap:
                  "8px",
                maxWidth:
                  "620px",
              }}
            >
              <div
                role="listbox"
                aria-label="Working Playlist"
                style={{
                  flex:
                    "1 1 auto",
                  minWidth:
                    0,
                  border:
                    "1px solid #c8c8c8",
                  borderRadius:
                    "4px",
                  overflow:
                    "hidden",
                  background:
                    "#fff",
                }}
              >
                {
                  sortedPlaylists.length === 0
                    ? (
                        <div
                          style={{
                            padding:
                              "9px 8px",
                            color:
                              "#777",
                            fontStyle:
                              "italic",
                          }}
                        >
                          No playlists tagged to this project
                        </div>
                      )
                    : sortedPlaylists.map(
                        (playlist) => {
                          const playlistId =
                            Number(
                              playlist.playlist_id ||
                              0
                            );

                          const selected =
                            playlistId > 0
                            &&
                            playlistId ===
                              Number(
                                workingPlaylistId
                                ||
                                form.playlist_id
                                ||
                                0
                              );

                          return (
                            <button
                              key={
                                playlist.playlist_id
                              }
                              type="button"
                              role="option"
                              aria-selected={
                                selected
                              }
                              onClick={
                                () =>
                                  selectWorkingPlaylist(
                                    playlist.playlist_id
                                  )
                              }
                              style={{
                                display:
                                  "block",
                                width:
                                  "100%",
                                padding:
                                  "5px 8px",
                                border:
                                  0,
                                borderBottom:
                                  "1px solid #eee",
                                background:
                                  selected
                                    ? "var(--admin-selected)"
                                    : "transparent",
                                color:
                                  "inherit",
                                textAlign:
                                  "left",
                                cursor:
                                  "pointer",
                                font:
                                  "inherit",
                              }}
                            >
                              {
                                playlistLabel(
                                  playlist
                                )
                              }
                            </button>
                          );
                        }
                      )
                }
              </div>

              <button
                type="button"
                title="New playlist"
                aria-label="New playlist"
                onClick={
                  onNewPlaylist
                }
                style={{
                  flex:
                    "0 0 36px",
                  width:
                    "36px",
                  height:
                    "36px",
                  border:
                    "1px solid #9bbf9f",
                  borderRadius:
                    "4px",
                  background:
                    "#e8f5e9",
                  color:
                    "#1b7f32",
                  cursor:
                    "pointer",
                  fontSize:
                    "22px",
                  lineHeight:
                    1,
                  fontWeight:
                    600,
                }}
              >
                +
              </button>
            </div>
          </AdminField>
      </AdminStack>
    </form>
  );
}
