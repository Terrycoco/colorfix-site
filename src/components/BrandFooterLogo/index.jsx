import { useEffect, useRef, useState } from "react";
import BrandBumperLogo from "@components/BrandBumperLogo";
import "./brand-footer-logo.css";

export default function BrandFooterLogo({ className = "" }) {
  const ref = useRef(null);
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    const node = ref.current;
    if (!node || typeof IntersectionObserver === "undefined") {
      setVisible(true);
      return undefined;
    }

    const observer = new IntersectionObserver(
      ([entry]) => {
        if (entry?.isIntersecting) {
          setVisible(true);
          observer.disconnect();
        }
      },
      { threshold: 0.35 }
    );

    observer.observe(node);
    return () => observer.disconnect();
  }, []);

  return (
    <a
      ref={ref}
      href="/"
      className={`brand-footer-logo ${visible ? "is-visible" : ""} ${className}`.trim()}
      aria-label="ColorFix by Terry"
    >
      {visible && <BrandBumperLogo className="brand-footer-logo__mark" />}
    </a>
  );
}
