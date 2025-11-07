import { useEffect, useRef, useState } from 'react';

export default function StickyToolbar({ children }) {
  const [hidden, setHidden] = useState(false);
  const lastScrollY = useRef(0);

  // 🟡 Add this ref so we can pass in the scroll container later if needed
  const scrollContainerRef = useRef(null);

  useEffect(() => {
    const container = document.querySelector('main'); // ⬅️ Attach to <main>
    if (!container) return;

    const handleScroll = () => {
      const currentY = container.scrollTop;
     // console.log('ScrollY:', currentY);

      if (currentY > lastScrollY.current && currentY > 50) {
        setHidden(true);
      } else {
        setHidden(false);
      }

      lastScrollY.current = currentY;
    };

    container.addEventListener('scroll', handleScroll);
  //  console.log('Scroll listener added to <main>');

    return () => {
      container.removeEventListener('scroll', handleScroll);
     // console.log('Scroll listener removed from <main>');
    };
  }, []);

  return (
    <div className={`sticky top-0 z-[600] w-full bg-gray-100 border-b border-gray-300 transition-transform duration-300 ${
      hidden ? '-translate-y-full' : 'translate-y-0'
    }`}>
      <div className="max-w-screen-xl mx-auto px-4 sm:px-6 py-2 flex flex-wrap items-center gap-4">
        {children}
      </div>
    </div>
  );
}
