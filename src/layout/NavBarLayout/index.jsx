const NavBarLayout = ({ children }) => {
  return (
    <div className="fixed top-0 left-0 w-full h-[var(--navbar-height)] bg-gray-800 z-[1000]">
      {children}
    </div>
  );
};

export default NavBarLayout;
