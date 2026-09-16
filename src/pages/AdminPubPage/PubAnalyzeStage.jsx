import {
  useEffect,
  useMemo,
  useState,
} from "react";

import {
  API_FOLDER,
} from "@helpers/config";

import {
  fetchRex,
} from "@helpers/rexHelpers";

import MarketingWorkspace
  from "@components/Marketing/MarketingWorkspace";

import {
  AdminButton,
  AdminDataGrid,
  AdminEmptyState,
  AdminFullScreenOverlay,
  AdminMediaPreview,
  AdminNotice,
  AdminSectionHeader,
  AdminStack,
  AdminTableTextarea,
  AdminToolbar,
  AdminWorkbenchDrawer,
  useAdminDialog,
} from "@components/AdminLayout";

import PubStageErrors
  from "./PubStageErrors";

import usePubStageErrors
  from "./hooks/usePubStageErrors";


const PLAYLISTS_URL =
  `${API_FOLDER}/v2/admin/playlists/list.php`;

const ANALYZE_URL =
  `${API_FOLDER}/v2/admin/pub/analyze.php`;

const CREATE_URL =
  `${API_FOLDER}/v2/admin/pub/create.php`;


export default function PubAnalyzeStage({
  contracts,
  contractError = "",
  createMessage = "",
  onCreateMessage,
  onCreateComplete,
  receivePubCom,
  cancelAnalysisToken = 0,
}) {
  const dialog =
    useAdminDialog();

  const {
    errors:
      pubStageErrors,
    setErrors:
      setPubStageErrors,
    clearErrors:
      clearPubStageErrors,
  } = usePubStageErrors();


  const analyzeStageContract =
    contracts
      ?.analyze ||
    null;


  /*
   * OUTPUT IS NOW A VIEW FILTER ONLY.
   *
   * Analyze always runs every registered specialist against the same
   * Market delivery. Changing this dropdown never causes a new run and
   * never discards the workbench.
   */
  const [
    outputFilter,
    setOutputFilter,
  ] = useState(
    "all"
  );


  const analyzeOutputTypes =
    useMemo(
      () => [
        {
          value:
            "all",
          label:
            "All",
        },
        ...Object.entries(
          analyzeStageContract
            ?.assetTypes ||
          {}
        )
          .filter(
            ([
              ,
              contract,
            ]) =>
              Boolean(
                contract
                  ?.analyzer
              )
          )
          .map(
            ([
              value,
              contract,
            ]) => ({
              value,
              label:
                contract
                  ?.label ||
                humanize(
                  value
                ),
              contract,
            })
          ),
      ],
      [
        analyzeStageContract,
      ]
    );


  const analyzeOutputContract =
    outputFilter ===
      "all"
      ? null
      : analyzeStageContract
          ?.assetTypes
          ?.[outputFilter] ||
        null;


  const contractByCreatedAssetType =
    useMemo(
      () => {
        const map =
          new Map();


        for (
          const [
            outputType,
            contract,
          ]
          of Object.entries(
            analyzeStageContract
              ?.assetTypes ||
            {}
          )
        ) {
          if (
            !contract
              ?.analyzer
          ) {
            continue;
          }


          const createdAssetType =
            String(
              contract
                ?.createsAssetType ||
              ""
            )
              .trim()
              .toLowerCase();


          if (!createdAssetType) {
            continue;
          }


          map.set(
            createdAssetType,
            {
              outputType,
              contract,
            }
          );
        }


        return map;
      },
      [
        analyzeStageContract,
      ]
    );


  const analyzeSourceColumns =
    useMemo(
      () =>
        Array.isArray(
          analyzeOutputContract
            ?.workbench
            ?.sourceColumns
        )
          ? analyzeOutputContract
              .workbench
              .sourceColumns
          : [],
      [
        analyzeOutputContract,
      ]
    );


  const [
    playlists,
    setPlaylists,
  ] = useState(
    []
  );

  const [
    playlistId,
    setPlaylistId,
  ] = useState(
    () =>
      new URLSearchParams(
        window.location.search
      ).get(
        "playlist_id"
      ) ||
      ""
  );

  const [
    loadingPlaylists,
    setLoadingPlaylists,
  ] = useState(
    false
  );

  const [
    analysis,
    setAnalysis,
  ] = useState(
    null
  );

  const [
    proposals,
    setProposals,
  ] = useState(
    []
  );

  const [
    analyzing,
    setAnalyzing,
  ] = useState(
    false
  );

  const [
    error,
    setError,
  ] = useState(
    ""
  );

  const [
    analyzeExistingPolicy,
    setAnalyzeExistingPolicy,
  ] = useState({
    unshipped:
      "check",
    shipped:
      "check",
  });

  const [
    analyzeHandoffValues,
    setAnalyzeHandoffValues,
  ] = useState(
    {}
  );

  /*
   * Canonical public REX URL for the selected source playlist.
   *
   * Every playlist-sourced asset gets this pingback automatically,
   * EXCEPT Pinterest YouTube teaser assets, whose pingback must stay
   * blank until the related YouTube video has shipped.
   */
  const [
    sourcePingback,
    setSourcePingback,
  ] = useState(
    ""
  );

  const [
    marketingOpen,
    setMarketingOpen,
  ] = useState(
    false
  );

  const [
    handoffOpen,
    setHandoffOpen,
  ] = useState(
    false
  );

  const [
    sendingToCreate,
    setSendingToCreate,
  ] = useState(
    false
  );


  useEffect(() => {
    if (
      !cancelAnalysisToken
    ) {
      return;
    }


    /*
     * Parent-level PubCom Cancel Analysis.
     *
     * Keep the selected playlist so Terry can immediately click
     * Edit Playlist, but discard this Analyze result/workbench and
     * close any Analyze-owned overlays.
     */
    setAnalysis(
      null
    );

    setProposals(
      []
    );

    setAnalyzeHandoffValues(
      {}
    );

    setSourcePingback(
      ""
    );

    setAnalyzeExistingPolicy({
      unshipped:
        "check",
      shipped:
        "check",
    });

    setMarketingOpen(
      false
    );

    setHandoffOpen(
      false
    );

    setError(
      ""
    );

    clearPubStageErrors();

    onCreateMessage?.(
      ""
    );
  }, [
    cancelAnalysisToken,
  ]);


  useEffect(() => {
    let active =
      true;


    async function loadPlaylists() {
      setLoadingPlaylists(
        true
      );

      setError(
        ""
      );


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
      active =
        false;
    };
  }, []);


  function changePlaylist(
    value
  ) {
    setPlaylistId(
      value
    );

    setAnalysis(
      null
    );

    setProposals(
      []
    );

    setAnalyzeHandoffValues(
      {}
    );

    setSourcePingback(
      ""
    );

    setAnalyzeExistingPolicy({
      unshipped:
        "check",
      shipped:
        "check",
    });

    clearPubStageErrors();

    setError(
      ""
    );
  }


  function changeOutputFilter(
    value
  ) {
    setOutputFilter(
      value ||
      "all"
    );

    clearPubStageErrors();

    setError(
      ""
    );
  }


  const visibleProposals =
    useMemo(
      () => {
        if (
          outputFilter ===
          "all"
        ) {
          return proposals;
        }


        return proposals.filter(
          (proposal) => {
            const product =
              contractByCreatedAssetType.get(
                String(
                  proposal
                    ?.asset_type ||
                  ""
                )
                  .trim()
                  .toLowerCase()
              );


            return (
              product
                ?.outputType ===
              outputFilter
            );
          }
        );
      },
      [
        proposals,
        outputFilter,
        contractByCreatedAssetType,
      ]
    );


  async function analyzeSource() {
    const id =
      Number(
        playlistId ||
        0
      );


    if (!id) {
      setError(
        "Pick a playlist first."
      );

      return;
    }


    setAnalyzing(
      true
    );

    onCreateMessage?.(
      ""
    );

    setError(
      ""
    );

    clearPubStageErrors();

    setAnalysis(
      null
    );

    setProposals(
      []
    );

    setAnalyzeHandoffValues(
      {}
    );

    setSourcePingback(
      ""
    );

    setAnalyzeExistingPolicy({
      unshipped:
        "check",
      shipped:
        "check",
    });


    try {
      /*
       * THE DOORBELL NOW CARRIES SOURCE IDENTITY ONLY.
       *
       * AnalyzeManager starts one run, orders one Market delivery, and
       * dispatches that same delivery to every registered Analyzer.
       */
      const res =
        await fetch(
          ANALYZE_URL,
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
                source_type:
                  "playlist",
                source_id:
                  id,
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
          "Failed to analyze playlist"
        );
      }


      const existingAssetMatches =
        Array.isArray(
          data
            ?.existing_asset_matches
        )
          ? data
              .existing_asset_matches
          : [];

      const nextExistingPolicy = {
        unshipped:
          "check",
        shipped:
          "check",
      };


      const unshippedMatches =
        existingAssetMatches.filter(
          (match) =>
            String(
              match
                ?.existing_state ||
              ""
            ).toLowerCase() ===
            "unshipped"
        );


      if (
        unshippedMatches.length >
        0
      ) {
        const count =
          unshippedMatches.length;

        const match =
          count === 1
            ? unshippedMatches[0]
            : null;

        const copyPreview =
          existingCopyPreview(
            match
          );

        const replace =
          await dialog.confirm({
            title:
              count === 1
                ? "Asset Already Created"
                : "Assets Already Created",
            message:
              (
                count === 1
                  ? "This asset combo has already been created. Replace it?"
                  : `${count} asset combos have already been created. Replace them?`
              ) +
              (
                copyPreview
                  ? `\n\n${copyPreview}`
                  : ""
              ),
            confirmLabel:
              count === 1
                ? "Replace"
                : "Replace All",
            cancelLabel:
              "Cancel",
          });


        if (!replace) {
          return;
        }


        nextExistingPolicy.unshipped =
          "replace";
      }


      const shippedMatches =
        existingAssetMatches.filter(
          (match) =>
            String(
              match
                ?.existing_state ||
              ""
            ).toLowerCase() ===
            "shipped"
        );


      if (
        shippedMatches.length >
        0
      ) {
        const count =
          shippedMatches.length;

        const match =
          count === 1
            ? shippedMatches[0]
            : null;

        const copyPreview =
          existingCopyPreview(
            match
          );

        const createNewVersion =
          await dialog.confirm({
            title:
              count === 1
                ? "Asset Already Shipped"
                : "Assets Already Shipped",
            message:
              (
                count === 1
                  ? "This asset combo has already been shipped. Create a new version?"
                  : `${count} asset combos have already been shipped. Create new versions?`
              ) +
              (
                copyPreview
                  ? `\n\n${copyPreview}`
                  : ""
              ),
            confirmLabel:
              count === 1
                ? "Create New Version"
                : "Create New Versions",
            cancelLabel:
              "Cancel",
          });


        if (!createNewVersion) {
          return;
        }


        nextExistingPolicy.shipped =
          "new_version";
      }


      setAnalyzeExistingPolicy(
        nextExistingPolicy
      );


      /*
       * Resolve the source playlist REX automatically.
       *
       * This is the canonical pingback for every normal playlist-sourced
       * asset. If it cannot be resolved, ANALYZE must not produce a CREATE
       * handoff that could later publish assets with no source pingback.
       */
      let resolvedSourcePingback =
        "";

      try {
        resolvedSourcePingback =
          await fetchRex(
            id,
            "pinterest"
          );
      } catch (rexError) {
        throw new Error(
          `Could not resolve source playlist pingback: ${
            rexError?.message ||
            rexError
          }`
        );
      }

      if (
        isEmptyValue(
          resolvedSourcePingback
        )
      ) {
        throw new Error(
          "Source playlist REX returned no pingback URL."
        );
      }

      setSourcePingback(
        resolvedSourcePingback
      );


      receivePubCom?.(
        data
      );


      const selectedPlaylist =
        playlists.find(
          (playlist) =>
            Number(
              playlist
                ?.playlist_id ||
              0
            ) ===
            id
        );

      const nextAnalysis = {
        ...data,
        source: {
          source_type:
            data.source_type ||
            "playlist",
          source_id:
            Number(
              data.source_id ||
              id
            ),
          title:
            selectedPlaylist
              ?.title ||
            "",
        },
      };


      const nextProposals =
        Array.isArray(
          data.boxes
        )
          ? data.boxes.map(
              (
                proposal,
                index
              ) => {
                const normalized =
                  normalizeProposal(
                    proposal,
                    index
                  );

                return {
                  ...normalized,

                  pingback:
                    isDeferredYoutubeTeaser(
                      normalized
                    )
                      ? ""
                      : resolvedSourcePingback,
                };
              }
            )
          : [];


      const failed =
        Array.isArray(
          data.failed
        )
          ? data.failed
          : [];

      const unexpectedFailures =
        failed.filter(
          (failure) =>
            !hasOperationalPubCom(
              failure
            )
        );


      if (
        unexpectedFailures.length
      ) {
        setPubStageErrors({
          stage:
            "analyze",
          code:
            "analyzer_failures",
          message:
            `${unexpectedFailures.length} specialist failure${
              unexpectedFailures.length === 1
                ? ""
                : "s"
            } occurred in ANALYZE.`,
          details:
            unexpectedFailures,
        });
      }


      setAnalysis(
        nextAnalysis
      );

      setProposals(
        nextProposals
      );


      if (
        nextProposals.length ===
          0 &&
        unexpectedFailures.length >
          0
      ) {
        setError(
          unexpectedFailures[0]
            ?.error ||
          "ANALYZE failed."
        );
      }

    } catch (err) {
      setError(
        err?.message ||
        "Failed to analyze playlist"
      );

    } finally {
      setAnalyzing(
        false
      );
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

    clearPubStageErrors();
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

    clearPubStageErrors();
  }


  function addProposal() {
    if (
      outputFilter ===
        "all" ||
      !analyzeOutputContract ||
      !analysis
    ) {
      return;
    }


    const key =
      `manual-${Date.now()}`;

    const createdAssetType =
      String(
        analyzeOutputContract
          ?.createsAssetType ||
        ""
      ).trim();


    if (!createdAssetType) {
      return;
    }


    const sameTypeOrders =
      proposals
        .filter(
          (proposal) =>
            String(
              proposal
                ?.asset_type ||
              ""
            ) ===
            createdAssetType
        )
        .map(
          (proposal) =>
            Number(
              proposal
                ?.sort_order
            )
        )
        .filter(
          Number.isFinite
        );

    const sortOrder =
      sameTypeOrders.length
        ? Math.max(
            ...sameTypeOrders
          ) + 1
        : 0;


    setProposals(
      (current) => [
        ...current,
        {
          proposal_key:
            key,
          pub_run_id:
            Number(
              analysis
                ?.pub_run_id ||
              0
            ),
          channel:
            String(
              analyzeOutputContract
                ?.channel ||
              ""
            ),
          asset_type:
            createdAssetType,
          source_type:
            String(
              analysis
                ?.source_type ||
              "playlist"
            ),
          source_id:
            Number(
              analysis
                ?.source_id ||
              playlistId ||
              0
            ),
          sort_order:
            sortOrder,
          display_order:
            sortOrder,
          include:
            true,
          search_title:
            "",
          description:
            "",
          pingback:
            isDeferredYoutubeTeaser({
              channel:
                String(
                  analyzeOutputContract
                    ?.channel ||
                  ""
                ),
              asset_type:
                createdAssetType,
            })
              ? ""
              : sourcePingback,
          ingredients:
            {},
          is_manual:
            true,
        },
      ]
    );


    setAnalyzeExistingPolicy({
      unshipped:
        "check",
      shipped:
        "check",
    });

    clearPubStageErrors();
  }


  function handleMarkReturn(
    result
  ) {
    const searchTitles =
      Array.isArray(
        result
          ?.search_title
      )
        ? result
            .search_title
        : [];

    const descriptions =
      Array.isArray(
        result
          ?.description
      )
        ? result
            .description
        : [];

    const visibleKeys =
      visibleProposals.map(
        (proposal) =>
          proposal.proposal_key
      );

    const indexByKey =
      new Map(
        visibleKeys.map(
          (
            key,
            index
          ) => [
            key,
            index,
          ]
        )
      );


    setProposals(
      (current) =>
        current.map(
          (proposal) => {
            const index =
              indexByKey.get(
                proposal
                  .proposal_key
              );


            if (
              index ===
              undefined
            ) {
              return proposal;
            }


            return {
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
            };
          }
        )
    );


    setMarketingOpen(
      false
    );

    clearPubStageErrors();
  }


  function updateAnalyzeHandoffValue(
    key,
    value
  ) {
    setAnalyzeHandoffValues(
      (current) => ({
        ...current,
        [key]:
          value,
      })
    );

    clearPubStageErrors();
  }


  const selectedProposals =
    useMemo(
      () =>
        proposals.filter(
          (proposal) =>
            proposal.include
        ),
      [
        proposals,
      ]
    );

  const selectedCount =
    selectedProposals.length;


  const analyzeManagerOutputFields =
    useMemo(
      () =>
        Array.isArray(
          analyzeStageContract
            ?.manager
            ?.output
        )
          ? analyzeStageContract
              .manager
              .output
          : [],
      [
        analyzeStageContract,
      ]
    );


  const createHandoffBoxes =
    useMemo(
      () =>
        selectedProposals.map(
          (proposal) => {
            const box =
              {};


            for (
              const field
              of analyzeManagerOutputFields
            ) {
              const key =
                String(
                  field?.key ||
                  ""
                ).trim();


              if (!key) {
                continue;
              }


              /*
               * Pingback is source identity, not a generic handoff override.
               *
               * Normal playlist-sourced assets always get the canonical
               * source playlist REX. Pinterest YouTube teasers are the only
               * exception and must remain blank until YouTube ships.
               */
              if (
                key ===
                "pingback"
              ) {
                box[key] =
                  isDeferredYoutubeTeaser(
                    proposal
                  )
                    ? ""
                    : sourcePingback ||
                      proposal[
                        key
                      ];

                continue;
              }


              const override =
                analyzeHandoffValues[
                  key
                ];


              box[key] =
                !isEmptyValue(
                  override
                )
                  ? override
                  : proposal[
                      key
                    ];
            }


            /*
             * BINDINGS ARE NOW RESOLVED PER BOX.
             * A mixed run can contain Idea, Palette, YouTube and Teaser
             * boxes at the same time, each with its own product contract.
             */
            const product =
              contractByCreatedAssetType.get(
                String(
                  proposal
                    ?.asset_type ||
                  ""
                )
                  .trim()
                  .toLowerCase()
              );

            const bindings =
              Array.isArray(
                product
                  ?.contract
                  ?.ingredientBindings
              )
                ? product
                    .contract
                    .ingredientBindings
                : [];


            for (
              const binding
              of bindings
            ) {
              const boxField =
                String(
                  binding
                    ?.boxField ||
                  ""
                ).trim();

              const ingredientPath =
                String(
                  binding
                    ?.ingredientPath ||
                  ""
                ).trim();


              if (
                !boxField ||
                !ingredientPath
              ) {
                continue;
              }


              box.ingredients =
                setNestedValue(
                  isPlainObject(
                    box.ingredients
                  )
                    ? box.ingredients
                    : {},
                  ingredientPath,
                  box[
                    boxField
                  ]
                );
            }


            return box;
          }
        ),
      [
        selectedProposals,
        analyzeManagerOutputFields,
        analyzeHandoffValues,
        sourcePingback,
        contractByCreatedAssetType,
      ]
    );


  const handoffFieldNames =
    useMemo(
      () =>
        analyzeManagerOutputFields
          .map(
            (field) =>
              String(
                field?.key ||
                ""
              ).trim()
          )
          .filter(
            Boolean
          ),
      [
        analyzeManagerOutputFields,
      ]
    );


  const requiredHandoffFieldNames =
    useMemo(
      () => {
        const sharedBoxFields =
          Array.isArray(
            contracts
              ?.shared
              ?.boxFields
          )
            ? contracts
                .shared
                .boxFields
            : [];

        const requiredSharedFields =
          sharedBoxFields
            .filter(
              (field) =>
                field
                  ?.requiredAtHandoff ===
                true
            )
            .map(
              (field) =>
                String(
                  field?.key ||
                  ""
                ).trim()
            )
            .filter(
              Boolean
            );


        return [
          ...new Set([
            ...requiredSharedFields,
            "ingredients",
          ]),
        ];
      },
      [
        contracts,
      ]
    );


  const pingbackInvariantSatisfied =
    createHandoffBoxes.every(
      (box) => {
        const sourceType =
          String(
            box
              ?.source_type ||
            ""
          )
            .trim()
            .toLowerCase();

        if (
          sourceType !==
          "playlist"
        ) {
          return true;
        }

        if (
          isDeferredYoutubeTeaser(
            box
          )
        ) {
          return isEmptyValue(
            box
              ?.pingback
          );
        }

        return !isEmptyValue(
          box
            ?.pingback
        );
      }
    );


  const canSendToCreate =
    createHandoffBoxes.length >
      0 &&
    requiredHandoffFieldNames.length >
      0 &&
    pingbackInvariantSatisfied &&
    createHandoffBoxes.every(
      (box) =>
        requiredHandoffFieldNames.every(
          (key) =>
            !isEmptyValue(
              box[
                key
              ]
            )
        )
    );


  const handoffJobId =
    Number(
      createHandoffBoxes[0]
        ?.pub_run_id ||
      analysis
        ?.pub_run_id ||
      0
    );


  async function requestCreate(
    sealedBoxes,
    existingPolicy = {
      unshipped:
        "check",
      shipped:
        "check",
    }
  ) {
    const response =
      await fetch(
        CREATE_URL,
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
              orders:
                sealedBoxes.map(
                  (box) => ({
                    box,
                  })
                ),
              existing_policy:
                existingPolicy,
            }),
        }
      );

    const data =
      await response.json();


    return {
      response,
      data,
    };
  }


  function acceptCreateResult(
    data,
    sealedBoxes
  ) {
    receivePubCom?.(
      data
    );


    const createdCount =
      Number(
        data.created_count ||
        0
      );

    const queuedCount =
      Number(
        data.queued_count ||
        0
      );

    const failedCount =
      Number(
        data.failed_count ||
        0
      );

    const replacedCount =
      Number(
        data.replaced_count ||
        0
      );

    const newVersionCount =
      Number(
        data.new_version_count ||
        0
      );

    const createSummaryParts =
      [];


    if (createdCount > 0) {
      createSummaryParts.push(
        `${createdCount} asset${
          createdCount === 1
            ? ""
            : "s"
        } created`
      );
    }


    if (queuedCount > 0) {
      createSummaryParts.push(
        `${queuedCount} asset${
          queuedCount === 1
            ? ""
            : "s"
        } queued`
      );
    }


    if (replacedCount > 0) {
      createSummaryParts.push(
        `${replacedCount} existing asset${
          replacedCount === 1
            ? ""
            : "s"
        } replaced`
      );
    }


    if (newVersionCount > 0) {
      createSummaryParts.push(
        `${newVersionCount} new version${
          newVersionCount === 1
            ? ""
            : "s"
        }`
      );
    }


    if (failedCount > 0) {
      createSummaryParts.push(
        `${failedCount} failed`
      );
    }


    const createSummary =
      createSummaryParts.length
        ? createSummaryParts.join(
            " · "
          )
        : "No assets accepted by CREATE.";


    onCreateMessage?.(
      createSummary
    );


    const createFailures =
      Array.isArray(
        data.failed
      )
        ? data.failed
        : [];

    const unexpectedCreateFailures =
      createFailures.filter(
        (failure) =>
          !hasOperationalPubCom(
            failure
          )
      );


    /*
     * CREATE failures are returned by batch index. Preserve enough of the
     * original sealed Box here so the error panel can identify the exact
     * failed asset even after the successful siblings have already moved on.
     */
    const detailedCreateFailures =
      unexpectedCreateFailures.map(
        (failure) => {
          const batchIndex =
            Number(
              failure?.index
            );

          const hasBatchIndex =
            Number.isInteger(
              batchIndex
            ) &&
            batchIndex >= 0;

          const box =
            hasBatchIndex &&
            Array.isArray(
              sealedBoxes
            )
              ? sealedBoxes[
                  batchIndex
                ] || null
              : null;

          const assetType =
            String(
              failure?.asset_type ||
              box?.asset_type ||
              ""
            )
              .trim()
              .toLowerCase();

          const product =
            contractByCreatedAssetType.get(
              assetType
            );


          return {
            ...failure,

            batch_index:
              hasBatchIndex
                ? batchIndex
                : null,

            asset_type:
              assetType,

            output_label:
              product
                ?.contract
                ?.label ||
              humanize(
                assetType
              ),

            search_title:
              String(
                box
                  ?.search_title ||
                failure
                  ?.search_title ||
                ""
              ).trim(),

            sort_order:
              box
                ?.sort_order ??
              failure
                ?.sort_order ??
              null,

            source_type:
              String(
                failure
                  ?.source_type ||
                box
                  ?.source_type ||
                ""
              ).trim(),

            source_id:
              Number(
                failure
                  ?.source_id ||
                box
                  ?.source_id ||
                0
              ) || null,

            error:
              failure?.error ||
              failure?.message ||
              "CREATE failed.",
          };
        }
      );


    if (
      detailedCreateFailures.length
    ) {
      setPubStageErrors({
        stage:
          "create",
        code:
          "creator_failures",
        message:
          `${detailedCreateFailures.length} asset${
            detailedCreateFailures.length === 1
              ? ""
              : "s"
          } failed in CREATE.`,
        details:
          detailedCreateFailures,
      });
    }


    setHandoffOpen(
      false
    );


    const acceptedCount =
      createdCount +
      queuedCount;

    const fullCreateSuccess =
      failedCount ===
        0 &&
      acceptedCount >
        0 &&
      acceptedCount ===
        sealedBoxes.length;


    if (fullCreateSuccess) {
      setAnalysis(
        null
      );

      setProposals(
        []
      );

      setAnalyzeHandoffValues(
        {}
      );

      setSourcePingback(
        ""
      );

      setAnalyzeExistingPolicy({
        unshipped:
          "check",
        shipped:
          "check",
      });

      setError(
        ""
      );

      clearPubStageErrors();

      onCreateComplete?.(
        createSummary
      );
    }
  }


  async function submitCreate(
    sealedBoxes,
    existingPolicy = {
      unshipped:
        "check",
      shipped:
        "check",
    }
  ) {
    setSendingToCreate(
      true
    );


    try {
      const {
        response,
        data,
      } =
        await requestCreate(
          sealedBoxes,
          existingPolicy
        );


      if (
        response.status ===
          409 &&
        data?.code ===
          "existing_asset_warning"
      ) {
        const matches =
          Array.isArray(
            data?.matches
          )
            ? data.matches
            : [];

        const nextPolicy = {
          unshipped:
            data
              ?.existing_policy
              ?.unshipped ||
            existingPolicy
              ?.unshipped ||
            "check",
          shipped:
            data
              ?.existing_policy
              ?.shipped ||
            existingPolicy
              ?.shipped ||
            "check",
        };


        const unshippedMatches =
          matches.filter(
            (match) =>
              String(
                match
                  ?.existing_state ||
                ""
              ).toLowerCase() ===
              "unshipped"
          );


        if (
          unshippedMatches.length >
            0 &&
          nextPolicy.unshipped ===
            "check"
        ) {
          const count =
            unshippedMatches.length;

          const replace =
            await dialog.confirm({
              title:
                count === 1
                  ? "Asset Already Created"
                  : "Assets Already Created",
              message:
                count === 1
                  ? "This asset combo has already been created. Replace it?"
                  : `${count} asset combos have already been created. Replace them?`,
              confirmLabel:
                count === 1
                  ? "Replace"
                  : "Replace All",
              cancelLabel:
                "Cancel",
            });


          if (!replace) {
            return;
          }


          nextPolicy.unshipped =
            "replace";
        }


        const shippedMatches =
          matches.filter(
            (match) =>
              String(
                match
                  ?.existing_state ||
                ""
              ).toLowerCase() ===
              "shipped"
          );


        if (
          shippedMatches.length >
            0 &&
          nextPolicy.shipped ===
            "check"
        ) {
          const count =
            shippedMatches.length;

          const createNewVersion =
            await dialog.confirm({
              title:
                count === 1
                  ? "Asset Already Shipped"
                  : "Assets Already Shipped",
              message:
                count === 1
                  ? "This asset combo has already been shipped. Create a new version?"
                  : `${count} asset combos have already been shipped. Create new versions?`,
              confirmLabel:
                count === 1
                  ? "Create New Version"
                  : "Create New Versions",
              cancelLabel:
                "Cancel",
            });


          if (!createNewVersion) {
            return;
          }


          nextPolicy.shipped =
            "new_version";
        }


        await submitCreate(
          sealedBoxes,
          nextPolicy
        );

        return;
      }


      if (
        !response.ok ||
        !data?.ok
      ) {
        throw new Error(
          data?.error ||
          "CREATE failed."
        );
      }


      acceptCreateResult(
        data,
        sealedBoxes
      );

    } catch (err) {
      setPubStageErrors({
        stage:
          "analyze",
        code:
          "handoff_failed",
        message:
          err?.message ||
          "Analyze handoff failed.",
      });

    } finally {
      setSendingToCreate(
        false
      );
    }
  }


  async function sendToCreate() {
    clearPubStageErrors();

    onCreateMessage?.(
      ""
    );


    if (!canSendToCreate) {
      setPubStageErrors({
        stage:
          "analyze",
        code:
          "incomplete_analyze_box",
        message:
          "One or more Analyze Manager output fields are incomplete.",
      });

      return;
    }

console.log(
  "CREATE BOXES",
  JSON.parse(JSON.stringify(createHandoffBoxes))
);

await submitCreate(
  createHandoffBoxes,
  analyzeExistingPolicy
);
  }


  const filterLabel =
    outputFilter ===
      "all"
      ? "All"
      : analyzeOutputContract
          ?.label ||
        humanize(
          outputFilter
        );

  const canEditSingleProduct =
    outputFilter !==
      "all" &&
    Boolean(
      analyzeOutputContract
    );


  return (
    <>
      <div className="admin-detail-workarea">
        <AdminToolbar>
          <label className="admin-field admin-field--compact">
            <span className="admin-field__label">
              Output
            </span>

            <select
              className="admin-field__control"
              value={outputFilter}
              onChange={(event) =>
                changeOutputFilter(event.target.value)
              }
            >
              {analyzeOutputTypes.map((type) => (
                <option
                  key={type.value}
                  value={type.value}
                >
                  {type.label}
                </option>
              ))}
            </select>
          </label>

          <label className="admin-field admin-field--grow">
            <span className="admin-field__label">
              Source Playlist
            </span>

            <select
              className="admin-field__control admin-field__control--full"
              value={playlistId}
              onChange={(event) =>
                changePlaylist(event.target.value)
              }
              disabled={loadingPlaylists}
            >
              <option value="">
                Pick playlist
              </option>

              {playlists.map((playlist) => (
                <option
                  key={playlist.playlist_id}
                  value={playlist.playlist_id}
                >
                  #{playlist.playlist_id} {playlist.title}
                </option>
              ))}
            </select>
          </label>

          {playlistId ? (
            <>
              <AdminButton
                type="button"
                variant="secondary"
                onClick={() => {
                  window.location.href =
                    `/admin/playlists/${playlistId}`;
                }}
              >
                Edit Playlist
              </AdminButton>

              <AdminButton
                type="button"
                variant="secondary"
                onClick={() => {
                  window.location.href =
                    "/admin/palette-viewers";
                }}
              >
                Edit PV Copy
              </AdminButton>
            </>
          ) : null}

          <AdminButton
            type="button"
            onClick={analyzeSource}
            disabled={analyzing || !playlistId}
          >
            {analyzing ? "Analyzing..." : "Analyze"}
          </AdminButton>
        </AdminToolbar>

        {contractError ? (
          <AdminEmptyState
            title="PUB contract failed"
            message={contractError}
          />
        ) : error ? (
          <AdminEmptyState
            title="Analyze failed"
            message={error}
          />
        ) : !analysis ? (
          <AdminEmptyState
            title="Analyze Source"
            message="Choose a source playlist. PUB will analyze it for all applicable outputs. Output is only a workbench filter."
          />
        ) : (
          <AdminStack gap="md" fill>
            <AdminSectionHeader
              title={analysis?.source?.title || "Playlist"}
              meta={
                <>
                  {outputFilter === "all"
                    ? `${proposals.length} possible assets`
                    : `${visibleProposals.length} ${filterLabel} asset${
                        visibleProposals.length === 1 ? "" : "s"
                      } shown · ${proposals.length} total`}
                  {analysis?.pub_run_id
                    ? ` · Run #${analysis.pub_run_id}`
                    : ""}
                </>
              }
              actions={
                <>
                  <AdminButton
                    type="button"
                    variant="secondary"
                    onClick={addProposal}
                    disabled={!canEditSingleProduct}
                    title={
                      canEditSingleProduct
                        ? ""
                        : "Choose one Output filter before adding a manual row."
                    }
                  >
                    Add Row
                  </AdminButton>

                  <AdminButton
                    type="button"
                    variant="secondary"
                    onClick={() => setMarketingOpen(true)}
                    disabled={
                      !canEditSingleProduct ||
                      visibleProposals.length === 0
                    }
                    title={
                      canEditSingleProduct
                        ? ""
                        : "Choose one Output filter before calling MARK."
                    }
                  >
                    Call Mark
                  </AdminButton>

                  <AdminButton
                    type="button"
                    disabled={selectedCount === 0}
                    onClick={() => {
                      clearPubStageErrors();
                      setHandoffOpen(true);
                    }}
                  >
                    Send to CREATE ({selectedCount})
                  </AdminButton>
                </>
              }
            />

            {createMessage ? (
              <AdminNotice variant="success">
                {createMessage}
              </AdminNotice>
            ) : null}

            <PubStageErrors errors={pubStageErrors} />

            <AdminDataGrid
              ariaLabel="Analyze proposals"
              bordered
              minWidth={1050}
              verticalAlign="top"
            >
              <thead>
                <tr>
                  <th>Use</th>
                  <th>Order</th>
                  <th>Output</th>

                  {outputFilter === "all" ? (
                    <th>Sources</th>
                  ) : (
                    analyzeSourceColumns.map((column, index) => (
                      <th
                        key={`${
                          column?.ingredientPath || "source"
                        }-${index}`}
                      >
                        {column?.label || "Source"}
                      </th>
                    ))
                  )}

                  <th>Search Title</th>
                  <th>Description</th>
                  <th>Actions</th>
                </tr>
              </thead>

              <tbody>
                {visibleProposals.map((proposal) => {
                  const product =
                    productForProposal(
                      proposal,
                      contractByCreatedAssetType
                    );

                  const rowSourceColumns =
                    Array.isArray(
                      product?.contract?.workbench?.sourceColumns
                    )
                      ? product.contract.workbench.sourceColumns
                      : [];

                  return (
                    <tr key={proposal.proposal_key}>
                      <td>
                        <input
                          type="checkbox"
                          checked={proposal.include}
                          onChange={(event) =>
                            updateProposal(
                              proposal.proposal_key,
                              {
                                include: event.target.checked,
                              }
                            )
                          }
                        />
                      </td>

                      <td>{proposal.display_order}</td>

                      <td>
                        <div>
                          {product?.contract?.label ||
                            humanize(proposal.asset_type)}
                        </div>

                        {String(proposal?.asset_type || "")
                          .trim()
                          .toLowerCase() === "youtube_video" &&
                        Number(
                          proposal?.estimated_duration_ms || 0
                        ) > 0 ? (
                          <div className="admin-grid-cell__secondary">
                            Est. runtime:{" "}
                            {formatEstimatedRuntime(
                              proposal.estimated_duration_ms
                            )}
                          </div>
                        ) : null}
                      </td>

                      {outputFilter === "all" ? (
                        <td>
                          <ProposalSources
                            proposal={proposal}
                            sourceColumns={rowSourceColumns}
                          />
                        </td>
                      ) : (
                        analyzeSourceColumns.map((column, index) => {
                          const previewItem =
                            proposalIngredientPreviewItem(
                              proposal,
                              column?.ingredientPath
                            );

                          return (
                            <td
                              key={`${
                                column?.ingredientPath || "source"
                              }-${index}`}
                            >
                              {previewItem ? (
                                <SourcePreview item={previewItem} />
                              ) : (
                                "—"
                              )}
                            </td>
                          );
                        })
                      )}

                      <td>
                        <AdminTableTextarea
                          width={200}
                          rows={3}
                          value={proposal.search_title}
                          onChange={(event) =>
                            updateProposal(
                              proposal.proposal_key,
                              {
                                search_title: event.target.value,
                              }
                            )
                          }
                        />
                      </td>

                      <td>
                        <AdminTableTextarea
                          width={330}
                          rows={5}
                          value={proposal.description}
                          onChange={(event) =>
                            updateProposal(
                              proposal.proposal_key,
                              {
                                description: event.target.value,
                              }
                            )
                          }
                        />
                      </td>

                      <td>
                        <AdminButton
                          type="button"
                          variant="secondary"
                          onClick={() =>
                            removeProposal(proposal.proposal_key)
                          }
                        >
                          Remove
                        </AdminButton>
                      </td>
                    </tr>
                  );
                })}

                {visibleProposals.length === 0 ? (
                  <tr>
                    <td
                      className="admin-grid-empty"
                      colSpan={
                        6 +
                        (outputFilter === "all"
                          ? 1
                          : analyzeSourceColumns.length)
                      }
                    >
                      No {filterLabel} proposals found.
                    </td>
                  </tr>
                ) : null}
              </tbody>
            </AdminDataGrid>
          </AdminStack>
        )}
      </div>

      <AdminFullScreenOverlay
        open={marketingOpen}
        ariaLabel="Marketing"
      >
        <MarketingWorkspace
          mode="call"
          title="Marketing"
          request={{
            tags: [
              analyzeOutputContract?.channel || "pinterest",
            ],
            deliverables: [
              {
                key: "search_title",
                label: "Search Titles",
                count: visibleProposals.length || 1,
                allowDuplicates: true,
              },
              {
                key: "description",
                label: "Descriptions",
                count: visibleProposals.length || 1,
                allowDuplicates: true,
              },
            ],
          }}
          onReturn={handleMarkReturn}
          onClose={() => setMarketingOpen(false)}
        />
      </AdminFullScreenOverlay>

      <AdminWorkbenchDrawer
        open={handoffOpen}
        portal
        padded
        width={440}
        title="Analyze Handoff"
        onClose={() => setHandoffOpen(false)}
        footer={
          <AdminButton
            type="button"
            fullWidth
            disabled={!canSendToCreate || sendingToCreate}
            onClick={sendToCreate}
          >
            {sendingToCreate
              ? "Sending..."
              : `Send ${createHandoffBoxes.length} Box${
                  createHandoffBoxes.length === 1 ? "" : "es"
                } to CREATE`}
          </AdminButton>
        }
      >
        <AdminStack gap="md">
          <PubStageErrors errors={pubStageErrors} />

          <AnalyzeHandoffSummary
            jobId={handoffJobId}
            boxes={createHandoffBoxes}
            fieldNames={handoffFieldNames}
            pingback={sourcePingback}
            showPingback={
              handoffFieldNames.includes("pingback")
            }
          />
        </AdminStack>
      </AdminWorkbenchDrawer>
    </>
  );
}

