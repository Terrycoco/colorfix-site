import { useContext } from "react";

import AdminDialogContext from "./AdminDialogContext";

export default function useAdminDialog() {
  const dialog = useContext(AdminDialogContext);

  if (!dialog) {
    throw new Error(
      "useAdminDialog() must be used inside <AdminDialogProvider>."
    );
  }

  return dialog;
}
