import path from "node:path";
import { fileURLToPath } from "node:url";
import { YoutubeDraftExportService } from "./services/YoutubeDraftExportService.mjs";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, "..");

function readArg(name, fallback = "") {
  const prefix = `--${name}=`;
  const match = process.argv.find((arg) => arg.startsWith(prefix));
  if (match) return match.slice(prefix.length);
  const index = process.argv.indexOf(`--${name}`);
  if (index >= 0) return process.argv[index + 1] || fallback;
  return fallback;
}

async function main() {
  const playlistId = Number(process.argv[2] || readArg("playlist-id") || readArg("playlist") || 37);
  const baseUrl = readArg("base-url", "https://colorfix.terrymarr.com");
  const service = new YoutubeDraftExportService({
    rootDir: ROOT,
    baseUrl,
  });

  const result = await service.exportPlaylist(playlistId);
  console.log(`Exported ${result.title}`);
  console.log(`Playlist: ${result.playlistId}`);
  console.log(`Slides: ${result.slideCount}`);
  console.log(result.outputPath);
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