function formatEstimatedRuntime(
  durationMs
) {
  const milliseconds =
    Number(
      durationMs ||
      0
    );


  if (
    !Number.isFinite(
      milliseconds
    ) ||
    milliseconds <=
      0
  ) {
    return "—";
  }


  const totalSeconds =
    Math.round(
      milliseconds /
      1000
    );

  const minutes =
    Math.floor(
      totalSeconds /
      60
    );

  const seconds =
    totalSeconds %
    60;


  return `${minutes}:${String(
    seconds
  ).padStart(
    2,
    "0"
  )}`;
}


function existingCopyPreview(
  match
) {
  if (!match) {
    return "";
  }


  const currentTitle =
    String(
      match
        ?.existing_asset
        ?.search_title ||
      ""
    ).trim();

  const currentDescription =
    String(
      match
        ?.existing_asset
        ?.description ||
      ""
    ).trim();


  return [
    currentTitle
      ? `Current title:\n${currentTitle}`
      : "",
    currentDescription
      ? `Current description:\n${currentDescription}`
      : "",
  ]
    .filter(
      Boolean
    )
    .join(
      "\n\n"
    );
}


function productForProposal(
  proposal,
  contractByCreatedAssetType
) {
  return contractByCreatedAssetType.get(
    String(
      proposal
        ?.asset_type ||
      ""
    )
      .trim()
      .toLowerCase()
  ) ||
  null;
}


