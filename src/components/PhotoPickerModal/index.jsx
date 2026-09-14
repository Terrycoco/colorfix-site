import {
  useCallback,
  useEffect,
  useRef,
  useState,
} from "react";

import {
  AdminButton,
  AdminDialog,
  AdminField,
  AdminNotice,
  AdminStack,
  AdminToolbar,
} from "@components/AdminLayout";

import {
  API_FOLDER,
} from "@helpers/config";

import {
  buildImageUrl,
} from "@helpers/assetImage";

import "./photo-picker-modal.css";


export default function PhotoPickerModal({
  open = false,
  title = "Pick Photo",
  sourceType = "",
  onClose,
  onPick,
}) {
  const QUERY_KEY =
    "photo-picker:last-query";

  const [
    query,
    setQuery,
  ] = useState("");

  const [
    items,
    setItems,
  ] = useState([]);

  const [
    loading,
    setLoading,
  ] = useState(false);

  const [
    error,
    setError,
  ] = useState("");

  const [
    thumbNonce,
    setThumbNonce,
  ] = useState(
    () => String(Date.now())
  );

  const inputRef =
    useRef(null);

  const requestRef =
    useRef(null);


  useEffect(() => {
    if (!open) {
      requestRef.current?.abort();

      requestRef.current =
        null;

      setItems([]);

      setError("");

      setLoading(false);

      setThumbNonce(
        String(Date.now())
      );

      return;
    }

    try {
      const stored =
        window.sessionStorage.getItem(
          QUERY_KEY
        );

      if (stored) {
        setQuery(
          stored
        );
      }
    } catch {
      // Ignore unavailable session storage.
    }

    const timer =
      window.setTimeout(
        () =>
          inputRef.current?.focus(),
        0
      );

    return () =>
      window.clearTimeout(
        timer
      );
  }, [
    open,
  ]);


  useEffect(() => {
    try {
      window.sessionStorage.setItem(
        QUERY_KEY,
        query
      );
    } catch {
      // Ignore unavailable session storage.
    }
  }, [
    query,
  ]);


  const listUrl =
    `${API_FOLDER}/v2/admin/photo-library/list.php`;


  const parseJsonResponse =
    useCallback(
      async (
        res
      ) => {
        const text =
          await res.text();

        const contentType =
          String(
            res.headers.get(
              "content-type"
            )
            ||
            ""
          ).toLowerCase();

        const looksLikeJson =
          contentType.includes(
            "application/json"
          );

        if (
          !text.trim()
        ) {
          return {};
        }

        if (
          !looksLikeJson
          &&
          text.trim().startsWith(
            "<"
          )
        ) {
          throw new Error(
            "Photo search returned HTML instead of JSON. The session may have expired."
          );
        }

        try {
          return JSON.parse(
            text
          );
        } catch {
          throw new Error(
            "Photo search returned invalid JSON."
          );
        }
      },
      []
    );


  const runSearch =
    useCallback(
      async () => {
        const cleanQuery =
          query.trim();

        if (
          !cleanQuery
        ) {
          requestRef.current?.abort();

          requestRef.current =
            null;

          setItems([]);

          setLoading(
            false
          );

          setError(
            "Enter a tag or search term first. Blank searches are disabled."
          );

          inputRef.current?.focus();

          return;
        }

        requestRef.current?.abort();

        const controller =
          new AbortController();

        requestRef.current =
          controller;

        setLoading(
          true
        );

        setError(
          ""
        );

        setThumbNonce(
          String(Date.now())
        );

        try {
          const params =
            new URLSearchParams();

          params.set(
            "q",
            cleanQuery
          );

          if (
            sourceType
          ) {
            params.set(
              "source_type",
              sourceType
            );
          }

          params.set(
            "limit",
            "200"
          );

          params.set(
            "_",
            String(Date.now())
          );

          const res =
            await fetch(
              `${listUrl}?${params.toString()}`,
              {
                credentials:
                  "include",

                signal:
                  controller.signal,
              }
            );

          const data =
            await parseJsonResponse(
              res
            );

          if (
            !res.ok
            ||
            !data?.ok
          ) {
            throw new Error(
              data?.error ||
              "Search failed"
            );
          }

          if (
            requestRef.current !==
            controller
          ) {
            return;
          }

          setItems(
            Array.isArray(
              data.items
            )
              ? data.items
              : []
          );

        } catch (err) {
          if (
            err?.name ===
            "AbortError"
          ) {
            return;
          }

          setItems([]);

          setError(
            err?.message ||
            "Search failed"
          );

        } finally {
          if (
            requestRef.current ===
            controller
          ) {
            requestRef.current =
              null;

            setLoading(
              false
            );
          }
        }
      },
      [
        listUrl,
        parseJsonResponse,
        query,
        sourceType,
      ]
    );


  const buildPickerImageUrl =
    useCallback(
      (
        url,
        updatedAt = null
      ) => {
        const base =
          buildImageUrl(
            url,
            updatedAt
          );

        if (
          !base
        ) {
          return "";
        }

        const separator =
          base.includes(
            "?"
          )
            ? "&"
            : "?";

        return (
          `${base}${separator}picker=${thumbNonce}`
        );
      },
      [
        thumbNonce,
      ]
    );


  function clearSearch() {
    setQuery("");

    setItems([]);

    setError("");

    inputRef.current?.focus();
  }


  function pickPhoto(
    item
  ) {
    onPick?.({
      photo_library_id:
        item.photo_library_id,

      raw_rel_path:
        item.raw_rel_path
        ||
        "",

      image_url:
        item.raw_rel_path
        ||
        item.rel_path
        ||
        item.image_url
        ||
        "",

      title:
        item.title
        ||
        "",

      tags:
        item.tags
        ||
        "",

      attached_saved_palette_id:
        item.attached_saved_palette_id
        ??
        null,

      attached_saved_palette_label:
        item.attached_saved_palette_label
        ||
        "",

      attached_saved_palette_set_id:
        item.attached_saved_palette_set_id
        ??
        null,

      attached_saved_palette_set_label:
        item.attached_saved_palette_set_label
        ||
        "",

      attached_saved_palette_photo_type:
        item.attached_saved_palette_photo_type
        ||
        "",
    });
  }


  return (
    <AdminDialog
      open={
        open
      }

      title={
        title
      }

      width={
        1100
      }

      onClose={
        onClose
      }

      actions={[
        {
          key:
            "close",

          label:
            "Close",

          variant:
            "secondary",

          onClick:
            onClose,
        },
      ]}
    >
      <AdminStack gap="md">
        <form
          onSubmit={(
            event
          ) => {
            event.preventDefault();

            runSearch();
          }}
        >
          <AdminStack gap="sm">
            <AdminField
              label="Search (title, tags, or photo ID)"
            >
              <div className="ppm-search-row">
                <input
                  ref={
                    inputRef
                  }
                  className="admin-field__control admin-field__control--full"
                  type="text"
                  value={
                    query
                  }
                  onChange={(
                    event
                  ) =>
                    setQuery(
                      event.target.value
                    )
                  }
                  placeholder="e.g., door, cottage, adobe, or #621"
                />

                <AdminButton
                  type="submit"
                  disabled={
                    loading
                  }
                >
                  Search
                </AdminButton>

                <AdminButton
                  type="button"
                  variant="secondary"
                  onClick={
                    clearSearch
                  }
                >
                  Clear
                </AdminButton>
              </div>
            </AdminField>
          </AdminStack>
        </form>


        {
          loading
            ? (
                <AdminNotice variant="info">
                  Loading…
                </AdminNotice>
              )
            : null
        }


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
          !loading
          &&
          !error
          &&
          !query.trim()
            ? (
                <AdminNotice variant="info">
                  Enter a search term, then press Enter or click Search.
                </AdminNotice>
              )
            : null
        }


        {
          !loading
          &&
          !error
          &&
          query.trim()
          &&
          items.length ===
            0
            ? (
                <AdminNotice variant="info">
                  No photos matched.
                </AdminNotice>
              )
            : null
        }


        {
          items.length
            ? (
                <div className="ppm-grid">
                  {
                    items.map(
                      (
                        item
                      ) => (
                        <button
                          key={
                            item.photo_library_id
                          }
                          type="button"
                          className="ppm-card"
                          onClick={() =>
                            pickPhoto(
                              item
                            )
                          }
                        >
                          <div className="ppm-thumb">
                            {
                              (
                                item.raw_rel_path
                                ||
                                item.rel_path
                                ||
                                item.image_url
                              )
                                ? (
                                    <img
                                      src={
                                        buildPickerImageUrl(
                                          item.raw_rel_path
                                          ||
                                          item.rel_path
                                          ||
                                          item.image_url,
                                          item.updated_at
                                          ||
                                          null
                                        )
                                      }
                                      alt=""
                                      loading="lazy"
                                    />
                                  )
                                : (
                                    <div className="ppm-thumb-placeholder">
                                      No preview
                                    </div>
                                  )
                            }
                          </div>

                          <div className="ppm-meta">
                            <div className="ppm-name">
                              {
                                item.title
                                ||
                                "Untitled"
                              }
                            </div>

                            <div className="ppm-sub">
                              #{item.photo_library_id}
                            </div>
                          </div>
                        </button>
                      )
                    )
                  }
                </div>
              )
            : null
        }
      </AdminStack>
    </AdminDialog>
  );
}
