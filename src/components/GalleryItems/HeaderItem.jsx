import ReferenceSwatch from '../Swatches/ReferenceSwatch';

export default function HeaderItem({meta}) {
  const referenceColor = meta?.search_mode === 'hex'
    ? meta.reference_color
    : null;


  function injectColorName(template) {
      return template.replace(/{{\s*colorName\s*}}/gi, meta.params.origName);
    }




    return (
        <div className="header">
           <div className={referenceColor ? 'header-hex-title' : undefined}>
             {referenceColor && <ReferenceSwatch color={referenceColor} />}
             <h3 className="header-title">{injectColorName(meta.header_title)}</h3>
           </div>
             <h4 className="header-subtitle">{injectColorName(meta.header_subtitle)}</h4> 
           <div className="header-content">{injectColorName(meta.header_content)}</div>
        </div>
    )
}