function normalizeProposal(
  proposal,
  index
) {
  return {
    ...proposal,

    proposal_key:
      proposal
        .proposal_key ||
      `analyze-row-${index + 1}`,

    /*
     * Keep Manager sort_order untouched. It is part of logical asset
     * identity. display_order exists only for the workbench.
     */
    sort_order:
      proposal
        .sort_order ??
      null,

    display_order:
      proposal
        .sort_order ??
      index + 1,

    include:
      proposal
        .include !==
      false,

    search_title:
      proposal
        .search_title ||
      "",

    description:
      proposal
        .description ||
      "",

    pingback:
      proposal
        .pingback ||
      "",

    ingredients:
      isPlainObject(
        proposal
          .ingredients
      )
        ? proposal.ingredients
        : {},
  };
}


function ProposalSources({
  proposal,
  sourceColumns,
}) {
  if (
    !Array.isArray(sourceColumns) ||
    sourceColumns.length === 0
  ) {
    return "—";
  }

  const rows =
    sourceColumns
      .map((column, index) => ({
        key: `${column?.ingredientPath || "source"}-${index}`,
        label: column?.label || "Source",
        item: proposalIngredientPreviewItem(
          proposal,
          column?.ingredientPath
        ),
      }))
      .filter((row) => row.item);

  if (rows.length === 0) {
    return "—";
  }

  return (
    <div className="admin-source-list">
      {rows.map((row) => (
        <div key={row.key}>
          <div className="admin-source-list__label">
            {row.label}
          </div>

          <SourcePreview item={row.item} />
        </div>
      ))}
    </div>
  );
}

