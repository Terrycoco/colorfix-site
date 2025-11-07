import FanDeckSvg from './fandeck.svg?react'; 

export default function FanDeckIcon({ color = '#111', size = 24 }) {
  return <FanDeckSvg style={{ color }} width={size} height={size} aria-hidden />;
}