


const SwatchItem = ({ name, r, g, b }) => {
  const backgroundColor = `rgb(${r}, ${g}, ${b})`;

  return (
    <div
      className="rounded-xl p-2 shadow-sm text-center flex items-center justify-center text-sm font-medium"
      style={{ backgroundColor }}
    >
      <span className="text-white drop-shadow-sm">{name}</span>
    </div>
  );
};

export default SwatchItem;
