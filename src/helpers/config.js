console.log('📦 Loaded config.js with API_FOLDER');

const PROD_HOST = "colorfix.terrymarr.com";
const hasWindow = typeof window !== "undefined";
const origin = hasWindow ? window.location.origin : `https://${PROD_HOST}`;
const isProdHost = hasWindow ? window.location.hostname === PROD_HOST : true;

export const API_FOLDER = isProdHost ? `https://${PROD_HOST}/api` : `${origin}/api`;

export const SHARE_FOLDER = isProdHost ? `https://${PROD_HOST}/share` : `${origin}/share`;
