// src/components/NavBar.jsx
import { useState, useRef, useEffect } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAppState } from '@context/AppStateContext';
import { isAdmin, setAdmin, clearLegacyAdminState } from '@helpers/authHelper';
import { API_FOLDER } from '@helpers/config';
import { Menu, X } from 'lucide-react';

function NavBar() {
  const { setLoggedIn, user, setUser, showBack, paletteMode, setPaletteMode } = useAppState();
  const [hamburgerOpen, setHamburgerOpen] = useState(false);
  const [adminDropdownOpen, setAdminDropdownOpen] = useState(false);
  const menuRef = useRef();
  const dropdownRef = useRef();
  const closeTimeout = useRef();
  const navigate = useNavigate();

  // Close hamburger menu on outside click
  useEffect(() => {
    const handleClickOutside = (event) => {
      if (hamburgerOpen && menuRef.current && !menuRef.current.contains(event.target)) {
        setHamburgerOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, [hamburgerOpen]);

  const handleLogout = async () => {
    try {
      const response = await fetch(`${API_FOLDER}/logout.php`, { method: 'POST', credentials: 'include' });
      if (!response.ok) throw new Error('Logout failed');
    } catch {
      window.alert('Could not sign out. Please try again.');
      return;
    }
    setAdmin(false);
    clearLegacyAdminState();
    setLoggedIn(false);
    setUser(null);
    navigate('/');
    setHamburgerOpen(false);
  };

  const handleMouseEnter = () => {
    if (closeTimeout.current) clearTimeout(closeTimeout.current);
    setAdminDropdownOpen(true);
  };

  const handleMouseLeave = () => {
    closeTimeout.current = setTimeout(() => {
      setAdminDropdownOpen(false);
    }, 200);
  };

  const navLinks = [
    { label: 'Home', path: '/' },
    { label: 'Search Colors', path: '/search' },
    { label: 'Palettes', path: '/palette' },
    { label: 'Playlists', href: '/playlists' },
    { label: 'Hire Terry', path: '/hire-terry' },
    { label: 'About', path: '/about' },
    user
      ? { label: 'Log Out', action: handleLogout }
      : { label: 'Log In', path: '/login' },
    ...(isAdmin(user)
      ? [{
          label: 'Admin',
          subLinks: [
            {
              label: 'Core',
              items: [
                { label: 'Categories', path: '/admin/categories' },
                { label: 'Edit Color', path: '/admin/colors' },
                { label: 'Saved Palettes', path: '/admin/saved-palettes' },
              ],
            },
          ]
        }]
      : []),
    
  ];

  return (
    <nav className="bg-gray-800 text-white sticky top-0 z-[var(--z-nav)]">
      <div className="max-w-screen-xl mx-auto px-4 py-3 flex items-center justify-between">
      
        {showBack ? (
          <button onClick={() => navigate(-1)} className="text-sm text-orange-400">
            ← Back
          </button>
        ) : (
          <Link to="/" className="text-xl font-bold text-orange-400">ColorFix</Link>
        )}

        {/* Center: My Palette toggle */}
        <div
          className="absolute left-1/2 transform -translate-x-1/2 text-sm font-semibold cursor-pointer hover:text-orange-300"
          onClick={() => setPaletteMode(!paletteMode)}
        >
          My Palette {paletteMode ? '▲' : '▼'}
        </div>




        {/* Desktop Nav */}
        <div className="hidden md:flex gap-x-6 text-sm items-center">
          {navLinks.map((link, idx) =>
            link.subLinks ? (
              <div
                key={idx}
                className="relative"
                onMouseEnter={handleMouseEnter}
                onMouseLeave={handleMouseLeave}
                ref={dropdownRef}
              >
                <span className="hover:text-orange-400 cursor-pointer">
                  {link.label}
                </span>
               {adminDropdownOpen && (
                     <div className="absolute mr-0 right-0 top-[3rem] flex flex-col bg-gray-900 text-white rounded shadow-md z-[var(--z-above)] min-w-[10rem]">

                    {link.subLinks.map((sublink, subIdx) => (
                      sublink.items ? (
                        <div key={subIdx} className="py-2">
                          <div className="px-4 pb-1 text-xs uppercase tracking-wide text-gray-400">
                            {sublink.label}
                          </div>
                          {sublink.items.map((item) => (
                            <Link
                              key={item.path}
                              to={item.path}
                              className="px-4 py-2 hover:text-orange-400 hover:bg-gray-800 whitespace-nowrap"
                            >
                              {item.label}
                            </Link>
                          ))}
                        </div>
                      ) : (
                        <Link
                          key={subIdx}
                          to={sublink.path}
                          className="px-4 py-2 hover:text-orange-400 hover:bg-gray-800 whitespace-nowrap"
                        >
                          {sublink.label}
                        </Link>
                      )
                    ))}
                  </div>
                )}
              </div>
            ) : link.href ? (
              <a
                key={idx}
                href={link.href}
                className="hover:text-orange-400"
              >
                {link.label}
              </a>
            ) : link.path ? (
              <Link
                key={idx}
                to={link.path}
                className="hover:text-orange-400"
              >
                {link.label}
              </Link>
            ) : (
              <button
                key={idx}
                onClick={link.action}
                className="hover:text-orange-400 text-left"
              >
                {link.label}
              </button>
            )
          )}
        </div>

        {/* Mobile Hamburger */}
        <div className="md:hidden relative" ref={menuRef}>
          <button onClick={() => setHamburgerOpen(!hamburgerOpen)}>
            {hamburgerOpen ? <X size={24} /> : <Menu size={24} />}
          </button>

          {/* Mobile Dropdown */}
          {hamburgerOpen && (
            <div className="absolute right-0 top-full mt-2 w-48 bg-gray-900 text-white shadow-lg rounded-md z-[var(--z-dropdown)] flex flex-col gap-y-2 py-3 px-4 text-base">
              <ul className="flex flex-col">
                {navLinks.map((link, idx) =>
                  link.subLinks ? (
                    <li key={idx}>
                      <span className="px-4 py-2 font-semibold">{link.label}</span>
                      <ul className="pl-4">
                        {link.subLinks.map((sublink, subIdx) => (
                          sublink.items ? (
                            <li key={subIdx}>
                              <div className="px-4 pt-2 text-xs uppercase tracking-wide text-gray-400">
                                {sublink.label}
                              </div>
                              <ul className="pl-2">
                                {sublink.items.map((item) => (
                                  <li key={item.path}>
                                    <Link
                                      to={item.path}
                                      onClick={() => setHamburgerOpen(false)}
                                      className="px-4 py-2 hover:text-orange-400 hover:bg-gray-800 whitespace-nowrap"
                                    >
                                      {item.label}
                                    </Link>
                                  </li>
                                ))}
                              </ul>
                            </li>
                          ) : (
                            <li key={subIdx}>
                              <Link
                                to={sublink.path}
                                onClick={() => setHamburgerOpen(false)}
                                className="px-4 py-2 hover:text-orange-400 hover:bg-gray-800 whitespace-nowrap"
                              >
                                {sublink.label}
                              </Link>
                            </li>
                          )
                        ))}
                      </ul>
                    </li>
                  ) : link.href ? (
                    <li key={idx}>
                      <a
                        href={link.href}
                        onClick={() => setHamburgerOpen(false)}
                        className="block w-full px-5 py-4 text-base hover:bg-gray-800"
                      >
                        {link.label}
                      </a>
                    </li>
                  ) : link.path ? (
                    <li key={idx}>
                      <Link
                        to={link.path}
                        onClick={() => setHamburgerOpen(false)}
                        className="block w-full px-5 py-4 text-base hover:bg-gray-800"
                      >
                        {link.label}
                      </Link>
                    </li>
                  ) : (
                    <li key={idx}>
                      <button
                        onClick={link.action}
                        className="w-full text-left px-5 py-4 text-base hover:bg-gray-800"
                      >
                        {link.label}
                      </button>
                    </li>
                  )
                )}
              </ul>
            </div>
          )}
        </div>
      </div>
    </nav>
  );
}

export default NavBar;