function proposalIngredientPreviewItem(
  proposal,
  ingredientPath
) {
  const value =
    getNestedValue(
      proposal
        ?.ingredients,
      ingredientPath
    );


  if (
    value ===
      undefined ||
    value ===
      null ||
    String(
      value
    ).trim() ===
      ""
  ) {
    return null;
  }


  return {
    file_path:
      value,
  };
}


function getNestedValue(
  source,
  path
) {
  const parts =
    String(
      path ||
      ""
    )
      .split(
        "."
      )
      .map(
        (part) =>
          part.trim()
      )
      .filter(
        Boolean
      );

  let cursor =
    source;


  for (
    const part
    of parts
  ) {
    if (
      cursor ===
        null ||
      cursor ===
        undefined ||
      typeof cursor !==
        "object"
    ) {
      return undefined;
    }


    cursor =
      cursor[
        part
      ];
  }


  return cursor;
}


function SourcePreview({
  item,
}) {
  const imageUrl =
    browserImageUrl(
      item?.image_url ||
      item?.file_path
    );

  const label =
    item?.photo_library_id
      ? `Photo #${item.photo_library_id}`
      : "";

  const detail =
    item?.title ||
    (item?.file_path
      ? sourceFileName(item.file_path)
      : "");

  return (
    <AdminMediaPreview
      imageUrl={imageUrl}
      label={label}
      detail={detail}
      detailTitle={
        !item?.title && item?.file_path
          ? item.file_path
          : ""
      }
    />
  );
}

