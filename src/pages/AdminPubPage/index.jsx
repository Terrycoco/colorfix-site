import {
  useEffect,
  useState,
} from "react";

import {
  API_FOLDER,
} from "@helpers/config";

import {
  AdminDetailPane,
  AdminDialog,
  AdminEmptyState,
  AdminListPane,
  AdminMasterDetail,
  AdminNote,
  AdminNotice,
  AdminObjectList,
  AdminObjectListItem,
  AdminStack,
  AdminToast,
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
    cancelAnalysisToken,
    setCancelAnalysisToken,
  ] = useState(
    0
  );

  const [
    stage,
    setStageState,
  ] = useState(
    () =>
      pubStageFromLocation()
  );


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


  function cancelAnalysis() {
    /*
     * The backend ANALYZE request has already completed by the time
     * PubCom warnings are displayed. Cancel here means:
     *
     *   - stop presenting the remaining warning queue
     *   - clear Analyze-owned notices
     *   - tell PubAnalyzeStage to discard this Analyze result/workbench
     *   - remain on ANALYZE with the selected playlist intact
     */
    setPubComPopups(
      []
    );

    setPubComToasts(
      []
    );

    setCancelAnalysisToken(
      (current) =>
        current + 1
    );
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
              <AdminStack gap="md">
                {createMessage ? (
                  <AdminNotice variant="success">
                    {createMessage}
                  </AdminNotice>
                ) : null}

                <PubAssetsTable
                  onOpenPackage={() =>
                    setStage(
                      "package"
                    )
                  }
                />
              </AdminStack>

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
                cancelAnalysisToken={
                  cancelAnalysisToken
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


      {pubComToasts.length ? (
        <PubComToast
          disposition={pubComToasts[0]}
          onClose={() =>
            setPubComToasts((current) => current.slice(1))
          }
        />
      ) : null}


      {pubComPopups.length ? (
        <PubComPopup
          disposition={pubComPopups[0]}
          onClose={() =>
            setPubComPopups((current) => current.slice(1))
          }
          onCancelAnalysis={
            stage === "analyze"
              ? cancelAnalysis
              : null
          }
        />
      ) : null}
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
  onClose,
}) {
  const message =
    disposition
      ?.signal
      ?.message ||
    "PUB notice.";


  return (
    <AdminToast
      label="PUB"
      message={message}
      duration={6000}
      onClose={onClose}
    />
  );
}


function PubComPopup({
  disposition,
  onClose,
  onCancelAnalysis,
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

  const reason =
    String(
      disposition
        ?.signal
        ?.context
        ?.reason ||
      ""
    ).trim();

  const title =
    signalType ===
      "ineligible"
      ? "Output skipped"
      : signalType ===
        "unavailable"
        ? "Worker unavailable"
        : "PUB notice";


  return (
    <AdminDialog
      open
      title={title}
      message={message}
      dismissOnBackdrop={false}
      onClose={onClose}
      actions={[
        typeof onCancelAnalysis === "function"
          ? {
              key: "cancel-analysis",
              label: "Cancel Analysis",
              variant: "secondary",
              onClick: onCancelAnalysis,
            }
          : null,
        {
          key: "ok",
          label: "OK",
          autoFocus: true,
          onClick: onClose,
        },
      ].filter(Boolean)}
    >
      {reason ? (
        <AdminNote>
          <strong>Reason:</strong>{" "}
          {reason}
        </AdminNote>
      ) : null}
    </AdminDialog>
  );
}
