import {
  useState,
} from "react";

export default function PubPipelineReference({
  contracts,
}) {
  if (!contracts) {
    return (
      <div style={pageStyle}>
        Loading PUB contracts…
      </div>
    );
  }

  /*
   * The contract itself owns the top-level reference order.
   *
   * Do NOT maintain a second hardcoded section/stage catalog here.
   * Any new top-level PubContract section should automatically appear
   * in this reference page in the same order PubContract::all() returns it.
   */
  const sections =
    Object.entries(
      contracts
    );

  const pipelineStages =
    sections
      .filter(
        ([, contract]) =>
          contract &&
          typeof contract === "object" &&
          !Array.isArray(contract) &&
          contract.stage
      )
      .map(
        ([sectionKey, contract]) =>
          humanize(
            contract?.stage ||
            sectionKey
          ).toUpperCase()
      );

  return (
    <div style={pageStyle}>
      {pipelineStages.length ? (
        <div style={pipelineStyle}>
          {pipelineStages.join(
            " → "
          )}
        </div>
      ) : null}

      {sections.map(
        ([
          sectionKey,
          contract,
        ]) => (
          <TopLevelContractSection
            key={sectionKey}
            sectionKey={sectionKey}
            contract={contract}
          />
        )
      )}
    </div>
  );
}


function TopLevelContractSection({
  sectionKey,
  contract,
}) {
  if (sectionKey === "defaults") {
    return (
      <DefaultsReference
        defaults={
          contract ||
          {}
        }
      />
    );
  }

  if (sectionKey === "marketRun") {
    return (
      <MarketRunReference
        marketRun={
          contract ||
          {}
        }
      />
    );
  }

  if (sectionKey === "shared") {
    return (
      <>
        <StateConventionReference
          convention={
            contract?.stateConvention ||
            {}
          }
        />

        <SharedBoxFields
          fields={
            contract?.boxFields ||
            []
          }
        />

        <GenericContractRemainder
          title="SHARED — OTHER CONTRACT DATA"
          contract={contract}
          omitKeys={[
            "stateConvention",
            "boxFields",
          ]}
        />
      </>
    );
  }

  if (sectionKey === "repositories") {
    return (
      <Repositories
        repositories={
          contract ||
          {}
        }
      />
    );
  }

  if (
    contract &&
    typeof contract === "object" &&
    !Array.isArray(contract) &&
    contract.stage
  ) {
    return (
      <StageSection
        title={
          humanize(
            contract?.stage ||
            sectionKey
          ).toUpperCase()
        }
        role={
          contract?.referenceRole
        }
      >
        <StageContractSummary
          contract={contract}
        />

        <StageStates
          contract={contract}
        />

        <ManagerContract
          manager={
            contract?.manager
          }
        />

        <AssetTypes
          assetTypes={
            contract?.assetTypes ||
            {}
          }
        />

        <GenericContractValue
          value={
            omitObjectKeys(
              contract,
              [
                "stage",
                "referenceRole",
                "nextStage",
                "states",
                "manager",
                "assetTypes",
              ]
            )
          }
        />
      </StageSection>
    );
  }

  /*
   * Future top-level contract sections need no React change.
   * Unknown sections fall back to the generic recursive renderer.
   */
  return (
    <GenericReferenceSection
      sectionKey={sectionKey}
      contract={contract}
    />
  );
}


function MarketRunReference({
  marketRun,
}) {
  const sourceTypes =
    marketRun?.sourceTypes ||
    {};

  const entries =
    Object.entries(
      sourceTypes
    );

  return (
    <AccordionSection
      title="MARKET RUN"
      role={
        marketRun?.referenceRole ||
        "Source Delivery"
      }
      sectionStyle={marketRunSectionStyle}
      headerStyle={marketRunHeaderStyle}
    >
      <div style={marketRunIntroStyle}>
        {marketRun?.label ||
          "Market Run"}
        {" — "}
        neutral source delivery before ANALYZE creates individual asset boxes.
        The fields below come directly from the PUB contract.
      </div>

      {entries.length ? (
        <div style={marketRunGridStyle}>
          {entries.map(
            ([
              sourceType,
              sourceContract,
            ]) => (
              <div
                key={sourceType}
                style={contractStyle}
              >
                <div style={contractHeaderStyle}>
                  {sourceContract?.label ||
                    humanize(
                      sourceType
                    )}
                </div>

                <div style={identityStyle}>
                  <div style={identityItemStyle}>
                    <span style={identityLabelStyle}>
                      Source Type
                    </span>

                    <code>
                      {sourceType}
                    </code>
                  </div>

                  {sourceContract?.preparer ? (
                    <div style={identityItemStyle}>
                      <span style={identityLabelStyle}>
                        Preparer
                      </span>

                      <code>
                        {sourceContract.preparer}
                      </code>
                    </div>
                  ) : null}
                </div>

                {sourceContract?.selectionRules ? (
                  <div style={specialistStyle}>
                    <div style={subHeaderStyle}>
                      SELECTION RULES
                    </div>

                    <KeyValueReference
                      value={
                        sourceContract.selectionRules
                      }
                    />
                  </div>
                ) : null}

                {sourceContract?.output?.length ? (
                  <div style={specialistStyle}>
                    <div style={subHeaderStyle}>
                      DELIVERY OUTPUT
                    </div>

                    <ContractFieldList
                      fields={
                        sourceContract.output
                      }
                    />
                  </div>
                ) : null}

                <GenericContractValue
                  value={
                    omitObjectKeys(
                      sourceContract,
                      [
                        "label",
                        "preparer",
                        "selectionRules",
                        "output",
                      ]
                    )
                  }
                />
              </div>
            )
          )}
        </div>
      ) : (
        <GenericContractValue
          value={
            omitObjectKeys(
              marketRun,
              [
                "label",
                "referenceRole",
                "sourceTypes",
              ]
            )
          }
        />
      )}

      <GenericContractValue
        value={
          omitObjectKeys(
            marketRun,
            [
              "label",
              "referenceRole",
              "sourceTypes",
            ]
          )
        }
      />
    </AccordionSection>
  );
}


