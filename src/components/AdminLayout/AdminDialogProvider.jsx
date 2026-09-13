import {
  useCallback,
  useMemo,
  useRef,
  useState,
} from "react";

import AdminDialog from "./AdminDialog";
import AdminDialogContext from "./AdminDialogContext";

const EMPTY_DIALOG = {
  open: false,
  mode: "confirm",
  title: "",
  message: "",
  confirmLabel: "OK",
  cancelLabel: "Cancel",
};

export default function AdminDialogProvider({
  children,
}) {
  const [dialogState, setDialogState] =
    useState(EMPTY_DIALOG);

  const resolverRef = useRef(null);

  const settle = useCallback(
    (result) => {
      const resolve = resolverRef.current;

      resolverRef.current = null;

      setDialogState(
        EMPTY_DIALOG
      );

      resolve?.(result);
    },
    []
  );

  const openDialog = useCallback(
    ({
      mode = "confirm",
      title = "",
      message = "",
      confirmLabel = "OK",
      cancelLabel = "Cancel",
    } = {}) => {
      /*
       * Only one admin dialog may be active at a time.
       *
       * If another caller somehow opens a new dialog before the
       * current one is answered, resolve the old one as cancelled
       * rather than leaving its Promise hanging forever.
       */
      if (resolverRef.current) {
        resolverRef.current(false);
        resolverRef.current = null;
      }

      return new Promise((resolve) => {
        resolverRef.current = resolve;

        setDialogState({
          open: true,
          mode,
          title,
          message,
          confirmLabel,
          cancelLabel,
        });
      });
    },
    []
  );

  const confirm = useCallback(
    (options = {}) =>
      openDialog({
        ...options,
        mode: "confirm",
        confirmLabel:
          options.confirmLabel
          ?? "Confirm",
        cancelLabel:
          options.cancelLabel
          ?? "Cancel",
      }),
    [
      openDialog,
    ]
  );

  const alert = useCallback(
    (options = {}) =>
      openDialog({
        ...options,
        mode: "alert",
        confirmLabel:
          options.confirmLabel
          ?? "OK",
      }),
    [
      openDialog,
    ]
  );

  const danger = useCallback(
    (options = {}) =>
      openDialog({
        ...options,
        mode: "danger",
        confirmLabel:
          options.confirmLabel
          ?? "Continue",
        cancelLabel:
          options.cancelLabel
          ?? "Cancel",
      }),
    [
      openDialog,
    ]
  );

  const api = useMemo(
    () => ({
      confirm,
      alert,
      danger,
    }),
    [
      confirm,
      alert,
      danger,
    ]
  );

  return (
    <AdminDialogContext.Provider
      value={api}
    >
      {children}

      <AdminDialog
        {...dialogState}
        onConfirm={() =>
          settle(true)
        }
        onCancel={() =>
          settle(false)
        }
      />
    </AdminDialogContext.Provider>
  );
}