function browserImageUrl(
  value
) {
  let raw =
    String(
      value ||
      ""
    ).trim();


  if (!raw) {
    return "";
  }


  const pipeIndex =
    raw.indexOf(
      "|"
    );


  if (
    pipeIndex >=
    0
  ) {
    raw =
      raw
        .slice(
          pipeIndex +
          1
        )
        .trim();
  }


  if (
    /^https?:\/\//i.test(
      raw
    )
  ) {
    return raw;
  }


  const projectMarker =
    "/colorfix/";

  const markerIndex =
    raw.lastIndexOf(
      projectMarker
    );


  if (
    markerIndex >=
    0
  ) {
    raw =
      raw.slice(
        markerIndex +
        projectMarker.length
      );
  }


  if (
    /^\/(?:home\d*|var|srv|opt)\//i.test(
      raw
    )
  ) {
    return "";
  }


  return (
    "/" +
    raw.replace(
      /^\/+/, 
      ""
    )
  );
}


function sourceFileName(
  value
) {
  const raw =
    String(
      value ||
      ""
    ).trim();


  if (!raw) {
    return "";
  }


  const parts =
    raw.split(
      "/"
    );


  return parts[
    parts.length - 1
  ] ||
  raw;
}


function collectPubComDispositions(
  payload
) {
  if (
    !payload ||
    typeof payload !==
      "object"
  ) {
    return [];
  }


  const collected =
    [];

  const append = (
    value
  ) => {
    if (
      !Array.isArray(
        value
      )
    ) {
      return;
    }


    for (
      const disposition
      of value
    ) {
      if (
        disposition &&
        typeof disposition ===
          "object"
      ) {
        collected.push(
          disposition
        );
      }
    }
  };


  append(
    payload.pubcom
  );


  for (
    const key
    of [
      "created",
      "queued",
      "failed",
    ]
  ) {
    const entries =
      Array.isArray(
        payload[key]
      )
        ? payload[key]
        : [];


    for (
      const entry
      of entries
    ) {
      append(
        entry
          ?.pubcom
      );
    }
  }


  append(
    payload
      ?.result
      ?.pubcom
  );


  return collected;
}


