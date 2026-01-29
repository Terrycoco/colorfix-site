import React from 'react';
import './introtext.css';

export default function IntroText({ item }) {
  const { title, subtitle, body } = item || {};

  return (
    <div className="intro-text">
     <div className="intro-text__content">
  <h1 className="intro-text__title">{title}</h1>
  <p className="intro-text__subtitle">{subtitle}</p>
</div>
    </div>
  );
}
