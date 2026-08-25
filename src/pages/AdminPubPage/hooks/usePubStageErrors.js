import { useState } from "react";

export default function usePubStageErrors() {
  const [errors, setErrorsState] =
    useState([]);

  function setErrors(nextErrors) {
    setErrorsState(
      Array.isArray(nextErrors)
        ? nextErrors
        : [nextErrors]
    );
  }

  function addError(error) {
    setErrorsState((current) => [
      ...current,
      error,
    ]);
  }

  function clearErrors() {
    setErrorsState([]);
  }

  const hasErrors =
    errors.length > 0;

  return {
    errors,
    hasErrors,
    setErrors,
    addError,
    clearErrors,
  };
}