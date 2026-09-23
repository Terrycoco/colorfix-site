import {
  useCallback,
  useEffect,
  useMemo,
  useState,
} from "react";

import {
  AdminButton,
  AdminDialog,
  AdminMetaText,
  AdminNotice,
} from "@components/AdminLayout";

import {
  API_FOLDER,
} from "@helpers/config";

import "./PlaylistRexDialog.css";


const ENDPOINT =
  `${API_FOLDER}/v2/admin/rex/playlist-experiences.php`;


const EXPERIENCE_ORDER = [
  {
    key: "public",
    label: "Public",
  },
  {
    key: "concept",
    label: "Concept",
  },
  {
    key: "client",
    label: "Client",
  },
];


async function readJsonResponse(
  response,
  fallbackMessage
) {
  const text =
    await response.text();

  let data =
    null;


  try {
    data =
      text
        ? JSON.parse(text)
        : {};
  } catch {
    throw new Error(
      `${fallbackMessage}: invalid JSON response`
    );
  }


  if (
    !response.ok ||
    !data?.ok
  ) {
    throw new Error(
      data?.error ||
      fallbackMessage
    );
  }


  return data;
}


function absoluteRexUrl(
  rex
) {
  const raw =
    String(
      rex?.url ||
      rex?.public_url ||
      (
        rex?.token
          ? `/t/${rex.token}`
          : ""
      )
    ).trim();


  if (!raw) {
    return "";
  }


  try {
    return new URL(
      raw,
      window.location.origin
    ).href;
  } catch {
    return raw;
  }
}


function childHealth(
  experience
) {
  const children =
    Array.isArray(
      experience?.children
    )
      ? experience.children
      : [];


  if (!children.length) {
    return {
      total: 0,
      ready: 0,
      ok:
        [
          "ready",
          "not_applicable",
        ].includes(
          String(
            experience?.status ||
            ""
          )
        ),
    };
  }


  const ready =
    children.filter(
      (child) =>
        [
          "ready",
          "retained",
        ].includes(
          String(
            child?.status ||
            ""
          )
        )
    ).length;


  return {
    total:
      children.length,

    ready,

    ok:
      ready ===
      children.length,
  };
}


