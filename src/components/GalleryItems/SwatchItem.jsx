import './items.css';
import { useNavigate } from 'react-router-dom';
import {useAppState} from '@context/AppStateContext';
import PaletteSwatch from "@components/Swatches/PaletteSwatch";

const SwatchItem = ({ item }) => {
  const navigate = useNavigate();
  const {addToPalette} = useAppState();

  function handleClick() {
      navigate(`/color/${item.id}`);
  }

  return (
   <PaletteSwatch color={item} />
  );
};

export default SwatchItem;
