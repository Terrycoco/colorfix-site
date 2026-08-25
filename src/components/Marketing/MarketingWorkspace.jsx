import {
  useEffect,
  useMemo,
  useState,
} from "react";

import { API_FOLDER } from "@helpers/config";

import {
  AdminWorkbench,
  AdminWorkbenchPane,
  AdminWorkbenchTabs,
  AdminWorkbenchListRow,
  AdminWorkbenchAddButton,
} from "@components/AdminLayout";

const MARKETING_STATE_KEY =
  "marketing-workbench-state";

const LIBRARY_URL =
  `${API_FOLDER}/v2/admin/marketing/library.php`;

const CREATE_GROUP_URL =
  `${API_FOLDER}/v2/admin/marketing/groups/create.php`;

const UPDATE_GROUP_URL =
  `${API_FOLDER}/v2/admin/marketing/groups/update.php`;

const DELETE_GROUP_URL =
  `${API_FOLDER}/v2/admin/marketing/groups/delete.php`;

const CREATE_TERM_URL =
  `${API_FOLDER}/v2/admin/marketing/terms/create.php`;

const UPDATE_TERM_URL =
  `${API_FOLDER}/v2/admin/marketing/terms/update.php`;

const DELETE_TERM_URL =
  `${API_FOLDER}/v2/admin/marketing/terms/delete.php`;

const CREATE_TEMPLATE_URL =
  `${API_FOLDER}/v2/admin/marketing/templates/create.php`;

const UPDATE_TEMPLATE_URL =
  `${API_FOLDER}/v2/admin/marketing/templates/update.php`;

const DELETE_TEMPLATE_URL =
  `${API_FOLDER}/v2/admin/marketing/templates/delete.php`;

const SUGGEST_URL =
  `${API_FOLDER}/v2/admin/marketing/suggest.php`;

const DEFAULT_TEMPLATE_TABS = [
  {
    key: "search_title",
    label: "Search Titles",
  },
  {
    key: "description",
    label: "Descriptions",
  },
];

