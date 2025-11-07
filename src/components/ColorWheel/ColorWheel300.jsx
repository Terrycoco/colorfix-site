import ColorWheelIndicator from '@components/ColorWheel/ColorWheelIndicator';
import Paths300 from './wheel-300-svg.js';
import LabelArcs from '@components/ColorWheel/LabelArcs';




export default function ColorWheel300({ currentColor, children }) {
  //get angles of current scheme
   //const angleDefs = scheme?.angles || [];




  return (
    <svg id="wheel300" height={300} width={300} viewBox="0 0 300 300"
      alt="HCL Color Wheel"
      className="max-w-full h-auto my-4"
    
    >
      <g 
          dangerouslySetInnerHTML={{ __html: Paths300 }}>
      </g>
      <LabelArcs width={300} />
      <ColorWheelIndicator
            key={'base'}
            hue={(currentColor?.hcl_h) % 360}
            center={150}
            radius={150}
            strokeColor="black"
          />

     {children}

    </svg>
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