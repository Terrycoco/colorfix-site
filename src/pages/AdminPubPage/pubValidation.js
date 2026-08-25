export function validatePubHandoff({
  stage,
  contract,
  values = {},
  boxes = [],
}) {
  const errors = [];

  if (!contract) {
    errors.push({
      stage,
      code: "missing_contract",
      message:
        "No handoff contract is defined for this stage.",
    });

    return {
      ok: false,
      errors,
    };
  }

  // ---------------------------------
  // Shared / batch fields
  // ---------------------------------

  for (
    const field of
    contract.sharedFields || []
  ) {
    if (!field.requiredAtHandoff) {
      continue;
    }

    const value =
      values[field.key];

    if (isEmpty(value)) {
      errors.push({
        stage,
        code: "missing_shared_field",
        field: field.key,
        message:
          `${field.label} is required.`,
      });
    }
  }

  // ---------------------------------
  // Individual production boxes
  // ---------------------------------

  for (const box of boxes) {
    const assetType =
      box.asset_type ||
      box.assetType ||
      box.pin_type ||
      box.pinType;

    if (!assetType) {
      errors.push({
        stage,
        code: "missing_asset_type",
        boxKey: getBoxKey(box),
        message:
          "A production box is missing its asset type.",
      });

      continue;
    }

    const assetContract =
      contract.assetTypes?.[
        assetType
      ];

    if (!assetContract) {
      errors.push({
        stage,
        code: "unknown_asset_type",
        boxKey: getBoxKey(box),
        assetType,
        message:
          `No contract is defined for ${assetType}.`,
      });

      continue;
    }

    for (
      const ingredient of
      assetContract.requiredIngredients ||
      []
    ) {
      /*
       * A shared field can satisfy an
       * ingredient requirement.
       *
       * Example:
       * pingback exists once at batch
       * level while ANALYZE is working,
       * then gets stamped into each box
       * when the handoff is sealed.
       */

      const value =
        box[ingredient] ??
        values[ingredient];

      if (isEmpty(value)) {
        errors.push({
          stage,
          code: "missing_ingredient",
          boxKey: getBoxKey(box),
          assetType,
          field: ingredient,
          message:
            `${getBoxLabel(box, assetContract)} is missing ${humanize(
              ingredient
            )}.`,
        });
      }
    }
  }

  return {
    ok: errors.length === 0,
    errors,
  };
}


function isEmpty(value) {
  if (
    value === null ||
    value === undefined
  ) {
    return true;
  }

  if (
    typeof value === "string"
  ) {
    return !value.trim();
  }

  if (Array.isArray(value)) {
    return value.length === 0;
  }

  return false;
}


function getBoxKey(box) {
  return (
    box.proposal_key ||
    box.proposalKey ||
    box.id ||
    null
  );
}


function getBoxLabel(
  box,
  assetContract
) {
  return (
    box.label ||
    assetContract?.label ||
    box.asset_type ||
    box.assetType ||
    box.pin_type ||
    box.pinType ||
    "Production box"
  );
}


function humanize(value) {
  return String(value || "")
    .replace(/[_-]+/g, " ")
    .replace(
      /\b\w/g,
      (character) =>
        character.toUpperCase()
    );
}