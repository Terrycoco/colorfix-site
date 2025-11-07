import MobileLayout from '@layout/MobileLayout';
import {API_FOLDER} from '@helpers/config';
import { useNavigate, useLocation } from 'react-router-dom';
import {useParams} from 'react-router-dom';
import {useEffect, useState} from 'react';
import { useAppState } from '@context/AppStateContext';
import fetchColorDetail from '@data/fetchColorDetail';
import ColorWheel300 from '@components/ColorWheel/ColorWheel300';
import {getColorUrl} from '@helpers/colorUrlHelper';
import './detailpage.css';
import {PaletteToggleIcon} from '@components/Icons/PaletteIcons';
import TopSpacer from '@layout/TopSpacer';

function formatChip(chipNum) {
  if (!chipNum) return '';
  // if it contains anything besides digits
  if (!/^\d+$/.test(chipNum)) {
    return chipNum + ' Brochure';
  }
  return chipNum;
}



export default function MobileDetailPage() {
   const {id} = useParams();
   const [ogImage, setOgImage] = useState(null);
   const navigate = useNavigate();
   const location = useLocation();
   const {palette, currentColorDetail, setCurrentColorDetail, setShowBack, recentSwatches, setRecentSwatches, addToPalette, setShowPalette} = useAppState();
  const inPalette = palette?.some((c) => c.id === currentColorDetail.id);
  const text = currentColorDetail?.hcl_l > 70 ? '#111' : '#fff';
 
    const previous = recentSwatches.length > 1
      ? recentSwatches[recentSwatches.length - 2]
      : null;

  // helpers near top of component (add these lines once)
  const isStain = Number(currentColorDetail?.is_stain) === 1;
  const nameHasStain = typeof currentColorDetail?.name === 'string' && /\bstain\b/i.test(currentColorDetail.name);
  const displayName = isStain && !nameHasStain ? `${currentColorDetail.name} (Stain)` : currentColorDetail.name;





   //USE EFFECTS

   //scroll to top of main layout
  useEffect(() => {
    const mainEl = document.querySelector("main");
    const isMainScrollable =
      mainEl && mainEl.scrollHeight > mainEl.clientHeight;

    const jumpTop = () => {
      if (isMainScrollable) {
        const prev = mainEl.style.scrollBehavior; // defeat any global smooth
        mainEl.style.scrollBehavior = "auto";
        mainEl.scrollTo({ top: 0, left: 0, behavior: "auto" });
        mainEl.style.scrollBehavior = prev;
      } else {
        // cover all browsers
        window.scrollTo({ top: 0, left: 0, behavior: "auto" });
        document.documentElement.scrollTop = 0;
        document.body.scrollTop = 0;
      }
    };

    // wait a tick so the new page paints (mobile Safari quirk)
    requestAnimationFrame(() => requestAnimationFrame(jumpTop));
    const t = setTimeout(jumpTop, 300); // nudge in case images/layout shift
    return () => clearTimeout(t);
  }, [location.key]); // runs on each navigation to this page

    useEffect(() => {
      setShowBack(true);

      return () => {
        setShowBack(false); //on unload
      };
    }, []);  //on load and unload


  //get the full info on this color
    useEffect(() => {
      if (id) {
        fetchColorDetail(id, setCurrentColorDetail);
      }
    }, [id, setCurrentColorDetail]);

    //fetch pic if available
    useEffect(() => {
      const fullUrl = getColorUrl(
        currentColorDetail.color_url,
        currentColorDetail.base_url
      );
      if (!fullUrl) return;

        const fetchOgImage = async () => {
          try {
            console.log('fetching:', fullUrl);
            const res = await fetch(`${API_FOLDER}/fetch-og-image.php?url=${encodeURIComponent(fullUrl)}`);
            const data = await res.json();
            setOgImage(data.image || null);
          } catch (err) {
            console.error('Failed to fetch og:image:', err);
          }
        };

        fetchOgImage();
    }, [currentColorDetail]);


    //add this to recently viewed list
    useEffect(() => {
      if (!currentColorDetail) return;

      // extract just the base swatch info
          const swatch = {
            id: currentColorDetail.id,
            name: currentColorDetail.name,
            brand_name: currentColorDetail.brand_name,
            code: currentColorDetail.code,
            r: currentColorDetail.r,
            g: currentColorDetail.g,
            b: currentColorDetail.b,
            hcl_l: currentColorDetail.hcl_l,
            hcl_c: currentColorDetail.hcl_c,
            hcl_h: currentColorDetail.hcl_h,
            cluster_id: currentColorDetail.cluster_id
          };

          setRecentSwatches(prev => {
            if (
              swatch &&
              swatch.id != null &&
              typeof swatch.r === 'number' &&
              typeof swatch.g === 'number' &&
              typeof swatch.b === 'number' &&
              swatch.name &&
              swatch.code &&
              swatch.brand_name
            ) {
              if (prev.find(c => c.id === swatch.id)) return prev;
              return [...prev.slice(-4), swatch]; // max 5
            }
            return prev;
          });

    }, [currentColorDetail]);

    useEffect(() => {
        console.log('recent: ', recentSwatches);
    }, [recentSwatches]);



    //HANDLERS

    const handleAddToPalette = () => {
      addToPalette(currentColorDetail);
      setShowPalette(true);
    }

    const handleBack = () => {
      navigate(-1);
    }

    const handleClick = (e) => {
        e.preventDefault();
        e.stopPropagation();
        console.log('palettebefore', palette);
        inPalette ? removeFromPalette(currentColorDetail.id) : addToPalette(currentColorDetail);
    }

    const handleCompare = () => {
       navigate('/sbs')
    };

    function formatCategoryList(catString) {
      if (!catString) return '';

      const cats = catString.split(',').map(s => s.trim());

      if (cats.length === 1) {
        return `<strong>${cats[0]}</strong> category`;
      } else if (cats.length === 2) {
        return `<strong>${cats[0]}</strong> and <strong>${cats[1]}</strong> categories`;
      } else {
        const wrapped = cats.map(cat => `<strong>${cat}</strong>`);
        const allButLast = wrapped.slice(0, -1).join(', ');
        const last = wrapped[wrapped.length - 1];
        return `${allButLast}, and ${last} categories`;
      }
    }




    if (!currentColorDetail) return <MobileLayout>Loading…</MobileLayout>;

    return (
    <MobileLayout>
      <TopSpacer />
      <div className="p-1">


            

      {/* SWATCH */}
        <div
          className="mt-4 relative w-full h-32 rounded-xl border border-gray-300 shadow-inner detail-swatch"
          onClick={(e) => e.stopPropagation()}
          style={{
            // for stains we’ll tint over a wood substrate, so keep the base transparent
            backgroundColor: isStain
              ? 'transparent'
              : `rgb(${currentColorDetail.r}, ${currentColorDetail.g}, ${currentColorDetail.b})`,
            color: text,
            // pass RGB to CSS for the tint layer
            '--stain-rgb': `${currentColorDetail.r}, ${currentColorDetail.g}, ${currentColorDetail.b}`,
            // sensible defaults, tweak anytime
            '--wood-size': 'clamp(140px, 55vw, 320px)',
            '--stain-alpha': isStain ? 0.90 : 1
          }}
          data-is-stain={isStain ? 1 : 0}
          data-stain-tone={typeof currentColorDetail.hcl_l === 'number' && currentColorDetail.hcl_l <= 55 ? 'dark' : 'light'}
        >
              <button
                  type="button"
                  className="pals-btn"
                  aria-label={inPalette ? 'Remove from palette' : 'Add to palette'}
                  onClick={handleClick}
                >
                  <PaletteToggleIcon
                    active={inPalette}
                    color={text}        // pass your contrast-aware text color here
                    className="pals-icon"
                  />
        </button>

          {currentColorDetail?.chip_num?.length > 0 && (
            <span
              className="chip-badge absolute top-2 right-2 z-10 px-2 py-0.5 rounded-md text-xs 
                          pointer-events-none select-none"
              aria-label={`Chip #${currentColorDetail.chip_num}`}
              title="Chip #"
            >
              {currentColorDetail.chip_num}
            </span>
          )}




       </div>


       <div className="w-full text-right text-xs">{currentColorDetail.id}/{currentColorDetail.cluster_id}</div>





    {/* INFO & WHEEL */}

  <div id="color-info" className="px-2 pb-16 mt-4">
        <h3 className="mt-4 text-xl font-bold">{displayName}</h3>
           <p className="text-sm text-gray-600">
          {currentColorDetail.brand_name} · {currentColorDetail.code}
        </p>
       

       
     
       
            <div className="text-sm mt-4 flex justify-start gap-4 w-full ">
              <span><strong>Hue:</strong> {Number(currentColorDetail.hcl_h).toFixed(4)}</span>
              <span><strong>Chroma:</strong> {Number(currentColorDetail.hcl_c).toFixed(4)}</span>
              <span><strong>Lightness:</strong> {Number(currentColorDetail.hcl_l).toFixed(4)}</span>
            </div>
            <p className="text-sm mt-2"><strong>Hue:  </strong>The hue is {Number(currentColorDetail.hcl_h).toFixed(4)} which puts it in  <strong>{currentColorDetail.hue_cats}</strong> on the HCL Color Wheel.</p>



            <div className="py-4">
            <ColorWheel300 currentColor={currentColorDetail} />
            </div>


            {currentColorDetail.neutral_cats ? (
              <>  <p
                  className="text-sm mt-2"
                  dangerouslySetInnerHTML={{
                    __html: `Because of the low chroma values, this color is also considered a neutral, in the ${formatCategoryList(currentColorDetail.neutral_cats)}.`
                  }}
                ></p>
                <p className="text-sm mt-2">
                  <strong>{currentColorDetail.chroma_cat}</strong> &mdash; {currentColorDetail.name} has a chroma of {Number(currentColorDetail.hcl_c).toFixed(4)}, which puts it in the {currentColorDetail.chroma_cat} range, {currentColorDetail.chroma_cat_descr}
                </p>
                </>
              ) : (
                 <p className="text-sm mt-2"><strong>Chroma:  {currentColorDetail.chroma_cat}</strong> &mdash; {currentColorDetail.name} has a chroma of {Number(currentColorDetail.hcl_c).toFixed(4)}, which puts it in the {currentColorDetail.chroma_cat} range, {currentColorDetail.chroma_cat_descr}</p>
              )
              
              }
         


            <p className="text-sm mt-2"><strong>Lightness:  {currentColorDetail.light_cat}</strong> &mdash; {currentColorDetail.name} has a lightness value of {Number(currentColorDetail.hcl_l).toFixed(4)} out of 100, which puts it in the {currentColorDetail.light_cat} range, {currentColorDetail.light_cat_descr}</p>
    
           <p className="text-sm mt-2"><strong>Hex:</strong> #{currentColorDetail.hex6}</p>
            <p className="text-sm mt-2"><strong>LRV:</strong> {currentColorDetail.lrv}</p>
              
            <div className="flex gap-8 mt-2 text-sm" >
                   <ul className="text-sm mt-2"><strong>RGB</strong>
              <li><strong>{`R:  `}</strong>{currentColorDetail?.r}</li>
              <li><strong>{`G:  `}</strong>{currentColorDetail?.g}</li>
              <li><strong>{`B:  `}</strong>{currentColorDetail?.b}</li>
              </ul>
              <ul className="text-sm mt-2"><strong>HSL</strong>
              <li><strong>{`H:  `}</strong>{Number(currentColorDetail?.hsl_h?.toFixed(3))}</li>
              <li><strong>{`S:  `}</strong>{Number(currentColorDetail?.hsl_s?.toFixed(3))}</li>
              <li><strong>{`L:  `}</strong>{Number(currentColorDetail?.hsl_l?.toFixed(3))}</li>
              </ul>
              <ul className="text-sm mt-2"><strong>CIELAB</strong>
              <li><strong>{`L:  `}</strong>{Number(currentColorDetail?.lab_l?.toFixed(3))}</li>
              <li><strong>{`a:  `}</strong>{Number(currentColorDetail?.lab_a?.toFixed(3))}</li>
              <li><strong>{`b:  `}</strong>{Number(currentColorDetail?.lab_b?.toFixed(3))}</li>
              </ul>
              </div>
            {currentColorDetail.chip_num && (<p className="text-sm mt-2"><strong>Chip Locator #:</strong> {formatChip(currentColorDetail.chip_num)}</p>)}
             <p className="text-sm mt-2"> {currentColorDetail.exterior == 0 ? "Not recommended for Exterior use" : ''}</p>
    </div>


          

  {ogImage && (
    <a
      href={getColorUrl(currentColorDetail.color_url, currentColorDetail.base_url)}
      target="_blank"
      rel="noopener noreferrer"
      className="block mt-4"
    >
      <img
        src={ogImage.replace(/&amp;/g, '&')}
        alt={`Preview of ${currentColorDetail.name}`}
        className="w-full rounded-md border"
      />
      <p className="text-xs text-gray-500 italic mt-1">
        Official preview from {currentColorDetail.brand_name}
      </p>
    </a>
  )}


      {/* COMPARE */}
         {recentSwatches.length > 1 && recentSwatches[recentSwatches.length - 2].id !== currentColorDetail.id && (
       <div className="flex items-center justify-between mt-2">
          <div className="flex items-center gap-2 text-xs text-gray-600">
            <span className="mr-1">Colors Viewed:</span>
            {recentSwatches.map(swatch => (
              <div
                key={swatch.id}
                className="w-5 h-5 rounded-sm border"
                style={{
                  backgroundColor: `rgb(${swatch.r}, ${swatch.g}, ${swatch.b})`,
                }}
              />
            ))}
        </div>

        <button onClick={handleCompare} className="compare-button">
          See side by side
        </button>
      </div>



         )}




      </div>
    </MobileLayout>
  );
}