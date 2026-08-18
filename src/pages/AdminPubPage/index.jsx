import {
  useEffect,
  useMemo,
  useState,
} from "react";

import {
  createPortal,
} from "react-dom";

import { API_FOLDER } from "@helpers/config";

import MarketingWorkspace
  from "@components/Marketing/MarketingWorkspace";

const CREATE_RENDER_JOB_URL =
  `${API_FOLDER}/v2/admin/pub/render-jobs/create.php`;

import {
  AdminDetailPane,
  AdminEmptyState,
  AdminListPane,
  AdminMasterDetail,
  AdminObjectList,
  AdminObjectListItem,
} from "@components/AdminLayout";

const PLAYLISTS_URL =
  `${API_FOLDER}/v2/admin/playlists/list.php`;

const ANALYZE_PINTEREST_URL =
  `${API_FOLDER}/v2/admin/pub/analyze-playlist-pinterest.php`;

const PIN_TYPES = [
  {
    value: "all",
    label: "All",
  },
  {
    value: "composite",
    label: "Composite",
  },
  {
    value: "before_after_video",
    label: "Before / After Video",
  },
  {
    value: "idea",
    label: "Idea",
  },
  {
    value: "idea_palette",
    label: "Idea + Palette",
  },
  {
    value: "youtube_teaser",
    label: "YouTube Teaser",
  },
];

