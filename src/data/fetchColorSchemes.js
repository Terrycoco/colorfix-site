const serverRoot = 'https://colorfix.terrymarr.com/api';

export default async function fetchColorSchemes() {
  const res = await fetch(`${serverRoot}/get-schemes-with-angles.php?t=${Date.now()}`);
  const json = await res.json();
  if (json.success) {
    console.log('schemes:', json.data);
    return json.data; // array of schemes with .angles
  } else {
    console.error('Failed to fetch color schemes:', json.error);
    return [];
  }
}
