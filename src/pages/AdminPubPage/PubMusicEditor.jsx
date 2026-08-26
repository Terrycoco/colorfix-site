import {
  useEffect,
  useMemo,
  useRef,
  useState,
} from "react";

import {
  createPortal,
} from "react-dom";

import {
  API_FOLDER,
} from "@helpers/config";


const ASSET_LIBRARY_URL =
  `${API_FOLDER}/v2/admin/asset-library/list.php`;


export default function PubMusicEditor({
  value = null,
  disabled = false,
  onApply,
  onClose,
}) {
  const [
    assets,
    setAssets,
  ] = useState([]);

  const [
    loading,
    setLoading,
  ] = useState(true);

  const [
    error,
    setError,
  ] = useState("");

  const [
    assetLibraryId,
    setAssetLibraryId,
  ] = useState("");

  const [
    volume,
    setVolume,
  ] = useState(0.35);

  const audioRef =
    useRef(null);


  useEffect(() => {
    const currentId =
      Number(
        value?.asset_library_id ||
        0
      );

    setAssetLibraryId(
      currentId > 0
        ? String(currentId)
        : ""
    );

    setVolume(
      normalizeVolume(
        value?.volume,
        0.35
      )
    );
  }, [
    value,
  ]);


  useEffect(() => {
    let cancelled =
      false;


    async function loadMusicAssets() {
      setLoading(true);
      setError("");

      try {
        const params =
          new URLSearchParams({
            asset_kind:
              "audio",

            include_inactive:
              "0",

            limit:
              "200",

            _:
              String(
                Date.now()
              ),
          });


        const res =
          await fetch(
            `${ASSET_LIBRARY_URL}?${params.toString()}`,
            {
              credentials:
                "include",
            }
          );


        const data =
          await res.json();


        if (
          !res.ok ||
          !data?.ok
        ) {
          throw new Error(
            data?.error ||
            "Could not load music assets."
          );
        }


        if (!cancelled) {
          setAssets(
            Array.isArray(
              data.items
            )
              ? data.items
              : []
          );
        }

      } catch (err) {
        if (!cancelled) {
          setError(
            err?.message ||
            "Could not load music assets."
          );
        }

      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    }


    loadMusicAssets();


    return () => {
      cancelled =
        true;
    };
  }, []);


  useEffect(() => {
    if (
      audioRef.current
    ) {
      audioRef.current.volume =
        normalizeVolume(
          volume,
          0.35
        );
    }
  }, [
    volume,
    assetLibraryId,
  ]);


  const selectedAsset =
    useMemo(
      () => {
        const id =
          Number(
            assetLibraryId ||
            0
          );


        if (!id) {
          return null;
        }


        return (
          assets.find(
            (asset) =>
              Number(
                asset
                  ?.asset_library_id ||
                0
              ) === id
          ) ||
          null
        );
      },
      [
        assetLibraryId,
        assets,
      ]
    );


  const previewUrl =
    String(
      selectedAsset?.public_url ||
      selectedAsset?.rel_path ||
      ""
    )
      .trim();


  function applyMusic() {
    const id =
      Number(
        assetLibraryId ||
        0
      );


    if (!id) {
      setError(
        "Choose a music track."
      );

      return;
    }


    onApply?.({
      asset_library_id:
        id,

      volume:
        normalizeVolume(
          volume,
          0.35
        ),
    });
  }


  return createPortal(
    <div
      style={
        overlayStyle
      }

      onMouseDown={(
        event
      ) => {
        if (
          event.target ===
          event.currentTarget &&
          !disabled
        ) {
          onClose?.();
        }
      }}
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-label="Music"

        style={
          dialogStyle
        }
      >
        <div
          style={
            headerStyle
          }
        >
          <strong>
            Music
          </strong>

          <button
            type="button"

            style={
              quietButtonStyle
            }

            disabled={
              disabled
            }

            onClick={
              onClose
            }
          >
            ×
          </button>
        </div>


        <div
          style={
            bodyStyle
          }
        >
          <label
            className="admin-field"
          >
            <span
              className="admin-field__label"
            >
              Track
            </span>

            <select
              className="admin-field__control"

              value={
                assetLibraryId
              }

              disabled={
                disabled ||
                loading
              }

              onChange={(
                event
              ) => {
                setAssetLibraryId(
                  event
                    .target
                    .value
                );

                setError(
                  ""
                );
              }}

              style={{
                width:
                  "100%",
              }}
            >
              <option value="">
                {loading
                  ? "Loading music..."
                  : "Choose music"}
              </option>

              {assets.map(
                (
                  asset
                ) => {
                  const id =
                    Number(
                      asset
                        ?.asset_library_id ||
                      0
                    );

                  if (!id) {
                    return null;
                  }


                  const title =
                    String(
                      asset?.title ||
                      `Audio #${id}`
                    );


                  return (
                    <option
                      key={
                        id
                      }

                      value={
                        String(
                          id
                        )
                      }
                    >
                      {title}
                    </option>
                  );
                }
              )}
            </select>
          </label>


          {selectedAsset ? (
            <div
              style={
                selectedStyle
              }
            >
              <div>
                <strong>
                  {
                    selectedAsset
                      .title ||
                    `Audio #${selectedAsset.asset_library_id}`
                  }
                </strong>
              </div>

              <div
                style={
                  metaStyle
                }
              >
                Asset #
                {
                  selectedAsset
                    .asset_library_id
                }

                {selectedAsset
                  ?.mime_type
                  ? ` · ${selectedAsset.mime_type}`
                  : ""}
              </div>
            </div>
          ) : null}


          {previewUrl ? (
            <audio
              ref={
                audioRef
              }

              controls

              preload="metadata"

              src={
                previewUrl
              }

              style={{
                width:
                  "100%",
              }}
            />
          ) : null}


          <label
            className="admin-field"
          >
            <span
              className="admin-field__label"
            >
              Volume
            </span>

            <div
              style={
                volumeRowStyle
              }
            >
              <input
                type="range"

                min="0"
                max="1"
                step="0.01"

                value={
                  volume
                }

                disabled={
                  disabled
                }

                onChange={(
                  event
                ) => {
                  setVolume(
                    normalizeVolume(
                      event
                        .target
                        .value,
                      0.35
                    )
                  );
                }}

                style={{
                  flex:
                    1,
                }}
              />

              <input
                className="admin-field__control"

                type="number"

                min="0"
                max="1"
                step="0.01"

                value={
                  volume
                }

                disabled={
                  disabled
                }

                onChange={(
                  event
                ) => {
                  setVolume(
                    normalizeVolume(
                      event
                        .target
                        .value,
                      0.35
                    )
                  );
                }}

                style={{
                  width:
                    78,
                }}
              />
            </div>
          </label>


          <div
            style={
              hintStyle
            }
          >
            This is a per-video override. Once you find the normal level,
            make that value the default in the YouTube recipe.
          </div>


          {error ? (
            <div
              style={
                errorStyle
              }
            >
              {error}
            </div>
          ) : null}
        </div>


        <div
          style={
            footerStyle
          }
        >
          <button
            type="button"

            style={
              quietButtonStyle
            }

            disabled={
              disabled
            }

            onClick={
              onClose
            }
          >
            Cancel
          </button>

          <button
            type="button"

            disabled={
              disabled ||
              loading
            }

            onClick={
              applyMusic
            }
          >
            Apply Music
          </button>
        </div>
      </div>
    </div>,

    document.body
  );
}


function normalizeVolume(
  value,
  fallback
) {
  const number =
    Number(
      value
    );


  if (
    !Number.isFinite(
      number
    )
  ) {
    return fallback;
  }


  return Math.max(
    0,
    Math.min(
      1,
      Math.round(
        number * 100
      ) / 100
    )
  );
}


const overlayStyle = {
  position:
    "fixed",

  inset:
    0,

  zIndex:
    2147483647,

  display:
    "grid",

  placeItems:
    "center",

  padding:
    30,

  background:
    "rgba(0, 0, 0, 0.45)",
};


const dialogStyle = {
  width:
    "min(520px, 94vw)",

  maxHeight:
    "90vh",

  display:
    "flex",

  flexDirection:
    "column",

  background:
    "#ffffff",

  border:
    "1px solid #cfd5dc",

  borderRadius:
    5,

  boxShadow:
    "0 18px 50px rgba(0,0,0,0.28)",
};


const headerStyle = {
  display:
    "flex",

  alignItems:
    "center",

  justifyContent:
    "space-between",

  padding:
    "10px 12px",

  borderBottom:
    "1px solid #d8dde3",
};


const bodyStyle = {
  display:
    "flex",

  flexDirection:
    "column",

  gap:
    14,

  padding:
    16,

  overflow:
    "auto",
};


const selectedStyle = {
  padding:
    "10px 12px",

  border:
    "1px solid #d8dde3",

  borderRadius:
    4,

  background:
    "#f8fafc",
};


const metaStyle = {
  marginTop:
    4,

  color:
    "#64748b",

  fontSize:
    12,
};


const volumeRowStyle = {
  display:
    "flex",

  alignItems:
    "center",

  gap:
    12,
};


const hintStyle = {
  color:
    "#586675",

  fontSize:
    12,

  lineHeight:
    1.4,
};


const errorStyle = {
  padding:
    "8px 10px",

  border:
    "1px solid #e4a5a5",

  background:
    "#fff5f5",

  color:
    "#8b1f1f",

  fontSize:
    12,
};


const footerStyle = {
  display:
    "flex",

  justifyContent:
    "flex-end",

  gap:
    8,

  padding:
    "10px 12px",

  borderTop:
    "1px solid #d8dde3",
};


const quietButtonStyle = {
  padding:
    "4px 8px",

  minHeight:
    0,

  border:
    "1px solid #cfd5dc",

  borderRadius:
    3,

  background:
    "#ffffff",

  color:
    "#334155",

  fontSize:
    12,

  lineHeight:
    1.2,

  fontWeight:
    500,

  cursor:
    "pointer",
};
