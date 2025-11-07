export default function ColorWheelIndicator({ hue = 0, radius = 200, center=200, strokeColor, dashed }) {


  // Convert hue to radians
  const angle = ((hue - 90) * Math.PI) / 180; // rotate -90° so 0° is at top

  // Calculate x, y coordinates
  const x = radius * Math.cos(angle);
  const y = radius * Math.sin(angle);

  return (
    <g id="indicator" transform={`translate(${center}, ${center})`}>
      <path
        id="current"
        d={`M0,0L${x.toFixed(3)},${y.toFixed(3)}`}
        stroke={strokeColor}
        strokeWidth={2}
        fill="none"
        strokeDasharray={(dashed ? "5,5" : 'none')}
      />
    </g>
  );
}

