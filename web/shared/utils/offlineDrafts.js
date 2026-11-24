/**
 * Local-first Offline Draft & Sync Queue backed by IndexedDB with localStorage fallback.
 * Stores pending Field Visit & Quick Lead drafts along with compressed photo Blobs.
 */
const DB_NAME = 'popstar_field_offline_v1';
const STORE_NAME = 'drafts';
const FALLBACK_KEY = 'popstar_offline_drafts_fallback_v1';

function openDb() {
  return new Promise((resolve, reject) => {
    if (typeof indexedDB === 'undefined') {
      reject(new Error('No IndexedDB'));
      return;
    }
    const req = indexedDB.open(DB_NAME, 1);
    req.onupgradeneeded = () => {
      const db = req.result;
      if (!db.objectStoreNames.contains(STORE_NAME)) {
        db.createObjectStore(STORE_NAME, { keyPath: 'client_draft_id' });
      }
    };
    req.onsuccess = () => resolve(req.result);
    req.onerror = () => reject(req.error);
  });
}

function readFallback() {
  try {
    return JSON.parse(localStorage.getItem(FALLBACK_KEY) || '[]');
  } catch {
    return [];
  }
}

function writeFallback(items) {
  try {
    localStorage.setItem(FALLBACK_KEY, JSON.stringify(items.map((x) => ({ ...x, photos: [] }))));
  } catch {
    // ignore quota error
  }
}

export function generateDraftId(prefix = 'draft') {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return `${prefix}-${crypto.randomUUID()}`;
  }
  return `${prefix}-${Date.now()}-${Math.random().toString(16).slice(2, 10)}`;
}

export async function listOfflineDrafts() {
  try {
    const db = await openDb();
    return await new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_NAME, 'readonly');
      const req = tx.objectStore(STORE_NAME).getAll();
      req.onsuccess = () =>
        resolve((req.result || []).sort((a, b) => (b.updatedAt || '').localeCompare(a.updatedAt || '')));
      req.onerror = () => reject(req.error);
    });
  } catch {
    return readFallback();
  }
}

export async function saveOfflineDraft(draft) {
  const item = {
    ...draft,
    client_draft_id: draft.client_draft_id || generateDraftId(draft.type || 'draft'),
    updatedAt: new Date().toISOString(),
    status: draft.status || 'pending',
  };
  try {
    const db = await openDb();
    await new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_NAME, 'readwrite');
      tx.objectStore(STORE_NAME).put(item);
      tx.oncomplete = resolve;
      tx.onerror = () => reject(tx.error);
    });
    return item;
  } catch {
    const items = readFallback().filter((x) => x.client_draft_id !== item.client_draft_id);
    items.unshift(item);
    writeFallback(items);
    return item;
  }
}

export async function removeOfflineDraft(clientDraftId) {
  try {
    const db = await openDb();
    await new Promise((resolve, reject) => {
      const tx = db.transaction(STORE_NAME, 'readwrite');
      tx.objectStore(STORE_NAME).delete(clientDraftId);
      tx.oncomplete = resolve;
      tx.onerror = () => reject(tx.error);
    });
  } catch {
    writeFallback(readFallback().filter((x) => x.client_draft_id !== clientDraftId));
  }
}
