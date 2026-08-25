import { API_FOLDER } from "@helpers/config";

const PLAYLIST_REX_URL =
  `${API_FOLDER}/v2/admin/rex/playlist-url.php`;

const CREATE_REX_URL =
  `${API_FOLDER}/v2/admin/rex/create.php`;

export async function fetchRex(
  playlistId,
  src = null
) {
  const id = Number(playlistId || 0);

  if (!id) {
    throw new Error(
      "Playlist ID is required."
    );
  }

  let item =
    await findPlaylistRex(id);

  if (!item) {
    item =
      await createPlaylistRex(id);
  }

  const publicUrl =
    String(
      item?.public_url || ""
    ).trim();

  if (!publicUrl) {
    throw new Error(
      "REX did not return a public URL."
    );
  }

    return appendSource(
    absoluteRexUrl(publicUrl),
    src
    );
}

async function findPlaylistRex(
  playlistId
) {
  const params =
    new URLSearchParams({
      playlist_id:
        String(playlistId),

      _:
        String(Date.now()),
    });

  const res =
    await fetch(
      `${PLAYLIST_REX_URL}?${params.toString()}`,
      {
        credentials:
          "include",
      }
    );

  const data =
    await res.json();

  if (
    !res.ok ||
    !data?.ok
  ) {
    throw new Error(
      data?.error ||
      "Failed to fetch playlist REX."
    );
  }

  return data.item || null;
}

async function createPlaylistRex(
  playlistId
) {
  const res =
    await fetch(
      CREATE_REX_URL,
      {
        method: "POST",

        credentials:
          "include",

        headers: {
          "Content-Type":
            "application/json",
        },

        body:
          JSON.stringify({
            label:
              `Public Playlist ${playlistId}`,

            resolver_key:
              "playlist_experience",

            resource_type:
              "playlist",

            resource_id:
              playlistId,

            context: {
              experience_key:
                "public",
            },

            reuse_existing:
              true,
          }),
      }
    );

  const data =
    await res.json();

  if (
    !res.ok ||
    !data?.ok
  ) {
    throw new Error(
      data?.error ||
      "Failed to create playlist REX."
    );
  }

  return data.item || null;
}


function absoluteRexUrl(url) {
  const value =
    String(url || "").trim();

  if (!value) {
    return "";
  }

  if (/^https?:\/\//i.test(value)) {
    return value;
  }

  return (
    "https://colorfix.terrymarr.com/"
    + value.replace(/^\/+/, "")
  );
}


function appendSource(
  url,
  src
) {
  const value =
    String(url || "").trim();

  const source =
    String(src || "").trim();

  if (!source) {
    return value;
  }

  const separator =
    value.includes("?")
      ? "&"
      : "?";

  return (
    `${value}${separator}src=`
    + encodeURIComponent(source)
  );
}

const PLAYLIST_PALETTES_URL =
  `${API_FOLDER}/v2/admin/rex/playlist-palettes.php`;

export async function fetchPaletteArray(
  playlistId
) {
  const id =
    Number(
      playlistId || 0
    );

  if (!id) {
    throw new Error(
      "Playlist ID is required."
    );
  }

  const params =
    new URLSearchParams({
      playlist_id:
        String(id),

      _:
        String(
          Date.now()
        ),
    });

  const res =
    await fetch(
      `${PLAYLIST_PALETTES_URL}?${params.toString()}`,
      {
        credentials:
          "include",
      }
    );

  const data =
    await res.json();

  if (
    !res.ok ||
    !data?.ok
  ) {
    throw new Error(
      data?.error ||
      "Failed to resolve playlist palettes."
    );
  }

  return Array.isArray(
    data.items
  )
    ? data.items
    : [];
}