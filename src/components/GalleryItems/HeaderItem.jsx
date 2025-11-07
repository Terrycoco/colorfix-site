export default function HeaderItem({meta}) {

  


  function injectColorName(template) {
      return template.replace(/{{\s*colorName\s*}}/gi, meta.params.origName);
    }




    return (
        <div className="header">
           <h3 className="header-title">{injectColorName(meta.header_title)}</h3> 
             <h4 className="header-subtitle">{injectColorName(meta.header_subtitle)}</h4> 
           <div className="header-content">{injectColorName(meta.header_content)}</div>
        </div>
    )
}