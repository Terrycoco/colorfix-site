const serverRoot = 'https://colorfix.terrymarr.com/api';
const v1 = '/get-categories.php';
const v2 = '/v2/get-categories.php';


  export default async function fetchCategories() {
 
    try {
      const response = await fetch(`${serverRoot}${v2}?t=${Date.now()}`);
      if (!response.ok) throw new Error(`Fetch failed: ${response.statusText}`);

      const data = await response.json();

      // Check if already an array (raw array returned)
      if (Array.isArray(data)) {
        return data;
      } 
      else if (data.status === "success"){
      // Check if wrapped format (status + data)
       return data.data;
    
      } else {
        console.error('Unexpected data format:', data);
      }

    } catch (error) {
      console.error('Fetch error:', error);
    }
  
}