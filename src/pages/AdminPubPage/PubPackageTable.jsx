 import {
  useEffect,
  useMemo,
  useState,
} from "react";

import {
  AdminDataGrid,
  AdminEmptyState,
} from "@components/AdminLayout";

import {
  API_FOLDER,
} from "@helpers/config";

import PubPackageDrawer
  from "./PubPackageDrawer";


const PACKAGE_URL =
  `${API_FOLDER}/v2/admin/pub/package.php`;


export default function PubPackageTable() {
  const [
    assets,
    setAssets,
  ] = useState([]);

  const [
    channels,
    setChannels,
  ] = useState([]);

  const [
    assetTypes,
    setAssetTypes,
  ] = useState([]);

  const [
    channelFilter,
    setChannelFilter,
  ] = useState("");

  const [
    typeFilter,
    setTypeFilter,
  ] = useState("");

  const [
    loading,
    setLoading,
  ] = useState(true);

  const [
    error,
    setError,
  ] = useState("");

  const [
    selectedAsset,
    setSelectedAsset,
  ] = useState(null);

  const [
    drawerOpen,
    setDrawerOpen,
  ] = useState(false);

  const [
    drawerAsset,
    setDrawerAsset,
  ] = useState(null);

  const [
    drawerLoading,
    setDrawerLoading,
  ] = useState(false);

  const [
    drawerError,
    setDrawerError,
  ] = useState("");


  /*
   * PACKAGE WORKBENCH
   *
   * Expected rows:
   *
   *   packing
   *   packed
   *   error where error_stage = package
   *
   * The summary request deliberately does NOT need the actual
   * package JSON. It only needs has_package for the green checkmark.
   *
   * Package JSON is fetched only when the drawer is open.
   */
  async function loadAssets() {
    setLoading(
      true
    );

    setError(
      ""
    );


    try {
      const params =
        new URLSearchParams({
          _:
            String(
              Date.now()
            ),
        });


      if (channelFilter) {
        params.set(
          "channel",
          channelFilter
        );
      }


      if (typeFilter) {
        params.set(
          "asset_type",
          typeFilter
        );
      }


      const res =
        await fetch(
          `${PACKAGE_URL}?${params.toString()}`,
          {
            credentials:
              "include",
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
          "Failed to load Package workbench."
        );
      }


      const nextAssets =
        Array.isArray(
          data.assets
        )
          ? data.assets
          : [];


      setAssets(
        nextAssets
      );


      setChannels(
        Array.isArray(
          data?.filters?.channels
        )
          ? data.filters.channels
          : []
      );


      setAssetTypes(
        Array.isArray(
          data?.filters?.asset_types
        )
          ? data.filters.asset_types
          : []
      );


      /*
       * Keep the selected row current after Refresh.
       */
      if (
        selectedAsset
          ?.pub_asset_id
      ) {
        const refreshed =
          nextAssets.find(
            (
              asset
            ) =>
              Number(
                asset
                  .pub_asset_id
              ) ===
              Number(
                selectedAsset
                  .pub_asset_id
              )
          );


        if (refreshed) {
          setSelectedAsset(
            refreshed
          );


          if (drawerOpen) {
            await loadDrawerAsset(
              refreshed
                .pub_asset_id
            );
          }
        }
      }

    } catch (err) {
      setError(
        err?.message ||
        "Failed to load Package workbench."
      );

    } finally {
      setLoading(
        false
      );
    }
  }


  useEffect(() => {
    loadAssets();
  }, [
    channelFilter,
    typeFilter,
  ]);


  /*
   * DETAIL DRAWER
   *
   * The actual package object is loaded only when somebody
   * wants to inspect the selected row.
   */
  async function loadDrawerAsset(
    pubAssetId
  ) {
    const id =
      Number(
        pubAssetId ||
        0
      );


    if (!id) {
      return;
    }


    setDrawerLoading(
      true
    );

    setDrawerError(
      ""
    );


    try {
      const params =
        new URLSearchParams({
          pub_asset_id:
            String(
              id
            ),

          _:
            String(
              Date.now()
            ),
        });


      const res =
        await fetch(
          `${PACKAGE_URL}?${params.toString()}`,
          {
            credentials:
              "include",
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
          "Could not load Package details."
        );
      }


      setDrawerAsset(
        data.asset ||
        null
      );

    } catch (err) {
      setDrawerError(
        err?.message ||
        "Could not load Package details."
      );

    } finally {
      setDrawerLoading(
        false
      );
    }
  }


  /*
   * One click:
   *   select/highlight the row.
   *
   * If the drawer is already open, selecting another row
   * immediately changes the drawer to that asset.
   */
  function selectAsset(
    asset
  ) {
    setSelectedAsset(
      asset
    );


    if (drawerOpen) {
      loadDrawerAsset(
        asset.pub_asset_id
      );
    }
  }


  /*
   * Double click:
   *   select the row
   *   open the Package drawer
   */
  function openDrawer(
    asset
  ) {
    setSelectedAsset(
      asset
    );

    setDrawerOpen(
      true
    );

    loadDrawerAsset(
      asset.pub_asset_id
    );
  }


  /*
   * GRID COLUMNS
   */
  const columns =
    useMemo(
      () => [
        {
          key:
            "pub_asset_id",

          label:
            "ID",

          sortValue:
            (asset) =>
              Number(
                asset
                  .pub_asset_id ||
                0
              ),
        },


        {
          key:
            "channel",

          label:
            "Channel",
        },


        {
          key:
            "asset_type",

          label:
            "Type",

          value:
            (asset) =>
              humanize(
                asset
                  .asset_type
              ),
        },


        {
          key:
            "source",

          label:
            "Source",

          value:
            (asset) => {
              const type =
                asset
                  .source_type ||
                "";

              const id =
                asset
                  .source_id ||
                "";


              if (
                !type
                &&
                !id
              ) {
                return "—";
              }


              return `${type} #${id}`;
            },

          sortValue:
            (asset) =>
              `${asset.source_type || ""} ${asset.source_id || ""}`,
        },


        {
          key:
            "search_title",

          label:
            "Title",

          value:
            (asset) =>
              asset
                .search_title ||
              "—",
        },


        {
          key:
            "pipeline_stage",

          label:
            "Stage",

          render:
            (asset) => {
              const stage =
                String(
                  asset
                    .pipeline_stage ||
                  ""
                )
                  .trim()
                  .toLowerCase();


              if (!stage) {
                return "—";
              }


              return (
                <span
                  style={
                    stage ===
                      "error"
                      ? errorStageStyle
                      : stage ===
                          "packing"
                        ? packingStageStyle
                        : stageBadgeStyle
                  }
                >
                  {
                    humanize(
                      stage
                    )
                  }
                </span>
              );
            },

          sortValue:
            (asset) =>
              String(
                asset
                  .pipeline_stage ||
                ""
              ),
        },


        {
          key:
            "stage_note",

          label:
            "Note",

          value:
            (asset) =>
              asset
                .stage_note ||
              (
                asset
                  .pipeline_stage ===
                "error"
                  ? asset
                      .error_message ||
                    "Package error"
                  : "—"
              ),
        },


        {
          key:
            "has_package",

          label:
            "Package",

          render:
            (asset) =>
              hasPackage(
                asset
              )
                ? (
                    <span
                      title="Package complete"
                      aria-label="Package complete"

                      style={
                        packageCheckStyle
                      }
                    >
                      ✓
                    </span>
                  )
                : "",

          sortValue:
            (asset) =>
              hasPackage(
                asset
              )
                ? 1
                : 0,
        },


        {
          key:
            "updated_at",

          label:
            "Last Updated",

          value:
            (asset) =>
              asset
                .updated_at ||
              "—",
        },
      ],
      []
    );


  if (
    loading
    &&
    !assets.length
  ) {
    return (
      <AdminEmptyState
        title="Package"

        message="Loading Package workbench..."
      />
    );
  }


  return (
    <div
      style={
        workbenchShellStyle
      }
    >
      <div
        className="admin-detail-workarea"

        style={
          workbenchMainStyle
        }
      >
        <div
          style={
            filterBarStyle
          }
        >
          <label
            className="admin-field"
          >
            <span
              className="admin-field__label"
            >
              Channel
            </span>

            <select
              className="admin-field__control"

              value={
                channelFilter
              }

              onChange={(
                event
              ) =>
                setChannelFilter(
                  event
                    .target
                    .value
                )
              }
            >
              <option value="">
                All
              </option>

              {channels.map(
                (
                  channel
                ) => (
                  <option
                    key={
                      channel
                    }

                    value={
                      channel
                    }
                  >
                    {
                      humanize(
                        channel
                      )
                    }
                  </option>
                )
              )}
            </select>
          </label>


          <label
            className="admin-field"
          >
            <span
              className="admin-field__label"
            >
              Type
            </span>

            <select
              className="admin-field__control"

              value={
                typeFilter
              }

              onChange={(
                event
              ) =>
                setTypeFilter(
                  event
                    .target
                    .value
                )
              }
            >
              <option value="">
                All
              </option>

              {assetTypes.map(
                (
                  assetType
                ) => (
                  <option
                    key={
                      assetType
                    }

                    value={
                      assetType
                    }
                  >
                    {
                      humanize(
                        assetType
                      )
                    }
                  </option>
                )
              )}
            </select>
          </label>


          <button
            type="button"

            onClick={
              loadAssets
            }

            disabled={
              loading
            }

            style={
              refreshButtonStyle
            }
          >
            {
              loading
                ? "Refreshing..."
                : "Refresh"
            }
          </button>


          <div
            style={
              countStyle
            }
          >
            {assets.length} asset
            {
              assets.length === 1
                ? ""
                : "s"
            }
          </div>
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


        <AdminDataGrid
          items={
            assets
          }

          columns={
            columns
          }

          getRowKey={(
            asset
          ) =>
            asset
              .pub_asset_id
          }

          selectedKey={
            selectedAsset
              ?.pub_asset_id ??
            null
          }

          onSelectionChange={
            selectAsset
          }

          onRowDoubleClick={
            openDrawer
          }

          defaultSortKey="pub_asset_id"

          defaultSortDirection="desc"

          ariaLabel="PUB Package workbench"
        />
      </div>


      <PubPackageDrawer
        open={
          drawerOpen
        }

        asset={
          drawerAsset
        }

        loading={
          drawerLoading
        }

        error={
          drawerError
        }

        onClose={() => {
          setDrawerOpen(
            false
          );
        }}
      />
    </div>
  );
}