export default function MarketingWorkspace({
  mode = "standalone",

  title = "Marketing",

  request = null,

  tags = [],

  onReturn,
  onClose,
}) {
  const isCallMode =
    mode === "call";

  /*
   * Support both:
   *
   * request.deliverables = [...]
   *
   * AND the older single-deliverable request shape.
   */
  const requestedDeliverables =
    useMemo(() => {
      if (
        Array.isArray(
          request?.deliverables
        ) &&
        request.deliverables.length
      ) {
        return request.deliverables.map(
          (item) => ({
            key:
              item.key ||
              "search_title",

            label:
              item.label ||
              humanizeKey(
                item.key ||
                "search_title"
              ),

            count:
              Math.max(
                1,
                Number(
                  item.count || 1
                )
              ),

            allowDuplicates:
              item.allowDuplicates ===
              true,
          })
        );
      }

      if (!isCallMode) {
        return [];
      }

      const key =
        request?.deliverableKey ||
        "search_title";

      return [
        {
          key,

          label:
            humanizeKey(key),

          count:
            Math.max(
              1,
              Number(
                request?.count || 1
              )
            ),

          allowDuplicates:
            request
              ?.allowDuplicates ===
            true,
        },
      ];
    }, [
      request,
      isCallMode,
    ]);

  const requestTags =
    Array.isArray(request?.tags)
      ? request.tags
      : tags;

  const firstRequestedKey =
    requestedDeliverables[0]
      ?.key ||
    "search_title";

  const [groups, setGroups] =
    useState([]);

  const [templates, setTemplates] =
    useState([]);

  /*
   * Highlighted row =
   * navigation / editor target.
   */
  const [
    selectedGroupId,
    setSelectedGroupId,
  ] = useState(null);

  const [
    selectedTermId,
    setSelectedTermId,
  ] = useState(null);

  const [
    selectedTemplateId,
    setSelectedTemplateId,
  ] = useState(null);

  /*
   * Checked =
   * included in Mark's current brief.
   */
  const [
    checkedGroupIds,
    setCheckedGroupIds,
  ] = useState(new Set());

  const [
    checkedTermIds,
    setCheckedTermIds,
  ] = useState(new Set());

  const [
    checkedTemplateIds,
    setCheckedTemplateIds,
  ] = useState(new Set());

  const [
    activeTemplateTab,
    setActiveTemplateTab,
  ] = useState(
    firstRequestedKey
  );

  /*
   * DRAWER
   *
   * standalone:
   *   Sandbox only
   *
   * call:
   *   Sandbox | Order
   */
  const [
    drawerTab,
    setDrawerTab,
  ] = useState(
    isCallMode
      ? "order"
      : "sandbox"
  );

  /*
   * SANDBOX
   *
   * type:
   *   group
   *   term
   *   template
   *
   * mode:
   *   new
   *   edit
   */
  const [sandbox, setSandbox] =
    useState(null);

  const [
    previewResults,
    setPreviewResults,
  ] = useState([]);

  const [
    previewing,
    setPreviewing,
  ] = useState(false);

  /*
   * ORDER
   *
   * Each requested deliverable keeps
   * its own independent draft.
   */
  const [
    activeOrderKey,
    setActiveOrderKey,
  ] = useState(
    firstRequestedKey
  );

  const [
    orderResults,
    setOrderResults,
  ] = useState({});

  const [
    orderAccepted,
    setOrderAccepted,
  ] = useState({});

  const [
    generatingOrder,
    setGeneratingOrder,
  ] = useState(false);

  const [loading, setLoading] =
    useState(false);

  const [saving, setSaving] =
    useState(false);

  const [error, setError] =
    useState("");

  /*
   * Restore Mark's working selections.
   */
  useEffect(() => {
    try {
      const raw =
        localStorage.getItem(
          MARKETING_STATE_KEY
        );

      if (raw) {
        const saved =
          JSON.parse(raw);

        setCheckedGroupIds(
          new Set(
            saved.checkedGroupIds ||
            []
          )
        );

        setCheckedTermIds(
          new Set(
            saved.checkedTermIds ||
            []
          )
        );

        setCheckedTemplateIds(
          new Set(
            saved.checkedTemplateIds ||
            []
          )
        );

        if (
          saved.selectedGroupId
        ) {
          setSelectedGroupId(
            saved.selectedGroupId
          );
        }

        if (
          saved.activeTemplateTab
        ) {
          setActiveTemplateTab(
            saved.activeTemplateTab
          );
        }
      }
    } catch {
      // Ignore bad local UI state.
    }

    loadLibrary();
  }, []);

  /*
   * Call mode starts on Order.
   */
  useEffect(() => {
    if (!isCallMode) {
      return;
    }

    setDrawerTab("order");

    setActiveOrderKey(
      firstRequestedKey
    );

    setActiveTemplateTab(
      firstRequestedKey
    );
  }, [
    isCallMode,
    firstRequestedKey,
  ]);

  /*
   * Persist Mark's checked vocabulary.
   */
  useEffect(() => {
    const state = {
      checkedGroupIds:
        [...checkedGroupIds],

      checkedTermIds:
        [...checkedTermIds],

      checkedTemplateIds:
        [...checkedTemplateIds],

      selectedGroupId,

      activeTemplateTab,
    };

    localStorage.setItem(
      MARKETING_STATE_KEY,
      JSON.stringify(state)
    );
  }, [
    checkedGroupIds,
    checkedTermIds,
    checkedTemplateIds,
    selectedGroupId,
    activeTemplateTab,
  ]);

  async function loadLibrary() {
    setLoading(true);
    setError("");

    try {
      const res = await fetch(
        `${LIBRARY_URL}?_=${Date.now()}`,
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
          "Failed to load Marketing library"
        );
      }

      const nextGroups =
        Array.isArray(
          data.groups
        )
          ? data.groups
          : [];

      const nextTemplates =
        Array.isArray(
          data.templates
        )
          ? data.templates
          : [];

      setGroups(nextGroups);
      setTemplates(
        nextTemplates
      );

      setSelectedGroupId(
        (current) => {
          if (
            current &&
            nextGroups.some(
              (group) =>
                group
                  .marketing_term_group_id ===
                current
            )
          ) {
            return current;
          }

          return (
            nextGroups[0]
              ?.marketing_term_group_id ??
            null
          );
        }
      );

    } catch (err) {
      setError(
        err?.message ||
        "Failed to load Marketing library"
      );
    } finally {
      setLoading(false);
    }
  }

  const selectedGroup =
    groups.find(
      (group) =>
        group.marketing_term_group_id ===
        selectedGroupId
    ) || null;

  const selectedTerm =
    (selectedGroup
      ?.terms || []
    ).find(
      (term) =>
        term.marketing_term_id ===
        selectedTermId
    ) || null;

  const templateTabs =
    useMemo(() => {
      const byKey =
        new Map(
          DEFAULT_TEMPLATE_TABS.map(
            (tab) => [
              tab.key,
              tab,
            ]
          )
        );

      for (
        const template
        of templates
      ) {
        const key =
          template
            .deliverable_key;

        if (
          !key ||
          byKey.has(key)
        ) {
          continue;
        }

        byKey.set(
          key,
          {
            key,
            label:
              humanizeKey(
                key
              ),
          }
        );
      }

      return Array.from(
        byKey.values()
      );
    }, [templates]);

  const visibleTemplates =
    templates
      .filter(
        (template) =>
          template
            .deliverable_key ===
          activeTemplateTab
      )
      .sort(
        (a, b) =>
          String(
            a.template || ""
          ).localeCompare(
            String(
              b.template || ""
            ),
            undefined,
            {
              sensitivity:
                "base",
            }
          )
      );

  /*
   * Mark gets only terms from:
   *
   * checked group
   * +
   * checked term
   */
  const checkedTermsByGroup =
    useMemo(() => {
      const result = {};

      for (
        const group
        of groups
      ) {
        if (
          !checkedGroupIds.has(
            group
              .marketing_term_group_id
          )
        ) {
          continue;
        }

        const terms =
          (
            group.terms || []
          ).filter(
            (term) =>
              checkedTermIds.has(
                term
                  .marketing_term_id
              )
          );

        if (terms.length) {
          result[
            group.group_token
          ] = terms;
        }
      }

      return result;
    }, [
      groups,
      checkedGroupIds,
      checkedTermIds,
    ]);

  const activeOrder =
    requestedDeliverables.find(
      (item) =>
        item.key ===
        activeOrderKey
    ) ||
    requestedDeliverables[0] ||
    null;

  const activeOrderResults =
    orderResults[
      activeOrderKey
    ] || [];

  const allOrdersAccepted =
    requestedDeliverables.length >
      0 &&
    requestedDeliverables.every(
      (item) =>
        orderAccepted[
          item.key
        ] === true
    );

  function toggleSetValue(
    setter,
    id,
    checked
  ) {
    setter((current) => {
      const next =
        new Set(current);

      if (checked) {
        next.add(id);
      } else {
        next.delete(id);
      }

      return next;
    });
  }

  function selectGroup(
    group
  ) {
    setSelectedGroupId(
      group
        .marketing_term_group_id
    );

    setSelectedTermId(
      null
    );
  }

  /*
   * SANDBOX OPENERS
   *
   * Any editor action switches
   * the drawer to Sandbox.
   */

  function openNewGroup() {
    setPreviewResults([]);
    setDrawerTab("sandbox");

    setSandbox({
      type: "group",
      mode: "new",
      label: "",
    });
  }

  function openGroupEditor(
    group
  ) {
    setPreviewResults([]);
    setDrawerTab("sandbox");

    setSandbox({
      type: "group",
      mode: "edit",

      marketing_term_group_id:
        group
          .marketing_term_group_id,

      group_token:
        group.group_token,

      label:
        group.label || "",
    });
  }

  function openNewTerm() {
    if (!selectedGroup) {
      return;
    }

    setPreviewResults([]);
    setDrawerTab("sandbox");

    setSandbox({
      type: "term",
      mode: "new",

      marketing_term_group_id:
        selectedGroup
          .marketing_term_group_id,

      singular: "",
      plural: "",
    });
  }

  function openTermEditor(
    term
  ) {
    setPreviewResults([]);
    setDrawerTab("sandbox");

    setSandbox({
      type: "term",
      mode: "edit",

      marketing_term_id:
        term
          .marketing_term_id,

      marketing_term_group_id:
        selectedGroup
          ?.marketing_term_group_id,

      singular:
        term.singular_term ||
        term.term ||
        "",

      plural:
        term.plural_term ||
        term.singular_term ||
        term.term ||
        "",
    });
  }

  function openNewTemplate() {
    setPreviewResults([]);
    setDrawerTab("sandbox");

    setSandbox({
      type: "template",
      mode: "new",

      deliverable_key:
        activeTemplateTab,

      template: "",

      tags:
        requestTags.join(
          ", "
        ),
    });
  }

  function openTemplateEditor(
    template
  ) {
    setSelectedTemplateId(
      template
        .marketing_template_id
    );

    setActiveTemplateTab(
      template
        .deliverable_key
    );

    setPreviewResults([]);
    setDrawerTab("sandbox");

    setSandbox({
      type: "template",
      mode: "edit",

      marketing_template_id:
        template
          .marketing_template_id,

      deliverable_key:
        template
          .deliverable_key,

      template:
        template.template ||
        "",

      tags:
        (
          template.tags || []
        ).join(", "),
    });
  }

  function closeSandbox() {
    setSandbox(null);
    setPreviewResults([]);

    if (isCallMode) {
      setDrawerTab(
        "order"
      );
    }
  }

  function updateSandbox(
    changes
  ) {
    setSandbox(
      (current) =>
        current
          ? {
              ...current,
              ...changes,
            }
          : current
    );
  }

  /*
   * API
   */

  async function postJson(
    url,
    payload
  ) {
    const res =
      await fetch(
        url,
        {
          method: "POST",

          credentials:
            "include",

          headers: {
            "Content-Type":
              "application/json",
          },

          body:
            JSON.stringify(
              payload
            ),
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
        "Marketing update failed"
      );
    }

    return data;
  }

  /*
   * SAVE SANDBOX
   */

  async function saveSandbox() {
    if (!sandbox) {
      return;
    }

    setSaving(true);
    setError("");

    try {
      if (
        sandbox.type ===
        "group"
      ) {
        await saveGroupSandbox();
      }

      if (
        sandbox.type ===
        "term"
      ) {
        await saveTermSandbox();
      }

      if (
        sandbox.type ===
        "template"
      ) {
        await saveTemplateSandbox(
          sandbox.mode ===
            "new"
            ? "new"
            : "update"
        );
      }

      await loadLibrary();
      closeSandbox();

    } catch (err) {
      setError(
        err?.message ||
        "Marketing save failed"
      );
    } finally {
      setSaving(false);
    }
  }

  async function saveGroupSandbox() {
    const label =
      String(
        sandbox.label || ""
      ).trim();

    if (!label) {
      throw new Error(
        "Group name required."
      );
    }

    if (
      sandbox.mode ===
      "edit"
    ) {
      await postJson(
        UPDATE_GROUP_URL,
        {
          marketing_term_group_id:
            sandbox
              .marketing_term_group_id,

          label,
        }
      );

      return;
    }

    const usedTokens =
      new Set(
        groups.map(
          (group) =>
            group.group_token
        )
      );

    let number = 1;

    while (
      usedTokens.has(
        `group${number}`
      )
    ) {
      number += 1;
    }

    await postJson(
      CREATE_GROUP_URL,
      {
        group_token:
          `group${number}`,

        label,

        sort_order:
          groups.length + 1,
      }
    );
  }

  async function saveTermSandbox() {
    const singular =
      String(
        sandbox.singular ||
        ""
      ).trim();

    const plural =
      String(
        sandbox.plural ||
        ""
      ).trim();

    if (!singular) {
      throw new Error(
        "Singular term required."
      );
    }

    if (!plural) {
      throw new Error(
        "Plural term required."
      );
    }

    if (
      sandbox.mode ===
      "edit"
    ) {
      await postJson(
        UPDATE_TERM_URL,
        {
          marketing_term_id:
            sandbox
              .marketing_term_id,

          term:
            singular,

          singular_term:
            singular,

          plural_term:
            plural,
        }
      );

      return;
    }

    await postJson(
      CREATE_TERM_URL,
      {
        marketing_term_group_id:
          sandbox
            .marketing_term_group_id,

        term:
          singular,

        singular_term:
          singular,

        plural_term:
          plural,

        sort_order:
          (
            selectedGroup
              ?.terms?.length ||
            0
          ) + 1,
      }
    );
  }

  async function saveTemplateSandbox(
    behavior
  ) {
    const template =
      String(
        sandbox.template ||
        ""
      ).trim();

    if (!template) {
      throw new Error(
        "Template required."
      );
    }

    const templateTags =
      String(
        sandbox.tags || ""
      )
        .split(",")
        .map(
          (tag) =>
            tag.trim()
        )
        .filter(Boolean);

    if (
      behavior ===
        "update" &&
      sandbox
        .marketing_template_id
    ) {
      await postJson(
        UPDATE_TEMPLATE_URL,
        {
          marketing_template_id:
            sandbox
              .marketing_template_id,

          deliverable_key:
            sandbox
              .deliverable_key,

          template,

          tags:
            templateTags,
        }
      );

      return;
    }

    await postJson(
      CREATE_TEMPLATE_URL,
      {
        deliverable_key:
          sandbox
            .deliverable_key,

        template,

        tags:
          templateTags,

        sort_order:
          templates.length + 1,
      }
    );
  }

  async function saveTemplateAsNew() {
    if (
      !sandbox ||
      sandbox.type !==
        "template"
    ) {
      return;
    }

    setSaving(true);
    setError("");

    try {
      await saveTemplateSandbox(
        "new"
      );

      await loadLibrary();
      closeSandbox();

    } catch (err) {
      setError(
        err?.message ||
        "Template save failed"
      );
    } finally {
      setSaving(false);
    }
  }

  /*
   * DELETE — SANDBOX ONLY
   */

  async function deleteSandboxItem() {
    if (
      !sandbox ||
      sandbox.mode !==
        "edit"
    ) {
      return;
    }

    let message = "";
    let url = "";
    let payload = {};

    if (
      sandbox.type ===
      "group"
    ) {
      const group =
        groups.find(
          (item) =>
            item
              .marketing_term_group_id ===
            sandbox
              .marketing_term_group_id
        );

      const termCount =
        group?.terms
          ?.length || 0;

      message =
        `Delete "${sandbox.label}" and all ${termCount} term`
        + `${termCount === 1 ? "" : "s"}? `
        + "This cannot be undone.";

      url =
        DELETE_GROUP_URL;

      payload = {
        marketing_term_group_id:
          sandbox
            .marketing_term_group_id,
      };
    }

    if (
      sandbox.type ===
      "term"
    ) {
      message =
        `Delete "${sandbox.singular}"? `
        + "This cannot be undone.";

      url =
        DELETE_TERM_URL;

      payload = {
        marketing_term_id:
          sandbox
            .marketing_term_id,
      };
    }

    if (
      sandbox.type ===
      "template"
    ) {
      message =
        "Delete this template? "
        + "Suggestions already accepted elsewhere are unaffected.";

      url =
        DELETE_TEMPLATE_URL;

      payload = {
        marketing_template_id:
          sandbox
            .marketing_template_id,
      };
    }

    if (
      !window.confirm(message)
    ) {
      return;
    }

    setSaving(true);
    setError("");

    try {
      await postJson(
        url,
        payload
      );

      await loadLibrary();
      closeSandbox();

    } catch (err) {
      setError(
        err?.message ||
        "Marketing delete failed"
      );
    } finally {
      setSaving(false);
    }
  }

  /*
   * SANDBOX TEMPLATE PREVIEW
   */

  async function previewTemplate() {
    if (
      !sandbox ||
      sandbox.type !==
        "template"
    ) {
      return;
    }

    const template =
      String(
        sandbox.template ||
        ""
      ).trim();

    if (!template) {
      setError(
        "Template required."
      );
      return;
    }

    if (
      !Object.keys(
        checkedTermsByGroup
      ).length
    ) {
      setError(
        "Check the groups and terms Mark should use."
      );

      return;
    }

    setPreviewing(true);
    setError("");

    try {
      const data =
        await postJson(
          SUGGEST_URL,
          {
            count: 10000,

            allow_duplicates:
              false,

            selected_terms:
              checkedTermsByGroup,

            templates: [
              {
                template,
              },
            ],
          }
        );

      setPreviewResults(
        Array.isArray(
          data?.result
            ?.suggestions
        )
          ? data.result
              .suggestions
          : []
      );

    } catch (err) {
      setError(
        err?.message ||
        "Template preview failed"
      );
    } finally {
      setPreviewing(false);
    }
  }

  /*
   * ORDER
   */

  function selectOrder(
    key
  ) {
    setActiveOrderKey(key);

    /*
     * Also show the matching template tab
     * in Mark's main office.
     */
    setActiveTemplateTab(
      key
    );
  }

  async function generateOrder() {
    if (
      !isCallMode ||
      !activeOrder
    ) {
      return;
    }

    if (
      !Object.keys(
        checkedTermsByGroup
      ).length
    ) {
      setError(
        "Check the groups and terms Mark should use."
      );

      return;
    }

    const selectedTemplates =
      templates.filter(
        (template) =>
          template
            .deliverable_key ===
            activeOrder.key &&
          checkedTemplateIds.has(
            template
              .marketing_template_id
          )
      );

    if (
      !selectedTemplates.length
    ) {
      setError(
        `Check at least one ${activeOrder.label} template.`
      );

      return;
    }

    setGeneratingOrder(true);
    setError("");

    try {
      const data =
        await postJson(
          SUGGEST_URL,
          {
            count:
              activeOrder.count,

            allow_duplicates:
              activeOrder
                .allowDuplicates,

            selected_terms:
              checkedTermsByGroup,

            templates:
              selectedTemplates,
          }
        );

      const suggestions =
        Array.isArray(
          data?.result
            ?.suggestions
        )
          ? data.result
              .suggestions
          : [];

      setOrderResults(
        (current) => ({
          ...current,

          [activeOrder.key]:
            suggestions,
        })
      );

      /*
       * New generation requires review again.
       */
      setOrderAccepted(
        (current) => ({
          ...current,

          [activeOrder.key]:
            false,
        })
      );

    } catch (err) {
      setError(
        err?.message ||
        "Mark could not complete the order."
      );
    } finally {
      setGeneratingOrder(false);
    }
  }

  function updateOrderResult(
    key,
    index,
    value
  ) {
    setOrderResults(
      (current) => {
        const next =
          [
            ...(
              current[key] ||
              []
            ),
          ];

        next[index] =
          value;

        return {
          ...current,
          [key]: next,
        };
      }
    );

    /*
     * Editing means it needs approval again.
     */
    setOrderAccepted(
      (current) => ({
        ...current,
        [key]: false,
      })
    );
  }

  function acceptActiveOrder() {
    if (!activeOrder) {
      return;
    }

    const results =
      orderResults[
        activeOrder.key
      ] || [];

    if (!results.length) {
      setError(
        "Generate this order first."
      );

      return;
    }

    setError("");

    setOrderAccepted(
      (current) => ({
        ...current,

        [activeOrder.key]:
          true,
      })
    );
  }

  function rejectActiveOrder() {
    if (!activeOrder) {
      return;
    }

    setOrderResults(
      (current) => ({
        ...current,

        [activeOrder.key]:
          [],
      })
    );

    setOrderAccepted(
      (current) => ({
        ...current,

        [activeOrder.key]:
          false,
      })
    );
  }

