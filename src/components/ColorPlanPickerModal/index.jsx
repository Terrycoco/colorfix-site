import {
  useEffect,
  useMemo,
  useState,
} from "react";

import {
  AdminDialog,
  AdminEmptyState,
  AdminField,
  AdminNotice,
  AdminStack,
} from "@components/AdminLayout";

import {
  API_FOLDER,
} from "@helpers/config";


const PROJECTS_LIST_URL =
  `${API_FOLDER}/v2/admin/projects/list.php`;

const COLOR_PLAN_LIST_URL =
  `${API_FOLDER}/v2/admin/project-color-plans/list.php`;


let projectsCache =
  null;

const plansByProjectCache =
  new Map();


async function fetchJson(
  url
) {
  const res =
    await fetch(
      url,
      {
        credentials:
          "include",
      }
    );

  const text =
    await res.text();

  if (
    !res.ok
  ) {
    throw new Error(
      `HTTP ${res.status}: ${text.slice(0, 200)}`
    );
  }

  const data =
    JSON.parse(
      text
    );

  if (
    !data?.ok
  ) {
    throw new Error(
      data?.error ||
      "Request failed"
    );
  }

  return data;
}


async function loadProjects() {
  if (
    projectsCache
  ) {
    return projectsCache;
  }

  const data =
    await fetchJson(
      `${PROJECTS_LIST_URL}?_=${Date.now()}`
    );

  projectsCache =
    (
      Array.isArray(
        data.items
      )
        ? data.items
        : []
    )
      .slice()
      .sort(
        (
          a,
          b
        ) =>
          String(
            a?.name ||
            ""
          ).localeCompare(
            String(
              b?.name ||
              ""
            ),
            undefined,
            {
              sensitivity:
                "base",
            }
          )
      );

  return projectsCache;
}


async function loadPlans(
  projectId
) {
  const key =
    String(
      projectId ||
      ""
    );

  if (!key) {
    return [];
  }

  if (
    plansByProjectCache.has(
      key
    )
  ) {
    return plansByProjectCache.get(
      key
    );
  }

  const data =
    await fetchJson(
      `${COLOR_PLAN_LIST_URL}?project_id=${encodeURIComponent(key)}&_=${Date.now()}`
    );

  const plans =
    (
      Array.isArray(
        data.items
      )
        ? data.items
        : []
    )
      .slice()
      .sort(
        (
          a,
          b
        ) =>
          String(
            a?.scheme_title ||
            ""
          ).localeCompare(
            String(
              b?.scheme_title ||
              ""
            ),
            undefined,
            {
              sensitivity:
                "base",
            }
          )
      );

  plansByProjectCache.set(
    key,
    plans
  );

  return plans;
}


async function findCurrentPlan(
  projects,
  currentPlanId
) {
  const targetId =
    String(
      currentPlanId ||
      ""
    ).trim();

  if (!targetId) {
    return null;
  }

  for (
    const project
    of projects
  ) {
    const projectId =
      String(
        project?.id ||
        ""
      );

    if (!projectId) {
      continue;
    }

    const plans =
      await loadPlans(
        projectId
      );

    const plan =
      plans.find(
        (item) =>
          String(
            item?.id ||
            ""
          ) ===
          targetId
      );

    if (
      plan
    ) {
      return {
        project,
        plans,
        plan,
      };
    }
  }

  return null;
}


