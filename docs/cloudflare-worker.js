export default {
  async fetch(request) {
    const url = new URL(request.url);
    const target = url.searchParams.get("url");

    if (!target) {
      return json({ error: "missing url" }, 400);
    }

    let targetUrl;
    try {
      targetUrl = new URL(target);
    } catch {
      return json({ error: "invalid url" }, 400);
    }

    const allowedHosts = new Set(["api.ice.ir", "ice.ir"]);
    if (!allowedHosts.has(targetUrl.hostname)) {
      return json({ error: "host not allowed" }, 403);
    }

    const upstream = await fetch(targetUrl.toString(), {
      method: "GET",
      headers: {
        "accept": "application/json, text/plain, */*",
        "user-agent":
          "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36",
        "accept-language": "fa-IR,fa;q=0.9,en;q=0.8",
      },
      cf: {
        cacheEverything: false,
      },
    });

    const body = await upstream.text();

    return new Response(body, {
      status: upstream.status,
      headers: {
        "content-type":
          upstream.headers.get("content-type") || "application/json; charset=utf-8",
        "access-control-allow-origin": "*",
        "cache-control": "no-store",
      },
    });
  },
};

function json(obj, status = 200) {
  return new Response(JSON.stringify(obj), {
    status,
    headers: {
      "content-type": "application/json; charset=utf-8",
      "access-control-allow-origin": "*",
      "cache-control": "no-store",
    },
  });
}
