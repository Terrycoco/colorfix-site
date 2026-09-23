import { useState } from "react";

import PlaylistRexDialog from "@components/REX/PlaylistRexDialog";

import "./PlaylistRexButton.css";


export default function PlaylistRexButton({
  playlistId,
  title = "",
  disabled = false,
}) {
  const [
    open,
    setOpen,
  ] = useState(false);


  const id =
    Number(
      playlistId ||
      0
    );


  return (
    <>
      <button
        type="button"
        className="playlist-rex-button"
        disabled={
          disabled ||
          !id
        }
        title="Outside Links / REX"
        aria-label="Outside Links / REX"
        onClick={() =>
          setOpen(true)
        }
      >
        <span aria-hidden="true">
          R↗
        </span>
      </button>


      <PlaylistRexDialog
        open={open}
        playlistId={id}
        title={title}
        onClose={() =>
          setOpen(false)
        }
      />
    </>
  );
}
