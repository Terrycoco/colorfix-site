import {
  useEffect,
  useMemo,
  useState,
} from "react";

import {
  API_FOLDER,
} from "@helpers/config";


const SCHEDULE_URL =
  `${API_FOLDER}/v2/admin/pub/schedule.php`;


export default function PubScheduleControls({
  onBack,
}) {
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
          (
            item
          ) =>
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
      (
        current
      ) => {
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
      (
        current
      ) => {
        if (!current) {
          return current;
        }


        return {
          ...current,

          channel_rules:
            current
              .channel_rules
              .map(
                (
                  rule
                ) =>
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


  return (
    <div
      className="admin-detail-workarea"
      style={
        pageStyle
      }
    >
      <div
        style={
          pageHeaderStyle
        }
      >
        <div>
          <div
            style={
              titleStyle
            }
          >
            Schedule Controls
          </div>

          <div
            style={
              subtitleStyle
            }
          >
            Automatic publishing rules. These settings do not affect Manual Send Now.
          </div>
        </div>


        <button
          type="button"
          onClick={
            onBack
          }
        >
          Back to Schedule
        </button>
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


      {message ? (
        <div
          style={
            messageStyle
          }
        >
          {message}
        </div>
      ) : null}


      {loading ? (
        <div
          style={
            mutedStyle
          }
        >
          Loading Schedule controls...
        </div>
      ) : config ? (
        <>
          <section
            style={
              sectionStyle
            }
          >
            <div
              style={
                sectionTitleStyle
              }
            >
              Master
            </div>

            <div
              style={
                cardStyle
              }
            >
              <label
                className="admin-field"
                style={
                  checkboxFieldStyle
                }
              >
                <span
                  className="admin-field__label"
                >
                  Automatic Schedule
                </span>

                <input
                  type="checkbox"

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
                />
              </label>


              <label
                className="admin-field"
              >
                <span
                  className="admin-field__label"
                >
                  Timezone
                </span>

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
              </label>


              <div
                style={
                  actionRowStyle
                }
              >
                <button
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
                      : "Save Master Controls"
                  }
                </button>
              </div>
            </div>
          </section>


          {rules.map(
            (
              rule
            ) => (
              <section
                key={
                  rule.channel
                }

                style={
                  sectionStyle
                }
              >
                <div
                  style={
                    sectionTitleStyle
                  }
                >
                  {
                    humanize(
                      rule.channel
                    )
                  }
                </div>

                <div
                  style={
                    cardStyle
                  }
                >
                  <label
                    className="admin-field"
                    style={
                      checkboxFieldStyle
                    }
                  >
                    <span
                      className="admin-field__label"
                    >
                      Channel Enabled
                    </span>

                    <input
                      type="checkbox"

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
                    />
                  </label>


                  <label
                    className="admin-field"
                  >
                    <span
                      className="admin-field__label"
                    >
                      Release Interval (minutes)
                    </span>

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
                  </label>


                  <label
                    className="admin-field"
                  >
                    <span
                      className="admin-field__label"
                    >
                      Same Source Max
                    </span>

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
                  </label>


                  <label
                    className="admin-field"
                  >
                    <span
                      className="admin-field__label"
                    >
                      Same Source Window (minutes)
                    </span>

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
                  </label>


                  <div
                    style={
                      actionRowStyle
                    }
                  >
                    <button
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
                          : `Save ${humanize(rule.channel)} Controls`
                      }
                    </button>
                  </div>
                </div>
              </section>
            )
          )}
        </>
      ) : null}
    </div>
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
        (
          rule
        ) => ({
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
      (
        character
      ) =>
        character
          .toUpperCase()
    );
}


const pageStyle = {
  padding:
    "16px 18px 28px",
  overflow:
    "auto",
};


const pageHeaderStyle = {
  display:
    "flex",
  alignItems:
    "flex-start",
  justifyContent:
    "space-between",
  gap:
    16,
  marginBottom:
    18,
};


const titleStyle = {
  fontSize:
    18,
  fontWeight:
    700,
};


const subtitleStyle = {
  marginTop:
    4,
  color:
    "#64748b",
  fontSize:
    12,
};


const sectionStyle = {
  marginBottom:
    20,
};


const sectionTitleStyle = {
  marginBottom:
    7,
  color:
    "#526273",
  fontSize:
    11,
  fontWeight:
    800,
  letterSpacing:
    "0.05em",
  textTransform:
    "uppercase",
};


const cardStyle = {
  display:
    "grid",
  gridTemplateColumns:
    "repeat(auto-fit, minmax(220px, 1fr))",
  gap:
    14,
  padding:
    14,
  border:
    "1px solid #d8dde3",
  borderRadius:
    4,
  background:
    "#ffffff",
};


const checkboxFieldStyle = {
  display:
    "flex",
  flexDirection:
    "column",
  justifyContent:
    "flex-end",
  gap:
    9,
};


const actionRowStyle = {
  display:
    "flex",
  alignItems:
    "flex-end",
  justifyContent:
    "flex-end",
};


const errorStyle = {
  marginBottom:
    12,
  padding:
    "8px 10px",
  border:
    "1px solid #e2baba",
  background:
    "#fff7f7",
  color:
    "#7d2e2e",
  fontSize:
    12,
};


const messageStyle = {
  marginBottom:
    12,
  padding:
    "8px 10px",
  border:
    "1px solid #b8d8c0",
  background:
    "#f3faf5",
  color:
    "#2f6840",
  fontSize:
    12,
  fontWeight:
    600,
};


const mutedStyle = {
  color:
    "#64748b",
  fontSize:
    12,
};
