import PictureSwatch from "@components/Swatches/PictureSwatch";

const PictureSwatchItem = ({ item }) => {
  const brand = item?.palette_brand ? String(item.palette_brand).toUpperCase() : "";
  const name = item?.palette_name || "Saved Palette";
  const meta = brand ? brand : (item?.palette_id ? `Palette #${item.palette_id}` : "");
  const to = item?.palette_hash ? `/palette/${item.palette_hash}/share` : undefined;

  return (
    <PictureSwatch
      photoUrl={item?.photo_url}
      name={name}
      meta={meta}
      to={to}
    />
  );
};

export default PictureSwatchItem;
