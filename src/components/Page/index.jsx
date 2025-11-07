
import {useAppState} from '@context/AppStateContext'

function Page({children}) {

    const {setInfoCardVisible} = useAppState();

    function closeInfo(e) {
        e.stopPropagation;
        setInfoCardVisible(false);
    }

    return (
        <div className="page" onClick={closeInfo}>
            {children}
        </div>
    )
}

export default Page;