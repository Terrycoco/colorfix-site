import {
  useEffect,
  useMemo,
  useState,
} from "react";

import {
  AdminDataGrid,
} from "@components/AdminLayout";

import {
  API_FOLDER,
} from "@helpers/config";


const ERRORS_URL =
  `${API_FOLDER}/v2/admin/pub/errors.php`;


export default function PubErrorsTable() {
  const [
    items,
    setItems,
  ] = useState([]);

  const [
    loading,
    setLoading,
  ] = useState(true);

  const [
    error,
    setError,
  ] = useState("");


  async function loadErrors() {
    setLoading(
      true
    );

    setError(
      ""
    );


    try {
      const params =
        new URLSearchParams({
          limit:
            "200",

          _:
            String(
              Date.now()
            ),
        });


      const response =
        await fetch(
          `${ERRORS_URL}?${params.toString()}`,
          {
            credentials:
              "include",
          }
        );


      const data =
        await response.json();


      if (
        !response.ok
        ||
        !data?.ok
      ) {
        throw new Error(
          data?.error ||
          "Failed to load PUB errors."
        );
      }


      setItems(
        Array.isArray(
          data.items
        )
          ? data.items
          : []
      );

    } catch (err) {
      setError(
        err?.message ||
        "Failed to load PUB errors."
      );

    } finally {
      setLoading(
        false
      );
    }
  }


  useEffect(
    () => {
      loadErrors();
    },
    []
  );


  const columns =
    useMemo(
      () => [
        {
          key:
            "occurred_at",

          label:
            "When",

          value:
            (item) =>
              formatOccurredAt(
                item
                  .occurred_at
              ),

          sortValue:
            (item) =>
              String(
                item
                  .occurred_at ||
                ""
              ),
        },


        {
          key:
            "severity",

          label:
            "Severity",

          render:
            (item) => {
              const severity =
                String(
                  item
                    .severity ||
                  "error"
                )
                  .trim()
                  .toLowerCase();


              return (
                <span
                  style={
                    severity ===
                      "critical"
                      ? criticalStyle
                      : errorBadgeStyle
                  }
                >
                  {humanize(
                    severity
                  )}
                </span>
              );
            },

          sortValue:
            (item) =>
              String(
                item
                  .severity ||
                ""
              ),
        },


        {
          key:
            "stage",

          label:
            "Stage",

          value:
            (item) =>
              item.stage
                ? humanize(
                    item.stage
                  )
                : "—",
        },


        {
          key:
            "channel",

          label:
            "Channel",

          value:
            (item) =>
              item.channel
                ? humanize(
                    item.channel
                  )
                : "—",
        },


        {
          key:
            "pub_asset_id",

          label:
            "Asset",

          value:
            (item) =>
              item
                .pub_asset_id ||
              "—",

          sortValue:
            (item) =>
              Number(
                item
                  .pub_asset_id ||
                0
              ),
        },


        {
          key:
            "code",

          label:
            "Code",

          value:
            (item) =>
              item.code ||
              "pub_failure",
        },


        {
          key:
            "error_message",

          label:
            "Message",

          render:
            (item) => {
              const message =
                String(
                  item
                    .error_message ||
                  ""
                )
                  .trim();


              if (!message) {
                return "—";
              }


              return (
                <span
                  title={
                    message
                  }

                  style={
                    messageStyle
                  }
                >
                  {message}
                </span>
              );
            },

          sortValue:
            (item) =>
              String(
                item
                  .error_message ||
                ""
              ),
        },
      ],
      []
    );


  return (
    <div>
      <div
        style={
          toolbarStyle
        }
      >
        <button
          type="button"

          onClick={
            loadErrors
          }

          disabled={
            loading
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
          {items.length} error
          {items.length === 1
            ? ""
            : "s"}
        </div>
      </div>


      {error ? (
        <div
          style={
            loadErrorStyle
          }
        >
          {error}
        </div>
      ) : null}


      {
        !loading
        &&
        !error
        &&
        items.length === 0
          ? (
              <div
                style={
                  emptyStyle
                }
              >
                No PUB errors logged.
              </div>
            )
          : null
      }


      {items.length > 0 ? (
        <AdminDataGrid
          items={
            items
          }

          columns={
            columns
          }

          getRowKey={(
            item
          ) =>
            item.id
          }

          defaultSortKey="occurred_at"

          defaultSortDirection="desc"

          ariaLabel="PUB errors"
        />
      ) : null}
    </div>
  );
}


function humanize(
  value
) {
  return String(
    value || ""
  )
    .replace(
      /[_-]+/g,
      " "
    )
    .replace(
      /\b\w/g,
      (letter) =>
        letter.toUpperCase()
    );
}


function formatOccurredAt(
  value
) {
  const raw =
    String(
      value || ""
    ).trim();


  if (!raw) {
    return "—";
  }


  /*
   * pub_errors.occurred_at is stored in UTC.
   * MySQL DATETIME has no timezone marker, so add Z
   * before converting to the browser's local time.
   */
  const iso =
    raw.includes("T")
      ? raw
      : raw.replace(
          " ",
          "T"
        );


  const date =
    new Date(
      /(?:Z|[+-]\d\d:\d\d)$/.test(
        iso
      )
        ? iso
        : `${iso}Z`
    );


  if (
    Number.isNaN(
      date.getTime()
    )
  ) {
    return raw;
  }


  return date.toLocaleString();
}


const toolbarStyle = {
  display:
    "flex",

  alignItems:
    "center",

  gap:
    12,

  marginBottom:
    12,
};


const countStyle = {
  marginLeft:
    "auto",

  fontSize:
    13,

  opacity:
    0.7,
};


const criticalStyle = {
  display:
    "inline-block",

  padding:
    "2px 7px",

  border:
    "1px solid #b42318",

  borderRadius:
    999,

  fontSize:
    12,

  fontWeight:
    700,

  color:
    "#b42318",

  background:
    "#fff4f2",
};


const errorBadgeStyle = {
  display:
    "inline-block",

  padding:
    "2px 7px",

  border:
    "1px solid #c77400",

  borderRadius:
    999,

  fontSize:
    12,

  fontWeight:
    600,

  color:
    "#8a4b00",

  background:
    "#fff8eb",
};


const messageStyle = {
  display:
    "block",

  maxWidth:
    560,

  whiteSpace:
    "normal",

  overflowWrap:
    "anywhere",
};


const loadErrorStyle = {
  marginBottom:
    12,

  padding:
    "8px 10px",

  border:
    "1px solid #d92d20",

  borderRadius:
    4,

  color:
    "#b42318",
};


const emptyStyle = {
  padding:
    "18px 0",

  opacity:
    0.7,
};