export default function AdminPubPage() {
  const [stage, setStage] =
    useState("analyze");

  const [playlists, setPlaylists] =
    useState([]);

  const [playlistId, setPlaylistId] =
    useState("");

  const [analysis, setAnalysis] =
    useState(null);

  const [proposals, setProposals] =
    useState([]);

  const [
    pinTypeFilter,
    setPinTypeFilter,
  ] = useState("all");

  const [
    loadingPlaylists,
    setLoadingPlaylists,
  ] = useState(false);

  const [analyzing, setAnalyzing] =
    useState(false);

  const [error, setError] =
    useState("");

  const [
    marketingOpen,
    setMarketingOpen,
  ] = useState(false);

  useEffect(() => {
    let active = true;

    async function loadPlaylists() {
      setLoadingPlaylists(true);
      setError("");

      try {
        const res =
          await fetch(
            `${PLAYLISTS_URL}?_=${Date.now()}`,
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
            "Failed to load playlists"
          );
        }

        if (!active) {
          return;
        }

        setPlaylists(
          Array.isArray(
            data.items
          )
            ? data.items
            : []
        );

      } catch (err) {
        if (!active) {
          return;
        }

        setError(
          err?.message ||
          "Failed to load playlists"
        );

      } finally {
        if (active) {
          setLoadingPlaylists(
            false
          );
        }
      }
    }

    loadPlaylists();

    return () => {
      active = false;
    };
  }, []);

  async function analyzePinterest() {
    const id =
      Number(
        playlistId || 0
      );

    if (!id) {
      setError(
        "Pick a playlist first."
      );

      return;
    }

    setAnalyzing(true);
    setError("");
    setAnalysis(null);
    setProposals([]);
    setPinTypeFilter("all");

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

      const res =
        await fetch(
          `${ANALYZE_PINTEREST_URL}?${params.toString()}`,
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
          "Failed to analyze playlist"
        );
      }

      const nextAnalysis =
        data.analysis || null;

      const nextProposals =
        Array.isArray(
          nextAnalysis
            ?.pinterest
            ?.asset_proposals
        )
          ? nextAnalysis
              .pinterest
              .asset_proposals
              .map(
                normalizeProposal
              )
          : [];

      setAnalysis(
        nextAnalysis
      );

      setProposals(
        nextProposals
      );

    } catch (err) {
      setError(
        err?.message ||
        "Failed to analyze playlist"
      );

    } finally {
      setAnalyzing(false);
    }
  }

  function updateProposal(
    proposalKey,
    changes
  ) {
    setProposals(
      (current) =>
        current.map(
          (proposal) =>
            proposal
              .proposal_key ===
            proposalKey
              ? {
                  ...proposal,
                  ...changes,
                }
              : proposal
        )
    );
  }

  function removeProposal(
    proposalKey
  ) {
    setProposals(
      (current) =>
        current.filter(
          (proposal) =>
            proposal
              .proposal_key !==
            proposalKey
        )
    );
  }

  /*
   * MARK RETURNS FINISHED COPY
   *
   * Marketing knows nothing about proposals.
   * PUB owns the mapping back into its boxes.
   */
  function handleMarkReturn(
    result
  ) {
    console.log("PUB RECEIVED MARK:", result);
    const searchTitles =
      Array.isArray(
        result?.search_title
      )
        ? result.search_title
        : [];

    const descriptions =
      Array.isArray(
        result?.description
      )
        ? result.description
        : [];

    setProposals(
      (current) =>
        current.map(
          (
            proposal,
            index
          ) => ({
            ...proposal,

            search_title:
              searchTitles[
                index
              ] ??
              proposal
                .search_title,

            description:
              descriptions[
                index
              ] ??
              proposal
                .description,
          })
        )
    );

    setMarketingOpen(
      false
    );
  }

  async function previewBeforeAfterVideo(
    proposal
  ) {
    if (
      !proposal?.before
        ?.image_url
    ) {
      setError(
        "Before image required."
      );

      return;
    }

    if (
      !proposal?.after
        ?.image_url
    ) {
      setError(
        "After image required."
      );

      return;
    }

    setError("");

    try {
      const res =
        await fetch(
          CREATE_RENDER_JOB_URL,
          {
            method: "POST",

            credentials:
              "include",

            headers: {
              "Content-Type":
                "application/json",
            },

            body:
              JSON.stringify({
                creator_key:
                  "pinterest.before_after_video",

                composition_key:
                  "colorfix-pinterest-before-after-video",

                props: {
                  before: {
                    image_url:
                      renderImageUrl(
                        proposal
                          .before
                          .image_url
                      ),
                  },

                  after: {
                    image_url:
                      renderImageUrl(
                        proposal
                          .after
                          .image_url
                      ),
                  },

                  search_title:
                    proposal
                      .search_title ||
                    "Exterior Color Ideas",

                  cta_text:
                    "See More Transformations",
                },
              }),
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
          "Failed to create render job"
        );
      }

      const jobId =
        data?.job
          ?.pub_render_job_id;

      if (!jobId) {
        throw new Error(
          "Render job ID was not returned."
        );
      }

      alert(
        `Render job #${jobId} queued.`
      );

    } catch (err) {
      setError(
        err?.message ||
        "Failed to create render job"
      );
    }
  }

  function addProposal() {
    const key =
      `manual-${Date.now()}`;

    setProposals(
      (current) => [
        ...current,

        {
          proposal_key:
            key,

          asset_type:
            "pin_idea",

          pin_type:
            "idea",

          sort_order:
            current.length + 1,

          include:
            true,

          search_title:
            "",

          description:
            "",

          source_item:
            null,

          before:
            null,

          after:
            null,

          is_manual:
            true,
        },
      ]
    );
  }

  const visibleProposals =
    useMemo(() => {
      if (
        pinTypeFilter ===
        "all"
      ) {
        return proposals;
      }

      return proposals.filter(
        (proposal) =>
          proposal.pin_type ===
          pinTypeFilter
      );
    }, [
      proposals,
      pinTypeFilter,
    ]);

  const selectedCount =
    proposals.filter(
      (proposal) =>
        proposal.include
    ).length;

  return (
    <>
      <AdminMasterDetail
        storageKey="admin-pub-list-width"
        defaultListWidth={280}
        minListWidth={0}
        maxListWidth={420}

        list={
          <AdminListPane
            title="PUB"
          >
            <AdminObjectList
              ariaLabel="PUB stages"
            >
              <AdminObjectListItem
                id="analyze"
                title="Analyze"
                selected={
                  stage ===
                  "analyze"
                }
                onSelect={() =>
                  setStage(
                    "analyze"
                  )
                }
              />

              <AdminObjectListItem
                id="assets"
                title="Assets"
                selected={
                  stage ===
                  "assets"
                }
                onSelect={() =>
                  setStage(
                    "assets"
                  )
                }
              />

              <AdminObjectListItem
                id="package"
                title="Package"
                selected={
                  stage ===
                  "package"
                }
                onSelect={() =>
                  setStage(
                    "package"
                  )
                }
              />

              <AdminObjectListItem
                id="schedule"
                title="Schedule"
                selected={
                  stage ===
                  "schedule"
                }
                onSelect={() =>
                  setStage(
                    "schedule"
                  )
                }
              />

              <AdminObjectListItem
                id="published"
                title="Published"
                selected={
                  stage ===
                  "published"
                }
                onSelect={() =>
                  setStage(
                    "published"
                  )
                }
              />
            </AdminObjectList>
          </AdminListPane>
        }

        detail={
          <AdminDetailPane
            ariaLabel="PUB"
          >
            <div
              className="admin-detail-header"
            >
              <strong>
                {stage.toUpperCase()}
              </strong>
            </div>

            {stage ===
            "analyze" ? (
              <PinterestAnalyzeStage
                playlists={
                  playlists
                }

                playlistId={
                  playlistId
                }

                setPlaylistId={
                  setPlaylistId
                }

                loadingPlaylists={
                  loadingPlaylists
                }

                analyzing={
                  analyzing
                }

                analyzePinterest={
                  analyzePinterest
                }

                error={
                  error
                }

                analysis={
                  analysis
                }

                proposals={
                  proposals
                }

                visibleProposals={
                  visibleProposals
                }

                pinTypeFilter={
                  pinTypeFilter
                }

                setPinTypeFilter={
                  setPinTypeFilter
                }

                updateProposal={
                  updateProposal
                }

                removeProposal={
                  removeProposal
                }

                addProposal={
                  addProposal
                }

                selectedCount={
                  selectedCount
                }

                setStage={
                  setStage
                }

                previewBeforeAfterVideo={
                  previewBeforeAfterVideo
                }

                onCallMark={() =>
                  setMarketingOpen(
                    true
                  )
                }
              />
            ) : (
              <AdminEmptyState
                title={
                  stage
                    .charAt(0)
                    .toUpperCase() +
                  stage.slice(1)
                }

                message="PUB workflow will appear here."
              />
            )}
          </AdminDetailPane>
        }
      />

      {marketingOpen
        ? createPortal(
            <div
              style={
                marketingOverlayStyle
              }
            >
              <MarketingWorkspace
                mode="call"

                title="Marketing"

                request={{
                  tags: [
                    "pinterest",
                  ],

                  deliverables: [
                    {
                      key:
                        "search_title",

                      label:
                        "Search Titles",

                      count:
                        proposals.length ||
                        1,

                      allowDuplicates:
                        true,
                    },

                    {
                      key:
                        "description",

                      label:
                        "Descriptions",

                      count:
                        proposals.length ||
                        1,

                      allowDuplicates:
                        true,
                    },
                  ],
                }}

                onReturn={
                  handleMarkReturn
                }

                onClose={() =>
                  setMarketingOpen(
                    false
                  )
                }
              />
            </div>,

            document.body
          )
        : null}
    </>
  );
}

