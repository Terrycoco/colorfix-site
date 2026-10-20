// src/components/PageDashboard.jsx

export default function PageDashboard({ children, sticky = true }) {
  const style = sticky
    ? {
        position: 'sticky',
        top: 0, // because MainLayout already accounts for navbar
        zIndex: "var(--z-nav)",
        background: '#f0f0f0',
        borderBottom: '1px solid #ccc',
      }
    : {
        position: 'relative',
      };

  return (
    <div className="page-dashboard" style={style}>
      {children}
    </div>
  );
}
