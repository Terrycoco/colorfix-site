import { useEffect, useState } from 'react';
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
  const {setMessage} = useAppState();

  const fetchAllItems = () => {
    fetch(`${API_FOLDER}/get-all-items.php?cb=${Date.now()}`)
      .then(res => res.json())
      .then(data => {
        console.log('item data:', data);
        setItems(data);
      })
      .catch(err => console.error('Error fetching items:', err));
  };



  const handleEdit = (item) => {
      setFormData({
        id: item.id,
        handle: item.handle || 'no handle',
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
          setMessage('Item saved');
           // ⬇️ Always go back to database for fresh items
           fetchAllItems();

        //setFormData(emptyItem);

        } else {
          setMessage('Error saving item');
        }
      })
      .catch(err => console.error('Error saving item:', err));
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
            {item.handle || item.display || item.name}
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
            onNew={() => setFormData(emptyItem)}
        />
          </div>

    
  </div>
  
  );
}
