// helpers/utils.js
export function armClickShield(ms = 450) {
  const id = 'click-shield';
  let el = document.getElementById(id);

  if (!el) {
    el = document.createElement('div');
    el.id = id;
    Object.assign(el.style, {
      position: 'fixed',
      inset: '0',
      background: 'transparent',
      pointerEvents: 'auto',
      zIndex: '9998',     // below any modal, above content
      display: 'none'
    });
    document.body.appendChild(el);
  }

  // always clear any prior timer
  if (el._t) clearTimeout(el._t);

  el.style.display = 'block';
  el._t = setTimeout(() => {
    el.style.display = 'none';
  }, ms);

  // absolute safety valve: auto-kill after 2s no matter what
  if (el._kill) clearTimeout(el._kill);
  el._kill = setTimeout(() => {
    el.style.display = 'none';
  }, 2000);

  // also hide on visibility change (e.g., user background/returns)
  const hide = () => { el.style.display = 'none'; };
  document.addEventListener('visibilitychange', hide, { once: true });
}


export function killClickShield() {
  const el = document.getElementById('click-shield');
  if (!el) return;
  if (el._hide) clearTimeout(el._hide);
  if (el._failsafe) clearTimeout(el._failsafe);
  el.style.display = 'none';
}