function hasOperationalPubCom(
  payload
) {
  return collectPubComDispositions(
    payload
  ).some(
    (disposition) => {
      const display =
        String(
          disposition
            ?.display ||
          ""
        ).toLowerCase();

      const signalType =
        String(
          disposition
            ?.signal
            ?.type ||
          ""
        ).toLowerCase();


      return (
        display ===
          "toast" ||
        display ===
          "popup" ||
        signalType ===
          "notice" ||
        signalType ===
          "ineligible" ||
        signalType ===
          "unavailable"
      );
    }
  );
}


function isDeferredYoutubeTeaser(
  value
) {
  const channel =
    String(
      value
        ?.channel ||
      ""
    )
      .trim()
      .toLowerCase();

  const assetType =
    String(
      value
        ?.asset_type ||
      ""
    )
      .trim()
      .toLowerCase();

  const pinterestChannel =
    channel ===
      "pinterest" ||
    channel ===
      "pin";

  return (
    pinterestChannel &&
    assetType.includes(
      "teaser"
    )
  );
}


function isPlainObject(
  value
) {
  return Boolean(
    value &&
    typeof value ===
      "object" &&
    !Array.isArray(
      value
    )
  );
}


function setNestedValue(
  source,
  path,
  value
) {
  const next = {
    ...source,
  };

  const parts =
    String(
      path ||
      ""
    )
      .split(
        "."
      )
      .map(
        (part) =>
          part.trim()
      )
      .filter(
        Boolean
      );


  if (!parts.length) {
    return next;
  }


  let cursor =
    next;


  for (
    let index = 0;
    index <
      parts.length - 1;
    index++
  ) {
    const key =
      parts[
        index
      ];

    const child =
      isPlainObject(
        cursor[
          key
        ]
      )
        ? cursor[
            key
          ]
        : {};


    cursor[
      key
    ] = {
      ...child,
    };

    cursor =
      cursor[
        key
      ];
  }


  cursor[
    parts[
      parts.length - 1
    ]
  ] =
    value;


  return next;
}


