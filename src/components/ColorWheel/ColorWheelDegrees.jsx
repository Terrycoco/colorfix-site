const toRadians = (deg) => ((deg - 90) * Math.PI) / 180;

const isDivisible = (value, step) => {
  if (!Number.isFinite(value) || !Number.isFinite(step) || step === 0) return false;
  return Math.abs(value / step - Math.round(value / step)) < 0.0001;
};

export default function ColorWheelDegrees({
  width = 300,
  step = 45,
  labelEvery = 90,
  radius,
  tickLength = 8,
  labelOffset = 14,
}) {
  const center = width / 2;
  const baseRadius = Number.isFinite(radius) ? radius : center;

  const ticks = [];
  const safeStep = Number(step) || 30;
  for (let deg = 0; deg < 360; deg += safeStep) {
    ticks.push(deg);
  }

  return (
    <g className="degree-layer" transform={`translate(${center}, ${center})`}>
      {ticks.map((deg) => {
        const angle = toRadians(deg);
        const cos = Math.cos(angle);
        const sin = Math.sin(angle);
        const x1 = (baseRadius - tickLength) * cos;
        const y1 = (baseRadius - tickLength) * sin;
        const x2 = (baseRadius + tickLength) * cos;
        const y2 = (baseRadius + tickLength) * sin;
        const labelR = baseRadius + tickLength + labelOffset;
        const showLabel = isDivisible(deg, labelEvery);

        return (
          <g key={`deg-${deg}`}>
            <line
              className={`degree-tick${showLabel ? " is-major" : ""}`}
              x1={x1}
              y1={y1}
              x2={x2}
              y2={y2}
            />
            {showLabel && (
              <text
                className="degree-label"
                x={labelR * cos}
                y={labelR * sin}
                textAnchor="middle"
                dominantBaseline="middle"
              >
                {deg}\u00B0
              </text>
            )}
          </g>
        );
      })}
    </g>
  );
}
