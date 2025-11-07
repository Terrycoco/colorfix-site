import { useNavigate, useParams } from 'react-router-dom';
import { useEffect, useState } from 'react';
import { useAppState } from '@context/AppStateContext';
import SwatchCardMini from '@components/SwatchCard/SwatchCardMini';
import SwatchCardTiny from '@components/SwatchCard/SwatchCardTiny';
import SwatchCard from '@components/SwatchCard';
import SwatchGallery from '@components/SwatchGallery';
import StickyToolbar from '@layout/StickyToolbar';
import Column from '@layout/Column';
import ResponsiveRow from '@layout/ResponsiveRow';
import ColorWheel300 from '@components/ColorWheel/ColorWheel300';
import fetchColorDetail from '@data/fetchColorDetail';
import fetchSearchResults from '@data/fetchSearchResults';




export default function ColorDetailPage() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [comparisonColor, setComparisonColor] = useState();
 const [schemeSearchInProgress, setSchemeSearchInProgress] = useState(false);
  const { colors,  currentColorDetail, setCurrentColorDetail, colorSchemes } = useAppState();
  const [selectedScheme, setSelectedScheme] = useState(colorSchemes?.[0] || null);
  const [schemeMatches, setSchemeMatches] = useState({});
  const [comparisonColors, setComparisonColors] = useState([]);







//USE EFFECTS
  useEffect(() => {
      if (id) {
        console.log("Fetching detail for ID:", id); 
        fetchColorDetail(id, setCurrentColorDetail);
      }
    }, [id, setCurrentColorDetail]);


    useEffect(() => {
      if (selectedScheme && currentColorDetail?.hcl_h != null) {
        handleFindSchemeMatches(selectedScheme);
      }
    }, [selectedScheme, currentColorDetail]);


  useEffect(() => {
    setSchemeSearchInProgress(false);
    setSchemeMatches([]); // also clear previous results
  }, [currentColorDetail?.id]); 






  function getOpposite(hue) {
    return Math.round((hue + 180) % 360);
  }

//EVENT HANDLERS
const handleSelectComparison = (slotIndex, color) => {
  setComparisonColors(prev => {
    const updated = [...prev];
    updated[slotIndex - 1] = color; // slotNumber is 1-based
    return updated;
  });
};


const handleSchemeChange = (e) => {
  const newScheme = colorSchemes.find(s => s.id === Number(e.target.value));
  setSelectedScheme(newScheme);
  //empty out comparison colors
  setComparisonColors([]);
  if (currentColorDetail?.hcl_h != null) {
    handleFindSchemeMatches(newScheme); // pass explicitly!
  }
};

const handleFindSchemeMatches = async (scheme = selectedScheme) => {
  setSchemeSearchInProgress(true);
  setSchemeMatches({}); // clear previous results

  const baseHue = currentColorDetail?.hcl_h;
  if (baseHue == null || !scheme) return;

  const tolerance = 2;

  const fetchPromises = scheme.angles.map((angleObj, index) => {
    const angle = angleObj.angle_offset;
    const targetHue = (baseHue + angle + 360) % 360;
    const minHue = (targetHue - tolerance + 360) % 360;
    const maxHue = (targetHue + tolerance) % 360;

    return fetchSearchResults({ hueMin: minHue, hueMax: maxHue })
      .then((res) => [`color${index + 1}`, res || []]);
  });

  const results = await Promise.all(fetchPromises);

  const matchesObj = Object.fromEntries(results);
  setSchemeMatches(matchesObj);
};


  if (!currentColorDetail) return <div>Loading...</div>;




  return (
    <div className="color-detail-page">
      <StickyToolbar>


    
      </StickyToolbar>

    


     <ResponsiveRow className="page-content">


      {/* COLUMN 1: SWATCH & INFO */}
        <Column align="left" className="swatch">
          <ResponsiveRow>
              <SwatchCard color={currentColorDetail} large />
              {comparisonColor && (
                <SwatchCard color={comparisonColor}  />
              )}
             
          </ResponsiveRow>
          <ResponsiveRow>
            <Column align="left">
                
                <div className="color-info">
                  <h3 disabled>{currentColorDetail.name}</h3>
                    <p><strong>Brand:</strong> {currentColorDetail.brand.toUpperCase()}</p>
                    <p><strong>Code:</strong> {currentColorDetail.code}</p>
                    <p><strong>RGB:</strong> {currentColorDetail.r}, {currentColorDetail.g}, {currentColorDetail.b}</p>
                    <p><strong>HCL:</strong> {currentColorDetail.hcl_h}, {currentColorDetail.hcl_c}, {currentColorDetail.hcl_l}</p>
                    <p><strong>LRV:</strong> {currentColorDetail.lrv}</p>
                    <p className="descr" disabled>{currentColorDetail.brand_descr}</p>
                    <p> <a 
                        href={currentColorDetail.color_url}
                        target="_blank" 
                        rel="noopener noreferrer"
                        className="underline"
                      >
                        Official Color Page
                      </a>
                      </p>
                  </div>
                 
              </Column>
          </ResponsiveRow>
    

   </Column>




        <Column>
            <ColorWheel300 currentColor={currentColorDetail} />
       
            <p className="descr text-sm mt-4">
             {currentColorDetail.hue_cats}
            </p>
             <p className="descr text-sm mt-4">
             {currentColorDetail.neutral_cats}
            </p>
          

    

          
       
                    
              <p className="descr">{`Its opposite hue is ${getOpposite(currentColorDetail.hcl_h)}° `}</p>



      </Column>



      <Column align="center">
        <div className="color-comparison-strip">
          <div >{currentColorDetail && <SwatchCardMini color={currentColorDetail} />}</div>
            {comparisonColors.map((color, i) =>
                  color ? <SwatchCardMini key={i} color={color} /> : <div key={i} className="slot" />
                )}
          </div>
      </Column>

    <Column align="center" className="swatch-gallery">
      <div className="gallery-scroll-container">
          {Object.entries(schemeMatches).map(([label, swatches], index) => (
            <div key={label} className="scheme-group">
            <h4>Color {index + 1} Matches</h4>
              <SwatchGallery
                swatchComponent={SwatchCardTiny}
                swatches={swatches}
                onSelect={(color) => handleSelectComparison(index + 1, color)}
              />
            </div>
          ))}
      </div>
   </ Column>

      </ResponsiveRow>
    
    </div>
  );
}