function acceptAndReturn() {
  if (!isCallMode) {
    return;
  }

  if (!activeOrder) {
    return;
  }

  const currentResults =
    orderResults[
      activeOrder.key
    ] || [];

  if (!currentResults.length) {
    setError(
      "Generate this order first."
    );

    return;
  }

  const nextAccepted = {
    ...orderAccepted,
    [activeOrder.key]: true,
  };

  const allAccepted =
    requestedDeliverables.every(
      (item) =>
        nextAccepted[
          item.key
        ] === true
    );

  if (!allAccepted) {
    setOrderAccepted(
      nextAccepted
    );

    setError(
      "Current deliverable accepted. Complete the remaining deliverables before returning."
    );

    return;
  }

  const result = {};

  for (
    const item
    of requestedDeliverables
  ) {
    result[item.key] =
      orderResults[
        item.key
      ] || [];
  }

  onReturn?.(result);
}

  /*
   * HEADER
   */

  const header = (
    <div
      className="admin-detail-header"
      style={{
        display: "flex",
        alignItems: "center",
        justifyContent:
          "space-between",
        gap: 12,
      }}
    >
      <div
        style={{
          minWidth: 0,
        }}
      >
        <strong>
          {title}
        </strong>

        {isCallMode ? (
          <span
            style={{
              marginLeft: 10,
              fontSize: 12,
              color: "#6b7280",
            }}
          >
            Order from caller
          </span>
        ) : null}

        {error ? (
          <span
            style={{
              marginLeft: 16,
              color: "#9b1c1c",
              fontSize: 12,
              fontWeight: 600,
            }}
          >
            {error}
          </span>
        ) : null}
      </div>

      {onClose ? (
        <button
          type="button"
          onClick={onClose}
        >
          Close
        </button>
      ) : null}
    </div>
  );

  /*
   * GROUPS
   */

  const groupsPane = (
    <AdminWorkbenchPane
      title="Groups"
      action={
        <AdminWorkbenchAddButton
          title="Add group"
          onClick={
            openNewGroup
          }
        />
      }
    >
      {[...groups]
        .sort(
          (a, b) =>
            String(
              a.label ||
              a.group_token
            ).localeCompare(
              String(
                b.label ||
                b.group_token
              ),
              undefined,
              {
                sensitivity:
                  "base",
              }
            )
        )
        .map(
          (group) => (
            <AdminWorkbenchListRow
              key={
                group
                  .marketing_term_group_id
              }
              selected={
                group
                  .marketing_term_group_id ===
                selectedGroupId
              }
              checkable
              checked={
                checkedGroupIds.has(
                  group
                    .marketing_term_group_id
                )
              }
              onCheck={(
                checked
              ) =>
                toggleSetValue(
                  setCheckedGroupIds,

                  group
                    .marketing_term_group_id,

                  checked
                )
              }
              onSelect={() =>
                selectGroup(
                  group
                )
              }
              onDoubleClick={() =>
                openGroupEditor(
                  group
                )
              }
            >
              <div
                style={{
                  display: "flex",
                  alignItems: "center",
                  justifyContent:
                    "space-between",
                  gap: 8,
                }}
              >
                <span>
                  {group.label ||
                    group.group_token}
                </span>

                <span
                  style={{
                    color:
                      "#7a838c",

                    fontSize:
                      10,
                  }}
                >
                  {
                    group
                      .group_token
                  }
                </span>
              </div>
            </AdminWorkbenchListRow>
          )
        )}

      {!groups.length ? (
        <div
          className="admin-empty-state"
        >
          No groups
        </div>
      ) : null}
    </AdminWorkbenchPane>
  );

  /*
   * TERMS
   */

  const termsPane = (
    <AdminWorkbenchPane
      title="Terms"
      action={
        <AdminWorkbenchAddButton
          title="Add term"
          disabled={
            !selectedGroup
          }
          onClick={
            openNewTerm
          }
        />
      }
    >
      {!selectedGroup ? (
        <div
          className="admin-empty-state"
        >
          Select a group
        </div>
      ) : (
        <>
          {[
            ...(
              selectedGroup
                .terms ||
              []
            ),
          ]
            .sort(
              (a, b) =>
                String(
                  a.singular_term ||
                  a.term
                ).localeCompare(
                  String(
                    b.singular_term ||
                    b.term
                  ),
                  undefined,
                  {
                    sensitivity:
                      "base",
                  }
                )
            )
            .map(
              (term) => (
                <AdminWorkbenchListRow
                  key={
                    term
                      .marketing_term_id
                  }
                  selected={
                    term
                      .marketing_term_id ===
                    selectedTermId
                  }
                  checkable
                  checked={
                    checkedTermIds.has(
                      term
                        .marketing_term_id
                    )
                  }
                  onCheck={(
                    checked
                  ) =>
                    toggleSetValue(
                      setCheckedTermIds,

                      term
                        .marketing_term_id,

                      checked
                    )
                  }
                  onSelect={() =>
                    setSelectedTermId(
                      term
                        .marketing_term_id
                    )
                  }
                  onDoubleClick={() =>
                    openTermEditor(
                      term
                    )
                  }
                >
                  {term.singular_term ||
                    term.term}
                </AdminWorkbenchListRow>
              )
            )}

          {!selectedGroup
            .terms?.length ? (
            <div
              className="admin-empty-state"
            >
              No terms
            </div>
          ) : null}
        </>
      )}
    </AdminWorkbenchPane>
  );

  /*
   * TEMPLATES
   */

  const templatesPane = (
    <AdminWorkbenchTabs
      tabs={
        templateTabs
      }
      activeKey={
        activeTemplateTab
      }
      onChange={(key) => {
        setActiveTemplateTab(
          key
        );

        setSelectedTemplateId(
          null
        );
      }}
      action={
        <AdminWorkbenchAddButton
          title="Add template"
          onClick={
            openNewTemplate
          }
        />
      }
    >
      {visibleTemplates.map(
        (template) => (
          <AdminWorkbenchListRow
            key={
              template
                .marketing_template_id
            }
            selected={
              template
                .marketing_template_id ===
              selectedTemplateId
            }
            checkable
            checked={
              checkedTemplateIds.has(
                template
                  .marketing_template_id
              )
            }
            onCheck={(
              checked
            ) =>
              toggleSetValue(
                setCheckedTemplateIds,

                template
                  .marketing_template_id,

                checked
              )
            }
            onSelect={() =>
              setSelectedTemplateId(
                template
                  .marketing_template_id
              )
            }
            onDoubleClick={() =>
              openTemplateEditor(
                template
              )
            }
            wrap={
              activeTemplateTab ===
              "description"
            }
          >
            {template.template}
          </AdminWorkbenchListRow>
        )
      )}

      {!visibleTemplates.length ? (
        <div
          className="admin-empty-state"
        >
          No templates
        </div>
      ) : null}
    </AdminWorkbenchTabs>
  );

  /*
   * DRAWER
   */

  const drawerOpen =
    isCallMode ||
    Boolean(sandbox);

  const drawerContent =
    isCallMode ? (
      <CallDrawer
        activeTab={
          drawerTab
        }

        onChangeTab={
          setDrawerTab
        }

        sandbox={
          sandbox
        }

        sandboxContent={
          sandbox ? (
            <MarketingSandbox
              sandbox={
                sandbox
              }

              updateSandbox={
                updateSandbox
              }

              previewResults={
                previewResults
              }

              previewing={
                previewing
              }

              saving={
                saving
              }

              onPreview={
                previewTemplate
              }

              onSave={
                saveSandbox
              }

              onSaveAsNew={
                saveTemplateAsNew
              }

              onDelete={
                deleteSandboxItem
              }
            />
          ) : null
        }

        requestedDeliverables={
          requestedDeliverables
        }

        activeOrderKey={
          activeOrderKey
        }

        onSelectOrder={
          selectOrder
        }

        activeOrder={
          activeOrder
        }

        activeResults={
          activeOrderResults
        }

        accepted={
          Boolean(
            activeOrder &&
            orderAccepted[
              activeOrder.key
            ]
          )
        }

        generating={
          generatingOrder
        }

        allOrdersAccepted={
          allOrdersAccepted
        }

        orderAccepted={
          orderAccepted
        }

        orderResults={
          orderResults
        }

        onGenerate={
          generateOrder
        }

        onEditResult={
          updateOrderResult
        }

        onAcceptOrder={
          acceptActiveOrder
        }

        onRejectOrder={
          rejectActiveOrder
        }

        onAcceptAndReturn={
          acceptAndReturn
        }
      />
    ) : (
      sandbox ? (
        <MarketingSandbox
          sandbox={
            sandbox
          }

          updateSandbox={
            updateSandbox
          }

          previewResults={
            previewResults
          }

          previewing={
            previewing
          }

          saving={
            saving
          }

          onPreview={
            previewTemplate
          }

          onSave={
            saveSandbox
          }

          onSaveAsNew={
            saveTemplateAsNew
          }

          onDelete={
            deleteSandboxItem
          }
        />
      ) : null
    );

  return (
    <AdminWorkbench
      header={header}

      upperLeft={
        loading
          ? "Loading..."
          : groupsPane
      }

      upperRight={
        loading
          ? null
          : termsPane
      }

      lower={
        loading
          ? null
          : templatesPane
      }

      drawerOpen={
        drawerOpen
      }

      drawerWidth={440}

      drawerTitle={
        isCallMode
          ? "Mark"
          : sandboxTitle(
              sandbox
            )
      }

      drawerContent={
        drawerContent
      }

      onCloseDrawer={
        isCallMode
          ? undefined
          : closeSandbox
      }
    />
  );
}

