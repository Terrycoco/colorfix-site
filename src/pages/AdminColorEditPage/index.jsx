import { useRef, useState } from 'react';
import { useAppState } from '@context/AppStateContext';
import StickyToolbar from '@layout/StickyToolbar';
import FuzzySearchColorSelect from '@components/FuzzySearchColorSelect';
import ColorForm from '@components/ColorForm';
import SwatchCard from '@components/SwatchCard';
import AnimatedHueWheel from '@components/AnimatedHueWheel';
import ResponsiveRow from '@layout/ResponsiveRow';
import Column from '@layout/Column';
import fetchColorDetail from '@data/fetchColorDetail';
import './editpage.css';

export default function AdminColorEditPage() {
  const { colors, currentColorDetail, setCurrentColorDetail } = useAppState();
  const formRef = useRef();
  const refreshInFlight = useRef(false);
  const [refreshing, setRefreshing] = useState(false);
  const [refreshMessage, setRefreshMessage] = useState('');

  async function refreshSwatches() {
    if (refreshInFlight.current) return;
    refreshInFlight.current = true;
    setRefreshing(true);
    setRefreshMessage('');
    try {
      const response = await fetch('/api/v2/admin/colors/refresh-swatches.php', {
        method: 'POST', credentials: 'include',
        headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({}),
      });
      const data = await response.json().catch(() => null);
      if (!response.ok || !data?.ok) throw new Error(data?.error || `HTTP ${response.status}`);
      setRefreshMessage(`Swatches refreshed: ${data.summary.rows_after} colors.`);
    } catch (error) {
      setRefreshMessage(`Swatch refresh failed: ${error.message || 'Network error. Check server status before retrying.'}`);
    } finally {
      refreshInFlight.current = false;
      setRefreshing(false);
    }
  }
  const currentId = Number(currentColorDetail?.id);
  const hasHueValue = currentColorDetail?.hcl_h != null && currentColorDetail.hcl_h !== '';
  const currentHue = hasHueValue ? Number(currentColorDetail.hcl_h) : NaN;
  const hasLoadedColor = Number.isFinite(currentId) && currentId > 0 && Number.isFinite(currentHue);
  const wheelItems = hasLoadedColor
    ? [{
        hue: currentHue,
        color: `rgb(${currentColorDetail?.r || 0}, ${currentColorDetail?.g || 0}, ${currentColorDetail?.b || 0})`,
        animate: true,
      }]
    : [];

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
        <button type="button" onClick={refreshSwatches} disabled={refreshing} aria-busy={refreshing}>
          {refreshing ? 'Refreshing Swatches…' : 'Refresh Swatches'}
        </button>
        <div className="info">DB Count: {colors.length}</div>
      </StickyToolbar>
      {refreshMessage && <p className="swatch-refresh-status" role="status">{refreshMessage}</p>}

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
          {wheelItems.length > 0 ? (
            <AnimatedHueWheel
              items={wheelItems}
              showLabels={false}
              size={360}
              spokeStartRadius={0}
              spokeEndRadius={136}
              spokeDelayMs={500}
              spokeDurationMs={900}
              caption="AnimatedHueWheel test"
            />
          ) : null}
        </Column>
      </ResponsiveRow>
    </div>
  );
}
