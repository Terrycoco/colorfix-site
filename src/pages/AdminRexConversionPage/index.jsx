import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
  AdminDetailPane,
  AdminEmptyState,
  AdminListPane,
  AdminMasterDetail,
  AdminObjectList,
  AdminObjectListItem,
} from "@components/AdminLayout";
import FetchRexButton from "@components/REX/FetchRexButton";
import RexManagementDialog from "@components/REX/RexManagementDialog";
import { API_FOLDER } from "@helpers/config";
import "./AdminRexConversionPage.css";

const PLAYLISTS_URL = `${API_FOLDER}/v2/admin/playlists/list.php`;
const INSTANCES_URL = `${API_FOLDER}/v2/admin/playlist-instances/list.php`;
const REX_PLAYLISTS_URL = `${API_FOLDER}/v2/admin/rex/playlist-summary.php`;

async function readJsonResponse(response, fallbackMessage) {
  const text = await response.text();

  if (!response.ok) {
    throw new Error(`HTTP ${response.status}: ${text.slice(0, 200)}`);
  }

  let data;
  try {
    data = JSON.parse(text);
  } catch {
    throw new Error(`${fallbackMessage}: invalid JSON response`);
  }

  if (!data?.ok) {
    throw new Error(data?.error || fallbackMessage);
  }

  return data;
}

function playlistTitleFromMap(playlistMap, playlistId) {
  const row = playlistMap.get(Number(playlistId));
  return row?.title || `Playlist #${playlistId}`;
}

function migrationAdminNote(instance) {
  const notes = String(instance?.instance_notes || "").trim();
  return notes
    ? `Migrated from PI #${instance.playlist_instance_id} — ${notes}`
    : `Migrated from PI #${instance.playlist_instance_id}`;
}

function migrationRequest(instance, playlistMap) {
  const playlistId = Number(instance?.playlist_id || 0);
  const playlistTitle = playlistTitleFromMap(playlistMap, playlistId);
  const instanceName = String(instance?.instance_name || "").trim();
  const slug = String(instance?.slug || "").trim();

  return {
    label: instanceName || `${playlistTitle} — migrated PI #${instance?.playlist_instance_id || ""}`,
    sourceKey: "",
    resolverKey: "playlist_experience",
    resourceType: "playlist",
    resourceId: playlistId,
    context: {
      experience_key: "public",
    },
    alias: slug,
    adminNote: migrationAdminNote(instance),
  };
}

function compareValues(a, b) {
  const aNumber = Number(a);
  const bNumber = Number(b);
  const bothNumeric = Number.isFinite(aNumber) && Number.isFinite(bNumber);

  if (bothNumeric) {
    return aNumber - bNumber;
  }

  return String(a || "").localeCompare(String(b || ""), undefined, {
    numeric: true,
    sensitivity: "base",
  });
}

