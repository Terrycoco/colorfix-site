const serverRoot = 'https://colorfix.terrymarr.com/api';

  export default async function fetchAllPresets() {
 
    try {
      const response = await fetch(`${serverRoot}/get-search-presets.php?t=${Date.now()}`);
      if (!response.ok) throw new Error(`Fetch failed: ${response.statusText}`);

      const data = await response.json();

      // Check if already an array (raw array returned)
      if (Array.isArray(data)) {
        return data;
      } 
      else if (data.status === "success"){
        console.log('all cats: ', data.data);
        return data.data;
      } else {
        console.error('Unexpected data format:', data);
      }

    } catch (error) {
      console.error('Fetch error:', error);
    }

}