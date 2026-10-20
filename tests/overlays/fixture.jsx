import React, { StrictMode, useState } from 'react';
import { createRoot } from 'react-dom/client';
import '../../src/styles/ZIndex.css';
import '../../src/styles/global.css';
import '../../src/styles/reset.css';
import {
  AdminDialog, AdminDialogProvider, AdminEditor, AdminToast,
} from '../../src/components/AdminLayout';
import MobileSelect from '../../src/components/AdminLayout/mobile/MobileSelect';
import AdminErrorPage from '../../src/pages/AdminErrorPage/AdminErrorPage';
import PubMusicEditor from '../../src/pages/AdminPubPage/PubMusicEditor';
import DropdownWithNote from '../../src/components/DropdownWithNote';
import FuzzySearchColorSelect from '../../src/components/FuzzySearchColorSelect';
import Toast from '../../src/components/Toast/Toast';
import PhotoRenderer from '../../src/components/PhotoRenderer';
import MobileGridDrawer from '../../src/components/AdminLayout/mobile/MobileGridDrawer';
import TagMultiSelect from '../../src/components/TagMultiSelect';
import EditableSwatch from '../../src/components/EditableSwatch';
import SavedPaletteEditorModal from '../../src/components/SavedPaletteEditorModal';
import PaletteInspector from '../../src/components/PaletteInspector';
import { MemoryRouter } from 'react-router-dom';
import '../../src/components/Player/player.css';
import '../../src/components/Player/playerendscreen.css';

// Deterministic UI fixtures; never issue recovery/delete requests to a server.
window.fetch = async (url, options = {}) => {
  const path = String(url);
  let data;
  if (path.includes('/errors/report.php')) data = {
    ok: true, working: [{ id: 1, err_num: 42, department: 'PUB', description: 'Fixture incident', message: 'Fixture failure', status: 'failed' }], history: [], codes: [],
  };
  else if (path.includes('/errors/recover.php')) {
    const request = JSON.parse(options.body);
    if (request.action !== 'plan') throw new Error('This test must not execute recovery');
    data = { ok: true, error_number: 42, department: 'PUB', handler_key: 'fixture', steps: [{ key: 'check', description: 'Fixture recovery step' }] };
  } else if (path.includes('/asset-library/list.php')) data = { ok: true, items: [{ asset_library_id: 1, title: 'Fixture music', public_url: '' }] };
  else if (path.includes('fuzzy-search')) data = { ok: true, items: [{ id: 1, name: 'Fixture Color', brand: 'SW', hex6: '123456' }] };
  else if (path.includes('palette-tag-search')) data = { items: [{ tag: 'Fixture tag', count: 1 }] };
  else if (path.includes('/saved-palettes.php')) data = { ok: true, items: [{ id: 1, nickname: 'Fixture palette', members: [{ color_id: 1, color_name: 'Fixture member', color_hex6: '123456' }] }] };
  else if (path.includes('/kickers/list.php') || path.includes('get-palette-details.php')) data = { ok: true, items: [] };
  else if (path.includes('/palette-roles.php')) data = { ok: true, roles: {} };
  else throw new Error(`Unexpected fixture request: ${path}`);
  return new Response(JSON.stringify(data), { status: 200, headers: { 'Content-Type': 'application/json' } });
};

export function Fixture() {
  const [mode, setMode] = useState('');
  const [dialog, setDialog] = useState(false);
  const [feedback, setFeedback] = useState(false);
  const [music, setMusic] = useState(false);
  const [toast, setToast] = useState(false);
  const [busy, setBusy] = useState(false);
  // Expose only the fixture feedback trigger; the rendered overlays are production components.
  window.overlayFixture = { feedback: () => setFeedback(true), busy: (value) => setBusy(value) };
  return <>
    <nav>
      <button onClick={() => setMode('err')}>ERR fixture</button>
      <button onClick={() => { setMode('dialog'); setDialog(true); }}>Dialog fixture</button>
      <button onClick={() => { setMode('pub'); setDialog(true); }}>PUB fixture</button>
      <button onClick={() => setMode('editor')}>Editor fixture</button>
      <button onClick={() => setMode('grid')}>Grid drawer fixture</button>
      <button onClick={() => setMode('saved')}>Saved palette fixture</button>
      <button onClick={() => setMode('inspector')}>Inspector fixture</button>
      <button onClick={() => setMode('player')}>Player layers fixture</button>
    </nav>
    {mode === 'err' && <AdminErrorPage />}
    <AdminDialog open={dialog} title="Fixture Edit" onCancel={() => setDialog(false)} onConfirm={() => setDialog(false)}>
      <MobileSelect title="Fixture selector" searchable options={[{ value: 'one', label: 'One' }, { value: 'two', label: 'Two' }]} />
      <div style={{ height: 80, overflow: 'hidden', position: 'relative' }}>
        <DropdownWithNote options={[{ value: 'Alpha' }, { value: 'Beta' }]} value="Alpha" onChange={() => {}} />
      </div>
      <FuzzySearchColorSelect autoFocus={false} />
      <TagMultiSelect onChange={() => {}} />
      <EditableSwatch label="Fixture editable swatch" onChange={() => {}} />
      {mode === 'pub' && <button onClick={() => setMusic(true)}>Open Music</button>}
      <button onClick={() => setToast(true)}>Show toast</button>
      <button onClick={() => setFeedback(true)}>Open nested feedback</button>
    </AdminDialog>
    {music && <PubMusicEditor disabled={busy} onClose={() => setMusic(false)} />}
    <AdminDialog open={feedback} mode="alert" title="Fixture Feedback" message="Feedback above its owner" onConfirm={() => setFeedback(false)} />
    <AdminEditor open={mode === 'editor'} title="Fixture Editor" onClose={() => setMode('')}>
      <button onClick={() => setFeedback(true)}>Editor feedback</button>
      <PhotoRenderer viewMode="before" asset={{ repairedUrl: 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="60" height="60"%3E%3Crect width="60" height="60" fill="red"/%3E%3C/svg%3E' }} />
    </AdminEditor>
    {mode === 'grid' && <MobileGridDrawer title="Fixture grid drawer" onClose={() => setMode('')}>
      <button onClick={() => setFeedback(true)}>Grid feedback</button>
    </MobileGridDrawer>}
    <SavedPaletteEditorModal open={mode === 'saved'} paletteId={1} onClose={() => setMode('')} />
    {mode === 'inspector' && <PaletteInspector palette={{ id: 1, name: 'Fixture inspector' }} onClose={() => setMode('')} />}
    {mode === 'player' && <div className="player-root">
      <div className="player-image is-prev" /><div className="player-image is-current" />
      <div className="player-hue-wheel-slide" /><div className="player-like" />
      <div className="player-end-footer" /><div className="player-end-actions" />
      <div className="player-end-screen"><div className="player-exit" /></div>
      <div className="player-exit" /><div className="player-palette-prompt" />
      <div className="player-advance-hint" /><div className="player-back--end-screen" />
    </div>}
    {toast && <Toast message="Public toast fixture" onClose={() => setToast(false)} />}
    <AdminToast open={toast} duration={false} message="Admin toast fixture" />
  </>;
}
createRoot(document.getElementById('root')).render(<StrictMode><MemoryRouter><AdminDialogProvider><Fixture /></AdminDialogProvider></MemoryRouter></StrictMode>);
