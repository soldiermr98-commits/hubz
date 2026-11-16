/**
 * S30 — Cloudflare Worker مستقل برای تولید تصویر hero مقاله‌ها (فلکس سریع)
 *
 * این ورکر کاملاً از relay متن جدا است (سکرت مستقل، دیپلوی مستقل) تا هیچ تداخلی
 * با پایپلاین S29 که سالم کار می‌کند نداشته باشد.
 *
 * نصب (داشبورد کلادفلر → Workers & Pages → Create Worker → paste this file):
 *  1. کد زیر را در ادیتور ورکر paste کنید و Deploy بزنید.
 *  2. در Settings → Variables دو Variable سکرت بسازید (نوع Secret):
 *       IMAGE_WORKER_SECRET  ← یک رشته تصادفی طولانی (این را در پنل حامی‌هاب وارد می‌کنید)
 *       CF_ACCOUNT_ID        ← Account ID حساب کلادفلر (داشبورد → Overview)
 *       CF_API_TOKEN         ← API Token با پرمیشن Account → Workers AI → Edit
 *  3. پلن رایگان Workers AI روزانه ~۱۰٬۰۰۰ neuron می‌دهد (چند ده تصویر در روز).
 *
 * قرارداد API (سرور هاست ↔ ورکر):
 *   POST /image
 *   Authorization: Bearer <IMAGE_WORKER_SECRET>
 *   {"prompt":"...", "width":1024, "height":576, "steps":4}
 *   ← {"ok":true, "image":"<base64>", "content_type":"image/jpeg"}
 */

const MODEL = '@cf/black-forest-labs/flux-1-schnell';
const MAX_PROMPT_CHARS = 1500;

function json(data, status) {
  return new Response(JSON.stringify(data), {
    status,
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
  });
}

function clampInt(v, min, max, dflt) {
  const n = Number.parseInt(v, 10);
  if (!Number.isFinite(n)) return dflt;
  // ابعاد باید مضرب ۱۶ باشند (محدودیت مدل)
  const snapped = Math.round(Math.min(max, Math.max(min, n)) / 16) * 16;
  return Math.min(max, Math.max(min, snapped));
}

export default {
  async fetch(request, env) {
    const url = new URL(request.url);

    if (request.method !== 'POST' || url.pathname !== '/image') {
      return json({ ok: false, error: 'not_found' }, 404);
    }

    // احراز هویت fail-closed: سکرت تنظیم‌نشده = همیشه رد
    const expected = env.IMAGE_WORKER_SECRET ? 'Bearer ' + env.IMAGE_WORKER_SECRET : '';
    const provided = request.headers.get('Authorization') || '';
    if (expected === '' || provided !== expected) {
      return json({ ok: false, error: 'unauthorized' }, 401);
    }

    if (!env.CF_ACCOUNT_ID || !env.CF_API_TOKEN) {
      return json({ ok: false, error: 'worker_not_configured' }, 500);
    }

    let body;
    try {
      body = await request.json();
    } catch (e) {
      return json({ ok: false, error: 'bad_json' }, 400);
    }

    const prompt = String(body.prompt || '').trim();
    if (prompt.length < 5) {
      return json({ ok: false, error: 'prompt_too_short' }, 422);
    }
    if (prompt.length > MAX_PROMPT_CHARS) {
      return json({ ok: false, error: 'prompt_too_long' }, 422);
    }

    const payload = {
      prompt,
      steps: clampInt(body.steps, 1, 8, 4),
      width: clampInt(body.width, 256, 1024, 1024),
      height: clampInt(body.height, 256, 1024, 576),
    };

    let upstream;
    try {
      upstream = await fetch(
        `https://api.cloudflare.com/client/v4/accounts/${env.CF_ACCOUNT_ID}/ai/run/${MODEL}`,
        {
          method: 'POST',
          headers: {
            Authorization: `Bearer ${env.CF_API_TOKEN}`,
            'Content-Type': 'application/json',
          },
          body: JSON.stringify(payload),
        }
      );
    } catch (e) {
      return json({ ok: false, error: 'upstream_unreachable' }, 502);
    }

    let data;
    try {
      data = await upstream.json();
    } catch (e) {
      return json({ ok: false, error: 'upstream_bad_response' }, 502);
    }

    if (!upstream.ok || !data.success || !data.result || !data.result.image) {
      const detail = Array.isArray(data.errors) && data.errors[0]
        ? String(data.errors[0].message || data.errors[0].code || 'unknown')
        : 'unknown';
      return json({ ok: false, error: 'generation_failed', detail }, upstream.status >= 500 ? 502 : 422);
    }

    return json({
      ok: true,
      image: data.result.image,
      content_type: String(data.result.content_type || 'image/jpeg'),
      model: MODEL,
    });
  },
};
