import {
  useEffect,
  useMemo,
  useState,
} from "react";

import {
  createPortal,
} from "react-dom";

import {
  API_FOLDER,
} from "@helpers/config";

import {
  fetchRex,
} from "@helpers/rexHelpers";

import MarketingWorkspace
  from "@components/Marketing/MarketingWorkspace";

import {
  AdminDetailPane,
  AdminEmptyState,
  AdminListPane,
  AdminMasterDetail,
  AdminObjectList,
  AdminObjectListItem,
  AdminWorkbenchDrawer,
} from "@components/AdminLayout";

import PubPipelineReference
  from "./PubPipelineReference";

import PubAssetsTable
  from "./PubAssetsTable";

import PubPackageTable
  from "./PubPackageTable";

import PubDispatchTable
  from "./PubDispatchTable";

import PubStageErrors
  from "./PubStageErrors";

import usePubStageErrors
  from "./hooks/usePubStageErrors";


const PLAYLISTS_URL =
  `${API_FOLDER}/v2/admin/playlists/list.php`;


/*
 * PUB ANALYZE FRONT DOOR.
 *
 * The endpoint creates the PUB run,
 * prepares the source, routes through
 * AnalyzeManager, and returns boxes.
 */
const ANALYZE_URL =
  `${API_FOLDER}/v2/admin/pub/analyze.php`;

const CREATE_URL =
  `${API_FOLDER}/v2/admin/pub/create.php`;

const PUB_CONTRACTS_URL =
  `${API_FOLDER}/v2/admin/pub/contracts.php`;


/*
 * ANALYZE OUTPUTS
 *
 * For now we deliberately expose only
 * the output type we have already tested.
 *
 * Later this becomes:
 *
 *   composite
 *   before_after_video
 *   idea
 *   idea_palette
 *   youtube_video
 *   youtube_teaser_pin
 *
 * and eventually a multi-select.
 */

const PUB_STAGE_KEYS =
  new Set([
    "reference",
    "analyze",
    "assets",
    "package",
    "schedule",
    "dispatch",
  ]);


function pubStageFromLocation() {
  if (
    typeof window ===
    "undefined"
  ) {
    return "analyze";
  }


  const params =
    new URLSearchParams(
      window.location.search
    );

  const queryStage =
    String(
      params.get(
        "stage"
      ) ||
      ""
    )
      .trim()
      .toLowerCase();


  if (
    PUB_STAGE_KEYS.has(
      queryStage
    )
  ) {
    return queryStage;
  }


  const hashStage =
    String(
      window.location.hash ||
      ""
    )
      .replace(
        /^#/,
        ""
      )
      .trim()
      .toLowerCase();


  return PUB_STAGE_KEYS.has(
    hashStage
  )
    ? hashStage
    : "analyze";
}


