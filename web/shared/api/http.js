import { QueryClient } from '@tanstack/vue-query';

export class ApiError extends Error {
  constructor(message, { status = 0, data = null, errors = null, url = '', method = 'GET' } = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.data = data;
    this.errors = errors;
    this.url = url;
    this.method = method;
    this.timestamp = new Date().toISOString();
  }
}

export function createAppQueryClient() {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 60_000,
        gcTime: 10 * 60_000,
        retry: (failureCount, err) => {
          if (err?.status === 401 || err?.status === 403 || err?.status === 404 || err?.status === 422) {
            return false;
          }
          return failureCount < 1;
        },
        refetchOnWindowFocus: false,
      },
    },
  });
}

let csrfTokenCache = '';
let csrfInflight = null;

export function clearCsrfCache() {
  csrfTokenCache = '';
}

export async function ensureCsrfToken(csrfEndpoint = '/api/crm/csrf') {
  if (csrfTokenCache) return csrfTokenCache;
  if (csrfInflight) return csrfInflight;

  csrfInflight = fetch(csrfEndpoint, { credentials: 'include', headers: { Accept: 'application/json' } })
    .then(async (res) => {
      const data = await res.json().catch(() => ({}));
      csrfTokenCache = data.csrf_token || '';
      return csrfTokenCache;
    })
    .finally(() => {
      csrfInflight = null;
    });

  return csrfInflight;
}

function parseErrorPayload(text, status, retryAfter = null) {
  let parsed;
  try {
    parsed = text ? JSON.parse(text) : null;
  } catch {
    parsed = null;
  }
  const validationErrors = parsed?.errors || null;
  let message = parsed?.message || parsed?.error || '';
  if (validationErrors && typeof validationErrors === 'object') {
    const first = Object.values(validationErrors).flat()[0];
    if (first) message = first;
  }
  if (status === 429) {
    const waitSec = Number(retryAfter);
    message = waitSec > 0
      ? `พยายามเข้าสู่ระบบหรือส่งคำขอบ่อยเกินไป กรุณารอ ${waitSec} วินาทีแล้วลองใหม่อีกครั้ง`
      : 'พยายามเข้าสู่ระบบหรือส่งคำขอบ่อยเกินไป กรุณารอสักครู่แล้วลองใหม่อีกครั้ง';
  } else if (!message) {
    message = status === 401
      ? 'กรุณาเข้าสู่ระบบใหม่อีกครั้ง (Session หมดอายุ)'
      : status === 403
        ? 'คุณไม่มีสิทธิ์ดำเนินการในส่วนนี้'
        : status === 419
          ? 'เซสชันความปลอดภัยหมดอายุ ระบบกำลังรีเฟรชโทเค็น'
          : `เกิดข้อผิดพลาดจากเซิร์ฟเวอร์ (HTTP ${status || 'Network'})`;
  }
  return { parsed, validationErrors, message };
}

export async function apiGet(url, { signal } = {}) {
  const res = await fetch(url, {
    credentials: 'include',
    signal,
    headers: { Accept: 'application/json' },
  });
  const text = await res.text();
  if (!res.ok) {
    const { parsed, validationErrors, message } = parseErrorPayload(text, res.status, res.headers?.get?.('Retry-After'));
    throw new ApiError(message, { status: res.status, data: parsed, errors: validationErrors, url, method: 'GET' });
  }
  return text ? JSON.parse(text) : null;
}

export async function apiMutate(
  url,
  method = 'POST',
  body = undefined,
  { csrfEndpoint = '/api/crm/csrf', signal } = {}
) {
  const send = async () => {
    const token = await ensureCsrfToken(csrfEndpoint);
    const isForm = body instanceof FormData;
    const headers = {
      Accept: 'application/json',
      'X-CSRF-TOKEN': token,
    };
    if (!isForm && body !== undefined) {
      headers['Content-Type'] = 'application/json';
    }
    const res = await fetch(url, {
      method,
      credentials: 'include',
      signal,
      headers,
      body: isForm ? body : body !== undefined ? JSON.stringify(body) : undefined,
    });
    const text = await res.text();
    return { res, text };
  };

  let { res, text } = await send();
  if (res.status === 419) {
    clearCsrfCache();
    ({ res, text } = await send());
  }

  if (!res.ok) {
    const { parsed, validationErrors, message } = parseErrorPayload(text, res.status, res.headers?.get?.('Retry-After'));
    throw new ApiError(message, { status: res.status, data: parsed, errors: validationErrors, url, method });
  }
  return text ? JSON.parse(text) : null;
}

export async function apiUploadWithProgress(
  url,
  formData,
  { csrfEndpoint = '/api/crm/csrf', onProgress, signal } = {}
) {
  const token = await ensureCsrfToken(csrfEndpoint);
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', url, true);
    xhr.withCredentials = true;
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('X-CSRF-TOKEN', token);

    if (signal) {
      if (signal.aborted) {
        xhr.abort();
        reject(new DOMException('Aborted', 'AbortError'));
        return;
      }
      signal.addEventListener('abort', () => xhr.abort(), { once: true });
    }

    xhr.upload.onprogress = (evt) => {
      if (onProgress && evt.lengthComputable) {
        const pct = Math.min(100, Math.round((evt.loaded / evt.total) * 100));
        onProgress({ percent: pct, loaded: evt.loaded, total: evt.total });
      }
    };

    xhr.onerror = () => {
      reject(new ApiError('ไม่สามารถเชื่อมต่อเครือข่ายระหว่างอัปโหลดได้', { status: 0, url, method: 'POST' }));
    };

    xhr.onabort = () => {
      reject(new DOMException('Upload cancelled', 'AbortError'));
    };

    xhr.onload = () => {
      if (xhr.status >= 200 && xhr.status < 300) {
        try {
          resolve(xhr.responseText ? JSON.parse(xhr.responseText) : { ok: true });
        } catch {
          resolve({ ok: true });
        }
        return;
      }
      if (xhr.status === 419) clearCsrfCache();
      const { parsed, validationErrors, message } = parseErrorPayload(
        xhr.responseText,
        xhr.status,
        xhr.getResponseHeader('Retry-After')
      );
      reject(new ApiError(message, { status: xhr.status, data: parsed, errors: validationErrors, url, method: 'POST' }));
    };

    xhr.send(formData);
  });
}
