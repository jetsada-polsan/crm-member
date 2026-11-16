import { onMounted, onUnmounted, watch } from 'vue';
import { askConfirm } from './useUiStore.js';

/**
 * Shared accessible overlay behavior (Escape-to-close, dirty-form confirmation,
 * and focus management) reused by modals, drawers, sheets, and full-screen forms.
 */
export function useOverlayBehavior({
  isOpen,
  persistent = () => false,
  isDirty = () => false,
  dirtyMessage = 'คุณมีข้อมูลที่ยังไม่ได้บันทึก ต้องการปิดหน้าต่างนี้หรือไม่?',
  onClose,
  panelRef = null,
}) {
  let prevFocused = null;

  async function requestClose() {
    if (persistent()) return;
    if (isDirty()) {
      const ok = await askConfirm({
        title: 'ยังไม่ได้บันทึกข้อมูล',
        message: dirtyMessage,
        confirmLabel: 'ทิ้งข้อมูลที่แก้ไข',
        cancelLabel: 'อยู่หน้านี้ต่อ',
        tone: 'danger',
      });
      if (!ok) return;
    }
    onClose();
  }

  function onKeydown(e) {
    if (!isOpen()) return;
    if (e.key === 'Escape') {
      e.preventDefault();
      e.stopPropagation();
      requestClose();
    }
  }

  watch(isOpen, (open) => {
    if (open) {
      prevFocused = document.activeElement;
      setTimeout(() => panelRef?.value?.focus?.(), 20);
    } else if (prevFocused && typeof prevFocused.focus === 'function') {
      prevFocused.focus();
      prevFocused = null;
    }
  });

  onMounted(() => window.addEventListener('keydown', onKeydown));
  onUnmounted(() => window.removeEventListener('keydown', onKeydown));

  return { requestClose };
}
