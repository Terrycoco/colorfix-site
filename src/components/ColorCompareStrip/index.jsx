
function ColorCompareStrip () {

return (
<div className="color-comparison-strip">
  <div className="slot">{currentColorDetail && <SwatchCardMini color={currentColorDetail} />}</div>
  <div className="slot">{comparisonColor && <SwatchCardMini color={comparisonColor} />}</div>
  <div className="slot">{schemeMatches.color1?.[0] && <SwatchCardMini color={schemeMatches.color1[0]} />}</div>
  <div className="slot">{schemeMatches.color2?.[0] && <SwatchCardMini color={schemeMatches.color2[0]} />}</div>
</div>
);
}

export default ColorCompareStrip;