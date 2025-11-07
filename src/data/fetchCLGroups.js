 export default async function fetchCLGroups() {
  if (refresh) {
    try {
      const response = await fetch(`${serverRoot}/get-cl-groups.php?t=${Date.now()}`);
      if (!response.ok) throw new Error(`Fetch failed: ${response.statusText}`);

      const data = await response.json();

      // Check if already an array (raw array returned)
      if (Array.isArray(data)) {
        setCategories(data);
      } 
      else if (data.status === "success"){
      // Check if wrapped format (status + data)
         //setCategories(data.data);
        console.log('init cats: ', data.data);
      } else {
        console.error('Unexpected data format:', data);
      }

    } catch (error) {
      console.error('Fetch error:', error);
    }
  } else {
    return setCategories(categories);
  }
}