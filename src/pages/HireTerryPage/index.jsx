import "./hire-terry.css";

export default function HireTerryPage() {
  return (
    <div className="hire-terry-page">
      <section className="hire-terry-section hire-terry-hero">
        <div className="hire-terry-hero-images">
          <div className="hire-terry-hero-image">
            <img
              src="/photos/uploads/saved-palettes/24/sp_24_96d4a31c043e.jpg"
              alt="Room before makeover"
            />
            <span className="hire-terry-hero-label">Before</span>
          </div>

          <div className="hire-terry-hero-image">
            <img
              src="/photos/uploads/saved-palettes/24/sp_24_10a4e51ffe34.jpg"
              alt="Room after makeover"
            />
            <span className="hire-terry-hero-label">After</span>
          </div>
        </div>

        <h1>Your Personal Makeover Playlist</h1>

        <p className="hire-terry-hero-intro">
          Terry creates a custom Makeover Playlist showing which colors to use,
          where they should go, and how to create focal points with color,
          contrast, and lighting.
        </p>

        <p>
          Terry brings years of experience helping real homeowners solve color
          challenges — deciding which colors work, where they belong, and how
          contrast and focal points shape the space.
        </p>

      </section>

      <section className="hire-terry-section hire-terry-steps">
        <h2>How It Works</h2>

        <div className="hire-terry-steps__grid">
          <article className="hire-terry-step">
            <div className="hire-terry-step__number">1</div>
            <p>
              Decide which rooms or exterior areas you'd like Terry to include
              in your Makeover Playlist.
            </p>
          </article>

          <article className="hire-terry-step">
            <div className="hire-terry-step__number">2</div>
            <p>
              Share a few details about your goals, your style, and any colors
              you love or want to avoid. After reviewing your submission, Terry
              may contact you for clarification about your space before
              beginning the design.
            </p>
          </article>

          <article className="hire-terry-step">
            <div className="hire-terry-step__number">3</div>
            <p>
              Receive your custom Makeover Playlist with color ideas, placement
              guidance, and visual previews for your space.
            </p>
          </article>
        </div>
      </section>

      <section className="hire-terry-section hire-terry-includes">
        <h2>What You Receive</h2>

        <ul className="hire-terry-list">
          <li>A custom Makeover Playlist for your space</li>
          <li>Expert color recommendations from Terry</li>
          <li>Guidance on where each color should go</li>
          <li>Direction on focal points, contrast, and lighting</li>
          <li>A palette list showing the exact paint colors used</li>
          <li>Visual previews you can revisit and share anytime</li>
        </ul>
      </section>

<section className="hire-terry-section hire-terry-pricing">
  <h2>Investment</h2>

  <article className="hire-terry-price-card hire-terry-price-card--single">
    <h3>Custom Makeover Playlist</h3>
    <div className="hire-terry-price-card__price">$75</div>
    <p>Per rendered image.</p>
    <p>
    Each rendering adds a new before-and-after transformation to your Makeover Playlist.
    </p>
    <p className="hire-terry-pricing-note">
      Start with one room. You can always add more spaces later.
    </p>
  </article>
</section>

      <section className="hire-terry-section hire-terry-expertise">
        <h2>Why This Is Different</h2>

        <p>
          Terry evaluates your actual space — lighting, materials,
          architecture, and visual balance — to determine which colors
          belong where and how contrast should shape the room.
        </p>

        <p>
          Each Makeover Playlist reflects Terry&apos;s professional color
          recommendation for the space.
        </p>
      </section>

      <section className="hire-terry-section hire-terry-final-cta">
        <a className="primary-cta" href="/hire-terry/request-playlist">
          Get Your Custom Playlist
        </a>
      </section>
    </div>
  );
}
