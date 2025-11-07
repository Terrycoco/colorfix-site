import useMediaQuery  from "@hooks/useMediaQuery.js";

/**
 * Renders the appropriate page component based on screen size.
 *
 * Props:
 * - `mobile`: component to render on mobile
 * - `desktop`: component to render on desktop
 * - All other props are forwarded to the chosen component
 */
export default function ResponsiveRoute({ mobile: MobilePage, desktop: DesktopPage, ...props }) {
  const isMobile = useMediaQuery("(max-width: 767px)");
  const Page = isMobile ? MobilePage : DesktopPage;
  return <Page {...props} />;
}
