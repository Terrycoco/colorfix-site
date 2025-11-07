import ColorWheelIndicator from '@components/ColorWheel/ColorWheelIndicator';
import Paths400 from './wheel-400-svg.js';





export default function ColorWheel400({ currentColor }) {
  return (
    <svg id="wheel400" height={400} width={400}>
      <g 
          dangerouslySetInnerHTML={{ __html: Paths400 }}>
      </g>
      <ColorWheelIndicator hue={currentColor?.hcl_h} center={200} radius={200}/>
    </svg>
  );
}


/*<g id="indicator" transform="translate(200, 200)"><path d="M0,0L-153.152,-112.448" id="current" stroke="black" stroke-width="2" fill="none"></path></g>
*/ 