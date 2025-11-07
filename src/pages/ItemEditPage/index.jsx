import { useEffect, useState } from 'react';
import { useAppState } from '@context/AppStateContext';
import {API_FOLDER} from '@helpers/config';
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
      body: '',
      target_url: '',
      is_clickable: 0,
      is_pinnable: 0,
      is_active: 1,
      color: '',
      insert_position: null
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
        body: item.body || '',
        target_url: item.target_url || '',
        is_clickable: item.is_clickable || 0,
        is_pinnable: item.is_pinnable || 0,
        is_active: item.is_active ?? 1,
        color: item.color || '',
        insert_position: item.insert_position || ''
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
    fetch(`${API_FOLDER}/upsert-item.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(formData),
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

       <div className="w-1/4">
        <p className="text-xs font-semibold ml-2 mt-2">All Items</p>
        <ul>
        {items.map(item => (
          <li key={item.id} onClick={() => handleEdit(item)}>
            {item.handle || item.display || item.name}
          </li>
        ))}
      </ul>
      </div>
        <div className="w-1/2">
        <ItemEditForm
            formData={formData}
            updateField={(field, value) => setFormData({ ...formData, [field]: value })}
            handleSubmit={handleSave}
            queries={queries}
            onNew={() => setFormData(emptyItem)}
        />
          </div>

    
  </div>
  
  );
}
