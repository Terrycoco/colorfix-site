export default function OppositeIndicator({ hue = 0, radius = 200, center = 200 }) {
  const angle = ((hue - 90) * Math.PI) / 180; // Rotate to put 0° at top

  const x = radius * Math.cos(angle);
  const y = radius * Math.sin(angle);

  return (
    <g id="opposite-indicator" transform={`translate(${center}, ${center})`}>
      <path
        d={`M0,0L${x.toFixed(3)},${y.toFixed(3)}`}
        stroke="gray"
        strokeWidth={2}
        strokeDasharray="5,5"
        fill="none"
      />
    </g>
  );
}
