import {
  useEffect,
  useState,
} from "react";

import {
  createPortal,
} from "react-dom";

import {
  API_FOLDER,
} from "@helpers/config";

import {
  AdminDetailPane,
  AdminEmptyState,
  AdminListPane,
  AdminMasterDetail,
  AdminObjectList,
  AdminObjectListItem,
} from "@components/AdminLayout";

import PubPipelineReference
  from "./PubPipelineReference";

import PubAssetsTable
  from "./PubAssetsTable";

import PubPackageTable
  from "./PubPackageTable";

import PubScheduleTable
  from "./PubScheduleTable";

import PubDispatchTable
  from "./PubDispatchTable";

import PubAnalyzeStage
  from "./PubAnalyzeStage";


const PUB_CONTRACTS_URL =
  `${API_FOLDER}/v2/admin/pub/contracts.php`;


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
  const [
    pubContracts,
    setPubContracts,
  ] = useState(
    null
  );

  const [
    contractError,
    setContractError,
  ] = useState(
    ""
  );

  const [
    createMessage,
    setCreateMessage,
  ] = useState(
    ""
  );

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

  const [
    stage,
    setStageState,
  ] = useState(
    () =>
      pubStageFromLocation()
  );


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


  useEffect(() => {
    setStageState(
      pubStageFromLocation()
    );
  }, []);


  useEffect(() => {
    let active =
      true;


    async function loadPubContracts() {
      setContractError(
        ""
      );


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


        setContractError(
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


  function handleCreateComplete(
    message
  ) {
    setCreateMessage(
      message ||
      ""
    );

    setStage(
      "assets"
    );
  }


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

            ) : stage ===
            "schedule" ? (
              <PubScheduleTable />

            ) : stage ===
            "dispatch" ? (
              <PubDispatchTable />

            ) : stage ===
            "analyze" ? (
              <PubAnalyzeStage
                contracts={
                  pubContracts
                }
                contractError={
                  contractError
                }
                createMessage={
                  createMessage
                }
                onCreateMessage={
                  setCreateMessage
                }
                onCreateComplete={
                  handleCreateComplete
                }
                receivePubCom={
                  receivePubCom
                }
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
    </>
  );
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
      ? "Output skipped"
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
