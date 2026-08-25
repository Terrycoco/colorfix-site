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

  const sharedBoxFields =
    contracts?.shared?.boxFields || [];

  const stages = [
    "analyze",
    "create",
    "package",
    "schedule",
    "dispatch",
  ];

  return (
    <div style={pageStyle}>
      <div style={pipelineStyle}>
        ANALYZE → CREATE → PACKAGE → SCHEDULE → DISPATCH
      </div>

      <SharedBoxFields
        fields={sharedBoxFields}
      />

      {stages.map((stageKey) => {
        const stage =
          contracts?.[stageKey] || {};

        return (
          <StageSection
            key={stageKey}
            title={stageKey.toUpperCase()}
          >
            <StageContractSummary
              contract={stage}
            />

            <ManagerContract
              manager={stage?.manager}
            />

            <AssetTypes
              assetTypes={
                stage?.assetTypes ||
                {}
              }
            />
          </StageSection>
        );
      })}

      <Repositories
        repositories={
          contracts?.repositories ||
          {}
        }
      />
    </div>
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
    <section style={repositorySectionStyle}>
      <div style={repositoryHeaderStyle}>
        REPOSITORIES
      </div>

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
    </section>
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
    <section style={stageStyle}>
      <div style={stageHeaderStyle}>
        PUB-WIDE BOX FIELDS
      </div>

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
    </section>
  );
}


function StageSection({
  title,
  children,
}) {
  return (
    <section style={stageStyle}>
      <div style={stageHeaderStyle}>
        {title}
      </div>

      {children}
    </section>
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
      <div style={subHeaderStyle}>
        MANAGER
      </div>

      <ContractColumns>
        <ContractBlock
          title="INPUT"
          fields={manager?.input}
        />

        <ContractBlock
          title="OUTPUT"
          fields={manager?.output}
        />

        {manager?.outputToCreator && (
          <ContractBlock
            title="OUTPUT TO CREATOR"
            fields={manager.outputToCreator}
          />
        )}

      </ContractColumns>
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
    null;

  const specialistLabel =
    contract?.analyzer
      ? "ANALYZER"
      : contract?.creator
      ? "CREATOR"
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
              title="OUTPUT"
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
        </div>
      ))}
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


const stageStyle = {
  marginBottom: 24,
  border: "1px solid #d8dde3",
};


const stageHeaderStyle = {
  padding: "8px 10px",
  background: "#0f3051",
  color: "#ffffff",
  fontSize: 15,
  fontWeight: 700,
};


const stageSummaryStyle = {
  padding: "10px",
  borderBottom: "1px solid #e9edf1",
};


const managerStyle = {
  padding: 10,
  borderBottom: "1px solid #d8dde3",
  background: "#fbfcfd",
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