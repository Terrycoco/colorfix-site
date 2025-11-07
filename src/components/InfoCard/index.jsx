import { useState, useEffect } from 'react';
import { useDraggable, DndContext } from '@dnd-kit/core';
import { useAppState } from '@context/AppStateContext';
import ColorWheel from '@components/ColorWheel';



function DraggableInfoCard({ color, onClose, position }) {
  const { attributes, listeners, setNodeRef, transform } = useDraggable({
    id: 'info-card',
  });

  const x = transform ? position.x + transform.x : position.x;
  const y = transform ? position.y + transform.y : position.y;

  const style = {
    top: `${y}px`,
    left: `${x}px`,
    position: 'fixed',
    zIndex: 3000,
  };

  const handlePointerDown = (e) => {
    const target = e.target;
    const isInteractive =
      target.closest('button') ||
      target.closest('a') ||
      target.closest('input') ||
      target.closest('textarea') ||
      target.closest('select');

    if (!isInteractive && listeners?.onPointerDown) {
      listeners.onPointerDown(e);
    }
  };

  return (
    <div
      ref={setNodeRef}
      className="info-card"
      style={style}
      onPointerDown={handlePointerDown}
      {...attributes}
    >
      <button className="close-btn" onClick={onClose}>×</button>
      <div className="info-card-header">
        <h3 className="color-name">{color.name}</h3>
      </div>
      <p><strong>Brand:</strong> {color.brand.toUpperCase()}</p>
      <p><strong>Code:</strong> {color.code}</p>
      <p><strong>RGB:</strong> {color.r}, {color.g}, {color.b}</p>
      <p><strong>LRV:</strong> {color.lrv ?? '-'}</p>
      <p><strong>HSL:</strong> {color.h ?? '—'}, {color.s ?? '—'}%, {color.l ?? '—'}%</p>
      <p><strong>Hue Calc:</strong> {color.hue_calculated ?? '—'}</p>
      <p><strong>Light Calc:</strong> {color.light_calc ?? '—'}</p>
      <p><strong>Groups:</strong> {color.categories || '—'}</p>
      <p><strong>Chroma:</strong> {color.chroma ?? '—'}</p>
      {color.notes && <p><strong>Notes:</strong> {color.notes}</p>}
      {color.company_descr && <p><strong>Brand Description:</strong> {color.company_descr}</p>}
      {color && <ColorWheel width='200' data={data} currentColor={color}/>}
    </div>
  );
}

function StaticInfoCard({ color, onClose }) {
  return (
    <div
      className="info-card"
      style={{
        position: 'fixed',
        top: '10vh',
        left: '50%',
        transform: 'translateX(-50%)',
        zIndex: 3000,
        width: '90vw',
        maxWidth: '400px',
      }}
    >
      <button className="close-btn" onClick={onClose}>×</button>
      <div className="info-card-header">
        <h3 className="color-name">{color.name}</h3>
      </div>
      <p><strong>Brand:</strong> {color.brand.toUpperCase()}</p>
      <p><strong>Code:</strong> {color.code}</p>
      <p><strong>RGB:</strong> {color.r}, {color.g}, {color.b}</p>
      <p><strong>LRV:</strong> {color.lrv ?? '-'}</p>
      <p><strong>HSL:</strong> {color.h ?? '—'}, {color.s ?? '—'}%, {color.l ?? '—'}%</p>
      <p><strong>Hue Calc:</strong> {color.hue_calculated ?? '—'}</p>
      <p><strong>Light Calc:</strong> {color.light_calc ?? '—'}</p>
      <p><strong>Groups:</strong> {color.categories || '—'}</p>
      <p><strong>Chroma:</strong> {color.chroma ?? '—'}</p>
      {color.notes && <p><strong>Notes:</strong> {color.notes}</p>}
      {color.company_descr && <p><strong>Brand Description:</strong> {color.company_descr}</p>}
      {color && <ColorWheel width='200' currentColor={color}/>}
    </div>
  );
}

function InfoCardWrapper() {
  const { currentColor, setInfoCardVisible, isInfoCardVisible } = useAppState();
  const [position, setPosition] = useState({ x: 100, y: 100 });
  const [isMobile, setIsMobile] = useState(false);

  useEffect(() => {
    const check = () => setIsMobile(window.innerWidth < 600);
    check();
    window.addEventListener('resize', check);
    return () => window.removeEventListener('resize', check);
  }, []);

  const handleClose = () => {
    setInfoCardVisible(false);
  };

  const handleDragEnd = (event) => {
    if (event.active.id === 'info-card') {
      setPosition((prev) => ({
        x: prev.x + event.delta.x,
        y: prev.y + event.delta.y,
      }));
    }
  };

  if (!currentColor || !isInfoCardVisible) return null;

  return isMobile ? (
    <StaticInfoCard color={currentColor} onClose={handleClose} />
  ) : (
    <DndContext onDragEnd={handleDragEnd}>
      <DraggableInfoCard
        color={currentColor}
        onClose={handleClose}
        position={position}
      />

    </DndContext>
  );
}

export default InfoCardWrapper;
