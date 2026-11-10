import { useEffect, useRef, useState } from 'react';
import { useAppState } from '@context/AppStateContext';
import {API_FOLDER} from '@helpers/config';
import { makePhotoRef, parsePhotoRef } from '@helpers/assetImage';
import ItemEditForm from './ItemEditForm';


const emptyItem = {
      id: null,
      handle: '',
      title: '',
      subtitle: '',
      display: '',
      description: '',
      query_id: '',
      item_type: '',
      image_url: '',
      photo_library_id: '',
      body: '',
      target_url: '',
      is_clickable: 0,
      is_pinnable: 0,
      is_active: 1,
      color: '',
      insert_position: ''
 };




export default function ItemEditPage() {
  const [items, setItems] = useState([]);
  const [queries, setQueries] = useState([]);
  const [formData, setFormData] = useState(emptyItem);
  const [busy, setBusy] = useState(false);
  const requestPending = useRef(false);
  const {setMessage} = useAppState();

  const fetchAllItems = () => {
    return fetch(`${API_FOLDER}/get-all-items.php?cb=${Date.now()}`)
      .then(res => res.json())
      .then(data => {
        console.log('item data:', data);
        setItems(data);
      })
      .catch(err => console.error('Error fetching items:', err));
  };



  const handleEdit = (item) => {
      if (requestPending.current) return;
      setFormData({
        id: item.id,
        handle: item.handle || '',
        title: item.title || '',
        subtitle: item.subtitle || '',
        display: item.display || '',
        description: item.description || '',
        item_type: item.item_type || '',
        query_id: item.query_id || '',
        image_url: item.image_url || '',
        photo_library_id: parsePhotoRef(item.image_url || '').photoId || '',
        body: item.body || '',
        target_url: item.target_url || '',
        is_clickable: item.is_clickable || 0,
        is_pinnable: item.is_pinnable || 0,
        is_active: item.is_active ?? 1,
        color: item.color || '',
        insert_position: item.insert_position ?? ''
      });
    };


  // Load items and queries on mount
  useEffect(() => {
    fetchAllItems();


    fetch(`${API_FOLDER}/get-all-queries.php`)
      .then(res => res.json())
      .then(data => {
        console.log('fetched queries:', data);
        setQueries(data.data);
      })
      .catch(err => console.error('Error fetching queries:', err));
 
  }, []);

  const handleSave = (e) => {
    e.preventDefault();
    if (requestPending.current) return;
    requestPending.current = true;
    setBusy(true);
    console.log('Submitting this formData:', formData);
    const parsedImage = parsePhotoRef(formData.image_url || '');
    const normalizedInsertPosition = formData.insert_position === '' || formData.insert_position == null
      ? null
      : Number.parseFloat(formData.insert_position);
    const payload = {
      ...formData,
      insert_position: Number.isFinite(normalizedInsertPosition) ? normalizedInsertPosition : null,
      image_url: formData.photo_library_id
        ? makePhotoRef(formData.photo_library_id, parsedImage.url || '')
        : formData.image_url,
    };
    delete payload.photo_library_id;
    fetch(`${API_FOLDER}/upsert-item.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
      .then(res => res.json())
      .then(data => {
        console.log('data returned: ', data);
        if (data.success) {
          if (!data.item?.id) throw new Error('Saved item ID was not returned. Reload the list before saving again.');
          // Keep the saved ID: subsequent saves update this record.
          setFormData(previous => ({ ...previous, id: data.item.id }));
          setMessage('Item saved');
           // ⬇️ Always go back to database for fresh items
           return fetchAllItems();

        //setFormData(emptyItem);

        } else {
          setMessage('Error saving item');
        }
      })
      .catch(err => setMessage(err.message || 'Error saving item'))
      .finally(() => { requestPending.current = false; setBusy(false); });
  };

  const handleDelete = async () => {
    if (!formData.id || requestPending.current) return;
    if (!window.confirm(`Delete item #${formData.id} (${formData.handle || formData.title || 'Untitled item'})? This permanently removes this item.`)) return;
    requestPending.current = true;
    setBusy(true);
    try {
      const response = await fetch(`${API_FOLDER}/delete-item.php`, {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: formData.id }),
      });
      const data = await response.json();
      if (!response.ok || !data.success) throw new Error(data.error || 'Error deleting item');
      setItems(previous => previous.filter(item => String(item.id) !== String(formData.id)));
      setFormData({ ...emptyItem });
      setMessage('Item deleted');
    } catch (error) {
      setMessage(error.message || 'Error deleting item');
    } finally {
      requestPending.current = false;
      setBusy(false);
    }
  };


  console.log('Rendering items:', items);
  return (
    <div className="flex">

       <div className="w-1/4 pl-4 pr-3">
        <p className="text-xs font-semibold mt-2">All Items</p>
        <ul className="mt-2 space-y-1">
        {items.map(item => (
          <li
            key={item.id}
            onClick={() => handleEdit(item)}
            className="cursor-pointer rounded px-2 py-1 hover:bg-gray-100"
          >
            {item.handle || item.display || item.name || '(Untitled item)'}
            <span className="ml-2 text-xs text-gray-500">#{item.id}</span>
            <span className="ml-2 text-xs text-gray-500">
              [{item.insert_position ?? 'none'}]
            </span>
          </li>
        ))}
      </ul>
      </div>
        <div className="w-1/2">
        <ItemEditForm
            formData={formData}
            updateField={(field, value) => {
              if (field === 'photo_library_id') {
                const currentParsed = parsePhotoRef(formData.image_url || '');
                setFormData({
                  ...formData,
                  photo_library_id: value,
                  image_url: value
                    ? makePhotoRef(value, currentParsed.url || '')
                    : currentParsed.photoId
                      ? ''
                      : formData.image_url,
                });
                return;
              }
              setFormData({ ...formData, [field]: value });
            }}
            handleSubmit={handleSave}
            queries={queries}
            onNew={() => setFormData({ ...emptyItem })}
            onDelete={handleDelete}
            busy={busy}
        />
          </div>

    
  </div>
  
  );
}
