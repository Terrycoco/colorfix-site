import http from "node:http";
import https from "node:https";
import { URL } from "node:url";

const DEFAULT_BASE_URL = "https://colorfix.terrymarr.com";

export function getBaseUrl() {
  return String(process.env.COLORFIX_BASE_URL || DEFAULT_BASE_URL).replace(/\/+$/, "");
}

export async function fetchJson(pathOrUrl, options = {}) {
  const url = pathOrUrl.startsWith("http")
    ? pathOrUrl
    : `${getBaseUrl()}/${String(pathOrUrl || "").replace(/^\/+/, "")}`;
  const response = await request(url, options);
  try {
    return JSON.parse(response.body.toString("utf8"));
  } catch {
    const preview = response.body.toString("utf8").replace(/\s+/g, " ").slice(0, 180);
    throw new Error(`Non-JSON response from ${url}: ${preview}`);
  }
}

export async function postJson(pathOrUrl, payload) {
  return fetchJson(pathOrUrl, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
    },
    body: Buffer.from(JSON.stringify(payload || {})),
  });
}

function request(url, options = {}) {
  return new Promise((resolve, reject) => {
    const parsed = new URL(url);
    const client = parsed.protocol === "https:" ? https : http;
    const req = client.request(url, {
      method: options.method || "GET",
      headers: options.headers || {},
    }, (res) => {
      if ([301, 302, 303, 307, 308].includes(res.statusCode || 0) && res.headers.location) {
        res.resume();
        const next = new URL(res.headers.location, url).toString();
        request(next, options).then(resolve, reject);
        return;
      }

      const chunks = [];
      res.on("data", (chunk) => chunks.push(chunk));
      res.on("end", () => {
        const body = Buffer.concat(chunks);
        if ((res.statusCode || 0) < 200 || (res.statusCode || 0) >= 300) {
          reject(new Error(`HTTP ${res.statusCode} from ${url}: ${body.toString("utf8").slice(0, 180)}`));
          return;
        }
        resolve({ statusCode: res.statusCode, headers: res.headers, body });
      });
    });

    req.on("error", reject);
    req.setTimeout(30000, () => {
      req.destroy(new Error(`Timed out calling ${url}`));
    });

    if (options.body) {
      req.write(options.body);
    }
    req.end();
  });
}
