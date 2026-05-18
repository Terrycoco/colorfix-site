import { useRef } from 'react';
import { useAppState } from '@context/AppStateContext';
import StickyToolbar from '@layout/StickyToolbar';
import FuzzySearchColorSelect from '@components/FuzzySearchColorSelect';
import ColorForm from '@components/ColorForm';
import SwatchCard from '@components/SwatchCard';
import ColorWheel300 from '@components/ColorWheel/ColorWheel300';
import ResponsiveRow from '@layout/ResponsiveRow';
import Column from '@layout/Column';
import fetchColorDetail from '@data/fetchColorDetail';
import './editpage.css';

export default function AdminColorEditPage() {
  const { colors, currentColorDetail, setCurrentColorDetail } = useAppState();
  const formRef = useRef();

  async function handleSelect(selection) {
    const id = selection?.id;
    if (!id) return;

    setCurrentColorDetail(selection);
    await fetchColorDetail(id, setCurrentColorDetail);
  }

  return (
    <div>
      <StickyToolbar>
        <FuzzySearchColorSelect
          onSelect={handleSelect}
          className="w-full max-w-xs"
        />
        <button onClick={() => formRef.current?.save()}>Save</button>
        <button onClick={() => formRef.current?.reset()}>New</button>
        <button onClick={() => formRef.current?.delete()}>Delete</button>
        <div className="info">DB Count: {colors.length}</div>
      </StickyToolbar>

      <ResponsiveRow className="page-content">
        <Column align="left" className="md:w-1/3 max-w-sm">
          <ColorForm ref={formRef} />
        </Column>

        <Column align="center" className="md:w-1/3 swatch-column">
          <SwatchCard color={currentColorDetail} />
          <h4 className="font-bold">Categories:</h4>
          <p className="descr text-sm">
            {currentColorDetail?.hue_cats} {currentColorDetail?.neutral_cats}
          </p>
        </Column>

        <Column align="center" className="md:w-1/3 wheel-column">
          <ColorWheel300 currentColor={currentColorDetail} />
        </Column>
      </ResponsiveRow>
    </div>
  );
}