export default function AdminPubPage() {
  const {
    errors:
      pubStageErrors,

    setErrors:
      setPubStageErrors,

    clearErrors:
      clearPubStageErrors,
  } = usePubStageErrors();


  const [
    pubContracts,
    setPubContracts,
  ] = useState(
    null
  );

  const [
  createMessage,
  setCreateMessage,
] = useState("");


  /*
   * ========================================================
   * PUBCOM UI RECEIVER
   * ========================================================
   *
   * Backend Managers decide whether a PubCom disposition
   * should be invisible, a toast, or a popup.
   *
   * This page only renders that decision. It does not
   * reinterpret worker signals.
   */
  const [
    pubComToasts,
    setPubComToasts,
  ] = useState(
    []
  );

  const [
    pubComPopups,
    setPubComPopups,
  ] = useState(
    []
  );


  /*
   * Toasts are non-blocking and disappear automatically.
   * If several arrive together, show them one at a time.
   */
  useEffect(() => {
    if (
      pubComToasts.length ===
      0
    ) {
      return undefined;
    }


    const timer =
      window.setTimeout(
        () => {
          setPubComToasts(
            (current) =>
              current.slice(
                1
              )
          );
        },
        6000
      );


    return () => {
      window.clearTimeout(
        timer
      );
    };
  }, [
    pubComToasts,
  ]);


  function receivePubCom(
    payload
  ) {
    const dispositions =
      collectPubComDispositions(
        payload
      );


    if (
      dispositions.length ===
      0
    ) {
      return dispositions;
    }


    const toasts =
      dispositions.filter(
        (disposition) =>
          String(
            disposition
              ?.display ||
            ""
          ).toLowerCase() ===
          "toast"
      );

    const popups =
      dispositions.filter(
        (disposition) =>
          String(
            disposition
              ?.display ||
            ""
          ).toLowerCase() ===
          "popup"
      );


    if (toasts.length) {
      setPubComToasts(
        (current) => [
          ...current,
          ...toasts,
        ]
      );
    }


    if (popups.length) {
      setPubComPopups(
        (current) => [
          ...current,
          ...popups,
        ]
      );
    }


    return dispositions;
  }


  /*
   * CURRENT OUTPUT DISH
   */
  const [
    outputType,
    setOutputType,
  ] = useState(
    "composite"
  );


  /*
   * ANALYZE STAGE CONTRACT
   *
   * Stage owns:
   *
   *   stage
   *   nextStage
   *   assetTypes
   */
  const analyzeStageContract =
    pubContracts
      ?.analyze ||
    null;


  /*
   * ANALYZE OUTPUT TYPES
   *
   * The UI does not maintain a product list.
   * Any Analyze product with a migrated specialist
   * contract appears automatically.
   */
  const analyzeOutputTypes =
    useMemo(
      () =>
        Object.entries(
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
            })
          ),

      [
        analyzeStageContract,
      ]
    );


  /*
   * OUTPUT CONTRACT
   *
   * Output type owns:
   *
   *   label
   *   channel
   *   requiredIngredients
   */
  const analyzeOutputContract =
    analyzeStageContract
      ?.assetTypes
      ?.[outputType] ||
    null;


  /*
   * RECIPE-SPECIFIC ANALYZE FORM
   *
   * Each specialist contract declares the visual source columns
   * its workbench needs. The page does not know about Before,
   * After, Source, Thumbnail, etc.
   */
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
    stage,
    setStageState,
  ] = useState(
    () =>
      pubStageFromLocation()
  );


  /*
   * Keep the current PUB room in ?stage=.
   *
   * OAuth can safely round-trip query parameters through
   * the server, so Dispatch returns to:
   *
   *   /admin/pub?stage=dispatch
   */
  function setStage(
    nextStage
  ) {
    const value =
      String(
        nextStage ||
        ""
      )
        .trim()
        .toLowerCase();

    const resolved =
      PUB_STAGE_KEYS.has(
        value
      )
        ? value
        : "analyze";


    setStageState(
      resolved
    );


    if (
      typeof window !==
      "undefined"
    ) {
      const params =
        new URLSearchParams(
          window.location.search
        );


      params.set(
        "stage",
        resolved
      );


      window.history.replaceState(
        window.history.state,
        "",
        `${window.location.pathname}?${params.toString()}`
      );
    }
  }


  /*
   * Direct stage URLs and OAuth returns must open
   * the requested PUB room instead of defaulting to Analyze.
   */
  useEffect(() => {
    setStageState(
      pubStageFromLocation()
    );
  }, []);


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
    ""
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
    loadingPlaylists,
    setLoadingPlaylists,
  ] = useState(
    false
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
    marketingOpen,
    setMarketingOpen,
  ] = useState(
    false
  );


  /*
   * ANALYZE HANDOFF VALUES
   *
   * Still retained while we retrofit
   * the existing Composite handoff.
   *
   * Handoff itself is the next thing
   * we will rework against the new
   * output-oriented contract.
   */
  const [
    analyzeHandoffValues,
    setAnalyzeHandoffValues,
  ] = useState(
    {}
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


  /*
   * CREATE DUPLICATE GATE.
   *
   * CreateManager may stop a NEW batch before production when one or
   * more final ingredient boxes exactly match something already shipped.
   *
   * Keep the exact sealed boxes that triggered the warning so the boss
   * decision resubmits the same production request unchanged.
   */
  const [
    createDuplicateWarning,
    setCreateDuplicateWarning,
  ] = useState(
    null
  );


  /*
   * ========================================================
   * LOAD PUB CONTRACTS
   * ========================================================
   */
  useEffect(() => {
    let active =
      true;


    async function loadPubContracts() {
      try {
        const res =
          await fetch(
            `${PUB_CONTRACTS_URL}?_=${Date.now()}`,
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
            "Failed to load PUB contracts"
          );
        }


        if (!active) {
          return;
        }


        setPubContracts(
          data.contracts ||
          null
        );

      } catch (err) {
        if (!active) {
          return;
        }


        setError(
          err?.message ||
          "Failed to load PUB contracts"
        );
      }
    }


    loadPubContracts();


    return () => {
      active =
        false;
    };
  }, []);


  /*
   * ========================================================
   * LOAD PLAYLISTS
   * ========================================================
   */
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


  /*
   * ========================================================
   * CHANGE OUTPUT TYPE
   * ========================================================
   *
   * A different requested dish means
   * the current analysis is obsolete.
   */
  function changeOutputType(
    value
  ) {
    setOutputType(
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

    clearPubStageErrors();

    setError(
      ""
    );
  }


  /*
   * ========================================================
   * ANALYZE SOURCE FOR ONE OUTPUT
   * ========================================================
   *
   * Request:
   *
   *   source_type = playlist
   *   source_id   = #
   *   output_type = composite
   *
   * The backend owns source preparation,
   * channel eligibility, specialist routing,
   * and pub_run_id stamping.
   */
  async function analyzeOutput() {
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


    if (!outputType) {
      setError(
        "Pick an output type first."
      );

      return;
    }


    setAnalyzing(
      true
    );

    setCreateMessage(
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


    try {
      /*
       * ANALYZE REQUEST HELPER.
       *
       * run_mode:
       *
       *   check     = normal Analyze click
       *   new       = intentionally make another Job ID
       *   overwrite = wipe and reuse an existing Job ID
       */
      async function requestAnalyze(
        runMode =
          "check",
        overwritePubRunId =
          0
      ) {
        const body = {
          source_type:
            "playlist",

          source_id:
            id,

          output_type:
            outputType,

          run_mode:
            runMode,
        };


        if (
          runMode ===
            "overwrite" &&
          overwritePubRunId >
            0
        ) {
          body
            .overwrite_pub_run_id =
              overwritePubRunId;
        }


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
                JSON.stringify(
                  body
                ),
            }
          );


        const data =
          await res.json();


        return {
          res,
          data,
        };
      }


      let {
        res,
        data,
      } =
        await requestAnalyze(
          "check"
        );


      /*
       * SAME SOURCE + OUTPUT ALREADY HAS A JOB.
       *
       * Do not silently create duplicate production work.
       *
       * First choice:
       *   OK     = overwrite the existing job
       *   Cancel = keep it
       *
       * If kept, ask explicitly whether to create a NEW job.
       * A second Cancel means stop entirely.
       */
      if (
        res.status ===
          409 &&
        data?.code ===
          "existing_pub_run"
      ) {
        const existingRunId =
          Number(
            data
              ?.existing_run
              ?.pub_run_id ||
            0
          );

        const existingAssetCount =
          Number(
            data
              ?.asset_count ||
            0
          );


        if (
          existingRunId >
            0 &&
          data
            ?.can_overwrite
        ) {
          const overwrite =
            window.confirm(
              `Job #${existingRunId} already exists for this source and output`
              + (
                existingAssetCount
                  ? ` and currently owns ${existingAssetCount} asset${
                      existingAssetCount === 1
                        ? ""
                        : "s"
                    }`
                  : ""
              )
              + ".\n\n"
              + "Overwrite this job?\n\n"
              + "OK = DELETE its existing in-house files/assets and rerun Analyze using the SAME Job ID.\n"
              + "Cancel = keep the existing job."
            );


          if (overwrite) {
            ({
              res,
              data,
            } =
              await requestAnalyze(
                "overwrite",
                existingRunId
              ));

          } else {
            const runNew =
              window.confirm(
                `Keep Job #${existingRunId} and create a NEW job instead?\n\n`
                + "OK = Run New Job\n"
                + "Cancel = Stop"
              );


            if (!runNew) {
              return;
            }


            ({
              res,
              data,
            } =
              await requestAnalyze(
                "new"
              ));
          }

        } else {
          /*
           * Something from this job has already left
           * the building. Whole-job overwrite is locked.
           */
          const runNew =
            window.confirm(
              `Job #${existingRunId || "?"} already exists, but it cannot be overwritten because at least one asset has been dispatched/published.\n\n`
              + "Create a NEW job instead?\n\n"
              + "OK = Run New Job\n"
              + "Cancel = Stop"
            );


          if (!runNew) {
            return;
          }


          ({
            res,
            data,
          } =
            await requestAnalyze(
              "new"
            ));
        }
      }


      if (
        !res.ok ||
        !data?.ok
      ) {
        throw new Error(
          data?.error ||
          "Failed to analyze playlist"
        );
      }


      /*
       * The Analyze Manager has already decided which
       * PubCom messages are invisible / toast / popup.
       */
      receivePubCom(
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
    ? data.boxes
        .map(
          (
            proposal,
            index
          ) =>
            normalizeProposal(
              proposal,
              index
            )
        )
    : [];


console.log(
  "ANALYZE INGREDIENTS:",
  nextProposals.map(
    (proposal) => ({
      asset_type:
        proposal.asset_type,

      ingredients:
        proposal.ingredients,
    })
  )
);



      const failed =
        Array.isArray(
          data.failed
        )
          ? data.failed
          : [];


      /*
       * Expected operating conditions belong to PubCom,
       * not the forensic/system-error presentation.
       *
       * A true exception may still contain READY PubCom
       * history, so suppress only entries that contain an
       * actual operational PubCom condition.
       */
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
            `${unexpectedFailures.length} box${
              unexpectedFailures.length === 1
                ? ""
                : "es"
            } failed in ANALYZE.`,

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

  /*
   * ========================================================
   * PROPOSALS
   * ========================================================
   */
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


  /*
   * Manual proposal for the CURRENT
   * requested output type.
   */
  function addProposal() {
    const key =
      `manual-${Date.now()}`;


    setProposals(
      (current) => [
        ...current,

        {
          proposal_key:
            key,

          pin_type:
            outputType,

          asset_type:
            analyzeOutputContract
              ?.createsAssetType ||
            "",

          sort_order:
            current.length +
            1,

          include:
            true,

          search_title:
            "",

          description:
            "",

          ingredients:
            {},

          is_manual:
            true,
        },
      ]
    );


    clearPubStageErrors();
  }


  /*
   * ========================================================
   * MARK
   * ========================================================
   */
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

    clearPubStageErrors();
  }


  /*
   * ========================================================
   * HANDOFF VALUES
   * ========================================================
   */
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


  /*
   * ========================================================
   * CONTRACT FIELD HELPERS
   * ========================================================
   *
   * TEMPORARY Composite helper.
   *
   * We will rework this with the handoff
   * code after AnalyzeManager is wired.
   */
  async function handleAnalyzeHelper(
    helperKey
  ) {
    if (
      helperKey !==
      "rex"
    ) {
      return;
    }


    const id =
      Number(
        playlistId ||
        0
      );


    if (!id) {
      setPubStageErrors({
        stage:
          "analyze",

        code:
          "missing_playlist",

        message:
          "Choose a playlist before fetching REX.",
      });

      return;
    }


    try {
      const url =
        await fetchRex(
          id,
          "pinterest"
        );


      updateAnalyzeHandoffValue(
        "pingback",
        url
      );

    } catch (err) {
      setPubStageErrors({
        stage:
          "analyze",

        code:
          "rex_failed",

        message:
          err?.message ||
          "Could not fetch REX.",
      });
    }
  }


  /*
   * ========================================================
   * SELECTED BOXES
   * ========================================================
   */
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
    selectedProposals
      .length;


  /*
   * ========================================================
   * ANALYZE -> CREATE BOXES
   * ========================================================
   *
   * The UI does not know anything about individual Analyzer
   * products here.
   *
   * Analyze Manager's OUTPUT contract defines the Box shape.
   * The actual values come from the boxes returned by ANALYZE,
   * plus operator-edited values (for example search copy and
   * pingback) that fill those same Box fields.
   *
   * New Analyzers can change ingredients{} without requiring
   * any handoff UI changes.
   */
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
            const box = {};


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
             * PRODUCT CONTRACT BINDINGS.
             *
             * Some Box metadata is also a physical production
             * ingredient. Example: Idea.search_title is both
             * metadata and text baked into the JPEG.
             *
             * This is generic: future products add bindings in
             * PubContract; this UI does not get product-specific
             * code.
             */
            const bindings =
              Array.isArray(
                analyzeOutputContract
                  ?.ingredientBindings
              )
                ? analyzeOutputContract
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
        analyzeOutputContract,
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


  /*
   * Only fields explicitly declared required at the PUB Box
   * boundary should block the handoff.
   *
   * search_title, description, and pingback may legitimately
   * be blank. Product-specific ingredient validation belongs
   * to CREATE / the selected Creator.
   */
  const requiredHandoffFieldNames =
    useMemo(
      () => {
        const sharedBoxFields =
          Array.isArray(
            pubContracts
              ?.shared
              ?.boxFields
          )
            ? pubContracts
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
        pubContracts,
      ]
    );


  const canSendToCreate =
    createHandoffBoxes
      .length >
      0 &&
    requiredHandoffFieldNames
      .length >
      0 &&
    createHandoffBoxes
      .every(
        (box) =>
          requiredHandoffFieldNames
            .every(
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
      createHandoffBoxes[
        0
      ]?.pub_run_id ||
      analysis
        ?.pub_run_id ||
      0
    );




  /*
   * ========================================================
   * FINAL ANALYZE HANDOFF
   * ========================================================
   *
   * CREATE doorbell.
   *
   * Each selected Analyze Box becomes one NEW CREATE order.
   * CreateManager owns all interpretation/routing after that.
   */

async function requestCreate(
  sealedBoxes,
  duplicatePolicy =
    "check"
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

            duplicate_policy:
              duplicatePolicy,
          }),
      }
    );


  const data =
    await response
      .json();


  return {
    response,
    data,
  };
}


