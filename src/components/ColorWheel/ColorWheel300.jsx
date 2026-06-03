import { useEffect, useMemo, useState } from 'react';
import ColorWheelIndicator from './ColorWheelIndicator';
import LabelArcs from './LabelArcs';
import './wheel.css';

const BASES = {
  labels: {
    src: '/wheels/wheel-300-labels.svg',
    radiusRatio: 142.5 / 420,
  },
  'labels-degrees': {
    src: '/wheels/wheel-300-labels-degrees.svg',
    radiusRatio: 142.5 / 420,
  },
  'labels-420': {
    src: '/wheels/wheel-420-labels.svg',
    radiusRatio: 199.5 / 540,
  },
  'labels-degrees-420': {
    src: '/wheels/wheel-420-labels-degrees.svg',
    radiusRatio: 199.5 / 540,
  },
};

const WHEEL_CENTER = 150;

export default function ColorWheel300({
  currentColor,
  children,
  base = 'labels',
  staticBase = true,
  size = 300,
}) {
  const hue = Number(currentColor?.hcl_h);
  const hasHue = Number.isFinite(hue);
  const [inlineBase, setInlineBase] = useState(null);
  const baseConfig = BASES[base] || BASES.labels;
  const baseSrc = baseConfig.src;
  const wheelRadius = 300 * baseConfig.radiusRatio;

  useEffect(() => {
    if (staticBase) {
      setInlineBase(null);
      return;
    }
    let alive = true;
    import('./wheel-300-svg.js')
      .then((mod) => {
        if (alive) setInlineBase(mod.default || null);
      })
      .catch(() => {
        if (alive) setInlineBase(null);
      });
    return () => {
      alive = false;
    };
  }, [staticBase, base]);

  const fallbackSvg = useMemo(() => {
    if (!inlineBase) return null;
    return (
      <svg
        className="wheel300-base wheel300-fallback"
        height={300}
        width={300}
        viewBox="0 0 300 300"
        aria-hidden="true"
      >
        <g dangerouslySetInnerHTML={{ __html: inlineBase }} />
        <LabelArcs width={300} />
      </svg>
    );
  }, [inlineBase]);

  return (
    <div
      className="wheel300-stack"
      aria-label="HCL Color Wheel"
      style={{ '--wheel-size': `${size}px` }}
    >
      {staticBase && !inlineBase && (
        <img
          className="wheel300-base"
          src={baseSrc}
          alt="HCL Color Wheel"
          onError={async () => {
            try {
              const mod = await import('./wheel-300-svg.js');
              setInlineBase(mod.default || null);
            } catch {
              setInlineBase(null);
            }
          }}
        />
      )}
      {inlineBase && fallbackSvg}
      <svg
        id="wheel300"
        className="wheel300-overlay"
        height={300}
        width={300}
        viewBox="0 0 300 300"
        aria-hidden="true"
      >
        {hasHue && (
          <ColorWheelIndicator
            key={'base'}
            hue={hue % 360}
            center={WHEEL_CENTER}
            radius={wheelRadius}
            strokeColor="black"
          />
        )}
        {children}
      </svg>
    </div>
  );
}


 /*{angleDefs.map((def, i) => (
          <ColorWheelIndicator
            key={i}
            hue={(currentColor?.hcl_h + def.angle_offset) % 360}
            center={150}
            radius={150}
            stroke={def.stroke}
            dashed={true}
          />
        ))}*/
