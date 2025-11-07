export default function ResponsiveRow({ children, className = '' }) {
  return (
    <div className={`flex flex-col md:flex-row md:items-start gap-8 px-4 py-6 max-w-screen-xl mx-auto ${className}`}>
      {children}
    </div>
  );
}