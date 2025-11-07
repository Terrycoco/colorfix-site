const serverRoot = 'https://colorfix.terrymarr.com/api';

export async function deleteColor(colorId) {
  if (!colorId || isNaN(colorId)) {
    console.error('❌ Invalid color ID');
    throw new Error('Invalid color ID');
  }

  const confirmDelete = window.confirm(`Are you sure you want to delete color ID ${colorId}? This cannot be undone.`);
  if (!confirmDelete) {
    console.log('🚫 Deletion cancelled by user.');
    return { status: 'cancelled', message: 'User cancelled deletion' };
  }

  try {
    const res = await fetch(`${serverRoot}/delete-color.php`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ id: colorId }),
    });

    const data = await res.json();
    console.log('🗑️ Delete response:', data);

    if (data.success) {
      console.log('✅ Color deleted.');
      return data;
    } else {
      console.error('❌ Deletion failed:', data.message);
      throw new Error(data.message || 'Delete failed');
    }
  } catch (err) {
    console.error('❌ Fetch error during delete:', err);
    throw err;
  }
}
