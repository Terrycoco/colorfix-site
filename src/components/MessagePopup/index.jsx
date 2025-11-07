import { useAppState } from '@context/AppStateContext';
import './message.css';

export default function MessagePopup() {
    const {message} = useAppState();

    return (
        <>
        {message && (
            <div className="message-popup">{message}</div>
        )}
        </>
    );

}