export default function AdminRexConversionPage() {
  const gridRef = useRef(null);
  const notePopoverRef = useRef(null);
  const [playlists, setPlaylists] = useState([]);
  const [instances, setInstances] = useState([]);
  const [rexPlaylists, setRexPlaylists] = useState([]);
  const [selectedPlaylistId, setSelectedPlaylistId] = useState(null);
  const [selectedInstanceId, setSelectedInstanceId] = useState(null);
  const [sort, setSort] = useState({
    key: "playlist_instance_id",
    direction: "desc",
  });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [status, setStatus] = useState("");
  const [rexDialog, setRexDialog] = useState({
    open: false,
    reservationIds: [],
    title: "",
  });
  const [notePopover, setNotePopover] = useState(null);

  const playlistMap = useMemo(() => {
    const map = new Map();

    playlists.forEach((playlist) => {
      const id = Number(playlist?.playlist_id || 0);
      if (id > 0) {
        map.set(id, playlist);
      }
    });

    return map;
  }, [playlists]);

  const sortedInstances = useMemo(() => {
    const getValue = (instance, key) => {
      if (key === "playlist") {
        return playlistTitleFromMap(playlistMap, instance.playlist_id);
      }
      return instance?.[key];
    };

    return [...instances].sort((a, b) => {
      const result = compareValues(getValue(a, sort.key), getValue(b, sort.key));
      return sort.direction === "asc" ? result : -result;
    });
  }, [instances, playlistMap, sort.direction, sort.key]);

  function updateSort(key) {
    setSort((current) => ({
      key,
      direction: current.key === key && current.direction === "asc" ? "desc" : "asc",
    }));
  }

  function sortAria(key) {
    if (sort.key !== key) return "none";
    return sort.direction === "asc" ? "ascending" : "descending";
  }

  const selectVisibleInstance = useCallback(
    (index) => {
      const instance = sortedInstances[index];
      const instanceId = Number(instance?.playlist_instance_id || 0);
      if (!instanceId) return;

      setSelectedInstanceId(instanceId);

      requestAnimationFrame(() => {
        gridRef.current
          ?.querySelector(`[data-instance-id="${instanceId}"]`)
          ?.scrollIntoView({ block: "nearest" });
      });
    },
    [sortedInstances]
  );

  const handleInstanceGridKeyDown = useCallback(
    (event) => {
      if (event.key === "Escape") {
        setNotePopover(null);
        return;
      }

      if (event.key !== "ArrowDown" && event.key !== "ArrowUp") return;
      if (!sortedInstances.length) return;

      event.preventDefault();

      const currentIndex = sortedInstances.findIndex(
        (instance) => Number(instance.playlist_instance_id || 0) === selectedInstanceId
      );
      const fallbackIndex = event.key === "ArrowDown" ? 0 : sortedInstances.length - 1;
      const nextIndex =
        currentIndex < 0
          ? fallbackIndex
          : Math.min(
              sortedInstances.length - 1,
              Math.max(0, currentIndex + (event.key === "ArrowDown" ? 1 : -1))
            );

      selectVisibleInstance(nextIndex);
    },
    [selectVisibleInstance, selectedInstanceId, sortedInstances]
  );

  const openNotePopover = useCallback((event, instance) => {
    const note = String(instance?.instance_notes || "").trim();
    if (!note) return;

    const buttonRect = event.currentTarget.getBoundingClientRect();
    const gridRect = gridRef.current?.getBoundingClientRect();
    if (!gridRect || !gridRef.current) return;

    setNotePopover({
      instanceId: Number(instance.playlist_instance_id || 0),
      note,
      top: buttonRect.bottom - gridRect.top + gridRef.current.scrollTop + 6,
      left: buttonRect.left - gridRect.left + gridRef.current.scrollLeft,
    });
  }, []);

  useEffect(() => {
    function handleDocumentMouseDown(event) {
      if (!notePopover) return;
      const target = event.target;

      if (
        notePopoverRef.current?.contains(target) ||
        target.closest?.(".admin-rex-conversion__note-button")
      ) {
        return;
      }

      setNotePopover(null);
    }

    document.addEventListener("mousedown", handleDocumentMouseDown);
    return () => document.removeEventListener("mousedown", handleDocumentMouseDown);
  }, [notePopover]);

  useEffect(() => {
    setNotePopover(null);
  }, [sort.direction, sort.key]);

  const loadRexPlaylists = useCallback(async () => {
    const data = await readJsonResponse(
      await fetch(`${REX_PLAYLISTS_URL}?_=${Date.now()}`, {
        credentials: "include",
      }),
      "Failed to load REX playlists"
    );

    const items = Array.isArray(data.items) ? data.items : [];
    setRexPlaylists(items);

    setSelectedPlaylistId((current) => {
      if (current && items.some((item) => Number(item.playlist_id) === Number(current))) {
        return current;
      }

      return items[0]?.playlist_id ?? null;
    });

    return items;
  }, []);

  const loadPage = useCallback(async () => {
    setLoading(true);
    setError("");

    try {
      const [playlistData, instanceData] = await Promise.all([
        readJsonResponse(
          await fetch(`${PLAYLISTS_URL}?limit=500&_=${Date.now()}`, {
            credentials: "include",
          }),
          "Failed to load playlists"
        ),
        readJsonResponse(
          await fetch(`${INSTANCES_URL}?active=1&_=${Date.now()}`, {
            credentials: "include",
          }),
          "Failed to load playlist instances"
        ),
      ]);

      setPlaylists(Array.isArray(playlistData.items) ? playlistData.items : []);
      setInstances(Array.isArray(instanceData.items) ? instanceData.items : []);

      await loadRexPlaylists();
    } catch (err) {
      setError(err?.message || "Failed to load REX conversion data");
    } finally {
      setLoading(false);
    }
  }, [loadRexPlaylists]);

  useEffect(() => {
    void loadPage();
  }, [loadPage]);

  async function handleRexCreated(instance, result) {
    setStatus(
      `REX #${result.reservationId} created for Playlist #${instance.playlist_id}.`
    );
    setError("");

    try {
      await loadRexPlaylists();
      setSelectedPlaylistId(Number(instance.playlist_id));
    } catch (err) {
      setError(err?.message || "REX was created, but the playlist summary could not refresh.");
    }
  }

  return (
    <div className="admin-rex-conversion">
      <AdminMasterDetail
        storageKey="admin-rex-conversion-list-width"
        defaultListWidth={320}
        minListWidth={260}
        maxListWidth={500}
        list={
          <AdminListPane title="REX Playlists">
            {loading ? (
              <AdminEmptyState title="Loading REX playlists" />
            ) : rexPlaylists.length === 0 ? (
              <AdminEmptyState
                title="No REX playlists yet"
                message="Use Fetch REX on a legacy Playlist Instance to begin."
              />
            ) : (
              <AdminObjectList ariaLabel="REX playlists">
                {rexPlaylists.map((playlist) => {
                  const ids = Array.isArray(playlist.rex) ? playlist.rex : [];
                  const count = ids.length;

                  return (
                    <AdminObjectListItem
                      key={playlist.playlist_id}
                      id={playlist.playlist_id}
                      title={playlist.title || `Playlist #${playlist.playlist_id}`}
                      meta={[
                        playlist.type || "",
                        Array.isArray(playlist.sources) && playlist.sources.length
                          ? `Sources: ${playlist.sources.join(", ")}`
                          : "",
                      ]}
                      selected={Number(selectedPlaylistId) === Number(playlist.playlist_id)}
                      status={{
                        active: count > 0,
                        count,
                        label: `${count} active REX reservation${count === 1 ? "" : "s"}`,
                      }}
                      onSelect={() => setSelectedPlaylistId(playlist.playlist_id)}
                      onStatusClick={() => {
                        if (!ids.length) return;

                        setRexDialog({
                          open: true,
                          reservationIds: ids,
                          title: playlist.title || `Playlist #${playlist.playlist_id}`,
                        });
                      }}
                    />
                  );
                })}
              </AdminObjectList>
            )}
          </AdminListPane>
        }
        detail={
          <AdminDetailPane
            ariaLabel="Playlist Instance conversion grid"
            className="admin-rex-conversion__detail"
          >
            <div className="admin-rex-conversion__header">
              <div>
                <h1>REX Conversion</h1>
                <p>
                  Legacy Playlist Instances are migration clues only. Fetch REX creates
                  reservations on the underlying Playlist.
                </p>
              </div>
            </div>

            {error ? (
              <div className="admin-rex-conversion__message admin-rex-conversion__message--error">
                {error}
              </div>
            ) : null}

            {status ? (
              <div className="admin-rex-conversion__message admin-rex-conversion__message--status">
                {status}
              </div>
            ) : null}

            {loading ? (
              <AdminEmptyState title="Loading Playlist Instances" />
            ) : instances.length === 0 ? (
              <AdminEmptyState
                title="No active Playlist Instances"
                message="There is nothing left in the active PI migration queue."
              />
            ) : (
              <div
                ref={gridRef}
                className="admin-rex-conversion__grid-wrap"
                tabIndex={0}
                onKeyDown={handleInstanceGridKeyDown}
                aria-label="Playlist Instance conversion rows"
              >
                <table className="admin-rex-conversion__grid">
                  <thead>
                    <tr>
                      <th aria-sort={sortAria("playlist_instance_id")}>
                        <button type="button" onClick={() => updateSort("playlist_instance_id")}>
                          PI ID
                        </button>
                      </th>
                      <th aria-sort={sortAria("instance_name")}>
                        <button type="button" onClick={() => updateSort("instance_name")}>
                          Instance name
                        </button>
                      </th>
                      <th aria-sort={sortAria("playlist")}>
                        <button type="button" onClick={() => updateSort("playlist")}>
                          Playlist
                        </button>
                      </th>
                      <th aria-sort={sortAria("instance_notes")}>
                        <button type="button" onClick={() => updateSort("instance_notes")}>
                          Notes
                        </button>
                      </th>
                      <th aria-sort={sortAria("slug")}>
                        <button type="button" onClick={() => updateSort("slug")}>
                          Slug
                        </button>
                      </th>
                      <th aria-label="Review" />
                      <th aria-label="Action" />
                    </tr>
                  </thead>

                  <tbody>
                    {sortedInstances.map((instance) => {
                      const playlistId = Number(instance.playlist_id || 0);
                      const playlistTitle = playlistTitleFromMap(playlistMap, playlistId);
                      const instanceId = Number(instance.playlist_instance_id || 0);
                      const isSelected = selectedInstanceId === instanceId;

                      return (
                        <tr
                          key={instance.playlist_instance_id}
                          data-instance-id={instanceId}
                          className={isSelected ? "is-selected" : ""}
                          onClick={() => {
                            setSelectedInstanceId(instanceId);
                            gridRef.current?.focus({ preventScroll: true });
                          }}
                        >
                          <td className="admin-rex-conversion__id">
                            #{instance.playlist_instance_id}
                          </td>

                          <td>
                            {instance.instance_name || "—"}
                          </td>

                          <td>
                            <span className="admin-rex-conversion__playlist-title">
                              {playlistTitle}
                            </span>
                            <span className="admin-rex-conversion__playlist-id">
                              #{playlistId}
                            </span>
                          </td>

                          <td className="admin-rex-conversion__notes">
                            {instance.instance_notes ? (
                              <button
                                type="button"
                                className="admin-rex-conversion__note-button"
                                onClick={(event) => openNotePopover(event, instance)}
                                aria-label={`Read note for ${
                                  instance.instance_name ||
                                  `PI #${instance.playlist_instance_id}`
                                }`}
                              >
                                {instance.instance_notes}
                              </button>
                            ) : (
                              "—"
                            )}
                          </td>

                          <td className="admin-rex-conversion__slug">
                            {instance.slug || "—"}
                          </td>

                          <td className="admin-rex-conversion__review">
                            <a
                              className="admin-rex-conversion__play"
                              href={instance.player_url || "#"}
                              target="_blank"
                              rel="noreferrer"
                              aria-label={`Play ${instance.instance_name || `PI #${instance.playlist_instance_id}`}`}
                              title="Play playlist"
                            >
                              ▶
                            </a>
                          </td>

                          <td className="admin-rex-conversion__action">
                            <FetchRexButton
                              request={migrationRequest(instance, playlistMap)}
                              buttonLabel="Fetch"
                              disabled={playlistId <= 0}
                              onCreated={(result) => void handleRexCreated(instance, result)}
                            />
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
                {notePopover ? (
                  <div
                    ref={notePopoverRef}
                    className="admin-rex-conversion__note-popover"
                    style={{
                      top: notePopover.top,
                      left: notePopover.left,
                    }}
                    role="dialog"
                    aria-label={`Note for PI #${notePopover.instanceId}`}
                  >
                    {notePopover.note}
                  </div>
                ) : null}
              </div>
            )}
          </AdminDetailPane>
        }
      />

      <RexManagementDialog
        open={rexDialog.open}
        reservationIds={rexDialog.reservationIds}
        title={rexDialog.title}
        onClose={() => {
          setRexDialog({
            open: false,
            reservationIds: [],
            title: "",
          });
        }}
      />
    </div>
  );
}