function PinterestAnalyzeStage({
  playlists,
  playlistId,
  setPlaylistId,

  loadingPlaylists,

  analyzing,
  analyzePinterest,

  error,
  analysis,

  proposals,
  visibleProposals,

  pinTypeFilter,
  setPinTypeFilter,

  updateProposal,
  removeProposal,
  addProposal,

  selectedCount,

  setStage,

  previewBeforeAfterVideo,

  onCallMark,
}) {
  return (
    <div
      className="pub-analyze admin-detail-workarea"
    >
      <div
        style={{
          display: "flex",
          alignItems: "flex-end",
          flexWrap: "wrap",
          gap: 12,
          padding: "14px 0",
        }}
      >
        <label
          className="admin-field"
          style={{
            flex:
              "0 0 140px",
          }}
        >
          <span
            className="admin-field__label"
          >
            Channel
          </span>

          <select
            className="admin-field__control"
            value="pinterest"
            disabled
          >
            <option
              value="pinterest"
            >
              Pinterest
            </option>
          </select>
        </label>

        <label
          className="admin-field"
          style={{
            flex:
              "1 1 360px",
          }}
        >
          <span
            className="admin-field__label"
          >
            Playlist
          </span>

          <select
            className="admin-field__control"
            value={
              playlistId
            }
            onChange={(
              event
            ) => {
              setPlaylistId(
                event.target
                  .value
              );
            }}
            disabled={
              loadingPlaylists
            }
            style={{
              width:
                "100%",
            }}
          >
            <option value="">
              Pick playlist
            </option>

            {playlists.map(
              (playlist) => (
                <option
                  key={
                    playlist
                      .playlist_id
                  }
                  value={
                    playlist
                      .playlist_id
                  }
                >
                  #
                  {
                    playlist
                      .playlist_id
                  }{" "}
                  {
                    playlist
                      .title
                  }
                </option>
              )
            )}
          </select>
        </label>

        <button
          type="button"
          onClick={
            analyzePinterest
          }
          disabled={
            analyzing ||
            !playlistId
          }
        >
          {analyzing
            ? "Analyzing..."
            : "Analyze"}
        </button>
      </div>

      {error ? (
        <AdminEmptyState
          title="Analyze failed"
          message={
            error
          }
        />
      ) : !analysis ? (
        <AdminEmptyState
          title="Analyze Pinterest"
          message="Choose a playlist to see what publishable Pinterest assets PUB can create."
        />
      ) : (
        <>
          <div
            style={{
              display: "flex",
              alignItems:
                "flex-end",
              justifyContent:
                "space-between",
              flexWrap:
                "wrap",
              gap: 12,
              marginBottom:
                12,
            }}
          >
            <div>
              <h2
                style={{
                  margin:
                    "0 0 3px",
                  fontSize:
                    20,
                  lineHeight:
                    1.2,
                  fontWeight:
                    600,
                }}
              >
                {analysis
                  .source
                  ?.title ||
                  "Playlist"}
              </h2>

              <div
                style={{
                  fontSize:
                    14,

                  color:
                    "#586675",
                }}
              >
                {
                  proposals.length
                }{" "}
                possible
                Pinterest
                asset
                {proposals.length ===
                1
                  ? ""
                  : "s"}
              </div>
            </div>

            <div
              style={{
                display:
                  "flex",

                alignItems:
                  "flex-end",

                gap: 8,
              }}
            >
              <label
                className="admin-field"
              >
                <span
                  className="admin-field__label"
                >
                  Pin Type
                </span>

                <select
                  className="admin-field__control"

                  value={
                    pinTypeFilter
                  }

                  onChange={(
                    event
                  ) =>
                    setPinTypeFilter(
                      event
                        .target
                        .value
                    )
                  }
                >
                  {PIN_TYPES.map(
                    (type) => (
                      <option
                        key={
                          type
                            .value
                        }
                        value={
                          type
                            .value
                        }
                      >
                        {
                          type
                            .label
                        }
                      </option>
                    )
                  )}
                </select>
              </label>

              <button
                type="button"
                onClick={
                  addProposal
                }
              >
                Add Row
              </button>

              <button
                type="button"
                onClick={
                  onCallMark
                }
                disabled={
                  proposals.length ===
                  0
                }
              >
                Call Mark
              </button>

              <button
                type="button"
                disabled={
                  selectedCount ===
                  0
                }
                onClick={() =>
                  setStage(
                    "assets"
                  )
                }
              >
                Create Selected
                Assets (
                {selectedCount}
                )
              </button>
            </div>
          </div>

          <div
            className="admin-scroll-region"
            style={{
              width:
                "100%",

              border:
                "1px solid #d8dde3",
            }}
          >
            <table
              style={{
                width:
                  "100%",

                borderCollapse:
                  "collapse",

                minWidth:
                  1100,

                fontSize:
                  13,
              }}
            >
              <thead>
                <tr>
                  <th
                    style={
                      headerCell
                    }
                  >
                    Use
                  </th>

                  <th
                    style={
                      headerCell
                    }
                  >
                    Order
                  </th>

                  <th
                    style={
                      headerCell
                    }
                  >
                    Type
                  </th>

                  <th
                    style={
                      headerCell
                    }
                  >
                    Before
                  </th>

                  <th
                    style={
                      headerCell
                    }
                  >
                    After /
                    Source
                  </th>

                  <th
                    style={
                      headerCell
                    }
                  >
                    Search
                    Title
                  </th>

                  <th
                    style={
                      headerCell
                    }
                  >
                    Description
                  </th>

                  <th
                    style={
                      headerCell
                    }
                  >
                    Actions
                  </th>
                </tr>
              </thead>

              <tbody>
                {visibleProposals.map(
                  (
                    proposal
                  ) => {
                    const isPairFormat =
                      proposal
                        .pin_type ===
                        "composite" ||
                      proposal
                        .pin_type ===
                        "before_after_video";

                    const isYoutubeTeaser =
                      proposal
                        .pin_type ===
                      "youtube_teaser";

                    const before =
                      isPairFormat ||
                      isYoutubeTeaser
                        ? proposal.before
                        : null;

                    const source =
                      isPairFormat
                        ? proposal.after
                        : isYoutubeTeaser
                          ? proposal
                              .after_context
                          : proposal
                              .source_item;

                    return (
                      <tr
                        key={
                          proposal
                            .proposal_key
                        }
                      >
                        <td
                          style={
                            bodyCell
                          }
                        >
                          <input
                            type="checkbox"

                            checked={
                              proposal
                                .include
                            }

                            onChange={(
                              event
                            ) =>
                              updateProposal(
                                proposal
                                  .proposal_key,

                                {
                                  include:
                                    event
                                      .target
                                      .checked,
                                }
                              )
                            }
                          />
                        </td>

                        <td
                          style={
                            bodyCell
                          }
                        >
                          {
                            proposal
                              .sort_order
                          }
                        </td>

                        <td
                          style={
                            bodyCell
                          }
                        >
                          <select
                            className="admin-field__control"

                            value={
                              proposal
                                .pin_type
                            }

                            onChange={(
                              event
                            ) => {
                              const pinType =
                                event
                                  .target
                                  .value;

                              updateProposal(
                                proposal
                                  .proposal_key,

                                {
                                  pin_type:
                                    pinType,

                                  asset_type:
                                    assetTypeForPinType(
                                      pinType
                                    ),
                                }
                              );
                            }}
                          >
                            <option
                              value="composite"
                            >
                              Composite
                            </option>

                            <option
                              value="before_after_video"
                            >
                              Before /
                              After
                              Video
                            </option>

                            <option
                              value="idea"
                            >
                              Idea
                            </option>

                            <option
                              value="idea_palette"
                            >
                              Idea +
                              Palette
                            </option>

                            <option
                              value="youtube_teaser"
                            >
                              YouTube
                              Teaser
                            </option>
                          </select>
                        </td>

                        <td
                          style={
                            bodyCell
                          }
                        >
                          {before ? (
                            <SourcePreview
                              item={
                                before
                              }
                            />
                          ) : (
                            "—"
                          )}
                        </td>

                        <td
                          style={
                            bodyCell
                          }
                        >
                          {source ? (
                            <SourcePreview
                              item={
                                source
                              }
                            />
                          ) : (
                            <span>
                              No source
                              selected
                            </span>
                          )}
                        </td>

                        <td
                          style={
                            bodyCell
                          }
                        >
                          <textarea
                            rows={3}

                            value={
                              proposal
                                .search_title
                            }

                            onChange={(
                              event
                            ) =>
                              updateProposal(
                                proposal
                                  .proposal_key,

                                {
                                  search_title:
                                    event
                                      .target
                                      .value,
                                }
                              )
                            }

                            style={{
                              width:
                                200,

                              boxSizing:
                                "border-box",

                              fontSize:
                                13,
                            }}
                          />
                        </td>

                        <td
                          style={
                            bodyCell
                          }
                        >
                          <textarea
                            rows={5}

                            value={
                              proposal
                                .description
                            }

                            onChange={(
                              event
                            ) =>
                              updateProposal(
                                proposal
                                  .proposal_key,

                                {
                                  description:
                                    event
                                      .target
                                      .value,
                                }
                              )
                            }

                            style={{
                              width:
                                330,

                              boxSizing:
                                "border-box",

                              fontSize:
                                13,
                            }}
                          />
                        </td>

                        <td
                          style={
                            bodyCell
                          }
                        >
                          {proposal
                            .pin_type ===
                          "before_after_video" ? (
                            <button
                              type="button"

                              onClick={() =>
                                previewBeforeAfterVideo(
                                  proposal
                                )
                              }

                              style={{
                                marginRight:
                                  6,
                              }}
                            >
                              Preview
                              Video
                            </button>
                          ) : null}

                          <button
                            type="button"

                            onClick={() =>
                              removeProposal(
                                proposal
                                  .proposal_key
                              )
                            }
                          >
                            Remove
                          </button>
                        </td>
                      </tr>
                    );
                  }
                )}

                {visibleProposals.length ===
                0 ? (
                  <tr>
                    <td
                      colSpan={8}

                      style={{
                        padding:
                          24,

                        textAlign:
                          "center",
                      }}
                    >
                      No proposals
                      match this
                      filter.
                    </td>
                  </tr>
                ) : null}
              </tbody>
            </table>
          </div>
        </>
      )}
    </div>
  );
}