function acceptCreateResult(
  data,
  sealedBoxes
) {
  /*
   * CREATE returns PubCom histories on the individual
   * created / failed entries. Feed them through the
   * same page-level receiver.
   */
  receivePubCom(
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


  if (failedCount > 0) {
    createSummaryParts.push(
      `${failedCount} failed`
    );
  }


  const skippedDuplicateCount =
    Number(
      data
        .skipped_duplicate_count ||
      0
    );


  if (
    skippedDuplicateCount >
    0
  ) {
    createSummaryParts.push(
      `${skippedDuplicateCount} previously shipped skipped`
    );
  }


  const createSummary =
    createSummaryParts.length
      ? createSummaryParts.join(
          " · "
        )
      : "No assets accepted by CREATE.";


  setCreateMessage(
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


  if (
    unexpectedCreateFailures.length
  ) {
    setPubStageErrors({
      stage:
        "create",

      code:
        "creator_failures",

      message:
        `${unexpectedCreateFailures.length} asset(s) failed in CREATE.`,

      details:
        unexpectedCreateFailures,
    });
  }


  console.log(
    "CREATE RESULT:",
    data
  );


  setHandoffOpen(
    false
  );


  /*
   * FULL CREATE ACCEPTANCE = ANALYZE WORKBENCH CONSUMED.
   *
   * A deliberately skipped duplicate is also a resolved box: the boss
   * explicitly chose not to manufacture it again.
   */
  const acceptedCount =
    createdCount +
    queuedCount;

  const resolvedCount =
    acceptedCount +
    skippedDuplicateCount;

  const fullCreateSuccess =
    failedCount ===
      0 &&
    resolvedCount >
      0 &&
    resolvedCount ===
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

    setError(
      ""
    );

    clearPubStageErrors();

    setStage(
      "assets"
    );
  }
}


async function submitCreate(
  sealedBoxes,
  duplicatePolicy =
    "check"
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
        duplicatePolicy
      );


    /*
     * EXPECTED CREATE GATE.
     *
     * This is not a CREATE failure. CreateManager has deliberately
     * stopped before reserving assets or waking Creators and is asking
     * the boss how to treat exact previously-shipped matches.
     */
    if (
      response.status ===
        409 &&
      data?.code ===
        "duplicate_warning"
    ) {
      setCreateDuplicateWarning({
        duplicate_count:
          Number(
            data
              ?.duplicate_count ||
            0
          ),

        duplicates:
          Array.isArray(
            data
              ?.duplicates
          )
            ? data
                .duplicates
            : [],

        sealedBoxes,
      });

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


    setCreateDuplicateWarning(
      null
    );


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

  setCreateMessage(
    ""
  );


  /*
   * The exact boxes shown in the handoff drawer are the exact
   * boxes sent to CREATE. No product-specific rebuilding,
   * stamping, or fallback identities happen in the UI.
   */
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


  await submitCreate(
    createHandoffBoxes,
    "check"
  );
}


async function resolveCreateDuplicateWarning(
  duplicatePolicy
) {
  const warning =
    createDuplicateWarning;


  if (!warning) {
    return;
  }


  if (
    duplicatePolicy ===
    "cancel"
  ) {
    setCreateDuplicateWarning(
      null
    );

    return;
  }


  if (
    duplicatePolicy !==
      "skip" &&
    duplicatePolicy !==
      "include"
  ) {
    return;
  }


  /*
   * Close the decision popup before retrying. If CREATE finds another
   * warning for any reason, submitCreate() will open a fresh one.
   */
  setCreateDuplicateWarning(
    null
  );


  await submitCreate(
    Array.isArray(
      warning.sealedBoxes
    )
      ? warning.sealedBoxes
      : [],
    duplicatePolicy
  );
}



  /*
   * ========================================================
   * PAGE
   * ========================================================
   */
  return (
    <>
      <AdminMasterDetail
        storageKey="admin-pub-list-width"

        defaultListWidth={
          280
        }

        minListWidth={
          0
        }

        maxListWidth={
          420
        }

        list={
          <AdminListPane
            title="PUB"
          >
            <AdminObjectList
              ariaLabel="PUB stages"
            >
              <AdminObjectListItem
                id="reference"

                title="REFERENCE"

                selected={
                  stage ===
                  "reference"
                }

                onSelect={() =>
                  setStage(
                    "reference"
                  )
                }
              />


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
                id="dispatch"

                title="Dispatch"

                selected={
                  stage ===
                  "dispatch"
                }

                onSelect={() =>
                  setStage(
                    "dispatch"
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
            "reference" ? (
              <PubPipelineReference
                contracts={
                  pubContracts
                }
              />

            ) : stage ===
            "assets" ? (
              <>
                {createMessage ? (
                  <div
                    style={{
                      marginBottom:
                        12,

                      padding:
                        "8px 10px",

                      border:
                        "1px solid #b8d8c0",

                      background:
                        "#f3faf5",

                      fontSize:
                        13,

                      fontWeight:
                        600,
                    }}
                  >
                    {createMessage}
                  </div>
                ) : null}

                <PubAssetsTable
                  onOpenPackage={() =>
                    setStage(
                      "package"
                    )
                  }
                />
              </>

            ) : stage ===
            "package" ? (
              <PubPackageTable
                onOpenDispatch={() =>
                  setStage(
                    "dispatch"
                  )
                }
              />
   
            ) : stage === "dispatch" ? (
              <PubDispatchTable />


            ) : stage ===
            "analyze" ? (
              <AnalyzeStage
                playlists={
                  playlists
                }

                playlistId={
                  playlistId
                }

                setPlaylistId={
                  setPlaylistId
                }

                outputType={
                  outputType
                }

                outputTypes={
                  analyzeOutputTypes
                }

                sourceColumns={
                  analyzeSourceColumns
                }

                onChangeOutputType={
                  changeOutputType
                }


                createMessage={
                  createMessage
                }

                loadingPlaylists={
                  loadingPlaylists
                }

                analyzing={
                  analyzing
                }

                analyzeOutput={
                  analyzeOutput
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

                pubStageErrors={
                  pubStageErrors
                }

                onCallMark={() =>
                  setMarketingOpen(
                    true
                  )
                }

                onOpenHandoff={() => {
                  clearPubStageErrors();

                  setHandoffOpen(
                    true
                  );
                }}
              />

            ) : (
              <AdminEmptyState
                title={
                  stage
                    .charAt(
                      0
                    )
                    .toUpperCase() +
                  stage.slice(
                    1
                  )
                }

                message="PUB workflow will appear here."
              />
            )}
          </AdminDetailPane>
        }
      />


      {/* PUBCOM TOAST */}
      {pubComToasts.length
        ? createPortal(
            <PubComToast
              disposition={
                pubComToasts[0]
              }
            />,

            document.body
          )
        : null}


      {/* PUBCOM POPUP */}
      {pubComPopups.length
        ? createPortal(
            <PubComPopup
              disposition={
                pubComPopups[0]
              }

              onClose={() =>
                setPubComPopups(
                  (current) =>
                    current.slice(
                      1
                    )
                )
              }
            />,

            document.body
          )
        : null}


      {/* CREATE DUPLICATE DECISION */}
      {createDuplicateWarning
        ? createPortal(
            <CreateDuplicatePopup
              warning={
                createDuplicateWarning
              }

              sending={
                sendingToCreate
              }

              onSkip={() =>
                resolveCreateDuplicateWarning(
                  "skip"
                )
              }

              onInclude={() =>
                resolveCreateDuplicateWarning(
                  "include"
                )
              }

              onCancel={() =>
                resolveCreateDuplicateWarning(
                  "cancel"
                )
              }
            />,

            document.body
          )
        : null}


      {/* MARK */}
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
                    analyzeOutputContract
                      ?.channel ||
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


      {/* ANALYZE HANDOFF */}
      {handoffOpen
        ? createPortal(
            <div
              style={
                handoffDrawerHostStyle
              }
            >
              <AdminWorkbenchDrawer
                open

                width={
                  440
                }

                title="Analyze Handoff"

                onClose={() =>
                  setHandoffOpen(
                    false
                  )
                }
              >
                <div
                  style={{
                    height:
                      "100%",

                    minHeight:
                      0,

                    display:
                      "flex",

                    flexDirection:
                      "column",
                  }}
                >
                  <PubStageErrors
                    errors={
                      pubStageErrors
                    }
                  />


                  <div
                    style={{
                      flex:
                        1,

                      minHeight:
                        0,
                    }}
                  >
                    <AnalyzeHandoffSummary
                      jobId={
                        handoffJobId
                      }

                      boxes={
                        createHandoffBoxes
                      }

                      fieldNames={
                        handoffFieldNames
                      }

                      pingback={
                        analyzeHandoffValues
                          .pingback ||
                        ""
                      }

                      showPingback={
                        handoffFieldNames
                          .includes(
                            "pingback"
                          )
                      }

                      onChangePingback={(
                        value
                      ) =>
                        updateAnalyzeHandoffValue(
                          "pingback",
                          value
                        )
                      }

                      onFetchRex={() =>
                        handleAnalyzeHelper(
                          "rex"
                        )
                      }

                      canSend={
                        canSendToCreate
                      }

                      sending={
                        sendingToCreate
                      }

                      onSend={
                        sendToCreate
                      }
                    />
                  </div>
                </div>
              </AdminWorkbenchDrawer>
            </div>,

            document.body
          )
        : null}
    </>
  );
}


/*
 * ========================================================
 * ANALYZE HANDOFF SUMMARY
 * ========================================================
 *
 * Generic stage-boundary viewer.
 *
 * It receives the already-built Analyze Manager output Boxes.
 * It does not know which Analyzer produced ingredients{}.
 */
function AnalyzeHandoffSummary({
  jobId,
  boxes,
  fieldNames,

  pingback,
  showPingback,

  onChangePingback,
  onFetchRex,

  canSend,
  sending,
  onSend,
}) {
  const boxCount =
    Array.isArray(
      boxes
    )
      ? boxes.length
      : 0;


  return (
    <div
      style={{
        height:
          "100%",

        display:
          "flex",

        flexDirection:
          "column",
      }}
    >
      <div
        style={{
          flex:
            1,

          overflow:
            "auto",

          padding:
            12,
        }}
      >
        <div
          style={{
            marginBottom:
              18,
          }}
        >
          <div
            style={{
              marginBottom:
                7,

              color:
                "#4b6b8a",

              fontSize:
                10,

              fontWeight:
                800,

              letterSpacing:
                "0.08em",
            }}
          >
            HANDING TO CREATE
          </div>

          <div
            style={{
              fontSize:
                18,

              fontWeight:
                700,

              lineHeight:
                1.35,
            }}
          >
            Job #
            {jobId || "—"}
          </div>

          <div
            style={{
              marginTop:
                2,

              color:
                "#586675",

              fontSize:
                14,
            }}
          >
            {boxCount} Box
            {boxCount === 1
              ? ""
              : "es"}
          </div>
        </div>


        {showPingback ? (
          <div
            style={{
              marginBottom:
                20,
            }}
          >
            <label
              className="admin-field"
            >
              <span
                className="admin-field__label"
              >
                Pingback
              </span>

              <div
                style={{
                  display:
                    "flex",

                  gap:
                    8,
                }}
              >
                <input
                  className="admin-field__control"

                  type="text"

                  value={
                    pingback
                  }

                  onChange={(
                    event
                  ) =>
                    onChangePingback(
                      event
                        .target
                        .value
                    )
                  }

                  style={{
                    flex:
                      1,

                    minWidth:
                      0,
                  }}
                />

                <button
                  type="button"

                  onClick={
                    onFetchRex
                  }
                >
                  REX
                </button>
              </div>
            </label>
          </div>
        ) : null}


        <div>
          <div
            style={{
              marginBottom:
                7,

              color:
                "#4b6b8a",

              fontSize:
                10,

              fontWeight:
                800,

              letterSpacing:
                "0.08em",
            }}
          >
            EACH BOX CONTAINS
          </div>

          <div
            style={{
              border:
                "1px solid #d8dde3",
            }}
          >
            {fieldNames.map(
              (fieldName) => (
                <div
                  key={
                    fieldName
                  }

                  style={{
                    padding:
                      "7px 9px",

                    borderBottom:
                      "1px solid #e9edf1",
                  }}
                >
                  <code>
                    {fieldName}
                    {fieldName ===
                    "ingredients"
                      ? " {}"
                      : ""}
                  </code>
                </div>
              )
            )}
          </div>
        </div>
      </div>


      <div
        style={{
          padding:
            12,

          borderTop:
            "1px solid #d8dde3",
        }}
      >
        <button
          type="button"

          disabled={
            !canSend ||
            sending
          }

          onClick={
            onSend
          }

          style={{
            width:
              "100%",

            ...(
              !canSend ||
              sending
                ? {
                    background:
                      "#d8dde3",

                    color:
                      "#6b7280",

                    borderColor:
                      "#c4cbd2",

                    cursor:
                      "not-allowed",
                  }
                : {}
            ),
          }}
        >
          {sending
            ? "Sending..."
            : `Send ${boxCount} Box${
                boxCount === 1
                  ? ""
                  : "es"
              } to CREATE`}
        </button>
      </div>
    </div>
  );
}


/*
 * ========================================================
 * ANALYZE STAGE
 * ========================================================
 */
function AnalyzeStage({
  playlists,
  playlistId,
  setPlaylistId,

  outputType,
  outputTypes,
  sourceColumns,
  onChangeOutputType,

      createMessage,

  loadingPlaylists,



  analyzing,
  analyzeOutput,

  error,
  analysis,

  proposals,

  updateProposal,
  removeProposal,
  addProposal,

  selectedCount,

  pubStageErrors,

  onCallMark,
  onOpenHandoff,
}) {
  return (
    <div
      className="pub-analyze admin-detail-workarea"
    >
      <div
        style={{
          display:
            "flex",

          alignItems:
            "flex-end",

          flexWrap:
            "wrap",

          gap:
            12,

          padding:
            "14px 0",
        }}
      >
        {/*
         * OUTPUT FIRST.
         *
         * No channel control.
         *
         * The output contract determines
         * the final dispatch channel.
         */}
        <label
          className="admin-field"

          style={{
            flex:
              "0 0 220px",
          }}
        >
          <span
            className="admin-field__label"
          >
            Output
          </span>

          <select
            className="admin-field__control"

            value={
              outputType
            }

            onChange={(
              event
            ) =>
              onChangeOutputType(
                event
                  .target
                  .value
              )
            }
          >
            {outputTypes.map(
              (type) => (
                <option
                  key={
                    type.value
                  }

                  value={
                    type.value
                  }
                >
                  {
                    type.label
                  }
                </option>
              )
            )}
          </select>
        </label>


        {/*
         * SOURCE.
         */}
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
            Source Playlist
          </span>

          <select
            className="admin-field__control"

            value={
              playlistId
            }

            onChange={(
              event
            ) =>
              setPlaylistId(
                event
                  .target
                  .value
              )
            }

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

        {playlistId ? (
          <>
            <button
              type="button"

              style={
                secondaryButtonStyle
              }

              onClick={() => {
                window.location.href =
                  `/admin/playlists/${playlistId}`;
              }}
            >
              Edit Playlist
            </button>

            <button
              type="button"

              style={
                secondaryButtonStyle
              }

              onClick={() => {
                window.location.href =
                  "/admin/palette-viewers";
              }}
            >
              Edit PV Copy
            </button>
          </>
        ) : null}


        <button
          type="button"

          onClick={
            analyzeOutput
          }



          disabled={
            analyzing ||
            !playlistId ||
            !outputType
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
          title="Analyze Source"

          message="Choose a source playlist and the output you want PUB to prepare."
        />

      ) : (
        <>
          <div
            style={{
              display:
                "flex",

              alignItems:
                "flex-end",

              justifyContent:
                "space-between",

              flexWrap:
                "wrap",

              gap:
                12,

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
                  ?.source
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
                possible{" "}
                {
                  humanize(
                    outputType
                  )
                }{" "}
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

                gap:
                  8,
              }}
            >
              <button
                type="button"

                style={
                  secondaryButtonStyle
                }

                onClick={
                  addProposal
                }
              >
                Add Row
              </button>


              <button
                type="button"

                style={
                  secondaryButtonStyle
                }

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

                onClick={
                  onOpenHandoff
                }
              >
                Send to CREATE (
                {selectedCount}
                )
              </button>
            </div>
          </div>

{createMessage ? (
  <div
    style={{
      marginBottom: 12,
      padding: "8px 10px",
      border: "1px solid #b8d8c0",
      background: "#f3faf5",
      fontSize: 13,
      fontWeight: 600,
    }}
  >
    {createMessage}
  </div>
) : null}



          <PubStageErrors
            errors={
              pubStageErrors
            }
          />


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
                  1000,

                fontSize:
                  13,
              }}
            >
              <thead>
                <tr>
                  <th style={headerCell}>
                    Use
                  </th>

                  <th style={headerCell}>
                    Order
                  </th>

                  {sourceColumns.map(
                    (
                      column,
                      index
                    ) => (
                      <th
                        key={
                          `${
                            column
                              ?.ingredientPath ||
                            "source"
                          }-${index}`
                        }

                        style={
                          headerCell
                        }
                      >
                        {
                          column
                            ?.label ||
                          "Source"
                        }
                      </th>
                    )
                  )}

                  <th style={headerCell}>
                    Search Title
                  </th>

                  <th style={headerCell}>
                    Description
                  </th>

                  <th style={headerCell}>
                    Actions
                  </th>
                </tr>
              </thead>


              <tbody>
                {proposals.map(
                  (proposal) => (
                    <tr
                      key={
                        proposal
                          .proposal_key
                      }
                    >
                      <td style={bodyCell}>
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


                      <td style={bodyCell}>
                        {
                          proposal
                            .sort_order
                        }
                      </td>


                      {sourceColumns.map(
                        (
                          column,
                          index
                        ) => {
                          const previewItem =
                            proposalIngredientPreviewItem(
                              proposal,
                              column
                                ?.ingredientPath
                            );


                          return (
                            <td
                              key={
                                `${
                                  column
                                    ?.ingredientPath ||
                                  "source"
                                }-${index}`
                              }

                              style={
                                bodyCell
                              }
                            >
                              {previewItem ? (
                                <SourcePreview
                                  item={
                                    previewItem
                                  }
                                />
                              ) : (
                                "—"
                              )}
                            </td>
                          );
                        }
                      )}


                      <td style={bodyCell}>
                        <textarea
                          rows={
                            3
                          }

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


                      <td style={bodyCell}>
                        <textarea
                          rows={
                            5
                          }

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


                      <td style={bodyCell}>
                        <button
                          type="button"

                          style={
                            secondaryButtonStyle
                          }

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
                  )
                )}


                {proposals.length ===
                0 ? (
                  <tr>
                    <td
                      colSpan={
                        5 +
                        sourceColumns.length
                      }

                      style={{
                        padding:
                          24,

                        textAlign:
                          "center",
                      }}
                    >
                      No {humanize(
                        outputType
                      )} proposals found.
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


/*
 * ========================================================
 * NORMALIZE ANALYZE BOX FOR THE WORKBENCH
 * ========================================================
 *
 * Do not reshape Creator ingredients here.
 * The Analyze -> Create Box stays intact.
 */
function normalizeProposal(
  proposal,
  index
) {
  const source =
    proposal.after;


  return {
    ...proposal,

    /*
     * WORKBENCH-ONLY IDENTITY.
     *
     * This is not part of the PUB Box.
     * It exists only so the UI can edit/remove
     * one row independently.
     */
    proposal_key:
      proposal
        .proposal_key ||
      `analyze-row-${index + 1}`,

    sort_order:
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
      source
        ?.title ||
      "",

    description:
      proposal
        .description ||
      "",

    cta_text:
      proposal
        .cta_text ||
      "See More Transformations",
  };
}


/*
 * ========================================================
 * RECIPE-SPECIFIC SOURCE PREVIEW
 * ========================================================
 *
 * The contract supplies an ingredient path such as:
 *
 *   before.file_path
 *   after.file_path
 *   source.file_path
 *
 * The UI reads that exact Creator ingredient and wraps it in
 * the tiny SourcePreview shape. No product names or recipes
 * are hard-coded here.
 */
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


/*
 * ========================================================
 * SOURCE PREVIEW
 * ========================================================
 */
function SourcePreview({
  item,
}) {
  const imageUrl =
    browserImageUrl(
      item
        ?.image_url ||
      item
        ?.file_path
    );


  return (
    <div
      style={{
        display:
          "flex",

        alignItems:
          "flex-start",

        gap:
          8,

        minWidth:
          145,
      }}
    >
      {imageUrl ? (
        <img
          src={
            imageUrl
          }

          alt=""

          style={{
            width:
              64,

            height:
              64,

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
            width:
              64,

            height:
              64,

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


        {item
          ?.title ? (
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
        ) : item
          ?.file_path ? (
          <div
            style={{
              marginTop:
                3,

              maxWidth:
                130,

              overflow:
                "hidden",

              textOverflow:
                "ellipsis",

              whiteSpace:
                "nowrap",

              fontSize:
                11,

              color:
                "#6b7280",
            }}

            title={
              item.file_path
            }
          >
            {sourceFileName(
              item.file_path
            )}
          </div>
        ) : null}
      </div>
    </div>
  );
}


/*
 * ========================================================
 * URL HELPERS
 * ========================================================
 */
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
    raw = raw
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


  /*
   * Composite ingredients intentionally contain the physical
   * server file_path because that is what the Creator needs.
   * For the admin preview only, strip everything through the
   * ColorFix project directory and use the remaining web path.
   *
   * No server account/home path is hard-coded here.
   */
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
    raw = raw.slice(
      markerIndex +
      projectMarker.length
    );
  }


  /*
   * If an absolute server path did not contain the expected
   * project marker, do not hand that filesystem path to <img>.
   */
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
  ] || raw;
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


/*
 * ========================================================
 * PUBCOM FRONTEND HELPERS
 * ========================================================
 *
 * The backend Manager owns the decision.
 *
 * Frontend responsibility:
 *
 *   display = none   -> nothing
 *   display = toast  -> non-blocking notice
 *   display = popup  -> blocking acknowledgement
 */
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


  /*
   * Direct Manager response.
   */
  append(
    payload.pubcom
  );


  /*
   * CREATE batch entries carry their own PubCom histories.
   */
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


  /*
   * Some single-item actions may wrap their result.
   */
  append(
    payload
      ?.result
      ?.pubcom
  );


  /*
   * Analyze may expose the same operational disposition
   * both at the Manager result level and on a failed entry.
   * De-duplicate within this one HTTP response.
   */
  const seen =
    new Set();

  const unique =
    [];


  for (
    const disposition
    of collected
  ) {
    const signal =
      disposition
        ?.signal ||
      {};

    const key =
      JSON.stringify([
        disposition
          ?.action ||
        "",

        disposition
          ?.display ||
        "",

        signal
          ?.type ||
        "",

        signal
          ?.code ||
        "",

        signal
          ?.message ||
        "",

        signal
          ?.context ||
        null,
      ]);


    if (
      seen.has(
        key
      )
    ) {
      continue;
    }


    seen.add(
      key
    );

    unique.push(
      disposition
    );
  }


  return unique;
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


function CreateDuplicatePopup({
  warning,
  sending,
  onSkip,
  onInclude,
  onCancel,
}) {
  const duplicates =
    Array.isArray(
      warning
        ?.duplicates
    )
      ? warning
          .duplicates
      : [];

  const duplicateCount =
    Number(
      warning
        ?.duplicate_count ||
      duplicates.length ||
      0
    );

  const totalBoxes =
    Array.isArray(
      warning
        ?.sealedBoxes
    )
      ? warning
          .sealedBoxes
          .length
      : 0;


  return (
    <div
      role="presentation"
      style={
        pubComPopupOverlayStyle
      }
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="create-duplicate-popup-title"
        style={{
          ...pubComPopupStyle,

          width:
            "min(620px, 100%)",
        }}
      >
        <div
          id="create-duplicate-popup-title"
          style={
            pubComPopupTitleStyle
          }
        >
          Previously Shipped
        </div>


        <div
          style={
            pubComPopupMessageStyle
          }
        >
          PUB found{" "}
          <strong>
            {duplicateCount}
          </strong>{" "}
          of{" "}
          <strong>
            {totalBoxes}
          </strong>{" "}
          production order
          {totalBoxes === 1
            ? ""
            : "s"}{" "}
          that exactly match asset
          {duplicateCount === 1
            ? ""
            : "s"}{" "}
          already shipped.

          <div
            style={{
              marginTop:
                8,

              fontWeight:
                600,

              color:
                "#334155",
            }}
          >
            Nothing has been created yet.
          </div>
        </div>


        {duplicates.length ? (
          <div
            style={{
              maxHeight:
                220,

              overflow:
                "auto",

              marginTop:
                14,

              border:
                "1px solid #d8dde3",

              borderRadius:
                6,
            }}
          >
            {duplicates.map(
              (
                duplicate,
                index
              ) => {
                const shippedIds =
                  Array.isArray(
                    duplicate
                      ?.shipped_matches
                  )
                    ? duplicate
                        .shipped_matches
                        .map(
                          (match) =>
                            Number(
                              match
                                ?.pub_asset_id ||
                              0
                            )
                        )
                        .filter(
                          Boolean
                        )
                    : [];


                return (
                  <div
                    key={
                      `${
                        duplicate
                          ?.order_index ??
                        index
                      }-${
                        duplicate
                          ?.production_signature ||
                        ""
                      }`
                    }

                    style={{
                      padding:
                        "9px 11px",

                      borderBottom:
                        index <
                        duplicates.length - 1
                          ? "1px solid #e9edf1"
                          : "none",

                      fontSize:
                        13,

                      lineHeight:
                        1.4,
                    }}
                  >
                    <div
                      style={{
                        fontWeight:
                          700,
                      }}
                    >
                      {
                        duplicate
                          ?.search_title ||
                        humanize(
                          duplicate
                            ?.asset_type ||
                          "asset"
                        )
                      }
                    </div>

                    <div
                      style={{
                        marginTop:
                          2,

                        color:
                          "#64748b",
                      }}
                    >
                      {humanize(
                        duplicate
                          ?.asset_type ||
                        "asset"
                      )}

                      {shippedIds.length
                        ? ` · already shipped as #${shippedIds.join(
                            ", #"
                          )}`
                        : ""}
                    </div>
                  </div>
                );
              }
            )}
          </div>
        ) : null}


        <div
          style={{
            ...pubComPopupActionsStyle,

            gap:
              8,

            flexWrap:
              "wrap",
          }}
        >
          <button
            type="button"

            style={
              secondaryButtonStyle
            }

            disabled={
              sending
            }

            onClick={
              onCancel
            }
          >
            Cancel
          </button>


          <button
            type="button"

            style={
              secondaryButtonStyle
            }

            disabled={
              sending
            }

            onClick={
              onInclude
            }
          >
            Include Them Again
          </button>


          <button
            type="button"

            autoFocus

            disabled={
              sending
            }

            onClick={
              onSkip
            }
          >
            {sending
              ? "Sending..."
              : "Skip Previously Shipped"}
          </button>
        </div>
      </div>
    </div>
  );
}


function PubComToast({
  disposition,
}) {
  const message =
    disposition
      ?.signal
      ?.message ||
    "PUB notice.";


  return (
    <div
      role="status"
      aria-live="polite"
      style={
        pubComToastStyle
      }
    >
      <div
        style={
          pubComToastLabelStyle
        }
      >
        PUB
      </div>

      <div>
        {message}
      </div>
    </div>
  );
}


function PubComPopup({
  disposition,
  onClose,
}) {
  const signalType =
    String(
      disposition
        ?.signal
        ?.type ||
      ""
    ).toLowerCase();

  const message =
    disposition
      ?.signal
      ?.message ||
    "PUB needs your attention.";

  const title =
    signalType ===
      "ineligible"
      ? "Cannot run this output"
      : signalType ===
        "unavailable"
        ? "Worker unavailable"
        : "PUB notice";


  return (
    <div
      role="presentation"
      style={
        pubComPopupOverlayStyle
      }
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="pubcom-popup-title"
        style={
          pubComPopupStyle
        }
      >
        <div
          id="pubcom-popup-title"
          style={
            pubComPopupTitleStyle
          }
        >
          {title}
        </div>

        <div
          style={
            pubComPopupMessageStyle
          }
        >
          {message}
        </div>

        <div
          style={
            pubComPopupActionsStyle
          }
        >
          <button
            type="button"
            autoFocus
            onClick={
              onClose
            }
          >
            OK
          </button>
        </div>
      </div>
    </div>
  );
}


function isPlainObject(
  value
) {
  return Boolean(
    value
    &&
    typeof value ===
      "object"
    &&
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


/*
 * ========================================================
 * STYLES
 * ========================================================
 */
const secondaryButtonStyle = {
  background:
    "#f7f8fa",

  color:
    "#334155",

  border:
    "1px solid #c7d0d9",

  boxShadow:
    "none",
};


const pubComToastStyle = {
  position:
    "fixed",

  top:
    18,

  right:
    18,

  zIndex:
    2147483646,

  maxWidth:
    420,

  padding:
    "11px 14px",

  border:
    "1px solid #cbd5df",

  borderRadius:
    6,

  background:
    "#ffffff",

  boxShadow:
    "0 8px 24px rgba(0, 0, 0, 0.16)",

  fontSize:
    13,

  lineHeight:
    1.4,
};


const pubComToastLabelStyle = {
  marginBottom:
    3,

  color:
    "#4b6b8a",

  fontSize:
    10,

  fontWeight:
    700,

  letterSpacing:
    "0.08em",

  textTransform:
    "uppercase",
};


const pubComPopupOverlayStyle = {
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
    24,

  background:
    "rgba(0, 0, 0, 0.34)",
};


const pubComPopupStyle = {
  width:
    "min(520px, 100%)",

  padding:
    22,

  borderRadius:
    8,

  background:
    "#ffffff",

  boxShadow:
    "0 18px 48px rgba(0, 0, 0, 0.24)",
};


const pubComPopupTitleStyle = {
  marginBottom:
    8,

  fontSize:
    18,

  fontWeight:
    700,
};


const pubComPopupMessageStyle = {
  color:
    "#4b5563",

  fontSize:
    14,

  lineHeight:
    1.5,
};


const pubComPopupActionsStyle = {
  display:
    "flex",

  justifyContent:
    "flex-end",

  marginTop:
    20,
};


const marketingOverlayStyle = {
  position:
    "fixed",

  inset:
    0,

  zIndex:
    2147483647,

  background:
    "#ffffff",

  overflow:
    "hidden",
};


const handoffDrawerHostStyle = {
  position:
    "fixed",

  top:
    0,

  right:
    0,

  bottom:
    0,

  width:
    440,

  zIndex:
    2147483646,

  display:
    "flex",
};


const headerCell = {
  position:
    "sticky",

  top:
    0,

  zIndex:
    1,

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