function GenericReferenceSection({
  sectionKey,
  contract,
}) {
  const isObject =
    contract &&
    typeof contract === "object" &&
    !Array.isArray(contract);

  return (
    <AccordionSection
      title={
        humanize(
          sectionKey
        ).toUpperCase()
      }
      role={
        isObject
          ? contract?.referenceRole || ""
          : ""
      }
      sectionStyle={genericSectionStyle}
      headerStyle={genericHeaderStyle}
    >
      <GenericContractValue
        value={
          isObject
            ? omitObjectKeys(
                contract,
                [
                  "referenceRole",
                ]
              )
            : contract
        }
      />
    </AccordionSection>
  );
}


function GenericContractRemainder({
  title,
  contract,
  omitKeys = [],
}) {
  const remaining =
    omitObjectKeys(
      contract,
      omitKeys
    );

  if (
    !remaining ||
    !Object.keys(
      remaining
    ).length
  ) {
    return null;
  }

  return (
    <AccordionSection
      title={title}
      sectionStyle={genericSectionStyle}
      headerStyle={genericHeaderStyle}
    >
      <GenericContractValue
        value={remaining}
      />
    </AccordionSection>
  );
}


function GenericContractValue({
  value,
}) {
  if (
    value === null ||
    value === undefined
  ) {
    return null;
  }

  if (Array.isArray(value)) {
    if (!value.length) {
      return null;
    }

    const looksLikeFieldList =
      value.every(
        (item) =>
          item &&
          typeof item === "object" &&
          !Array.isArray(item) &&
          Object.prototype.hasOwnProperty.call(
            item,
            "key"
          )
      );

    if (looksLikeFieldList) {
      return (
        <div style={genericBodyStyle}>
          <ContractFieldList
            fields={value}
          />
        </div>
      );
    }

    return (
      <div style={genericBodyStyle}>
        {value.map(
          (item, index) => (
            <div
              key={index}
              style={genericValueRowStyle}
            >
              <GenericContractValue
                value={item}
              />
            </div>
          )
        )}
      </div>
    );
  }

  if (
    typeof value === "object"
  ) {
    const entries =
      Object.entries(
        value
      );

    if (!entries.length) {
      return null;
    }

    return (
      <div style={genericBodyStyle}>
        {entries.map(
          ([key, nestedValue]) => (
            <div
              key={key}
              style={genericObjectBlockStyle}
            >
              <div style={genericObjectKeyStyle}>
                {humanize(
                  key
                )}
                <span style={genericCodeKeyStyle}>
                  <code>
                    {key}
                  </code>
                </span>
              </div>

              <GenericContractValue
                value={nestedValue}
              />
            </div>
          )
        )}
      </div>
    );
  }

  return (
    <div style={genericPrimitiveStyle}>
      {formatContractValue(
        value
      )}
    </div>
  );
}


function KeyValueReference({
  value,
}) {
  if (
    !value ||
    typeof value !== "object" ||
    Array.isArray(value)
  ) {
    return null;
  }

  return (
    <div style={keyValueGridStyle}>
      {Object.entries(
        value
      ).map(
        ([key, nestedValue]) => (
          <div
            key={key}
            style={keyValueRowStyle}
          >
            <code>
              {key}
            </code>

            <span>
              {formatContractValue(
                nestedValue
              )}
            </span>
          </div>
        )
      )}
    </div>
  );
}


function omitObjectKeys(
  value,
  keys
) {
  if (
    !value ||
    typeof value !== "object" ||
    Array.isArray(value)
  ) {
    return {};
  }

  const omitted =
    new Set(
      keys
    );

  return Object.fromEntries(
    Object.entries(
      value
    ).filter(
      ([key]) =>
        !omitted.has(
          key
        )
    )
  );
}


