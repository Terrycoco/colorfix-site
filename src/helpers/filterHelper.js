export default function getFilteredColors(colors, filters) {
  return colors.filter(color => {
    const hue = color.hcl_h;
    const light = color.hcl_l; // or hcl_l if you prefer HCL-based lightness

    // Basic filters
    const matchesHue = hue >= filters.hueRange[0] && hue <= filters.hueRange[1];
    const matchesLight = light >= filters.lightRange[0] && light <= filters.lightRange[1];
    const matchesInterior = filters.interior ? color.interior : true;
    const matchesExterior = filters.exterior ? color.exterior : true;
    const matchesCategory = filters.category
      ? color.hue_cats?.includes(filters.category) || color.neutral_cats?.includes(filters.category)
      : true;
    const matchesSearch = filters.search
      ? (color.name?.toLowerCase().includes(filters.search.toLowerCase()) ||
         color.code?.toLowerCase().includes(filters.search.toLowerCase()))
      : true;

    // Complement filter
    let matchesComplement = true;
    if (filters.showComplementsFilter && filters.selectedHue !== undefined) {
      const complementHue = (filters.selectedHue + 180) % 360;
      const tolerance = filters.complementTolerance ?? 0;
      matchesComplement = hue >= complementHue - tolerance && hue <= complementHue + tolerance;
    }

    return matchesHue && matchesLight && matchesInterior && matchesExterior &&
           matchesCategory && matchesSearch && matchesComplement;
  });
}
