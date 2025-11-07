import './icons.css';


export  function PaletteIcon(props) {
  // Tabler "palette" outline
  return (
    <svg viewBox="0 0 24 24" width="100%" height="100%" aria-hidden="true" {...props}>
      <path d="M12 3a9 9 0 1 0 0 18h.5a2.5 2.5 0 0 0 2.5-2.5c0-.9-.4-1.5-1.1-2.1-.6-.4-.9-.8-.9-1.4
               0-1.1.9-2 2-2h1a3 3 0 0 0 0-6H12z" fill="none" stroke="currentColor" strokeWidth="2" />
      <circle cx="7.5" cy="10.5" r="1" fill="currentColor" />
      <circle cx="9.5" cy="7.5"  r="1" fill="currentColor" />
      <circle cx="14.5" cy="7.5" r="1" fill="currentColor" />
    </svg>
  );
}

export function PaletteFilledIcon(props) {
  return (
    <svg xmlns="http://www.w3.org/2000/svg"
      viewBox="0 0 24 24" width="100%" height="100%"
      fill="currentColor" {...props}
    >
      <path stroke="none" d="M0 0h24v24H0z" fill="none" />
      <path d="M12 2c5.498 0 10 4.002 10 9c0 1.351 -.6 2.64 -1.654 3.576
               c-1.03 .914 -2.412 1.424 -3.846 1.424h-2.516a1 1 0 0 0 -.5 1.875
               a1 1 0 0 1 .194 .14a2.3 2.3 0 0 1 -1.597 3.99l-.156 -.009l.068 .004l-.273 -.004
               c-5.3 -.146 -9.57 -4.416 -9.716 -9.716l-.004 -.28c0 -5.523 4.477 -10 10 -10
               m-3.5 6.5a2 2 0 0 0 -1.995 1.85l-.005 .15a2 2 0 1 0 2 -2
               m8 0a2 2 0 0 0 -1.995 1.85l-.005 .15a2 2 0 1 0 2 -2" />
    </svg>
  );
}

export function PaletteOutlineIcon(props) {
  const d =
    "M12 21a9 9 0 0 1 0 -18c4.97 0 9 3.582 9 8c0 1.06 -.474 2.078 -1.318 2.828c-.844 .75 -1.989 1.172 -3.182 1.172h-2.5a2 2 0 0 0 -1 3.75a1.3 1.3 0 0 1 -1 2.25";

  return (
    <svg
      viewBox="0 0 24 24"
      width="100%"
      height="100%"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      {...props}
    >
      {/* Fill layer (behind outline) */}
      <path className="palette-fill" d={d} />

      {/* Outline */}
      <path className="palette-outline" d={d} fill="none" />

      {/* Dots */}
      <path className="palette-dot" d="M8.5 10.5m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
      <path className="palette-dot" d="M12.5 7.5m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
      <path className="palette-dot" d="M16.5 10.5m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
    </svg>
  );
}



export  function PaletteToggleIcon({ active=false, color='currentColor', className='' }) {
  // Determine dot color based on fill color for active state
  const dotColor = (color.toLowerCase() === '#fff' || color.toLowerCase() === 'white')
    ? '#000' // black dots on white fill
    : '#fff'; // white dots otherwise

  if (active) {
    return (
      <svg viewBox="0 0 24 24" width="100%" height="100%" fill={color} className={className} aria-hidden="true">
        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
        <path d="M12 2c5.498 0 10 4.002 10 9c0 1.351 -.6 2.64 -1.654 3.576
                 c-1.03 .914 -2.412 1.424 -3.846 1.424h-2.516a1 1 0 0 0 -.5 1.875
                 a1 1 0 0 1 .194 .14a2.3 2.3 0 0 1 -1.597 3.99l-.273 -.004
                 c-5.3 -.146 -9.57 -4.416 -9.716 -9.716l-.004 -.28
                 c0 -5.523 4.477 -10 10 -10z" />
        <circle cx="8.5" cy="10.5" r="1" fill={dotColor}/>
        <circle cx="12.5" cy="7.5" r="1" fill={dotColor}/>
        <circle cx="16.5" cy="10.5" r="1" fill={dotColor}/>
      </svg>
    );
  }

  // Outline version
  return (
    <svg viewBox="0 0 24 24" width="100%" height="100%" fill="none"
         stroke={color} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"
         className={className} aria-hidden="true">
      <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
      <path d="M12 21a9 9 0 0 1 0 -18c4.97 0 9 3.582 9 8
               c0 1.06 -.474 2.078 -1.318 2.828
               c-.844 .75 -1.989 1.172 -3.182 1.172h-2.5
               a2 2 0 0 0 -1 3.75a1.3 1.3 0 0 1 -1 2.25"/>
      <circle cx="8.5" cy="10.5" r="1"/>
      <circle cx="12.5" cy="7.5" r="1"/>
      <circle cx="16.5" cy="10.5" r="1"/>
    </svg>
  );
}
