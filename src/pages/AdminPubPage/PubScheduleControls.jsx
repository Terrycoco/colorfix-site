import {
  useEffect,
  useMemo,
  useState,
} from "react";

import {
  AdminButton,
  AdminCheckboxRow,
  AdminEmptyState,
  AdminField,
  AdminMetaText,
  AdminNotice,
  AdminPanel,
  AdminStack,
  AdminToolbar,
} from "@components/AdminLayout";

import {
  API_FOLDER,
} from "@helpers/config";


const SCHEDULE_URL =
  `${API_FOLDER}/v2/admin/pub/schedule.php`;


export default function PubScheduleControls() {
  const [
    config,
    setConfig,
  ] = useState(
    null
  );

  const [
    loading,
    setLoading,
  ] = useState(
    true
  );

  const [
    savingSettings,
    setSavingSettings,
  ] = useState(
    false
  );

  const [
    savingChannel,
    setSavingChannel,
  ] = useState(
    ""
  );

  const [
    error,
    setError,
  ] = useState(
    ""
  );

  const [
    message,
    setMessage,
  ] = useState(
    ""
  );


  async function loadConfig() {
    setLoading(
      true
    );

    setError(
      ""
    );

    try {
      const params =
        new URLSearchParams({
          meta:
            "config",

          _:
            String(
              Date.now()
            ),
        });

      const res =
        await fetch(
          `${SCHEDULE_URL}?${params.toString()}`,
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
          "Failed to load Schedule controls."
        );
      }

      setConfig(
        normalizeConfig(
          data.config
        )
      );

    } catch (err) {
      setError(
        err?.message ||
        "Failed to load Schedule controls."
      );

    } finally {
      setLoading(
        false
      );
    }
  }


  useEffect(() => {
    loadConfig();
  }, []);


  async function saveSettings() {
    if (!config) {
      return;
    }

    setSavingSettings(
      true
    );

    setError(
      ""
    );

    setMessage(
      ""
    );

    try {
      const res =
        await fetch(
          SCHEDULE_URL,
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
                action:
                  "update_settings",

                scheduler_enabled:
                  Boolean(
                    config
                      .settings
                      .scheduler_enabled
                  ),

                timezone:
                  String(
                    config
                      .settings
                      .timezone ||
                    ""
                  ).trim(),
              }),
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
          "Failed to save Schedule settings."
        );
      }

      setConfig(
        normalizeConfig(
          data.config
        )
      );

      setMessage(
        "Schedule settings saved."
      );

    } catch (err) {
      setError(
        err?.message ||
        "Failed to save Schedule settings."
      );

    } finally {
      setSavingSettings(
        false
      );
    }
  }


  async function saveChannelRule(
    channel
  ) {
    const rule =
      config
        ?.channel_rules
        ?.find(
          (item) =>
            String(
              item
                ?.channel ||
              ""
            )
              .trim()
              .toLowerCase() ===
            String(
              channel ||
              ""
            )
              .trim()
              .toLowerCase()
        );

    if (!rule) {
      return;
    }

    setSavingChannel(
      rule.channel
    );

    setError(
      ""
    );

    setMessage(
      ""
    );

    try {
      const res =
        await fetch(
          SCHEDULE_URL,
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
                action:
                  "save_channel_rule",

                channel:
                  rule.channel,

                enabled:
                  Boolean(
                    rule.enabled
                  ),

                notify_on_publish:
                  Boolean(
                    rule
                      .notify_on_publish
                  ),

                release_interval_minutes:
                  Number(
                    rule
                      .release_interval_minutes ||
                    0
                  ),

                same_source_max:
                  Number(
                    rule
                      .same_source_max ||
                    0
                  ),

                same_source_window_minutes:
                  Number(
                    rule
                      .same_source_window_minutes ||
                    0
                  ),
              }),
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
          `Failed to save ${rule.channel} Schedule controls.`
        );
      }

      setConfig(
        normalizeConfig(
          data.config
        )
      );

      setMessage(
        `${humanize(rule.channel)} controls saved.`
      );

    } catch (err) {
      setError(
        err?.message ||
        `Failed to save ${rule.channel} Schedule controls.`
      );

    } finally {
      setSavingChannel(
        ""
      );
    }
  }


  function updateSettings(
    field,
    value
  ) {
    setConfig(
      (current) => {
        if (!current) {
          return current;
        }

        return {
          ...current,

          settings: {
            ...current.settings,

            [field]:
              value,
          },
        };
      }
    );
  }


  function updateChannelRule(
    channel,
    field,
    value
  ) {
    setConfig(
      (current) => {
        if (!current) {
          return current;
        }

        return {
          ...current,

          channel_rules:
            current
              .channel_rules
              .map(
                (rule) =>
                  rule.channel ===
                  channel
                    ? {
                        ...rule,

                        [field]:
                          value,
                      }
                    : rule
              ),
        };
      }
    );
  }


  const rules =
    useMemo(
      () =>
        Array.isArray(
          config
            ?.channel_rules
        )
          ? config.channel_rules
          : [],
      [
        config,
      ]
    );


  if (
    loading
    &&
    !config
  ) {
    return (
      <AdminEmptyState
        title="Schedule Controls"

        message="Loading Schedule controls..."
      />
    );
  }


  return (
    <AdminStack gap="md">
      {error ? (
        <AdminNotice variant="danger">
          {error}
        </AdminNotice>
      ) : null}


      {message ? (
        <AdminNotice variant="success">
          {message}
        </AdminNotice>
      ) : null}


      {config ? (
        <>
          <AdminPanel
            title="Master"

            compact

            actions={
              <AdminButton
                type="button"

                disabled={
                  savingSettings
                }

                onClick={
                  saveSettings
                }
              >
                {
                  savingSettings
                    ? "Saving..."
                    : "Save Master"
                }
              </AdminButton>
            }
          >
            <AdminToolbar compact>
              <AdminCheckboxRow
                checked={
                  Boolean(
                    config
                      .settings
                      .scheduler_enabled
                  )
                }

                onChange={(
                  event
                ) =>
                  updateSettings(
                    "scheduler_enabled",
                    event
                      .target
                      .checked
                  )
                }
              >
                Automatic Schedule
              </AdminCheckboxRow>


              <AdminField
                label="Timezone"

                compact
              >
                <input
                  className="admin-field__control"

                  type="text"

                  value={
                    config
                      .settings
                      .timezone
                  }

                  onChange={(
                    event
                  ) =>
                    updateSettings(
                      "timezone",
                      event
                        .target
                        .value
                    )
                  }
                />
              </AdminField>
            </AdminToolbar>
          </AdminPanel>


          {rules.map(
            (rule) => (
              <AdminPanel
                key={
                  rule.channel
                }

                title={
                  humanize(
                    rule.channel
                  )
                }

                compact

                actions={
                  <AdminButton
                    type="button"

                    disabled={
                      savingChannel ===
                      rule.channel
                    }

                    onClick={() =>
                      saveChannelRule(
                        rule.channel
                      )
                    }
                  >
                    {
                      savingChannel ===
                      rule.channel
                        ? "Saving..."
                        : "Save"
                    }
                  </AdminButton>
                }
              >
                <AdminStack gap="sm">
                  <AdminToolbar compact>
                    <AdminCheckboxRow
                      checked={
                        Boolean(
                          rule.enabled
                        )
                      }

                      onChange={(
                        event
                      ) =>
                        updateChannelRule(
                          rule.channel,
                          "enabled",
                          event
                            .target
                            .checked
                        )
                      }
                    >
                      Channel Enabled
                    </AdminCheckboxRow>


                    <AdminCheckboxRow
                      checked={
                        Boolean(
                          rule
                            .notify_on_publish
                        )
                      }

                      onChange={(
                        event
                      ) =>
                        updateChannelRule(
                          rule.channel,
                          "notify_on_publish",
                          event
                            .target
                            .checked
                        )
                      }
                    >
                      Notify on publish
                    </AdminCheckboxRow>
                  </AdminToolbar>


                  <AdminToolbar compact>
                    <AdminField
                      label="Release Interval (minutes)"

                      compact
                    >
                      <input
                        className="admin-field__control"

                        type="number"

                        min="1"

                        value={
                          rule
                            .release_interval_minutes
                        }

                        onChange={(
                          event
                        ) =>
                          updateChannelRule(
                            rule.channel,
                            "release_interval_minutes",
                            event
                              .target
                              .value
                          )
                        }
                      />
                    </AdminField>


                    <AdminField
                      label="Same Source Max"

                      compact
                    >
                      <input
                        className="admin-field__control"

                        type="number"

                        min="1"

                        value={
                          rule
                            .same_source_max
                        }

                        onChange={(
                          event
                        ) =>
                          updateChannelRule(
                            rule.channel,
                            "same_source_max",
                            event
                              .target
                              .value
                          )
                        }
                      />
                    </AdminField>


                    <AdminField
                      label="Same Source Window (minutes)"

                      compact
                    >
                      <input
                        className="admin-field__control"

                        type="number"

                        min="1"

                        value={
                          rule
                            .same_source_window_minutes
                        }

                        onChange={(
                          event
                        ) =>
                          updateChannelRule(
                            rule.channel,
                            "same_source_window_minutes",
                            event
                              .target
                              .value
                          )
                        }
                      />
                    </AdminField>
                  </AdminToolbar>
                </AdminStack>
              </AdminPanel>
            )
          )}


          <AdminMetaText as="div">
            Schedule changes take effect after their individual Save button is used.
          </AdminMetaText>
        </>
      ) : null}
    </AdminStack>
  );
}


function normalizeConfig(
  raw
) {
  const settings =
    raw
      ?.settings &&
    typeof raw.settings ===
      "object"
      ? raw.settings
      : {};

  const rules =
    Array.isArray(
      raw
        ?.channel_rules
    )
      ? raw.channel_rules
      : [];

  return {
    settings: {
      scheduler_enabled:
        Boolean(
          settings
            .scheduler_enabled
        ),

      timezone:
        String(
          settings
            .timezone ||
          "America/Los_Angeles"
        ),
    },

    channel_rules:
      rules.map(
        (rule) => ({
          channel:
            String(
              rule
                ?.channel ||
              ""
            )
              .trim()
              .toLowerCase(),

          enabled:
            Boolean(
              rule
                ?.enabled
            ),

          notify_on_publish:
            Boolean(
              rule
                ?.notify_on_publish
            ),

          release_interval_minutes:
            Number(
              rule
                ?.release_interval_minutes ||
              0
            ),

          same_source_max:
            Number(
              rule
                ?.same_source_max ||
              0
            ),

          same_source_window_minutes:
            Number(
              rule
                ?.same_source_window_minutes ||
              0
            ),
        })
      ),
  };
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
