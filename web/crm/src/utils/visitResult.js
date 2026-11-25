/**
 * Thai labels for field-visit results. The API stores the English codes accepted by
 * FieldController (visited, follow_up, interested, not_interested, not_found); the
 * last two entries cover results written by older app versions.
 */
export const VISIT_RESULT_LABELS = {
  visited: 'เข้าพบแล้ว',
  interested: 'สนใจสินค้า',
  follow_up: 'ต้องติดตาม',
  not_interested: 'ยังไม่สนใจ',
  not_found: 'ไม่พบลูกค้า',
  closed: 'ปิดการขาย',
  no_answer: 'ไม่พบผู้ติดต่อ',
};

export function visitResultLabel(value) {
  return VISIT_RESULT_LABELS[value] || value || 'ไม่ระบุผล';
}