/*
 * CALL-MODE DRAWER
 */

function CallDrawer({
  activeTab,
  onChangeTab,

  sandbox,
  sandboxContent,

  requestedDeliverables,

  activeOrderKey,
  onSelectOrder,
  activeOrder,
  activeResults,

  accepted,
  generating,

  allOrdersAccepted,

  orderAccepted,
  orderResults,

  onGenerate,
  onEditResult,
  onAcceptOrder,
  onRejectOrder,
  onAcceptAndReturn,
}) {
  return (
    <div
      style={{
        height: "100%",
        minHeight: 0,

        display: "flex",
        flexDirection: "column",
      }}
    >
      <div
        style={
          drawerTabsStyle
        }
      >
        <button
          type="button"
          onClick={() =>
            onChangeTab(
              "sandbox"
            )
          }
          style={{
            ...drawerTabStyle,

            ...(activeTab ===
            "sandbox"
              ? drawerActiveTabStyle
              : {}),
          }}
        >
          Sandbox
        </button>

        <button
          type="button"
          onClick={() =>
            onChangeTab(
              "order"
            )
          }
          style={{
            ...drawerTabStyle,

            ...(activeTab ===
            "order"
              ? drawerActiveTabStyle
              : {}),
          }}
        >
          Order
        </button>
      </div>

      <div
        style={{
          flex: 1,
          minHeight: 0,
        }}
      >
        {activeTab ===
        "sandbox" ? (
          sandboxContent || (
            <div
              style={
                drawerEmptyStyle
              }
            >
              Double-click a
              group, term, or
              template to open it
              here.
            </div>
          )
        ) : (
          <MarketingOrder
            requestedDeliverables={
              requestedDeliverables
            }

            activeOrderKey={
              activeOrderKey
            }

            onSelectOrder={
              onSelectOrder
            }

            activeOrder={
              activeOrder
            }

            activeResults={
              activeResults
            }

            accepted={
              accepted
            }

            generating={
              generating
            }

            allOrdersAccepted={
              allOrdersAccepted
            }

            orderAccepted={
              orderAccepted
            }

            orderResults={
              orderResults
            }

            onGenerate={
              onGenerate
            }

            onEditResult={
              onEditResult
            }

            onAcceptOrder={
              onAcceptOrder
            }

            onRejectOrder={
              onRejectOrder
            }

            onAcceptAndReturn={
              onAcceptAndReturn
            }
          />
        )}
      </div>
    </div>
  );
}

