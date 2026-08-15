import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import path from 'node:path'
import fs from 'node:fs/promises'
import { spawn } from 'node:child_process'
import svgr from 'vite-plugin-svgr'
import { YOUTUBE_VIDEO_TIMING } from './src/remotion/youtubeVideoTiming.js'

export default defineConfig({
  plugins: [
    adminDevSpaFallback(),
    localYoutubePreviewApi(),
    nonBlockingPlayerCss(),
    react(),
    tailwindcss(),
    svgr(),
  ],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
      '@styles': path.resolve(__dirname, './src/styles'),
      '@config': path.resolve(__dirname, './src/config'),
      '@helpers': path.resolve(__dirname, './src/helpers'),
      '@components': path.resolve(__dirname, './src/components'),
      '@context': path.resolve(__dirname, './src/context'),
      '@data': path.resolve(__dirname, './src/data'),
      '@layout': path.resolve(__dirname, './src/layout'),
      '@pages': path.resolve(__dirname, './src/pages'),
      '@hooks': path.resolve(__dirname, './src/hooks'),
      '@test': path.resolve(__dirname, './src/test'),
      '@lib': path.resolve(__dirname, './src/lib'),
      '@Analytics': path.resolve(__dirname, './src/Analytics'),

    },
  },
  server: {
    port: 5173,
    strictPort: true,
    watch: {
      ignored: ['**/tmp_partial.jsx'],
    },
    proxy: {
      // Proxy ALL /api requests to your PHP host in dev
      '/api': {
        target: 'https://colorfix.terrymarr.com',
        changeOrigin: true,
        secure: false,              // ignore TLS quirks on shared hosts
        rewrite: (p) => p,          // keep /api/v2/... path as-is
      },
    },
  },
  build: {
    rollupOptions: {
      input: {
        main: path.resolve(__dirname, 'index.html'),
        admin: path.resolve(__dirname, 'admin/index.html'),
        player: path.resolve(__dirname, 'player/index.html'),
      },
    },
  },
})

function localYoutubePreviewApi() {
  return {
    name: 'local-youtube-preview-api',
    configureServer(server) {
      server.middlewares.use('/api/v2/admin/asset-creators/preview.php', async (req, res, next) => {
        if (req.method !== 'POST') {
          res.statusCode = 405;
          res.setHeader('Content-Type', 'application/json; charset=UTF-8');
          res.end(JSON.stringify({ ok: false, error: 'POST only' }));
          return;
        }

        try {
          const payload = JSON.parse(await readRequestBody(req) || '{}');
          const creatorKey = String(payload?.creator_key || payload?.recipe?.creator_key || '').trim();
          if (creatorKey !== 'youtube.playlist_video') {
            res.statusCode = 400;
            res.setHeader('Content-Type', 'application/json; charset=UTF-8');
            res.end(JSON.stringify({ ok: false, error: `Preview is not wired for creator: ${creatorKey}` }));
            return;
          }

          const root = process.cwd();
          const previewDir = path.join(root, 'exports', 'youtube-preview', 'current');
          await fs.mkdir(previewDir, { recursive: true });
          const recipePath = path.join(previewDir, 'recipe.json');
          const outputPath = path.join(previewDir, 'preview.mp4');
          const plan = youtubePlanFromRecipe(payload.recipe || payload.instructions || {});
          await fs.writeFile(recipePath, JSON.stringify({ plan }, null, 2));
          await runCommand('node', [
            path.join(root, 'scripts', 'render-youtube-video.mjs'),
            `--recipe=${recipePath}`,
            `--output=${outputPath}`,
            '--preview=1',
          ], root);

          const stat = await fs.stat(outputPath);
          res.statusCode = 200;
          res.setHeader('Content-Type', 'application/json; charset=UTF-8');
          res.end(JSON.stringify({
            ok: true,
            preview: {
              kind: 'video',
              preview_type: 'local_video',
              channel: 'youtube',
              title: plan.title || 'YouTube Preview',
              local_path: outputPath,
              recipe_path: recipePath,
              open_url: '/exports/youtube-preview/current/preview.mp4',
              file_size_bytes: stat.size,
              duration_seconds: durationSeconds(plan),
              slide_count: plan.items.length,
              persisted: false,
              creates_asset_library_row: false,
              cleanup_policy: 'replace_previous_preview',
            },
          }));
        } catch (error) {
          res.statusCode = 400;
          res.setHeader('Content-Type', 'application/json; charset=UTF-8');
          res.end(JSON.stringify({ ok: false, error: error?.message || 'Preview failed' }));
        }
      });
    },
  };
}

function readRequestBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    req.on('data', (chunk) => chunks.push(chunk));
    req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
    req.on('error', reject);
  });
}

function runCommand(command, args, cwd) {
  return new Promise((resolve, reject) => {
    const child = spawn(command, args, {
      cwd,
      stdio: ['ignore', 'pipe', 'pipe'],
      env: process.env,
    });
    let output = '';
    child.stdout.on('data', (chunk) => { output += chunk.toString(); });
    child.stderr.on('data', (chunk) => { output += chunk.toString(); });
    child.on('error', reject);
    child.on('exit', (code) => {
      if (code === 0) {
        resolve();
        return;
      }
      reject(new Error(output.trim() || `${command} exited with code ${code}`));
    });
  });
}

function youtubePlanFromRecipe(recipe) {
  const rows = Array.isArray(recipe?.video_rows)
    ? recipe.video_rows
    : (Array.isArray(recipe?.pairs) ? recipe.pairs : []);
  const row = rows.find((candidate) => candidate && candidate.include !== false);
  if (!row) {
    throw new Error('YouTube recipe has no included video row.');
  }

  const slides = Array.isArray(row.slides) ? row.slides : [];
  const items = slides.map((slide, index) => {
    const asset = slide?.asset && typeof slide.asset === 'object' ? slide.asset : {};
    const itemType = String(slide?.item_type || slide?.type || 'normal');
    const imageUrl = absoluteColorFixUrl(asset.public_url || slide?.image_url || slide?.public_url || '');
    if (!imageUrl && itemType.toLowerCase().trim() !== 'brand-bumper') return null;
    return {
      playlist_item_id: slide?.playlist_item_id ? Number(slide.playlist_item_id) : null,
      type: itemType,
      item_type: itemType,
      title: String(slide?.title || asset.title || ''),
      subtitle: String(slide?.subtitle || ''),
      body: String(slide?.body || ''),
      image_url: imageUrl,
      duration_ms: slide?.duration_ms ? Number(slide.duration_ms) : null,
      sort_order: index + 1,
    };
  }).filter(Boolean);

  if (!items.length) {
    throw new Error('YouTube preview needs at least one included slide.');
  }

  const title = String(row.search_title || row.title || recipe?.source?.title || 'ColorFix YouTube Preview').trim();
  const music = musicFromRecipe(recipe);
  return {
    playlist_id: recipe?.source?.playlist_id ? Number(recipe.source.playlist_id) : null,
    title: title || 'ColorFix YouTube Preview',
    type: 'youtube_preview',
    total_items: items.length,
    items,
    music,
    video: {
      width: YOUTUBE_VIDEO_TIMING.width,
      height: YOUTUBE_VIDEO_TIMING.height,
      fps: YOUTUBE_VIDEO_TIMING.fps,
      default_slide_duration_ms: YOUTUBE_VIDEO_TIMING.defaultSlideDurationMs,
      default_intro_duration_ms: YOUTUBE_VIDEO_TIMING.defaultIntroDurationMs,
      default_text_duration_ms: YOUTUBE_VIDEO_TIMING.defaultTextDurationMs,
      default_hue_wheel_duration_ms: YOUTUBE_VIDEO_TIMING.defaultHueWheelDurationMs,
      default_brand_bumper_duration_ms: YOUTUBE_VIDEO_TIMING.defaultBrandBumperDurationMs,
      dissolve_ms: YOUTUBE_VIDEO_TIMING.dissolveMs,
      cut_ms: YOUTUBE_VIDEO_TIMING.cutMs,
      caption_delay_after_photo_ms: YOUTUBE_VIDEO_TIMING.captionDelayAfterPhotoMs,
      caption_fade_ms: YOUTUBE_VIDEO_TIMING.captionFadeMs,
      signature_reveal_delay_ms: YOUTUBE_VIDEO_TIMING.signatureRevealDelayMs,
      signature_reveal_duration_ms: YOUTUBE_VIDEO_TIMING.signatureRevealDurationMs,
      final_fade_ms: YOUTUBE_VIDEO_TIMING.finalFadeMs,
      timeline: buildYoutubeTimeline(items),
    },
  };
}

