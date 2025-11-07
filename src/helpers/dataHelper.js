
const numericFields = [
    'id',
    'r',
    'g',
    'b',
    'h',
    's',
    'l',
    'lab_l',
    'lab_a',
    'lab_b',
    'lrv',
    'hue_calculated',
    'chroma'
];


export function toNumberOrNull(value) {
  const num = parseFloat(value);
  return isNaN(num) ? null : num;
}

export function convertToNumbers(colorObj) {
    numericFields.forEach ((f) => {
        colorObj[f] = toNumberOrNull(colorObj[f]);
    });
    return colorObj;
}