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
const REX_PREVIEW_URL = `${API_FOLDER}/v2/admin/rex/preview.php`;
const REX_CREATE_URL = `${API_FOLDER}/v2/admin/rex/create.php`;
const REX_CREATE_ALIAS_URL = `${API_FOLDER}/v2/admin/rex/create-alias.php`;
const REX_LINK_VIEWERS_URL = `${API_FOLDER}/v2/admin/rex/link-playlist-viewers.php`;
const REX_PLAYLIST_URL = `${API_FOLDER}/v2/admin/rex/playlist-url.php`;

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
    resolverKey: "playlist_experience",
    resourceType: "playlist",
    resourceId: playlistId,
    context: {
      experience_key: "public",
    },
    alias: slug,
    adminNote: migrationAdminNote(instance),
    reuseExisting: true,
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
  const [batch, setBatch] = useState({
    running: false,
    total: 0,
    done: 0,
    created: 0,
    failed: 0,
  });
  const [linkingViewers, setLinkingViewers] = useState(false);
  const [rexPreviewUrls, setRexPreviewUrls] = useState({});

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

  const scrollInstanceIntoView = useCallback((instanceId, block = "center") => {
    if (!instanceId) return;

    requestAnimationFrame(() => {
      gridRef.current
        ?.querySelector(`[data-instance-id="${instanceId}"]`)
        ?.scrollIntoView({ block, behavior: "smooth" });
    });
  }, []);

  const selectInstance = useCallback(
    (instance, { scroll = true, block = "center" } = {}) => {
      const instanceId = Number(instance?.playlist_instance_id || 0);
      const playlistId = Number(instance?.playlist_id || 0);
      if (!instanceId) return;

      setSelectedInstanceId(instanceId);
      if (playlistId > 0) {
        setSelectedPlaylistId(playlistId);
      }

      if (scroll) {
        scrollInstanceIntoView(instanceId, block);
      }
    },
    [scrollInstanceIntoView]
  );

  const selectPlaylist = useCallback(
    (playlistId) => {
      const normalizedPlaylistId = Number(playlistId || 0);
      setSelectedPlaylistId(normalizedPlaylistId || null);

      if (normalizedPlaylistId <= 0) return;

      const matchingInstance = sortedInstances.find(
        (instance) => Number(instance?.playlist_id || 0) === normalizedPlaylistId
      );

      if (matchingInstance) {
        selectInstance(matchingInstance, { scroll: true, block: "center" });
      }
    },
    [selectInstance, sortedInstances]
  );

  const selectVisibleInstance = useCallback(
    (index) => {
      const instance = sortedInstances[index];
      selectInstance(instance, { scroll: true, block: "nearest" });
    },
    [selectInstance, sortedInstances]
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

  useEffect(() => {
    if (!selectedPlaylistId || !sortedInstances.length) return;

    const selectedInstance = sortedInstances.find(
      (instance) => Number(instance?.playlist_instance_id || 0) === Number(selectedInstanceId || 0)
    );

    if (selectedInstance && Number(selectedInstance?.playlist_id || 0) === Number(selectedPlaylistId)) {
      return;
    }

    const matchingInstance = sortedInstances.find(
      (instance) => Number(instance?.playlist_id || 0) === Number(selectedPlaylistId)
    );

    if (matchingInstance) {
      selectInstance(matchingInstance, { scroll: true, block: "center" });
    }
  }, [selectInstance, selectedInstanceId, selectedPlaylistId, sortedInstances]);

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

  useEffect(() => {
    let cancelled = false;

    async function loadRexPreviewUrls() {
      const playlistIds = Array.from(new Set(
        instances
          .map((instance) => Number(instance?.playlist_id || 0))
          .filter((playlistId) => playlistId > 0)
      ));

      if (!playlistIds.length) {
        setRexPreviewUrls({});
        return;
      }

      const urlByPlaylistId = {};

      await Promise.all(
        playlistIds.map(async (playlistId) => {
          try {
            const data = await readJsonResponse(
              await fetch(
                `${REX_PLAYLIST_URL}?playlist_id=${encodeURIComponent(playlistId)}&_=${Date.now()}`,
                { credentials: "include" }
              ),
              "Failed to load REX playlist URL"
            );
            urlByPlaylistId[playlistId] = data?.item?.public_url || "";
          } catch {
            urlByPlaylistId[playlistId] = "";
          }
        })
      );

      if (!cancelled) {
        const next = {};
        instances.forEach((instance) => {
          const instanceId = Number(instance?.playlist_instance_id || 0);
          const playlistId = Number(instance?.playlist_id || 0);
          if (instanceId > 0) {
            next[instanceId] = urlByPlaylistId[playlistId] || "";
          }
        });
        setRexPreviewUrls(next);
      }
    }

    void loadRexPreviewUrls();

    return () => {
      cancelled = true;
    };
  }, [instances]);

  async function handleRexCreated(instance, result) {
    setStatus(
      `REX #${result.reservationId} ${result?.reused ? "reused" : "created"} for Playlist #${instance.playlist_id}.`
    );
    setError("");

    try {
      await loadRexPlaylists();
      setSelectedPlaylistId(Number(instance.playlist_id));
    } catch (err) {
      setError(err?.message || "REX was created, but the playlist summary could not refresh.");
    }
  }

  async function createRexFromRequest(request) {
    const context =
      request?.context && typeof request.context === "object" && !Array.isArray(request.context)
        ? request.context
        : {};
    const resourceId = Number(request?.resourceId || 0);

    await readJsonResponse(
      await fetch(REX_PREVIEW_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          resolver_key: String(request?.resolverKey || "").trim(),
          resource_type: String(request?.resourceType || "").trim(),
          resource_id: resourceId,
          context,
        }),
      }),
      "Failed to preview REX destination"
    );

    const createData = await readJsonResponse(
      await fetch(REX_CREATE_URL, {
        method: "POST",
        credentials: "include",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          label: String(request?.label || "").trim(),
          resolver_key: String(request?.resolverKey || "").trim(),
          resource_type: String(request?.resourceType || "").trim(),
          resource_id: resourceId,
          context,
          admin_note: String(request?.adminNote || "").trim() || null,
          reuse_existing: Boolean(request?.reuseExisting),
        }),
      }),
      "Failed to create REX reservation"
    );

    const reservation = createData.item || null;
    const alias = String(request?.alias || "").trim();

    if (alias && reservation?.id && !createData.reused) {
      await readJsonResponse(
        await fetch(REX_CREATE_ALIAS_URL, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            reservation_id: Number(reservation.id),
            alias,
          }),
        }),
        "Failed to create REX alias"
      );
    }

    return {
      reservation,
      reused: Boolean(createData.reused),
    };
  }

  async function fetchAllRex() {
    if (batch.running) return;

    const queue = sortedInstances.filter((instance) => Number(instance?.playlist_id || 0) > 0);
    if (!queue.length) {
      setStatus("No playlist instances are eligible for REX conversion.");
      setError("");
      return;
    }

    setBatch({
      running: true,
      total: queue.length,
      done: 0,
      created: 0,
      failed: 0,
    });
    setStatus(`Starting REX conversion for ${queue.length} playlist instance${queue.length === 1 ? "" : "s"}...`);
    setError("");

    const failures = [];
    let created = 0;

    for (let index = 0; index < queue.length; index += 1) {
      const instance = queue[index];
      const instanceId = Number(instance?.playlist_instance_id || 0);
      setSelectedInstanceId(instanceId);
      setStatus(`Fetching REX ${index + 1} of ${queue.length}: PI #${instanceId}`);

      try {
        const result = await createRexFromRequest(migrationRequest(instance, playlistMap));
        if (!result?.reused) {
          created += 1;
        }
      } catch (err) {
        failures.push({
          instanceId,
          message: err?.message || "Unknown REX conversion error",
        });
      }

      setBatch({
        running: true,
        total: queue.length,
        done: index + 1,
        created,
        failed: failures.length,
      });
    }

    setBatch((current) => ({ ...current, running: false }));

    try {
      await loadRexPlaylists();
    } catch (err) {
      failures.push({
        instanceId: null,
        message: err?.message || "REX conversion finished, but the summary could not refresh.",
      });
    }

    if (failures.length) {
      setStatus(`REX conversion finished: ${created} created, ${failures.length} failed.`);
      setError(
        failures
          .slice(0, 5)
          .map((failure) => `${failure.instanceId ? `PI #${failure.instanceId}` : "Refresh"}: ${failure.message}`)
          .join(" | ")
      );
    } else {
      setStatus(`REX conversion finished: ${created} created, 0 failed.`);
      setError("");
    }
  }

  async function linkPlaylistViewers() {
    if (linkingViewers || batch.running) return;

    setLinkingViewers(true);
    setError("");
    setStatus("Linking playlist REX reservations to palette viewer REX reservations...");

    try {
      const data = await readJsonResponse(
        await fetch(REX_LINK_VIEWERS_URL, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({}),
        }),
        "Failed to link playlist viewers"
      );
      const result = data.result || {};
      setStatus(
        `Viewer links finished: ${result.created_count || 0} created, ${result.existing_count || 0} already existed, ${result.skipped_count || 0} skipped.`
      );
      if (Number(result.skipped_count || 0) > 0 && Array.isArray(result.skipped)) {
        setError(
          result.skipped
            .slice(0, 5)
            .map((row) => `PI #${row.playlist_item_id || "?"}: ${row.reason}`)
            .join(" | ")
        );
      }
    } catch (err) {
      setError(err?.message || "Failed to link playlist viewers");
      setStatus("");
    } finally {
      setLinkingViewers(false);
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
                      onSelect={() => selectPlaylist(playlist.playlist_id)}
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
              <div className="admin-rex-conversion__header-actions">
                <button
                  type="button"
                  onClick={() => void fetchAllRex()}
                  disabled={loading || batch.running || linkingViewers || sortedInstances.length === 0}
                >
                  {batch.running ? "Fetching..." : "Fetch All"}
                </button>
                <button
                  type="button"
                  onClick={() => void linkPlaylistViewers()}
                  disabled={loading || batch.running || linkingViewers}
                >
                  {linkingViewers ? "Linking..." : "Link Viewers"}
                </button>
                {batch.total > 0 ? (
                  <span>
                    {batch.done}/{batch.total} done · {batch.created} created · {batch.failed} failed
                  </span>
                ) : null}
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
                      <th className="admin-rex-conversion__preview-heading">
                        <span>OLD</span>
                        <span>NEW</span>
                      </th>
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
                            selectInstance(instance, { scroll: false });
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
                            {rexPreviewUrls[instanceId] ? (
                              <a
                                className="admin-rex-conversion__play"
                                href={rexPreviewUrls[instanceId]}
                                target="_blank"
                                rel="noreferrer"
                                aria-label={`Play REX ${
                                  instance.instance_name || `PI #${instance.playlist_instance_id}`
                                }`}
                                title="Play REX playlist"
                              >
                                ▶
                              </a>
                            ) : (
                              <span
                                className="admin-rex-conversion__play admin-rex-conversion__play--disabled"
                                aria-label="No REX playlist URL"
                                title="No REX playlist URL"
                              >
                                ▶
                              </span>
                            )}
                          </td>

                          <td className="admin-rex-conversion__action">
                            <FetchRexButton
                              request={migrationRequest(instance, playlistMap)}
                              buttonLabel="Fetch"
                              disabled={playlistId <= 0 || batch.running}
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
