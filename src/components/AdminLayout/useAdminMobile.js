import { useEffect, useState } from "react";

const MOBILE_QUERY = "(max-width: 720px)";

export default function useAdminMobile() {
  const [isMobile, setIsMobile] = useState(
    () => window.matchMedia(MOBILE_QUERY).matches
  );

  useEffect(() => {
    const media = window.matchMedia(MOBILE_QUERY);

    const update = (event) => setIsMobile(event.matches);

    media.addEventListener("change", update);

    return () => media.removeEventListener("change", update);
  }, []);

  return isMobile;
}