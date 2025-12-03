import { useEffect, useState } from "react";
import { useNavigate } from 'react-router-dom';
import "./logo-animated.css";

export default function LogoAnimation() {
  const [play, setPlay] = useState(false);
  const navigate = useNavigate();

  useEffect(() => {
    // slight delay for smooth load
    setTimeout(() => setPlay(true), 100);
  }, []);

  function goHome() {
    window.location('https://colorfix.terrymarr.com');
  }

  return (
    <div className={`logo-header ${play ? "play" : ""}`} onClick={goHome}>
      <img src="/white-brush-xparent.png" alt="ColorFix" className="logo-brush" />
      <span className="logo-text">ColorFix</span>
    </div>
  );
}