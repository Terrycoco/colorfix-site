export default function ControlLayout({ children, width = '100%', className = '' }) {
  return (
    <div
      style={{ width }}
      className={`self-start relative font-sans flex flex-col items-center p-0 flex-wrap ${className}`}
    >
      {children}
    </div>
  );
}