/*
 * ORDER TAB
 */

function MarketingOrder({
  requestedDeliverables,

  activeOrderKey,
  onSelectOrder,

  activeOrder,
  activeResults,

  accepted,
  generating,

  allOrdersAccepted,

  orderAccepted,
  orderResults,

  onGenerate,
  onEditResult,

  onAcceptOrder,
  onRejectOrder,

  onAcceptAndReturn,
}) {
  return (
    <div
      style={
        orderStyle
      }
    >
      <div
        style={
          orderControlsStyle
        }
      >
        <label
          className="admin-field"
        >
          <span
            className="admin-field__label"
          >
            Deliverable
          </span>

          <select
            className="admin-field__control"
            value={
              activeOrderKey
            }
            onChange={(
              event
            ) =>
              onSelectOrder(
                event.target
                  .value
              )
            }
          >
            {requestedDeliverables.map(
              (item) => (
                <option
                  key={
                    item.key
                  }
                  value={
                    item.key
                  }
                >
                  {item.label}
                </option>
              )
            )}
          </select>
        </label>

        <div
          style={
            orderQuantityStyle
          }
        >
          <span>
            Quantity
          </span>

          <strong>
            {activeOrder
              ?.count || 0}
          </strong>
        </div>

        <button
          type="button"
          onClick={
            onGenerate
          }
          disabled={
            generating
          }
        >
          {generating
            ? "Mark is working..."
            : activeResults.length
              ? "Regenerate"
              : "Generate"}
        </button>

        <button
          type="button"
          onClick={
            onAcceptOrder
          }
          disabled={
            !activeResults.length ||
            accepted
          }
          title={
            accepted
              ? "Deliverable accepted"
              : "Accept deliverable"
          }
          aria-label={
            accepted
              ? "Deliverable accepted"
              : "Accept deliverable"
          }
          style={
            orderAcceptButtonStyle
          }
        >
          ✓
        </button>
      </div>

      <div
        style={
          orderStatusBarStyle
        }
      >
        {requestedDeliverables.map(
          (item) => {
            const count =
              (
                orderResults[
                  item.key
                ] || []
              ).length;

            const ready =
              orderAccepted[
                item.key
              ] === true;

            return (
              <button
                key={
                  item.key
                }
                type="button"
                onClick={() =>
                  onSelectOrder(
                    item.key
                  )
                }
                style={{
                  ...orderStatusStyle,

                  ...(item.key ===
                  activeOrderKey
                    ? orderStatusActiveStyle
                    : {}),
                }}
              >
                {item.label}
                {" · "}
                {count}
                {ready
                  ? " ✓"
                  : ""}
              </button>
            );
          }
        )}
      </div>

      <div
        style={
          orderResultsStyle
        }
      >
        {!activeResults.length ? (
          <div
            style={
              drawerEmptyStyle
            }
          >
            Choose the terms and
            templates Mark should
            use, then Generate.
          </div>
        ) : (
          activeResults.map(
            (
              result,
              index
            ) => (
              <div
                key={index}
                style={
                  orderResultRowStyle
                }
              >
                <div
                  style={
                    orderNumberStyle
                  }
                >
                  {index + 1}
                </div>

                {activeOrder
                  ?.key ===
                "description" ? (
                  <textarea
                    value={
                      result
                    }
                    rows={4}
                    onChange={(
                      event
                    ) =>
                      onEditResult(
                        activeOrder.key,
                        index,
                        event.target
                          .value
                      )
                    }
                    style={
                      orderTextAreaStyle
                    }
                  />
                ) : (
                  <input
                    type="text"
                    value={
                      result
                    }
                    onChange={(
                      event
                    ) =>
                      onEditResult(
                        activeOrder.key,
                        index,
                        event.target
                          .value
                      )
                    }
                    style={
                      orderInputStyle
                    }
                  />
                )}
              </div>
            )
          )
        )}
      </div>

      <div
        style={
          orderFooterStyle
        }
      >
        {activeResults.length ? (
          <button
            type="button"
            onClick={
              onRejectOrder
            }
          >
            Reject / Clear
          </button>
        ) : null}

        <div
          style={{
            flex: 1,
          }}
        />

        <button
          type="button"
          onClick={
            onAcceptAndReturn
          }
          disabled={
            !activeResults.length
          }
        >
          Accept & Return
        </button>
      </div>
    </div>
  );
}

