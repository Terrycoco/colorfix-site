const serverRoot = 'https://colorfix.terrymarr.com/api';

  
export default async function fetchSearchResults(params) {
    if (params.hueMin != null && params.hueMax == null) {
      params.hueMax = params.hueMin + 1;
    }

  console.log('params: ', params);
  try {
    const response = await fetch(
      `${serverRoot}/search-colors.php?t=${Date.now()}`,
      {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(params),
      }
    );

    const result = await response.json();

    if (result.status === 'success') {
      return result.data;
      console.log('search results:', result.data);
    } else {
      console.error('Search failed:', result.message);
       
    }
  } catch (error) {
    console.error('Fetch error:', error);
  }
}
