export default function MobilePageLayout({ children }) {
  return (
    <div className="relative w-full bg-white text-gray-900 overflow-auto">
      <div className="flex flex-col h-full w-full max-w-md mx-auto px-4">
        {children}
      </div>
    </div>
  );
}