export default function ColorPlanPickerModal({
  open,
  currentPlanId = null,
  onClose,
  onSave,
}) {
  const [
    projects,
    setProjects,
  ] = useState([]);

  const [
    plans,
    setPlans,
  ] = useState([]);

  const [
    projectId,
    setProjectId,
  ] = useState("");

  const [
    planId,
    setPlanId,
  ] = useState("");

  const [
    loading,
    setLoading,
  ] = useState(false);

  const [
    loadingPlans,
    setLoadingPlans,
  ] = useState(false);

  const [
    error,
    setError,
  ] = useState("");


  useEffect(() => {
    if (
      !open
    ) {
      return;
    }

    let cancelled =
      false;

    setLoading(
      true
    );

    setError(
      ""
    );

    setProjects(
      []
    );

    setPlans(
      []
    );

    setProjectId(
      ""
    );

    setPlanId(
      ""
    );


    (
      async () => {
        try {
          const nextProjects =
            await loadProjects();

          if (
            cancelled
          ) {
            return;
          }

          setProjects(
            nextProjects
          );

          const currentId =
            String(
              currentPlanId ||
              ""
            ).trim();

          if (
            !currentId
          ) {
            return;
          }

          const current =
            await findCurrentPlan(
              nextProjects,
              currentId
            );

          if (
            cancelled
          ) {
            return;
          }

          if (
            !current
          ) {
            setError(
              `Color Plan #${currentId} was not found.`
            );

            return;
          }

          setProjectId(
            String(
              current.project.id
            )
          );

          setPlans(
            current.plans
          );

          setPlanId(
            String(
              current.plan.id
            )
          );

        } catch (err) {
          if (
            !cancelled
          ) {
            setError(
              err?.message ||
              "Failed to load Color Plans"
            );
          }

        } finally {
          if (
            !cancelled
          ) {
            setLoading(
              false
            );
          }
        }
      }
    )();


    return () => {
      cancelled =
        true;
    };
  }, [
    open,
    currentPlanId,
  ]);


  const selectedProject =
    useMemo(
      () =>
        projects.find(
          (item) =>
            String(
              item?.id ||
              ""
            ) ===
            String(
              projectId
            )
        )
        ||
        null,
      [
        projects,
        projectId,
      ]
    );


  const selectedPlan =
    useMemo(
      () =>
        plans.find(
          (item) =>
            String(
              item?.id ||
              ""
            ) ===
            String(
              planId
            )
        )
        ||
        null,
      [
        plans,
        planId,
      ]
    );


  async function handleProjectChange(
    event
  ) {
    const nextProjectId =
      event.target.value;

    setProjectId(
      nextProjectId
    );

    setPlanId(
      ""
    );

    setPlans(
      []
    );

    setError(
      ""
    );

    if (
      !nextProjectId
    ) {
      return;
    }

    setLoadingPlans(
      true
    );

    try {
      setPlans(
        await loadPlans(
          nextProjectId
        )
      );

    } catch (err) {
      setError(
        err?.message ||
        "Failed to load Color Plans"
      );

    } finally {
      setLoadingPlans(
        false
      );
    }
  }


  function handleSave() {
    if (
      !selectedPlan
      ||
      !selectedProject
    ) {
      return;
    }

    onSave?.({
      projectId:
        Number(
          selectedProject.id
        ),

      colorPlanId:
        Number(
          selectedPlan.id
        ),

      project:
        selectedProject,

      colorPlan:
        selectedPlan,
    });
  }


  return (
    <AdminDialog
      open={
        open
      }

      title="Select Color Plan"

      width={
        520
      }

      dismissOnBackdrop={
        !loading
        &&
        !loadingPlans
      }

      onClose={
        onClose
      }

      actions={[
        {
          key:
            "cancel",

          label:
            "Cancel",

          variant:
            "secondary",

          disabled:
            loading
            ||
            loadingPlans,

          onClick:
            onClose,
        },

        {
          key:
            "save",

          label:
            "Save",

          disabled:
            !selectedPlan
            ||
            loading
            ||
            loadingPlans,

          onClick:
            handleSave,
        },
      ]}
    >
      <AdminStack gap="md">
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
          loading
            ? (
                <AdminEmptyState
                  title="Loading"
                  message="Loading projects and color plans..."
                />
              )
            : (
                <>
                  <AdminField
                    label="Project"
                  >
                    <select
                      className="admin-field__control admin-field__control--full"
                      value={
                        projectId
                      }
                      onChange={
                        handleProjectChange
                      }
                    >
                      <option value="">
                        Select project
                      </option>

                      {
                        projects.map(
                          (project) => (
                            <option
                              key={
                                project.id
                              }
                              value={
                                project.id
                              }
                            >
                              {
                                project.name
                                ||
                                `Project #${project.id}`
                              }
                            </option>
                          )
                        )
                      }
                    </select>
                  </AdminField>


                  <AdminField
                    label="Color Plan"
                  >
                    <select
                      className="admin-field__control admin-field__control--full"
                      value={
                        planId
                      }
                      onChange={(
                        event
                      ) =>
                        setPlanId(
                          event.target.value
                        )
                      }
                      disabled={
                        !projectId
                        ||
                        loadingPlans
                      }
                    >
                      <option value="">
                        {
                          loadingPlans
                            ? "Loading Color Plans..."
                            : "Select color plan"
                        }
                      </option>

                      {
                        plans.map(
                          (plan) => (
                            <option
                              key={
                                plan.id
                              }
                              value={
                                plan.id
                              }
                            >
                              {
                                plan.scheme_title
                                ||
                                `Color Plan #${plan.id}`
                              }
                            </option>
                          )
                        )
                      }
                    </select>
                  </AdminField>
                </>
              )
        }
      </AdminStack>
    </AdminDialog>
  );
}
