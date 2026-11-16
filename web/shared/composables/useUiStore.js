import { reactive } from 'vue';

let toastSeq = 1;

export const uiState = reactive({
  toasts: [],
  confirm: {
    open: false,
    title: '',
    message: '',
    confirmLabel: 'ยืนยัน',
    cancelLabel: 'ยกเลิก',
    tone: 'primary',
    resolve: null,
  },
  errorDetail: {
    open: false,
    title: '',
    error: null,
  },
  commandPaletteOpen: false,
});

export const toast = {
  push({ title, message = '', tone = 'info', duration = 3800, error = null }) {
    const id = toastSeq++;
    uiState.toasts.push({ id, title, message, tone, error });
    if (duration > 0) {
      setTimeout(() => this.dismiss(id), duration);
    }
    return id;
  },
  success(title, message = '') {
    return this.push({ title, message, tone: 'success', duration: 3200 });
  },
  info(title, message = '') {
    return this.push({ title, message, tone: 'info', duration: 3200 });
  },
  warning(title, message = '') {
    return this.push({ title, message, tone: 'warning', duration: 4500 });
  },
  error(title, errOrMessage = '') {
    const msg = typeof errOrMessage === 'string'
      ? errOrMessage
      : errOrMessage?.message || 'เกิดข้อผิดพลาด กรุณาลองใหม่';
    const errorObj = typeof errOrMessage === 'object' ? errOrMessage : null;
    return this.push({ title, message: msg, tone: 'error', duration: 6000, error: errorObj });
  },
  dismiss(id) {
    const idx = uiState.toasts.findIndex((t) => t.id === id);
    if (idx >= 0) uiState.toasts.splice(idx, 1);
  },
};

export function openErrorDetail(title, error) {
  uiState.errorDetail.open = true;
  uiState.errorDetail.title = title;
  uiState.errorDetail.error = error;
}

export function closeErrorDetail() {
  uiState.errorDetail.open = false;
  uiState.errorDetail.error = null;
}

export function askConfirm({
  title = 'ยืนยันการทำรายการ',
  message = '',
  confirmLabel = 'ยืนยัน',
  cancelLabel = 'ยกเลิก',
  tone = 'primary',
} = {}) {
  return new Promise((resolve) => {
    uiState.confirm.open = true;
    uiState.confirm.title = title;
    uiState.confirm.message = message;
    uiState.confirm.confirmLabel = confirmLabel;
    uiState.confirm.cancelLabel = cancelLabel;
    uiState.confirm.tone = tone;
    uiState.confirm.resolve = resolve;
  });
}

export function resolveConfirm(answer) {
  const fn = uiState.confirm.resolve;
  uiState.confirm.open = false;
  uiState.confirm.resolve = null;
  if (typeof fn === 'function') fn(Boolean(answer));
}