function hasPackage(
  asset
) {
  return (
    asset
      ?.has_package ===
      true
    ||
    Number(
      asset
        ?.has_package ||
      0
    ) === 1
    ||
    (
      asset
        ?.package
      &&
      typeof asset
        .package ===
        "object"
    )
  );
}


function humanize(
  value
) {
  return String(
    value ||
    ""
  )
    .replace(
      /^pin_/,
      ""
    )
    .replace(
      /[_-]+/g,
      " "
    )
    .replace(
      /\b\w/g,
      (
        character
      ) =>
        character
          .toUpperCase()
    );
}


const workbenchShellStyle = {
  display:
    "flex",

  width:
    "100%",

  minWidth:
    0,

  minHeight:
    0,
};


const workbenchMainStyle = {
  flex:
    1,

  minWidth:
    0,
};


const stageBadgeStyle = {
  display:
    "inline-block",

  padding:
    "3px 7px",

  borderRadius:
    999,

  background:
    "#eef1f4",

  color:
    "#465465",

  fontSize:
    11,

  fontWeight:
    600,

  lineHeight:
    1.2,
};


const packingStageStyle = {
  ...stageBadgeStyle,

  background:
    "#e8f1fb",

  color:
    "#245b88",
};


const errorStageStyle = {
  ...stageBadgeStyle,

  background:
    "#fff1f1",

  color:
    "#8a3131",

  border:
    "1px solid #e2baba",
};


const packageCheckStyle = {
  display:
    "inline-block",

  minWidth:
    18,

  color:
    "#248451",

  fontSize:
    18,

  fontWeight:
    800,

  lineHeight:
    1,

  textAlign:
    "center",
};


const filterBarStyle = {
  display:
    "flex",

  alignItems:
    "flex-end",

  gap:
    12,

  padding:
    "14px 0",
};


const refreshButtonStyle = {
  marginLeft:
    "auto",

  marginBottom:
    1,
};


const countStyle = {
  paddingBottom:
    7,

  color:
    "#586675",

  fontSize:
    13,
};


const errorStyle = {
  marginBottom:
    12,

  padding:
    "8px 10px",

  border:
    "1px solid #d8dde3",

  background:
    "#fff7f7",
};
