const serverRoot = 'https://colorfix.terrymarr.com/api';
import { convertToNumbers } from '@helpers/dataHelper';


export async function saveColor(colorObj) {
  try {
    // Convert number-like strings to numbers
    colorObj = convertToNumbers(colorObj);

    // Normalize brand name
    colorObj.brand = colorObj.brand.toLowerCase();

    // Prepare payload (only raw fields!)
    const payload = {
      id: colorObj.id ?? null,
      name: colorObj.name,
      code: colorObj.code,
      brand: colorObj.brand,
      brand_descr: colorObj.brand_descr,
      r: colorObj.r,
      g: colorObj.g,
      b: colorObj.b,
      lrv: colorObj.lrv,
      color_url: colorObj.color_url,
      notes: colorObj.notes,
      interior: colorObj.interior ? 1 : 0,
      exterior: colorObj.exterior ? 1 : 0,
      // DON'T send hsl, lab, hcl, hue, etc. – let PHP handle it
    };

    console.log('Saving color to server:', payload);

    const res = await fetch(`${serverRoot}/save-color.php`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(payload),
    });

    const data = await res.json();
    console.log('Response from server:', data);

    if (data.status === 'success') {
      console.log('✅ Color saved:', data.color);
      return data;
    } else {
      console.error('❌ Error saving color:', data.message);
      throw new Error(data.message);
    }
  } catch (err) {
    console.error('❌ Fetch error:', err);
    throw err;
  }
}
