const serverRoot = 'https://colorfix.terrymarr.com/api';

function cleanUpData(data) {
  const normalized = data.map(color => ({
      ...color,
      interior: !!parseInt(color.interior), // change 1,0 to true false
      exterior: !!parseInt(color.exterior),
    }));
  return normalized;
}



  export default async function fetchColors() {
  try {
    let normalized;
    const response = await fetch(`${serverRoot}/get-colors.php?t=${Date.now()}`);
    if (!response.ok) throw new Error(`Fetch failed: ${response.statusText}`);

    const data = await response.json();

    // Check if already an array (raw array returned)
    if (Array.isArray(data)) {
      normalized = cleanUpData(data);
      setColors(normalized);
    } 
    else if (data.status === "success"){
    // Check if wrapped format (status + data)
    normalized = cleanUpData(data.data);
     return normalized;
    } else {
      console.error('Unexpected data format:', data);
    }

  } catch (error) {
    console.error('Fetch error:', error);
  }
}


export async function fetchColorsByCategory(category, setColors) {
  console.log('fetchColorsByCategory called');
  const url = category === 'all'
    ? `${serverRoot}/get-colors.php`
    : `${serverRoot}/get-colors-by-cat.php?category=${encodeURIComponent(category)}&t=${Date.now()}`;
   console.log("fetching from: ", url);

   try {
      const res = await fetch(url);
      const json = await res.json();  // ✅ already parsed object
      console.log('parsed JSON:', json);

   if(Array.isArray(json)) {
    setColors(json); //if sent as array only
   }


  else if (json.status === 'success') {
    const normalized = json.data.map(color => ({
      ...color,
      interior: !!parseInt(color.interior),
      exterior: !!parseInt(color.exterior),
    }));

    setColors(normalized);

  } else {
    console.error('Error fetching colors:', json.message);
  }
} catch (err) {
  console.error('Fetch error:', err);
}

}