function normalizeProposal(
  proposal
) {
  const pinType =
    proposal.pin_type ||
    "idea";

  const isPairFormat =
    pinType ===
      "composite" ||
    pinType ===
      "before_after_video";

  const isYoutubeTeaser =
    pinType ===
    "youtube_teaser";

  const source =
    isPairFormat
      ? proposal.after
      : isYoutubeTeaser
        ? proposal
            .after_context
        : proposal
            .source_item;

  return {
    ...proposal,

    include:
      proposal.include !==
      false,

    search_title:
      proposal
        .search_title ||
      source?.title ||
      "",

    description:
      proposal
        .description ||
      "",
  };
}

function SourcePreview({
  item,
}) {
  const imageUrl =
    browserImageUrl(
      item?.image_url
    );

  return (
    <div
      style={{
        display: "flex",
        alignItems:
          "flex-start",
        gap: 8,
        minWidth: 145,
      }}
    >
      {imageUrl ? (
        <img
          src={imageUrl}
          alt=""

          style={{
            width: 64,
            height: 64,

            objectFit:
              "cover",

            borderRadius:
              4,

            border:
              "1px solid #d8dde3",
          }}
        />
      ) : (
        <div
          style={{
            width: 64,
            height: 64,

            display:
              "grid",

            placeItems:
              "center",

            background:
              "#f2f4f5",

            border:
              "1px solid #d8dde3",

            fontSize:
              11,
          }}
        >
          No image
        </div>
      )}

      <div>
        {item
          ?.photo_library_id ? (
          <div
            style={{
              fontSize:
                12,

              fontWeight:
                600,
            }}
          >
            Photo #
            {
              item
                .photo_library_id
            }
          </div>
        ) : null}

        {item?.title ? (
          <div
            style={{
              marginTop:
                3,

              maxWidth:
                130,

              fontSize:
                11,

              lineHeight:
                1.35,

              color:
                "#6b7280",
            }}
          >
            {
              item.title
            }
          </div>
        ) : null}
      </div>
    </div>
  );
}

