const mockSwatches = [
  { id: 2, name: "Blue Spruce", code: "1637", r: 96, g: 112, b: 119 },
  { id: 102, name: "Soft Clay", code: "C245", r: 185, g: 132, b: 112 },
  { id: 103, name: "Golden Linen", code: "C156", r: 222, g: 202, b: 171 }
];

export function getSwatchByIdOrName(input) {
  const idNum = parseInt(input, 10);
  return mockSwatches.find(
    (s) => s.id === idNum || s.name.toLowerCase() === input.toLowerCase()
  );
}