export default function PlaylistRexDialog({
  open = false,
  playlistId = null,
  title = "",
  onClose,
}) {
  const id =
    Number(
      playlistId ||
      0
    );


  const [
    data,
    setData,
  ] = useState(null);

  const [
    loading,
    setLoading,
  ] = useState(false);

  const [
    fetching,
    setFetching,
  ] = useState(false);

  const [
    error,
    setError,
  ] = useState("");

  const [
    copiedKey,
    setCopiedKey,
  ] = useState("");


  const subtitle =
    useMemo(
      () =>
        [
          `Playlist #${id || "—"}`,
          title ||
            data?.playlist_title ||
            "",
        ]
          .filter(Boolean)
          .join(" — "),
      [
        data?.playlist_title,
        id,
        title,
      ]
    );


  const load =
    useCallback(
      async () => {
        if (
          !open ||
          !id
        ) {
          return;
        }


        setLoading(true);
        setError("");


        try {
          const params =
            new URLSearchParams({
              playlist_id:
                String(id),

              _:
                String(
                  Date.now()
                ),
            });


          const next =
            await readJsonResponse(
              await fetch(
                `${ENDPOINT}?${params.toString()}`,
                {
                  credentials:
                    "include",
                }
              ),
              "Could not inspect Playlist REX."
            );


          setData(
            next.item ||
            null
          );

        } catch (err) {
          setData(null);

          setError(
            err?.message ||
            "Could not inspect Playlist REX."
          );

        } finally {
          setLoading(false);
        }
      },
      [
        id,
        open,
      ]
    );


  useEffect(() => {
    if (!open) {
      setData(null);
      setError("");
      setCopiedKey("");
      return;
    }


    void load();
  }, [
    load,
    open,
  ]);


  async function fetchRex() {
    if (
      !id ||
      fetching
    ) {
      return;
    }


    setFetching(true);
    setError("");


    try {
      await readJsonResponse(
        await fetch(
          ENDPOINT,
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
                playlist_id:
                  id,
              }),
          }
        ),
        "Could not fetch Playlist REX."
      );


      await load();

    } catch (err) {
      setError(
        err?.message ||
        "Could not fetch Playlist REX."
      );

    } finally {
      setFetching(false);
    }
  }


  async function copyUrl(
    experienceKey,
    url
  ) {
    if (!url) {
      return;
    }


    try {
      await navigator
        .clipboard
        .writeText(
          url
        );

      setCopiedKey(
        experienceKey
      );

      window.setTimeout(
        () => {
          setCopiedKey(
            (current) =>
              current ===
              experienceKey
                ? ""
                : current
          );
        },
        1200
      );

    } catch {
      setError(
        "Could not copy the REX URL."
      );
    }
  }


  function openExperience(
    url
  ) {
    if (!url) {
      return;
    }


    onClose?.();

    window.location.assign(
      url
    );
  }


  const experiences =
    data?.experiences &&
    typeof data.experiences ===
      "object"
      ? data.experiences
      : {};


  return (
    <AdminDialog
      open={open}
      title="REX Outside Links"
      width={720}
      onCancel={onClose}
      onClose={onClose}
      actions={[
        {
          label:
            "Close",

          variant:
            "secondary",

          onClick:
            onClose,
        },
      ]}
    >
      <div className="playlist-rex-dialog">

        <div className="playlist-rex-dialog__heading">
          <strong>
            {subtitle}
          </strong>
        </div>


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
          loading &&
          !data
            ? (
                <AdminMetaText>
                  Checking REX…
                </AdminMetaText>
              )
            : null
        }


        {
          !loading
          ? (
              <div className="playlist-rex-dialog__rows">
                {
                  EXPERIENCE_ORDER.map(
                    ({
                      key,
                      label,
                    }) => {
                      const experience =
                        experiences[key] ||
                        null;

                      const rex =
                        experience?.playlist_rex ||
                        null;

                      const url =
                        absoluteRexUrl(
                          rex
                        );

                      const health =
                        childHealth(
                          experience
                        );


                      return (
                        <div
                          className="playlist-rex-dialog__row"
                          key={key}
                        >
                          <div className="playlist-rex-dialog__label">
                            {label}:
                          </div>


                          <div className="playlist-rex-dialog__value">
                            {
                              url
                                ? (
                                    <button
                                      type="button"
                                      className="playlist-rex-dialog__url"
                                      title="Open exactly as the outside user sees it"
                                      onClick={() =>
                                        openExperience(
                                          url
                                        )
                                      }
                                    >
                                      {url}
                                    </button>
                                  )
                                : (
                                    <span className="playlist-rex-dialog__missing">
                                      not created yet
                                    </span>
                                  )
                            }
                          </div>


                          <div className="playlist-rex-dialog__actions">
                            {
                              url
                                ? (
                                    <>
                                      <AdminButton
                                        type="button"
                                        variant="secondary"
                                        compact
                                        onClick={() =>
                                          copyUrl(
                                            key,
                                            url
                                          )
                                        }
                                      >
                                        {
                                          copiedKey ===
                                          key
                                            ? "Copied"
                                            : "Copy"
                                        }
                                      </AdminButton>

                                      <span
                                        className={
                                          health.ok
                                            ? "playlist-rex-dialog__health is-good"
                                            : "playlist-rex-dialog__health is-bad"
                                        }
                                        title={
                                          health.total
                                            ? `${health.ready}/${health.total} REX children ready`
                                            : "No REX children required"
                                        }
                                      >
                                        {
                                          health.ok
                                            ? "✓"
                                            : "⚠"
                                        }

                                        {
                                          health.total
                                            ? ` ${health.ready}/${health.total}`
                                            : ""
                                        }
                                      </span>
                                    </>
                                  )
                                : (
                                    <AdminButton
                                      type="button"
                                      variant="secondary"
                                      compact
                                      disabled={
                                        fetching
                                      }
                                      onClick={
                                        fetchRex
                                      }
                                    >
                                      {
                                        fetching
                                          ? "Fetching…"
                                          : "Fetch"
                                      }
                                    </AdminButton>
                                  )
                            }
                          </div>
                        </div>
                      );
                    }
                  )
                }
              </div>
            )
          : null
        }


        <AdminMetaText>
          Click an existing REX URL to close this dialog and test the outside experience.
        </AdminMetaText>

      </div>
    </AdminDialog>
  );
}