function browserImageUrl(
  value
) {
  const raw =
    String(
      value || ""
    ).trim();

  if (!raw) {
    return "";
  }

  const pipeIndex =
    raw.indexOf("|");

  if (
    pipeIndex >= 0
  ) {
    return raw
      .slice(
        pipeIndex + 1
      )
      .trim();
  }

  return raw;
}

function renderImageUrl(
  value
) {
  const raw =
    browserImageUrl(
      value
    );

  if (!raw) {
    return "";
  }

  if (
    /^https?:\/\//i.test(
      raw
    )
  ) {
    return raw;
  }

  return (
    "https://colorfix.terrymarr.com/" +
    raw.replace(
      /^\/+/,
      ""
    )
  );
}

function assetTypeForPinType(
  pinType
) {
  if (
    pinType ===
    "before_after_video"
  ) {
    return "pin_before_after_video";
  }

  if (
    pinType ===
    "youtube_teaser"
  ) {
    return "pin_youtube_teaser";
  }

  if (
    pinType ===
    "idea_palette"
  ) {
    return "pin_idea_palette";
  }

  if (
    pinType ===
    "idea"
  ) {
    return "pin_idea";
  }

  return "pin_composite";
}

const marketingOverlayStyle = {
  position: "fixed",

  inset: 0,

  zIndex:
    2147483647,

  background:
    "#ffffff",

  overflow:
    "hidden",
};

const headerCell = {
  position:
    "sticky",

  top: 0,

  zIndex: 1,

  padding:
    "8px 9px",

  textAlign:
    "left",

  verticalAlign:
    "middle",

  background:
    "#f7f8fa",

  borderBottom:
    "1px solid #d8dde3",

  color:
    "#4b6b8a",

  fontSize:
    10,

  lineHeight:
    1.15,

  fontWeight:
    700,

  letterSpacing:
    "0.08em",

  textTransform:
    "uppercase",
};

const bodyCell = {
  padding:
    "8px 9px",

  textAlign:
    "left",

  verticalAlign:
    "top",

  borderBottom:
    "1px solid #e9edf1",
};