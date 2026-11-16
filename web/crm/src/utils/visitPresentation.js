const empty = (value) => String(value ?? '').trim() || 'ไม่ได้บันทึก';
export function visitFields(visit) {
  return [
    ['ร้านค้าเดิมหรือใหม่', { new: 'ร้านค้าใหม่', existing: 'ร้านค้าเดิม' }[visit.shop_type] || empty(visit.shop_type)],
    ['เบอร์โทรร้านค้า', empty(visit.shop_phone)],
    ['แหล่งที่ซื้อ', empty(visit.supplier_name)],
    ['ประเภทสินค้าที่ใช้', empty(visit.product_types)],
    ['ข้อมูลและปัญหาของลูกค้า', empty(visit.customer_issues)],
    ['ความคิดเห็นของลูกค้า', empty(visit.customer_feedback)],
    ['ผลการเข้าพบ', ({ visited: 'เข้าพบแล้ว', interested: 'สนใจ', follow_up: 'ติดตามต่อ', not_interested: 'ยังไม่สนใจ', closed: 'ปิดการขาย', no_answer: 'ไม่พบผู้ติดต่อ' }[visit.result] || empty(visit.result))],
    ['การติดตาม', `${empty(visit.follow_up_action)}${visit.follow_up_date ? ` · กำหนด ${String(visit.follow_up_date).slice(0, 10)}` : ''}`],
    ['หมายเหตุ', empty(visit.note)],
  ];
}