function musicFromRecipe(recipe) {
  const music = recipe?.music && typeof recipe.music === 'object'
    ? recipe.music
    : (recipe?.source?.music && typeof recipe.source.music === 'object' ? recipe.source.music : null);
  if (!music) return null;
  const src = absoluteColorFixUrl(music.public_url || music.src || music.rel_path || '');
  if (!src) return null;
  const volume = Math.max(0, Math.min(1, Number(music.volume ?? 0.35)));
  return {
    asset_library_id: music.asset_library_id ? Number(music.asset_library_id) : null,
    title: String(music.title || ''),
    src,
    public_url: src,
    rel_path: String(music.rel_path || ''),
    mime_type: String(music.mime_type || ''),
    volume,
  };
}

function buildYoutubeTimeline(items) {
  let cursor = 0;
  return items.map((item, index) => {
    const duration = youtubeSlideDuration(item);
    const transition = String(item.transition || 'animation').toLowerCase().trim() === 'cut' ? 'cut' : 'dissolve';
    const entry = {
      index,
      start_ms: cursor,
      duration_ms: duration,
      end_ms: cursor + duration,
      transition,
      transition_ms: transition === 'cut' ? YOUTUBE_VIDEO_TIMING.cutMs : YOUTUBE_VIDEO_TIMING.dissolveMs,
    };
    cursor += duration;
    return entry;
  });
}

function youtubeSlideDuration(item) {
  const explicit = Number(item?.duration_ms || 0);
  const type = String(item?.type || item?.item_type || 'normal').toLowerCase().trim();
  if (type === 'brand-bumper') return Math.max(YOUTUBE_VIDEO_TIMING.defaultBrandBumperDurationMs, explicit || 0);
  if (explicit > 0) return explicit;
  if (type === 'intro') return YOUTUBE_VIDEO_TIMING.defaultIntroDurationMs;
  if (type === 'text') return YOUTUBE_VIDEO_TIMING.defaultTextDurationMs;
  if (type === 'hue-wheel') return YOUTUBE_VIDEO_TIMING.defaultHueWheelDurationMs;
  return YOUTUBE_VIDEO_TIMING.defaultSlideDurationMs;
}

function durationSeconds(plan) {
  const timeline = Array.isArray(plan?.video?.timeline) ? plan.video.timeline : [];
  const end = timeline.reduce((max, entry) => Math.max(max, Number(entry?.end_ms || 0)), 0);
  return Math.round((end / 1000) * 100) / 100;
}

function absoluteColorFixUrl(value) {
  const raw = String(value || '').trim();
  if (!raw) return '';
  if (/^https?:\/\//i.test(raw)) return raw;
  return `https://colorfix.terrymarr.com/${raw.replace(/^\/+/, '')}`;
}

function adminDevSpaFallback() {
  return {
    name: 'admin-dev-spa-fallback',
    configureServer(server) {
      server.middlewares.use(async (req, res, next) => {
        const method = req.method || 'GET';
        if (method !== 'GET' && method !== 'HEAD') {
          next();
          return;
        }

        const url = new URL(req.url || '/', 'http://localhost');
        const pathname = url.pathname;
        const acceptsHtml = String(req.headers.accept || '').includes('text/html');
        if (!acceptsHtml || (pathname !== '/admin' && !pathname.startsWith('/admin/'))) {
          next();
          return;
        }

        if (path.extname(pathname)) {
          next();
          return;
        }

        try {
          const htmlPath = path.resolve(process.cwd(), 'admin/index.html');
          const rawHtml = await fs.readFile(htmlPath, 'utf8');
          const html = await server.transformIndexHtml(pathname, rawHtml);
          res.statusCode = 200;
          res.setHeader('Content-Type', 'text/html');
          res.end(html);
        } catch (error) {
          next(error);
        }
      });
    },
  };
}

function nonBlockingPlayerCss() {
  return {
    name: 'non-blocking-player-css',
    enforce: 'post',
    transformIndexHtml: {
      order: 'post',
      handler(html, context) {
        const filename = context?.filename ? path.normalize(context.filename) : '';
        if (!filename.endsWith(path.normalize('player/index.html'))) return html;
        return html.replace(
          /<link rel="stylesheet" crossorigin href="([^"]+)">/g,
          (_, href) => (
            `<link rel="preload" as="style" crossorigin href="${href}" onload="this.onload=null;this.rel='stylesheet'">`
            + `<noscript><link rel="stylesheet" crossorigin href="${href}"></noscript>`
          ),
        );
      },
    },
  };
}