function DefaultsReference({
  defaults,
}) {
  const assetTypes =
    defaults?.assetTypes ||
    {};

  const entries =
    Object.entries(
      assetTypes
    );

  if (!entries.length) {
    return null;
  }

  return (
    <AccordionSection
      title="DEFAULTS"
      sectionStyle={defaultsSectionStyle}
      headerStyle={defaultsHeaderStyle}
    >
      <div style={defaultsIntroStyle}>
        {defaults?.label ||
          "Default Pantry"}
        {" — "}
        raw ANALYZE/procurement fallbacks only.
        Creators receive the fully prepared ingredient,
        never the pantry reference.
      </div>

      <div style={defaultsGridStyle}>
        {entries.map(
          ([
            assetType,
            ingredients,
          ]) => (
            <div
              key={assetType}
              style={contractStyle}
            >
              <div style={contractHeaderStyle}>
                {humanize(
                  assetType
                )}
              </div>

              <div style={specialistStyle}>
                {Object.entries(
                  ingredients || {}
                ).map(
                  ([
                    ingredientKey,
                    value,
                  ]) => (
                    <div
                      key={ingredientKey}
                      style={defaultIngredientStyle}
                    >
                      <div style={defaultIngredientNameStyle}>
                        <code>
                          {ingredientKey}
                        </code>
                      </div>

                      <DefaultValue
                        value={value}
                      />
                    </div>
                  )
                )}
              </div>
            </div>
          )
        )}
      </div>
    </AccordionSection>
  );
}


function DefaultValue({
  value,
}) {
  if (
    value &&
    typeof value === "object" &&
    !Array.isArray(value)
  ) {
    return (
      <div style={defaultValueGridStyle}>
        {Object.entries(value).map(
          ([
            key,
            nestedValue,
          ]) => (
            <div
              key={key}
              style={defaultValueRowStyle}
            >
              <code>
                {key}
              </code>

              <span>
                {formatContractValue(
                  nestedValue
                )}
              </span>
            </div>
          )
        )}
      </div>
    );
  }

  return (
    <span>
      {formatContractValue(
        value
      )}
    </span>
  );
}


function Repositories({
  repositories,
}) {
  const entries =
    Object.entries(
      repositories
    );

  if (!entries.length) {
    return null;
  }

  return (
    <AccordionSection
      title="REPOSITORIES"
      sectionStyle={repositorySectionStyle}
      headerStyle={repositoryHeaderStyle}
    >
      <div style={repositoryIntroStyle}>
        Storage / file cabinets — not a pipeline stage
      </div>

      <div style={repositoryGridStyle}>
        {entries.map(
          ([
            repositoryKey,
            repository,
          ]) => (
            <RepositoryContract
              key={repositoryKey}
              repositoryKey={repositoryKey}
              repository={repository}
            />
          )
        )}
      </div>
    </AccordionSection>
  );
}


