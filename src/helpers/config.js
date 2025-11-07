console.log('📦 Loaded config.js with API_FOLDER');



export const API_FOLDER =
  import.meta.env.PROD
    ? 'https://colorfix.terrymarr.com/api'
    : '/api';