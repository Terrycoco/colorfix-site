import { useLayoutEffect } from "react";
import { useLocation, useNavigate } from "react-router-dom";

// Keep React Router's location in sync with the source added by navigation history.
export default function SourceNavigation() {
  const location = useLocation();
  const navigate = useNavigate();
  useLayoutEffect(() => {
    const actual = window.location;
    if (location.pathname === actual.pathname && location.search !== actual.search) {
      navigate(`${actual.pathname}${actual.search}${actual.hash}`, {
        replace: true,
        state: location.state,
      });
    }
  }, [location, navigate]);
  return null;
}