/*
 * SANDBOX CONTENT
 */

function MarketingSandbox({
  sandbox,
  updateSandbox,

  previewResults,

  previewing,
  saving,

  onPreview,
  onSave,
  onSaveAsNew,
  onDelete,
}) {
  if (
    sandbox.type ===
    "group"
  ) {
    return (
      <div
        style={
          sandboxBodyStyle
        }
      >
        <label
          className="admin-field"
        >
          <span
            className="admin-field__label"
          >
            Group Name
          </span>

          <input
            className="admin-field__control"
            value={
              sandbox.label
            }
            onChange={(
              event
            ) =>
              updateSandbox({
                label:
                  event.target
                    .value,
              })
            }
          />
        </label>

        {sandbox.mode ===
        "edit" ? (
          <div
            style={
              tokenNoteStyle
            }
          >
            Template token:{" "}
            {
              sandbox
                .group_token
            }
          </div>
        ) : null}

        <SandboxActions
          sandbox={
            sandbox
          }

          saving={
            saving
          }

          onSave={
            onSave
          }

          onDelete={
            onDelete
          }
        />
      </div>
    );
  }

  if (
    sandbox.type ===
    "term"
  ) {
    return (
      <div
        style={
          sandboxBodyStyle
        }
      >
        <label
          className="admin-field"
        >
          <span
            className="admin-field__label"
          >
            Singular
          </span>

          <input
            className="admin-field__control"
            value={
              sandbox.singular
            }
            onChange={(
              event
            ) =>
              updateSandbox({
                singular:
                  event.target
                    .value,
              })
            }
          />
        </label>

        <label
          className="admin-field"
        >
          <span
            className="admin-field__label"
          >
            Plural
          </span>

          <input
            className="admin-field__control"
            value={
              sandbox.plural
            }
            onChange={(
              event
            ) =>
              updateSandbox({
                plural:
                  event.target
                    .value,
              })
            }
          />
        </label>

        <SandboxActions
          sandbox={
            sandbox
          }

          saving={
            saving
          }

          onSave={
            onSave
          }

          onDelete={
            onDelete
          }
        />
      </div>
    );
  }

  return (
    <div
      style={
        templateSandboxStyle
      }
    >
      <div
        style={
          templateEditorStyle
        }
      >
        <label
          className="admin-field"
        >
          <span
            className="admin-field__label"
          >
            Template
          </span>

          <textarea
            className="admin-field__control"
            rows={5}
            value={
              sandbox.template
            }
            onChange={(
              event
            ) =>
              updateSandbox({
                template:
                  event.target
                    .value,
              })
            }
          />
        </label>

        <label
          className="admin-field"
        >
          <span
            className="admin-field__label"
          >
            Tags
          </span>

          <input
            className="admin-field__control"
            value={
              sandbox.tags
            }
            onChange={(
              event
            ) =>
              updateSandbox({
                tags:
                  event.target
                    .value,
              })
            }
          />
        </label>

        <button
          type="button"
          onClick={
            onPreview
          }
          disabled={
            previewing
          }
        >
          {previewing
            ? "Previewing..."
            : "Preview"}
        </button>
      </div>

      <div
        style={
          previewAreaStyle
        }
      >
        <div
          style={
            previewHeaderStyle
          }
        >
          Results
          {previewResults.length
            ? ` (${previewResults.length})`
            : ""}
        </div>

        <div
          style={
            previewListStyle
          }
        >
          {previewResults.map(
            (
              result,
              index
            ) => (
              <div
                key={`${result}-${index}`}
                style={
                  previewRowStyle
                }
              >
                {result}
              </div>
            )
          )}

          {!previewResults.length ? (
            <div
              style={
                previewEmptyStyle
              }
            >
              Check terms, edit
              the template, then
              Preview.
            </div>
          ) : null}
        </div>
      </div>

      <div
        style={
          sandboxFooterStyle
        }
      >
        {sandbox.mode ===
        "edit" ? (
          <>
            <button
              type="button"
              onClick={
                onDelete
              }
              disabled={
                saving
              }
            >
              Delete
            </button>

            <div
              style={{
                flex: 1,
              }}
            />

            <button
              type="button"
              onClick={
                onSaveAsNew
              }
              disabled={
                saving
              }
            >
              Save as New
            </button>

            <button
              type="button"
              onClick={
                onSave
              }
              disabled={
                saving
              }
            >
              Update
            </button>
          </>
        ) : (
          <>
            <div
              style={{
                flex: 1,
              }}
            />

            <button
              type="button"
              onClick={
                onSave
              }
              disabled={
                saving
              }
            >
              Save
            </button>
          </>
        )}
      </div>
    </div>
  );
}

