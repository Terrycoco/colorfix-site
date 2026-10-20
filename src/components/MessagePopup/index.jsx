import { createPortal } from "react-dom";
import { useAppState } from '@context/AppStateContext';
import './message.css';

export default function MessagePopup() {
    const {message} = useAppState();

    return (
        <>
        {message && (
            createPortal(<div data-overlay-notification className="message-popup">{message}</div>, document.body)
        )}
        </>
    );

}