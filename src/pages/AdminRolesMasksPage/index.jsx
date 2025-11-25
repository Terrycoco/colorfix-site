import { useEffect, useState } from 'react';
import { API_FOLDER } from '@helpers/config';
import './rolesmasks.css';

const API_PATH = `${API_FOLDER}/v2/admin/roles-masks.php`;

export default function AdminRolesMasksPage() {
  const [roles, setRoles] = useState([]);
  const [masks, setMasks] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [status, setStatus] = useState('');

  const [newRole, setNewRole] = useState({ slug: '', display_name: '', sort_order: 0 });
  const [newMask, setNewMask] = useState({ mask_slug: '', role_id: '' });

  useEffect(() => {
    loadData();
  }, []);

  async function loadData() {
    setLoading(true);
    setError('');
    setStatus('');
    try {
      const res = await fetch(API_PATH, { credentials: 'include' });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed to load data');
      setRoles(data.roles || []);
      setMasks(data.masks || []);
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  }

  function setRoleField(id, field, value) {
    setRoles((prev) => prev.map((role) => (role.id === id ? { ...role, [field]: value } : role)));
  }

  async function saveRole(role) {
    try {
      setStatus('');
      setError('');
      const payload = {
        action: 'update_role',
        id: role.id,
        slug: role.slug,
        display_name: role.display_name,
        sort_order: Number(role.sort_order) || 0,
      };
      const res = await fetch(API_PATH, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify(payload),
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed to save role');
      setStatus(`Role “${role.display_name}” saved.`);
      await loadData();
    } catch (err) {
      setError(err.message);
    }
  }

  async function createRole(e) {
    e.preventDefault();
    if (!newRole.slug || !newRole.display_name) {
      setError('Slug and display name required');
      return;
    }
    try {
      setStatus('');
      setError('');
      const res = await fetch(API_PATH, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({
          action: 'create_role',
          slug: newRole.slug,
          display_name: newRole.display_name,
          sort_order: Number(newRole.sort_order) || 0,
        }),
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed to create role');
      setStatus('Role created.');
      setNewRole({ slug: '', display_name: '', sort_order: 0 });
      await loadData();
    } catch (err) {
      setError(err.message);
    }
  }

  async function updateMask(maskSlug, roleId) {
    try {
      setStatus('');
      setError('');
      const res = await fetch(API_PATH, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ action: 'set_mask_role', mask_slug: maskSlug, role_id: roleId === '' ? null : Number(roleId) }),
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed to update mask');
      setStatus(`Mask “${maskSlug}” updated.`);
      setMasks((prev) => prev.map((m) => (m.mask_slug === maskSlug ? { ...m, role_id: roleId === '' ? null : Number(roleId) } : m)));
    } catch (err) {
      setError(err.message);
    }
  }

  async function addMask(e) {
    e.preventDefault();
    if (!newMask.mask_slug) {
      setError('Mask slug required');
      return;
    }
    await updateMask(newMask.mask_slug, newMask.role_id);
    setNewMask({ mask_slug: '', role_id: '' });
    await loadData();
  }

  return (
    <div className="roles-masks-page">
      <h1>Admin: Roles &amp; Masks</h1>
      {loading && <div className="notice">Loading…</div>}
      {error && <div className="error-banner">{error}</div>}
      {status && <div className="status-banner">{status}</div>}

      <section className="panel">
        <div className="panel-header">
          <h2>Roles</h2>
        </div>
        <div className="roles-table">
          <div className="roles-head">
            <span>Slug</span>
            <span>Name</span>
            <span>Order</span>
            <span></span>
          </div>
          {roles.map((role) => (
            <div className="roles-row" key={role.id}>
              <input value={role.slug} onChange={(e) => setRoleField(role.id, 'slug', e.target.value)} />
              <input value={role.display_name} onChange={(e) => setRoleField(role.id, 'display_name', e.target.value)} />
              <input
                type="number"
                value={role.sort_order ?? 0}
                onChange={(e) => setRoleField(role.id, 'sort_order', e.target.value)}
              />
              <button onClick={() => saveRole(role)}>Save</button>
            </div>
          ))}
        </div>
        <form className="new-role" onSubmit={createRole}>
          <h3>Add Role</h3>
          <input placeholder="slug" value={newRole.slug} onChange={(e) => setNewRole((prev) => ({ ...prev, slug: e.target.value }))} />
          <input
            placeholder="display name"
            value={newRole.display_name}
            onChange={(e) => setNewRole((prev) => ({ ...prev, display_name: e.target.value }))}
          />
          <input
            type="number"
            placeholder="sort order"
            value={newRole.sort_order}
            onChange={(e) => setNewRole((prev) => ({ ...prev, sort_order: e.target.value }))}
          />
          <button type="submit">Add Role</button>
        </form>
      </section>

      <section className="panel">
        <div className="panel-header">
          <h2>Masks</h2>
        </div>
        <div className="masks-table">
          <div className="masks-head">
            <span>Mask</span>
            <span>Default Role</span>
          </div>
          {masks.map((mask) => (
            <div className="masks-row" key={mask.mask_slug}>
              <span className="mask-name">{mask.mask_slug}</span>
              <select value={mask.role_id ?? ''} onChange={(e) => updateMask(mask.mask_slug, e.target.value)}>
                <option value="">(none)</option>
                {roles.map((role) => (
                  <option key={role.id} value={role.id}>{role.display_name}</option>
                ))}
              </select>
            </div>
          ))}
        </div>
        <form className="new-mask" onSubmit={addMask}>
          <h3>Add / Update Mask</h3>
          <input
            placeholder="mask slug (e.g., stucco)"
            value={newMask.mask_slug}
            onChange={(e) => setNewMask((prev) => ({ ...prev, mask_slug: e.target.value }))}
          />
          <select value={newMask.role_id} onChange={(e) => setNewMask((prev) => ({ ...prev, role_id: e.target.value }))}>
            <option value="">(none)</option>
            {roles.map((role) => (
              <option key={role.id} value={role.id}>{role.display_name}</option>
            ))}
          </select>
          <button type="submit">Save Mask</button>
        </form>
      </section>
    </div>
  );
}
