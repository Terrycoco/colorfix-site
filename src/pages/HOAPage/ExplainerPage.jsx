import { SUBMISSION_HTML } from "./submissionExample";
import "./explainer.css";
import { useNavigate } from "react-router-dom";
import CTASection from "@components/CTASection";

export default function ExplainerPage() {
  const navigate = useNavigate();
  const handleCtaClick = (cta) => {
    if (cta?.key === "contact") {
      navigate("/hoa/contact");
      return;
    }
    if (cta?.key === "back_overview") {
      navigate("/hoa");
    }
  };
  return (
    <main className="hoa-explainer">

      <header className="hoa-explainer-header">
        <h1>What an HOA Receives for Review</h1>
        <p>
          After a homeowner selects an exterior color scheme from the approved
          visual playlist, this is the information that is submitted for HOA
          review.
        </p>
      </header>

      <section className="hoa-explainer-context">
        <p>
          Submissions are tied to a specific home model and an existing approved
          scheme. Homeowners do not enter custom color combinations.
        </p>
        <p>
          This helps boards and property managers review requests consistently
          without interpreting swatches or written descriptions.
        </p>
      </section>

      <section
        className="hoa-explainer-artifact"
        dangerouslySetInnerHTML={{ __html: SUBMISSION_HTML }}
      />

      <section className="hoa-explainer-followup">
        <p>
          Because each submission references an existing approved scheme, reviews
          can focus on confirmation rather than interpretation.
        </p>
        <p>
          Additional examples can include revision requests or alternate schemes
          when required by HOA guidelines.
        </p>
        <CTASection
          className="hoa-explainer-cta cta-section--transparent cta-section--left cta-section--desktop-row-split"
          ctas={[
            {
              cta_id: "hoa-explain-contact",
              label: "Contact Terry for more information",
              key: "contact",
              variant: "primary",
              enabled: true,
              params: {},
            },
            {
              cta_id: "hoa-explain-back",
              label: "Back to overview",
              key: "back_overview",
              variant: "link",
              enabled: true,
              params: {},
            },
          ]}
          onCtaClick={handleCtaClick}
        />
      </section>

    </main>
  );
}
