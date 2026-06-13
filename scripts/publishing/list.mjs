import { listAssetCreators, listChannels } from "./registry.mjs";

const channelArg = process.argv.find((arg) => arg.startsWith("--channel="));
const channelKey = channelArg ? channelArg.slice("--channel=".length) : "";

console.log("Publishing channels:");
for (const channel of listChannels()) {
  console.log(`- ${channel.channelKey}: ${channel.label} (${channel.assetCreatorCount} asset creators)`);
}

console.log("");
console.log(channelKey ? `Asset creators for ${channelKey}:` : "Asset creators:");
for (const creator of listAssetCreators(channelKey)) {
  const status = creator.status ? ` [${creator.status}]` : "";
  const entrypoint = creator.entrypoint ? ` -> ${creator.entrypoint}` : "";
  console.log(`- ${creator.channelKey}/${creator.assetType}: ${creator.label}${status}${entrypoint}`);
}