function SandboxActions({
  sandbox,
  saving,
  onSave,
  onDelete,
}) {
  return (
    <div
      style={
        sandboxFooterStyle
      }
    >
      {sandbox.mode ===
      "edit" ? (
        <button
          type="button"
          onClick={
            onDelete
          }
          disabled={
            saving
          }
        >
          Delete
        </button>
      ) : null}

      <div
        style={{
          flex: 1,
        }}
      />

      <button
        type="button"
        onClick={
          onSave
        }
        disabled={
          saving
        }
      >
        {sandbox.mode ===
        "edit"
          ? "Save"
          : "Add"}
      </button>
    </div>
  );
}

function sandboxTitle(
  sandbox
) {
  if (!sandbox) {
    return "";
  }

  const action =
    sandbox.mode === "new"
      ? "New"
      : "Edit";

  if (
    sandbox.type ===
    "group"
  ) {
    return `${action} Group`;
  }

  if (
    sandbox.type ===
    "term"
  ) {
    return `${action} Term`;
  }

  return "Template Sandbox";
}

function humanizeKey(
  value
) {
  return String(
    value || ""
  )
    .replace(
      /_/g,
      " "
    )
    .replace(
      /\b\w/g,
      (char) =>
        char.toUpperCase()
    );
}

/*
 * MARK-SPECIFIC STYLES
 */

