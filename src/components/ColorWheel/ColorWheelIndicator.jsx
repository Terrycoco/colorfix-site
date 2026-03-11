export default function ColorWheelIndicator({
  hue = 0,
  radius = 200,
  center = 200,
  strokeColor,
  dashed,
  startRadius = 0,
  endRadius
}) {


  // Convert hue to radians
  const angle = ((hue - 90) * Math.PI) / 180; // rotate -90° so 0° is at top

  // Calculate x, y coordinates
  const endR = Number.isFinite(endRadius) ? endRadius : radius;
  const x1 = startRadius * Math.cos(angle);
  const y1 = startRadius * Math.sin(angle);
  const x2 = endR * Math.cos(angle);
  const y2 = endR * Math.sin(angle);

  return (
    <g id="indicator" transform={`translate(${center}, ${center})`}>
      <path
        id="current"
        d={`M${x1.toFixed(3)},${y1.toFixed(3)}L${x2.toFixed(3)},${y2.toFixed(3)}`}
        stroke={strokeColor}
        strokeWidth={2}
        fill="none"
        strokeDasharray={(dashed ? "5,5" : 'none')}
      />
    </g>
  );
}
