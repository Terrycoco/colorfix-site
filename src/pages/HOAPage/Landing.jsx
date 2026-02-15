import React, { useEffect } from "react";
import "@components/CTA/cta.css";
import "./hoa.css";

export default function HOALandingPage() {
  useEffect(() => {
    window.scrollTo({ top: 0, left: 0, behavior: "instant" });
  }, []);

  return (
    <>

    <main className="hoa-page">
      <h1>HOA Exterior Color Playlists</h1>
        <p className="hoa-subhead">
          A visual system that shows approved exterior color schemes<br /> 
          on each home model — so homeowners choose confidently <br />
          and boards approve consistently.
        </p>
           <figure className="hoa-hero-image">
          <img
            src="/images/hoa-landing-hero.png"
            alt="Example HOA color scheme shown on a phone"
          />
          <figcaption>Scheme 3 — example homeowner view</figcaption>
        </figure>
      <header className="hoa-hero">
    
  


        <div className="hoa-hero-ctas">
          <a
            className="cta-button cta-button--primary"
            href="/picker?psi=2&aud=hoa"
          >
            See What Homeowners See
          </a>
        </div>



      </header>

      <section className="hoa-section">
        <h2>Why HOAs use this</h2>
        <ul>
          <li>Approved color lists don’t show how colors look on a real home</li>
          <li>Homeowners struggle before committing $7–10K</li>
          <li>Boards get forced into subjective decisions</li>
          <li>Rejections create delays and disputes</li>
        </ul>
      </section>

      <section className="hoa-section">
     <h2>How it works</h2>
<ol>
  <li>HOA provides its approved colors and schemes</li>
  <li>ColorFix renders those schemes on each HOA home model</li>
  <li>Homeowners select their model and tap through approved options</li>
  <li>Boards review the same visuals homeowners see</li>

               <div className="hoa-secondary-cta">
        <a className="cta-button cta-button--link" href="/hoa/explain">
          See what homeowners submit for approval
        </a>
      </div>
        </ol>
   
      </section>
 

      <section className="hoa-section hoa-split">
        <div>
          <h3>What this is</h3>
          <ul>
            <li>HOA-specific and private</li>
            <li>Built only from approved colors</li>
            <li>Designed to reduce disputes</li>
          </ul>
        </div>

        <div>
          <h3>What this is not</h3>
          <ul>
            <li>Not homeowner experimentation</li>
            <li>Not unlimited revisions</li>
            <li>Not AI generated</li>
          </ul>
        </div>
      </section>



<section className="hoa-section hoa-next-step">
  <p>
    If you’re responsible for exterior color approvals and would like
    more information, you can get in touch.
  </p>

  <a className="cta-button cta-button--primary" href="/hoa/contact">
    Contact for more information
  </a>
</section>

      <footer className="hoa-footer">
        © {new Date().getFullYear()} ColorFix
      </footer>
    </main>
      </>
  );
}