function RepositoryContract({
  repositoryKey,
  repository,
}) {
  const fields =
    repository?.fields ||
    [];

  return (
    <div style={contractStyle}>
      <div style={contractHeaderStyle}>
        {repository?.label ||
          humanize(
            repositoryKey
          )}
      </div>

      <table style={tableStyle}>
        <thead>
          <tr>
            <th style={thStyle}>
              Field
            </th>

            <th style={thStyle}>
              Type
            </th>

            <th style={thStyle}>
              Note
            </th>
          </tr>
        </thead>

        <tbody>
          {fields.map((field) => (
            <tr key={field.key}>
              <td style={tdNameStyle}>
                <code>
                  {field.key}
                </code>
              </td>

              <td style={tdStyle}>
                <code>
                  {field.type || "—"}
                </code>
              </td>

              <td style={tdStyle}>
                {field.note || ""}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}


function SharedBoxFields({
  fields,
}) {
  return (
    <AccordionSection
      title="PUB-WIDE BOX FIELDS"
      sectionStyle={stageStyle}
      headerStyle={stageHeaderStyle}
    >
      <div style={stageSummaryStyle}>
        <div style={referenceFieldStyle}>
          <div style={referenceLabelStyle}>
            Applies to
          </div>

          <div>
            Every PUB box, every output type,
            every stage
          </div>
        </div>
      </div>

      <FieldTable
        fields={fields}
      />
    </AccordionSection>
  );
}


function StateConventionReference({
  convention,
}) {
  const gerund =
    convention?.gerund ||
    null;

  const stable =
    convention?.stable ||
    null;

  const recoveryInvariant =
    convention?.recoveryInvariant ||
    "";

  if (
    !gerund &&
    !stable &&
    !recoveryInvariant
  ) {
    return null;
  }

  return (
    <AccordionSection
      title="PUB STATE CONVENTION"
      sectionStyle={stateConventionSectionStyle}
      headerStyle={stateConventionHeaderStyle}
    >
      <div style={stateConventionIntroStyle}>
        Pipeline state names are operational promises. A gerund means
        real work is actively responsible for advancing the row.
      </div>

      <div style={stateConventionGridStyle}>
        {gerund ? (
          <StateConventionCard
            title="GERUND"
            state={gerund}
          />
        ) : null}

        {stable ? (
          <StateConventionCard
            title="STABLE"
            state={stable}
          />
        ) : null}
      </div>

      {recoveryInvariant ? (
        <div style={recoveryInvariantStyle}>
          <strong>
            Recovery invariant
          </strong>

          <span>
            {recoveryInvariant}
          </span>
        </div>
      ) : null}
    </AccordionSection>
  );
}


function StateConventionCard({
  title,
  state,
}) {
  return (
    <div style={stateConventionCardStyle}>
      <div style={stateConventionCardTitleStyle}>
        {title}
      </div>

      <div style={stateConventionKindStyle}>
        <code>
          {state?.kind ||
            "—"}
        </code>
      </div>

      <div style={stateMeaningStyle}>
        {state?.meaning ||
          ""}
      </div>

      {state?.ui ? (
        <div style={stateUiStyle}>
          UI: {state.ui}
        </div>
      ) : null}
    </div>
  );
}


function StageStates({
  contract,
}) {
  const states =
    contract?.states ||
    {};

  const entries =
    Object.entries(
      states
    );


  if (!entries.length) {
    return null;
  }


  return (
    <div style={stageStatesStyle}>
      <div style={stageStatesHeaderStyle}>
        STATES:
      </div>

      {entries.map(
        ([
          stateKey,
          state,
        ]) => (
          <StateReferenceLine
            key={stateKey}

            human={
              humanize(
                stateKey
              ).toUpperCase()
            }

            code={
              stateKey
            }

            meaning={
              state?.meaning ||
              ""
            }
          />
        )
      )}
    </div>
  );
}


function StateReferenceLine({
  human,
  code,
  meaning,
}) {
  return (
    <div style={stateReferenceLineStyle}>
      <strong style={stateReferenceHumanStyle}>
        {human}
      </strong>

      <code style={stateReferenceCodeStyle}>
        ({code})
      </code>

      {meaning ? (
        <span style={stateReferenceMeaningStyle}>
          -- {meaning}
        </span>
      ) : null}
    </div>
  );
}


function AccordionSection({
  title,
  role = "",
  sectionStyle,
  headerStyle,
  children,
  defaultOpen = false,
}) {
  const [
    open,
    setOpen,
  ] = useState(
    defaultOpen
  );


  return (
    <section
      style={
        sectionStyle
      }
    >
      <button
        type="button"

        aria-expanded={
          open
        }

        onClick={() =>
          setOpen(
            (current) =>
              !current
          )
        }

        style={{
          ...headerStyle,
          width:
            "100%",
          border:
            0,
          textAlign:
            "left",
          cursor:
            "pointer",
        }}
      >
        <span
          style={
            accordionHeaderContentStyle
          }
        >
          <span
            style={
              accordionChevronStyle
            }
          >
            {open
              ? "▾"
              : "▸"}
          </span>

          <span>
            {title}
          </span>

          {role ? (
            <span
              style={
                stageRoleStyle
              }
            >
              — {role}
            </span>
          ) : null}
        </span>
      </button>

      {open
        ? children
        : null}
    </section>
  );
}


function StageSection({
  title,
  role,
  children,
}) {
  return (
    <AccordionSection
      title={title}
      role={role}
      sectionStyle={stageStyle}
      headerStyle={stageHeaderStyle}
    >
      {children}
    </AccordionSection>
  );
}


function StageContractSummary({
  contract,
}) {
  return (
    <div style={stageSummaryStyle}>
      <ReferenceField
        label="Stage"
        value={
          contract?.stage || "—"
        }
      />

      <ReferenceField
        label="Next Stage"
        value={
          contract?.nextStage || "—"
        }
      />
    </div>
  );
}


function ManagerContract({
  manager,
}) {
  if (!manager) {
    return null;
  }

  return (
    <div style={managerStyle}>
      <div style={managerHeaderStyle}>
        MANAGER
      </div>

      <div style={managerBodyStyle}>
        <ContractColumns>
          <ContractBlock
            title="INPUT"
            fields={manager?.input}
          />

          {manager?.outputToCreator && (
            <ContractBlock
              title="OUTPUT TO CREATOR"
              fields={manager.outputToCreator}
            />
          )}

          <ContractBlock
            title="OUTPUT"
            fields={manager?.output}
          />
        </ContractColumns>
      </div>
    </div>
  );
}


function AssetTypes({
  assetTypes,
}) {
  const entries =
    Object.entries(
      assetTypes
    );

  if (!entries.length) {
    return (
      <div style={pendingStyle}>
        <span style={pendingTextStyle}>
          No output contracts defined yet
        </span>
      </div>
    );
  }

  return (
    <div style={assetGridStyle}>
      {entries.map(
        ([
          assetType,
          contract,
        ]) => (
          <AssetContract
            key={
              assetType
            }

            assetType={
              assetType
            }

            contract={
              contract
            }
          />
        )
      )}
    </div>
  );
}


function AssetContract({
  assetType,
  contract,
}) {
  const specialist =
    contract?.analyzer ||
    contract?.creator ||
    contract?.packager ||
    contract?.shipper ||
    null;

  const specialistLabel =
    contract?.analyzer
      ? "ANALYZER"
      : contract?.creator
      ? "CREATOR"
      : contract?.packager
      ? "PACKAGER"
      : contract?.shipper
      ? "SHIPPER"
      : null;

  return (
    <div style={contractStyle}>
      <div style={contractHeaderStyle}>
        {contract?.label ||
          humanize(
            assetType
          )}
      </div>

      <div style={identityStyle}>
        <div style={identityItemStyle}>
          <span style={identityLabelStyle}>
            Output Type
          </span>

          <code>
            {assetType}
          </code>
        </div>

        <div style={identityItemStyle}>
          <span style={identityLabelStyle}>
            Dispatch Channel
          </span>

          <code>
            {contract?.channel ||
              "—"}
          </code>
        </div>

        {contract?.createsAssetType && (
          <div style={identityItemStyle}>
            <span style={identityLabelStyle}>
              Creates Asset
            </span>

            <code>
              {contract.createsAssetType}
            </code>
          </div>
        )}
      </div>

      {specialist ? (
        <div style={specialistStyle}>
          <div style={subHeaderStyle}>
            {specialistLabel}
          </div>

          <ContractColumns>
            <ContractBlock
              title="INPUT"
              fields={specialist?.input}
            />

            {specialist?.requires && (
              <ContractBlock
                title="REQUIRES"
                fields={specialist.requires}
              />
            )}

            <ContractBlock
              title={
                specialistLabel === "PACKAGER"
                  ? "OUTPUT IN PACKAGE"
                  : "OUTPUT"
              }
              fields={specialist?.output}
            />
          </ContractColumns>
        </div>
      ) : (
        <div style={pendingStyle}>
          <span style={pendingTextStyle}>
            Specialist contract not migrated
          </span>
        </div>
      )}

    </div>
  );
}


function ContractColumns({
  children,
}) {
  return (
    <div style={contractColumnsStyle}>
      {children}
    </div>
  );
}


function ContractBlock({
  title,
  fields,
}) {
  if (!fields?.length) {
    return null;
  }

  return (
    <div style={contractBlockStyle}>
      <div style={contractBlockTitleStyle}>
        {title}
      </div>

      <ContractFieldList
        fields={fields}
      />
    </div>
  );
}


function ContractFieldList({
  fields,
}) {
  return (
    <div>
      {fields.map((field, index) => (
        <div
          key={`${field?.key || "field"}-${index}`}
          style={contractFieldRowStyle}
        >
          <div style={contractFieldMainStyle}>
            <code>
              {field?.key || "—"}
            </code>

            {field?.type && (
              <span style={typeStyle}>
                {field.type}
              </span>
            )}

            {field?.required === true && (
              <span style={requiredStyle}>
                required
              </span>
            )}

            {field?.defaultFrom && (
              <span style={defaultFromStyle}>
                default: {field.defaultFrom}
              </span>
            )}

            {field?.requiresAny?.length ? (
              <span style={requiresAnyStyle}>
                requires any: {field.requiresAny.join(", ")}
              </span>
            ) : null}

            {field?.note && (
              <span style={noteStyle}>
                {field.note}
              </span>
            )}
          </div>

          {field?.fields?.length ? (
            <div style={nestedFieldsStyle}>
              <ContractFieldList
                fields={field.fields}
              />
            </div>
          ) : null}

          {field?.itemTypes &&
          typeof field.itemTypes === "object" ? (
            <ItemTypeRules
              itemTypes={
                field.itemTypes
              }
            />
          ) : null}
        </div>
      ))}
    </div>
  );
}


function ItemTypeRules({
  itemTypes,
}) {
  return (
    <div style={itemTypesStyle}>
      <div style={itemTypesHeaderStyle}>
        ITEM-TYPE RULES
      </div>

      {Object.entries(
        itemTypes
      ).map(
        ([
          itemType,
          rule,
        ]) => (
          <div
            key={itemType}
            style={itemTypeRuleStyle}
          >
            <div style={itemTypeIdentityStyle}>
              <code>
                {itemType}
              </code>

              {rule?.photo && (
                <span style={typeStyle}>
                  photo: {rule.photo}
                </span>
              )}

              {rule?.requiresAny?.length ? (
                <span style={requiresAnyStyle}>
                  requires any: {rule.requiresAny.join(", ")}
                </span>
              ) : null}
            </div>

            {rule?.fields?.length ? (
              <div style={nestedFieldsStyle}>
                <ContractFieldList
                  fields={rule.fields}
                />
              </div>
            ) : null}

            {rule?.note ? (
              <div style={itemTypeNoteStyle}>
                {rule.note}
              </div>
            ) : null}
          </div>
        )
      )}
    </div>
  );
}


function SimpleKeyList({
  values,
}) {
  return (
    <div>
      {values.map((value) => (
        <div
          key={value}
          style={simpleKeyRowStyle}
        >
          <code>{value}</code>
        </div>
      ))}
    </div>
  );
}


function FieldTable({
  fields,
}) {
  if (!fields.length) {
    return null;
  }

  return (
    <table style={tableStyle}>
      <thead>
        <tr>
          <th style={thStyle}>
            Field
          </th>

          <th style={thStyle}>
            Type
          </th>

          <th style={thStyle}>
            Scope
          </th>

          <th style={thStyle}>
            Required
          </th>

          <th style={thStyle}>
            Supplied By
          </th>
        </tr>
      </thead>

      <tbody>
        {fields.map(
          (field) => (
            <tr key={field.key}>
              <td style={tdNameStyle}>
                {field.label ||
                  field.key}
              </td>

              <td style={tdStyle}>
                <code>
                  {field.type ||
                    "—"}
                </code>
              </td>

              <td style={tdStyle}>
                {field.scope ||
                  "—"}
              </td>

              <td style={tdStyle}>
                {field.requiredAtHandoff
                  ? "Yes"
                  : "No"}
              </td>

              <td style={tdStyle}>
                {field.systemSupplied
                  ? "System"
                  : field
                      ?.helper
                      ?.label
                  ? field
                      .helper
                      .label
                  : "Operator / stage"}
              </td>
            </tr>
          )
        )}
      </tbody>
    </table>
  );
}


function ReferenceField({
  label,
  value,
}) {
  return (
    <div style={referenceFieldStyle}>
      <div style={referenceLabelStyle}>
        {label}
      </div>

      <div>
        {value}
      </div>
    </div>
  );
}


function formatContractValue(
  value
) {
  if (value === null) {
    return "null";
  }

  if (value === true) {
    return "true";
  }

  if (value === false) {
    return "false";
  }

  if (
    typeof value === "object"
  ) {
    return JSON.stringify(
      value
    );
  }

  return String(
    value ?? ""
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
      (character) =>
        character.toUpperCase()
    );
}


const marketRunSectionStyle = {
  marginBottom: 24,
  border: "1px solid #9fb6aa",
  background: "#fbfdfc",
};


const marketRunHeaderStyle = {
  padding: "8px 10px",
  background: "#355f4a",
  color: "#ffffff",
  fontSize: 15,
  fontWeight: 700,
};


const marketRunIntroStyle = {
  padding: "9px 10px",
  borderBottom: "1px solid #d8e3dc",
  color: "#425c4e",
  fontSize: 12,
};


const marketRunGridStyle = {
  display: "grid",
  gridTemplateColumns:
    "repeat(auto-fit, minmax(360px, 1fr))",
  gap: 10,
  padding: 10,
};


const genericSectionStyle = {
  marginBottom: 24,
  border: "1px solid #d8dde3",
  background: "#ffffff",
};


const genericHeaderStyle = {
  padding: "8px 10px",
  background: "#526273",
  color: "#ffffff",
  fontSize: 15,
  fontWeight: 700,
};


const genericBodyStyle = {
  display: "grid",
  gap: 7,
  padding: 9,
};


const genericObjectBlockStyle = {
  border: "1px solid #e1e6eb",
  background: "#ffffff",
};


const genericObjectKeyStyle = {
  display: "flex",
  alignItems: "baseline",
  gap: 7,
  padding: "5px 7px",
  background: "#f7f8fa",
  borderBottom: "1px solid #e1e6eb",
  fontSize: 11,
  fontWeight: 800,
  color: "#4b6b8a",
};


const genericCodeKeyStyle = {
  fontSize: 9,
  fontWeight: 400,
  color: "#6b7280",
};


const genericValueRowStyle = {
  borderBottom: "1px solid #eef1f4",
};


const genericPrimitiveStyle = {
  padding: "5px 7px",
  fontSize: 12,
};


const keyValueGridStyle = {
  display: "grid",
  gap: 3,
};


const keyValueRowStyle = {
  display: "grid",
  gridTemplateColumns: "minmax(180px, auto) 1fr",
  gap: 10,
  padding: "4px 6px",
  borderBottom: "1px solid #eef1f4",
  fontSize: 11,
};


const pageStyle = {
  padding: "18px 24px 40px",
  overflow: "auto",
  color: "#1f2933",
  fontSize: 13,
};


const pipelineStyle = {
  marginBottom: 16,
  padding: "8px 10px",
  background: "#f7f8fa",
  border: "1px solid #d8dde3",
  fontSize: 12,
  fontWeight: 700,
  letterSpacing: "0.03em",
};


const defaultsSectionStyle = {
  marginBottom: 24,
  border: "1px solid #d5c48b",
  background: "#fffdf6",
};


const defaultsHeaderStyle = {
  padding: "8px 10px",
  background: "#725918",
  color: "#ffffff",
  fontSize: 15,
  fontWeight: 700,
};


const defaultsIntroStyle = {
  padding: "9px 10px",
  borderBottom: "1px solid #e5dcc0",
  color: "#665522",
  fontSize: 12,
};


const defaultsGridStyle = {
  display: "grid",
  gridTemplateColumns:
    "repeat(auto-fit, minmax(300px, 1fr))",
  gap: 10,
  padding: 10,
};


const defaultIngredientStyle = {
  padding: "7px 8px",
  border: "1px solid #eee4c5",
  background: "#ffffff",
};


const defaultIngredientNameStyle = {
  marginBottom: 5,
  fontWeight: 800,
};


const defaultValueGridStyle = {
  display: "grid",
  gap: 3,
};


const defaultValueRowStyle = {
  display: "grid",
  gridTemplateColumns: "minmax(140px, auto) 1fr",
  gap: 10,
  fontSize: 12,
};


const defaultFromStyle = {
  fontSize: 10,
  fontWeight: 700,
  color: "#725918",
};


const requiresAnyStyle = {
  fontSize: 10,
  fontWeight: 700,
  color: "#38577a",
};


const itemTypesStyle = {
  marginTop: 7,
  marginLeft: 12,
  borderLeft: "3px solid #c7d5e5",
  background: "#fbfcfe",
};


const itemTypesHeaderStyle = {
  padding: "5px 7px",
  fontSize: 9,
  fontWeight: 900,
  color: "#4b6b8a",
  letterSpacing: "0.08em",
};


const itemTypeRuleStyle = {
  padding: "6px 7px",
  borderTop: "1px solid #e5ebf1",
};


const itemTypeIdentityStyle = {
  display: "flex",
  flexWrap: "wrap",
  gap: 7,
  alignItems: "baseline",
  fontWeight: 700,
};


const itemTypeNoteStyle = {
  marginTop: 5,
  fontSize: 10,
  color: "#6b7280",
};


const stateConventionSectionStyle = {
  marginBottom: 24,
  border: "1px solid #b7c3cf",
  background: "#fbfcfd",
};


const stateConventionHeaderStyle = {
  padding: "8px 10px",
  background: "#465b70",
  color: "#ffffff",
  fontSize: 15,
  fontWeight: 700,
};


const stateConventionIntroStyle = {
  padding: "9px 10px",
  borderBottom: "1px solid #d8dde3",
  color: "#526273",
  fontSize: 12,
};


const stateConventionGridStyle = {
  display: "grid",
  gridTemplateColumns:
    "repeat(auto-fit, minmax(280px, 1fr))",
  gap: 10,
  padding: 10,
};


const stateConventionCardStyle = {
  border: "1px solid #d8dde3",
  background: "#ffffff",
  padding: 10,
};


const stateConventionCardTitleStyle = {
  marginBottom: 5,
  fontSize: 11,
  fontWeight: 900,
  letterSpacing: "0.06em",
  color: "#1c325e",
};


const stateConventionKindStyle = {
  marginBottom: 6,
  fontSize: 11,
};


const stateMeaningStyle = {
  fontSize: 12,
  lineHeight: 1.45,
};


const stateUiStyle = {
  marginTop: 6,
  color: "#586675",
  fontSize: 11,
};


const recoveryInvariantStyle = {
  display: "grid",
  gap: 4,
  margin: "0 10px 10px",
  padding: "9px 10px",
  border: "1px solid #d8dde3",
  background: "#f7f8fa",
  fontSize: 12,
  lineHeight: 1.45,
};


const stageStatesStyle = {
  padding: "8px 10px",
  borderBottom: "1px solid #d8dde3",
  background: "#ffffff",
};


const stageStatesHeaderStyle = {
  marginBottom: 4,
  fontSize: 10,
  fontWeight: 900,
  color: "#4b6b8a",
  letterSpacing: "0.08em",
};


const stateReferenceLineStyle = {
  display: "flex",
  alignItems: "baseline",
  flexWrap: "wrap",
  gap: 6,
  padding: "2px 0",
  lineHeight: 1.35,
};


const stateReferenceHumanStyle = {
  fontSize: 11,
  fontWeight: 900,
  color: "#1c325e",
  letterSpacing: "0.03em",
};


const stateReferenceCodeStyle = {
  fontSize: 10,
  color: "#6b7280",
};


const stateReferenceMeaningStyle = {
  fontSize: 11,
  color: "#526273",
};


const stageStyle = {
  marginBottom: 24,
  border: "1px solid #d8dde3",
};


const stageHeaderStyle = {
  display: "flex",
  alignItems: "baseline",
  gap: 8,
  padding: "8px 10px",
  background: "#0f3051",
  color: "#ffffff",
  fontSize: 15,
  fontWeight: 700,
};


const accordionHeaderContentStyle = {
  display:
    "flex",

  alignItems:
    "baseline",

  gap:
    8,
};


const accordionChevronStyle = {
  width:
    14,

  flex:
    "0 0 14px",

  fontSize:
    13,

  lineHeight:
    1,
};


const stageRoleStyle = {
  fontSize: 12,
  fontWeight: 500,
  opacity: 0.88,
};


const stageSummaryStyle = {
  padding: "10px",
  borderBottom: "1px solid #e9edf1",
};


const managerStyle = {
  borderBottom: "1px solid #d8dde3",
  background: "#fbfcfd",
};


const managerHeaderStyle = {
  width: "100%",
  boxSizing: "border-box",
  padding: "10px 12px 8px",
  borderBottom: "3px solid #4b6b8a",
  fontSize: 14,
  fontWeight: 900,
  color: "#1c325e",
  letterSpacing: "0.08em",
};


const managerBodyStyle = {
  padding: 10,
};


const specialistStyle = {
  padding: 9,
};


const boxStyle = {
  borderTop: "1px solid #e9edf1",
  padding: 9,
};


const subHeaderStyle = {
  marginBottom: 7,
  fontSize: 10,
  fontWeight: 800,
  color: "#4b6b8a",
  letterSpacing: "0.08em",
};


const contractColumnsStyle = {
  display: "grid",
  gridTemplateColumns:
    "repeat(auto-fit, minmax(180px, 1fr))",
  gap: 8,
};


const contractBlockStyle = {
  minWidth: 0,
  border: "1px solid #e1e6eb",
};


const contractBlockTitleStyle = {
  padding: "5px 7px",
  background: "#f7f8fa",
  borderBottom: "1px solid #e1e6eb",
  fontSize: 10,
  fontWeight: 800,
  color: "#4b6b8a",
};


const contractFieldRowStyle = {
  padding: "5px 7px",
  borderBottom: "1px solid #eef1f4",
};


const contractFieldMainStyle = {
  display: "flex",
  flexWrap: "wrap",
  gap: 6,
  alignItems: "baseline",
};


const nestedFieldsStyle = {
  marginTop: 5,
  marginLeft: 12,
  borderLeft: "2px solid #e1e6eb",
};


const typeStyle = {
  fontSize: 10,
  color: "#6b7280",
};


const requiredStyle = {
  fontSize: 10,
  fontWeight: 700,
  color: "#7a2e2e",
};


const noteStyle = {
  fontSize: 10,
  color: "#6b7280",
};


const simpleKeyRowStyle = {
  padding: "4px 7px",
  borderBottom: "1px solid #eef1f4",
};


const referenceFieldStyle = {
  display: "grid",
  gridTemplateColumns: "110px 1fr",
  gap: 10,
  padding: "3px 0",
  lineHeight: 1.45,
};


const referenceLabelStyle = {
  fontWeight: 700,
  color: "#4b6b8a",
};


const assetGridStyle = {
  display: "grid",
  gridTemplateColumns:
    "repeat(auto-fit, minmax(300px, 1fr))",
  gap: 10,
  padding: 10,
};


const contractStyle = {
  minWidth: 0,
  border: "1px solid #d8dde3",
};


const contractHeaderStyle = {
  padding: "7px 9px",
  fontSize: 13,
  borderBottom: "3px solid #16549f",
  fontWeight: 700,
};


const identityStyle = {
  display: "flex",
  flexWrap: "wrap",
  gap: "6px 20px",
  padding: "8px 9px",
  borderBottom: "1px solid #e9edf1",
};


const identityItemStyle = {
  display: "flex",
  gap: 6,
  alignItems: "baseline",
};


const identityLabelStyle = {
  fontSize: 11,
  fontWeight: 700,
  color: "#1c325e",
};


const tableStyle = {
  width: "100%",
  borderCollapse: "collapse",
};


const thStyle = {
  padding: "6px 8px",
  textAlign: "left",
  background: "#f7f8fa",
  borderBottom: "1px solid #d8dde3",
  fontSize: 10,
  color: "#4b6b8a",
  textTransform: "uppercase",
  letterSpacing: "0.06em",
};


const tdStyle = {
  padding: "6px 8px",
  borderBottom: "1px solid #e9edf1",
  verticalAlign: "top",
};


const tdNameStyle = {
  ...tdStyle,
  width: "28%",
  fontWeight: 600,
};


const pendingStyle = {
  margin: 10,
  padding: "8px 9px",
  border: "1px solid #e9edf1",
};


const pendingTextStyle = {
  color: "#6b7280",
  fontSize: 12,
};


const repositorySectionStyle = {
  marginTop: 32,
  marginBottom: 24,
  border: "1px solid #cfd6dd",
  background: "#fbfcfd",
};


const repositoryHeaderStyle = {
  padding: "8px 10px",
  background: "#374151",
  color: "#ffffff",
  fontSize: 15,
  fontWeight: 700,
};


const repositoryIntroStyle = {
  padding: "8px 10px",
  borderBottom: "1px solid #d8dde3",
  color: "#6b7280",
  fontSize: 12,
};


const repositoryGridStyle = {
  display: "grid",
  gridTemplateColumns:
    "repeat(auto-fit, minmax(360px, 1fr))",
  gap: 10,
  padding: 10,
};