const drawerTabsStyle = {
  height: 38,
  flexShrink: 0,

  display: "flex",
  alignItems: "stretch",

  borderBottom:
    "1px solid var(--admin-layout-border, #d8dde3)",

  background: "#eef1f3",
};

const drawerTabStyle = {
  border: 0,
  borderRight:
    "1px solid var(--admin-layout-border, #d8dde3)",

  padding: "0 14px",

  background: "#dfe4e8",

  color: "#39434d",

  fontSize: 12,
  fontWeight: 600,

  cursor: "pointer",
};

const drawerActiveTabStyle = {
  background:
    "var(--highlight-cyan)",

  color: "#1f2933",
};

const drawerEmptyStyle = {
  padding: 12,

  color: "#6b7280",

  fontSize: 12,
  lineHeight: 1.4,
};

const sandboxBodyStyle = {
  minHeight: "100%",

  display: "flex",
  flexDirection: "column",

  gap: 12,

  padding: 14,

  boxSizing:
    "border-box",
};

const tokenNoteStyle = {
  color: "#6b7280",

  fontSize: 11,
};

const templateSandboxStyle = {
  height: "100%",
  minHeight: 0,

  display: "flex",
  flexDirection: "column",
};

const templateEditorStyle = {
  display: "flex",
  flexDirection: "column",

  gap: 10,

  padding: 12,

  borderBottom:
    "1px solid var(--admin-layout-border, #d8dde3)",
};

const previewAreaStyle = {
  flex: 1,
  minHeight: 0,

  display: "flex",
  flexDirection: "column",
};

const previewHeaderStyle = {
  height: 30,
  flexShrink: 0,

  display: "flex",
  alignItems: "center",

  padding: "0 8px",

  fontSize: 11,
  fontWeight: 700,

  background: "#f7f8fa",

  borderBottom:
    "1px solid var(--admin-layout-border, #d8dde3)",
};

const previewListStyle = {
  flex: 1,
  minHeight: 0,

  overflow: "auto",
};

const previewRowStyle = {
  padding: "6px 8px",

  borderBottom:
    "1px solid #eceff1",

  fontSize: 12,
  lineHeight: 1.35,
};

const previewEmptyStyle = {
  padding: 10,

  color: "#6b7280",

  fontSize: 12,
};

const sandboxFooterStyle = {
  flexShrink: 0,

  display: "flex",
  alignItems: "center",

  gap: 6,

  padding: 10,

  borderTop:
    "1px solid var(--admin-layout-border, #d8dde3)",
};

/*
 * ORDER
 */

const orderStyle = {
  height: "100%",
  minHeight: 0,

  display: "flex",
  flexDirection: "column",
};

const orderControlsStyle = {
  flexShrink: 0,

  display: "flex",
  alignItems: "flex-end",

  gap: 10,

  padding: 10,

  borderBottom:
    "1px solid var(--admin-layout-border, #d8dde3)",
};

const orderQuantityStyle = {
  display: "flex",
  flexDirection: "column",

  gap: 3,

  minWidth: 55,

  fontSize: 11,

  color: "#6b7280",
};


const orderAcceptButtonStyle = {
  minWidth: 32,
  width: 32,
  height: 32,

  padding: 0,

  fontSize: 16,
  fontWeight: 700,

  lineHeight: 1,
};


const orderStatusBarStyle = {
  flexShrink: 0,

  display: "flex",
  flexWrap: "wrap",

  gap: 4,

  padding: 6,

  background: "#f7f8fa",

  borderBottom:
    "1px solid var(--admin-layout-border, #d8dde3)",
};


const orderStatusStyle = {
  border:
    "1px solid #aeb6bf",

  background: "#dfe4e8",

  color: "#1f2933",

  padding: "4px 7px",

  fontSize: 11,
  fontWeight: 600,

  cursor: "pointer",
};

const orderStatusActiveStyle = {
  background:
    "var(--highlight-cyan)",

  color: "#102a2e",

  border:
    "1px solid #6f9da3",

  fontWeight: 700,
};


const orderResultsStyle = {
  flex: 1,
  minHeight: 0,

  overflow: "auto",
};

const orderResultRowStyle = {
  display: "grid",

  gridTemplateColumns:
    "28px minmax(0, 1fr)",

  alignItems: "start",

  gap: 5,

  padding: "5px 7px",

  borderBottom:
    "1px solid #eceff1",
};

const orderNumberStyle = {
  paddingTop: 6,

  color: "#8a939c",

  fontSize: 10,

  textAlign: "right",
};

const orderInputStyle = {
  width: "100%",

  boxSizing:
    "border-box",

  padding: "5px 6px",

  fontSize: 12,
};

const orderTextAreaStyle = {
  width: "100%",

  boxSizing:
    "border-box",

  resize: "vertical",

  padding: "5px 6px",

  fontSize: 12,
  lineHeight: 1.35,
};

const orderFooterStyle = {
  flexShrink: 0,

  display: "flex",
  alignItems: "center",

  gap: 6,

  padding: 8,

  borderTop:
    "1px solid var(--admin-layout-border, #d8dde3)",

  background: "#f7f8fa",
};