function isEmptyValue(
  value
) {
  if (
    value ===
      null ||
    value ===
      undefined
  ) {
    return true;
  }


  if (
    typeof value ===
    "string"
  ) {
    return (
      value.trim() ===
      ""
    );
  }


  if (
    Array.isArray(
      value
    )
  ) {
    return (
      value.length ===
      0
    );
  }


  if (
    typeof value ===
      "object"
  ) {
    return (
      Object.keys(
        value
      ).length ===
      0
    );
  }


  return false;
}


function humanize(
  value
) {
  return String(
    value ||
    ""
  )
    .replace(
      /[_-]+/g,
      " "
    )
    .replace(
      /\b\w/g,
      (character) =>
        character
          .toUpperCase()
    );
}


function AnalyzeHandoffSummary({
  jobId,
  boxes,
  fieldNames,
  pingback,
  showPingback,
}) {
  const boxCount =
    Array.isArray(boxes)
      ? boxes.length
      : 0;

  return (
    <AdminStack gap="lg">
      <div>
        <div className="admin-eyebrow">
          Handing to CREATE
        </div>

        <div className="admin-summary-title">
          Run #{jobId || "—"}
        </div>

        <div className="admin-summary-meta">
          {boxCount} Box{boxCount === 1 ? "" : "es"}
        </div>
      </div>

      {showPingback ? (
        <label className="admin-field">
          <span className="admin-field__label">
            Source Playlist Pingback
          </span>

          <input
            className="admin-field__control admin-field__control--full"
            type="text"
            value={pingback}
            readOnly
          />
        </label>
      ) : null}

      <div>
        <div className="admin-eyebrow">
          Each Box Contains
        </div>

        <div className="admin-key-list">
          {fieldNames.map((fieldName) => (
            <div
              key={fieldName}
              className="admin-key-list__item"
            >
              <code>
                {fieldName}
                {fieldName === "ingredients" ? " {}" : ""}
              </code>
            </div>
          ))}
        </div>
      </div>
    </AdminStack>
  );
}
