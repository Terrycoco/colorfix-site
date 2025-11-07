import DesignerSvg from './designer.min.svg?react'; 

export default function DesignerIcon({ color = '#111', size = 24 }) {
  return <DesignerSvg style={{ color }} width={size} height={size} aria-hidden />;
}