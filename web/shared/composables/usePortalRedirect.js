import { apiMutate, clearCsrfCache, ensureCsrfToken } from '../api/http.js';

const REMEMBER_KEYS = {
  staff: 'popstar_remember_staff',
  member: 'popstar_remember_member',
};

export function readRememberedIdentifier(role) {
  if (typeof window === 'undefined') return '';
  try {
    return window.localStorage.getItem(REMEMBER_KEYS[role] || '') || '';
  } catch {
    return '';
  }
}

export function saveRememberedIdentifier(role, identifier, remember) {
  if (typeof window === 'undefined') return;
  const key = REMEMBER_KEYS[role];
  if (!key) return;
  try {
    if (remember && String(identifier || '').trim()) {
      window.localStorage.setItem(key, String(identifier).trim());
    } else {
      window.localStorage.removeItem(key);
    }
  } catch {
    // Ignore storage quota errors
  }
}

export function getPortalUrl(targetPortal, path = '/') {
  if (typeof window === 'undefined') return path;
  const normalizedPath = path.startsWith('/') ? path : `/${path}`;
  const configured =
    targetPortal === 'crm'
      ? import.meta.env.VITE_CRM_PORTAL_URL
      : import.meta.env.VITE_MEMBER_PORTAL_URL;
  if (configured) {
    return `${String(configured).replace(/\/$/, '')}${normalizedPath}`;
  }
  const { protocol, hostname, port } = window.location;

  // Name-based hosts (Apache vhosts / production): popstar.localhost <-> member.popstar.localhost
  const isIpHost = /^[\d.]+$/.test(hostname) || hostname.includes(':') || hostname === 'localhost';
  if (!port && !isIpHost) {
    const baseHost = hostname.replace(/^member\./, '');
    const targetHost = targetPortal === 'crm' ? baseHost : `member.${baseHost}`;
    return `${protocol}//${targetHost}${normalizedPath}`;
  }

  const targetPort =
    targetPortal === 'crm'
      ? port === '5174' || port === '5173'
        ? '5173'
        : '18081'
      : port === '5174' || port === '5173'
        ? '5174'
        : '18082';
  return `${protocol}//${hostname}:${targetPort}${normalizedPath}`;
}

export async function authenticateStaffPortal({ email, password }) {
  clearCsrfCache();
  await ensureCsrfToken('/api/crm/csrf');
  const res = await apiMutate('/api/crm/login', 'POST', { email, password }, { csrfEndpoint: '/api/crm/csrf' });
  clearCsrfCache();
  return res;
}

export async function authenticateMemberPortal({ identifier, password }) {
  clearCsrfCache();
  await ensureCsrfToken('/api/member/csrf');
  const trimmed = String(identifier || '').trim();
  const payload = trimmed.includes('@')
    ? { email: trimmed, password }
    : { phone: trimmed, password };
  const res = await apiMutate('/api/member/login', 'POST', payload, { csrfEndpoint: '/api/member/csrf' });
  clearCsrfCache();
  return res;
}

export async function registerMemberPortal(payload) {
  clearCsrfCache();
  await ensureCsrfToken('/api/member/csrf');
  const res = await apiMutate('/api/member/register', 'POST', payload, { csrfEndpoint: '/api/member/csrf' });
  clearCsrfCache();
  return res;
}
