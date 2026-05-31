import AnimatedHueWheel from "@components/AnimatedHueWheel";

function AboutPage() {
  return (
    <main
      style={{
        minHeight: "calc(100vh - 96px)",
        display: "grid",
        placeItems: "center",
        gap: 32,
        padding: "48px 20px",
      }}
    >
      <AnimatedHueWheel
        animated={true}
        items={[
          { hue: 145, label: "Green", color: "#6F8F72", animate: false },
          { hue: 175, label: "Blue-Green", color: "#6F9A95", animate: false },
          { hue: 355, label: "Red", color: "#A6403A", animate: true },
        ]}
        caption="Opposite hues instantly attract attention."
      />

      <AnimatedHueWheel
        animated={false}
        items={[
          { hue: 180, label: "Cyan", color: "#168C94" },
          { hue: 10, label: "Red", color: "#A6403A" },
        ]}
        caption="Static mode renders wheel and spokes immediately."
      />
    </main>
  );
}

export default AboutPage;
