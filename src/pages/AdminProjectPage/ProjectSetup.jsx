import {
  useEffect,
  useMemo,
  useState,
} from "react";

import {
  AdminButton,
  AdminField,
  AdminNotice,
  AdminPanel,
  AdminStack,
} from "@components/AdminLayout";

import {
  API_FOLDER,
} from "@helpers/config";


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
}) {
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
        [...playlists].sort(
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
      ]
    );


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
      className="admin-project-setup"
      onSubmit={saveSetup}
    >
      <AdminPanel
        title={`Project #${project.id} Setup`}
        actions={
          <AdminButton
            type="submit"
            disabled={
              saving
              ||
              optionsLoading
            }
          >
            {
              saving
                ? "Saving..."
                : "Save"
            }
          </AdminButton>
        }
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
            <select
              className="admin-field__control"
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
          </AdminField>

          <AdminField
            label="Property"
          >
            <select
              className="admin-field__control"
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
          </AdminField>

          <AdminField
            label="Playlist"
          >
            <select
              className="admin-field__control"
              value={
                form.playlist_id
              }
              disabled={
                optionsLoading
              }
              onChange={
                (event) =>
                  setField(
                    "playlist_id",
                    event.target.value
                  )
              }
            >
              <option value="">
                No playlist assigned
              </option>

              {
                sortedPlaylists.map(
                  (playlist) => (
                    <option
                      key={
                        playlist.playlist_id
                      }
                      value={
                        playlist.playlist_id
                      }
                    >
                      {
                        playlistLabel(
                          playlist
                        )
                      }
                    </option>
                  )
                )
              }
            </select>
          </AdminField>
        </AdminStack>
      </AdminPanel>
    </form>
  );
}
