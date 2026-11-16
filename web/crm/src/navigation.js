/**
 * Single source of truth for POPSTAR Connect CRM navigation,
 * role-based filtering, More hub grid, and Ctrl+K quick search.
 */

export const CRM_NAV_GROUPS = [
  {
    id: 'daily',
    title: 'งานประจำวัน',
    showInMore: false,
    items: [
      {
        name: 'dashboard',
        path: '/dashboard',
        icon: '🏠',
        label: 'วันนี้',
        keywords: 'dashboard today งานวันนี้ หน้าแรก ยอดขาย งานค้าง',
      },
      {
        name: 'customers',
        path: '/customers',
        icon: '🏪',
        label: 'ลูกค้า',
        keywords: 'customers ร้านค้า ค้นหาลูกค้า ทะเบียนร้าน',
      },
      {
        name: 'visits',
        path: '/visits',
        icon: '📍',
        label: 'ออกตลาด',
        keywords: 'visits เข้าพบ ออกตลาด เช็คอิน รูปถ่าย หลักฐาน',
      },
      {
        name: 'quick-add',
        path: '/quick-add',
        icon: '➕',
        label: 'เพิ่มร้านใหม่',
        keywords: 'เพิ่มร้าน ใหม่ ร้านใหม่ ตรวจเบอร์ซ้ำ',
      },
    ],
  },
  {
    id: 'sales',
    title: 'งานขาย',
    showInMore: true,
    items: [
      {
        name: 'pipeline',
        path: '/pipeline',
        icon: '🎯',
        label: 'โอกาสการขาย',
        keywords: 'โอกาสการขาย ลีด ติดตามสถานะ เสนอราคา',
      },
      {
        name: 'customer-360',
        path: '/customer-360',
        icon: '🔍',
        label: 'ข้อมูลลูกค้าแบบละเอียด',
        keywords: 'ข้อมูลลูกค้า ประวัติซื้อ บิล ข้อมูลเชิงลึก',
      },
      {
        name: 'map',
        path: '/map',
        icon: '🗺️',
        label: 'แผนที่ร้าน',
        keywords: 'แผนที่ ร้านค้า พิกัด ปักหมุด',
      },
      {
        name: 'plans',
        path: '/plans',
        icon: '📅',
        label: 'แผนออกตลาด',
        keywords: 'แผนออกตลาด ตารางเยี่ยม เยี่ยมร้าน',
      },
      {
        name: 'followups',
        path: '/followups',
        icon: '✅',
        label: 'งานติดตามผล',
        keywords: 'งานติดตามผล งานค้าง นัดหมาย เตือนงาน',
      },
      {
        name: 'reports',
        path: '/reports',
        icon: '📈',
        label: 'รายงานยอดขาย',
        keywords: 'รายงาน ยอดขาย สถิติ ออกตลาด',
      },
    ],
  },
  {
    id: 'members',
    title: 'สมาชิก',
    showInMore: true,
    items: [
      {
        name: 'member-registration',
        path: '/member-registration',
        icon: '📝',
        label: 'วิธีสมัครสมาชิก',
        keywords: 'วิธีสมัครสมาชิก สมัครสมาชิก ใหม่',
      },
      {
        name: 'member-portal',
        path: '/member-portal',
        icon: '🪪',
        label: 'เปิดหน้าสมาชิก',
        keywords: 'เปิดหน้าสมาชิก หน้าลูกค้า ลิงก์สมาชิก',
      },
      {
        name: 'members',
        path: '/members',
        icon: '👥',
        label: 'ทะเบียนสมาชิก',
        adminOnly: true,
        keywords: 'ทะเบียนสมาชิก ยืนยันตัวตน ร้านค้าสมาชิก',
      },
      {
        name: 'loyalty',
        path: '/loyalty',
        icon: '💎',
        label: 'กติกาแต้ม',
        adminOnly: true,
        keywords: 'กติกาแต้ม คะแนนสะสม รายการแต้ม',
      },
      {
        name: 'rewards',
        path: '/rewards',
        icon: '🎁',
        label: 'ของรางวัล',
        adminOnly: true,
        keywords: 'ของรางวัล แลกแต้ม สิทธิพิเศษ',
      },
    ],
  },
  {
    id: 'erp',
    title: 'ERP (Bplus)',
    showInMore: true,
    items: [
      {
        name: 'erp-customers',
        path: '/erp-customers',
        icon: '🏢',
        label: 'ลูกค้าใน ERP',
        keywords: 'ลูกค้าใน ERP ลูกหนี้ ข้อมูลบัญชี',
      },
      {
        name: 'erp-requests',
        path: '/erp-requests',
        icon: '📨',
        label: 'คำขอเปิดรหัส ERP',
        adminOnly: true,
        keywords: 'คำขอเปิดรหัส ERP ขอรหัส เปิดบัญชี อนุมัติ',
      },
      {
        name: 'matching',
        path: '/matching',
        icon: '🔗',
        label: 'จับคู่ลูกค้ากับ ERP',
        adminOnly: true,
        keywords: 'จับคู่ลูกค้า ERP เชื่อมรหัส จับคู่ร้าน',
      },
      {
        name: 'sync',
        path: '/sync',
        icon: '🔄',
        label: 'สถานะดึงข้อมูล ERP',
        adminOnly: true,
        keywords: 'สถานะดึงข้อมูล ERP ซิงก์ ตรวจสอบข้อมูล',
      },
    ],
  },
  {
    id: 'system',
    title: 'ระบบ',
    showInMore: true,
    items: [
      {
        name: 'profile',
        path: '/profile',
        icon: '📱',
        label: 'โปรไฟล์ & ข้อมูลค้างส่ง',
        keywords: 'โปรไฟล์ ข้อมูลค้างส่ง บัญชีผู้ใช้ มือถือ',
      },
      {
        name: 'settings',
        path: '/settings',
        icon: '⚙️',
        label: 'ตั้งค่า & ผู้ใช้งาน',
        adminOnly: true,
        keywords: 'ตั้งค่า ผู้ใช้งาน สิทธิ์ บัญชีทีมขาย',
      },
    ],
  },
];

export function filterNavGroups(groups, isManager) {
  return groups
    .map((group) => ({
      ...group,
      items: group.items.filter((item) => !item.adminOnly || isManager),
    }))
    .filter((group) => group.items.length > 0);
}

export function getMoreNavGroups(groups, isManager) {
  return filterNavGroups(groups, isManager).filter((group) => group.showInMore !== false);
}

export function findNavMetaByRouteName(groups, routeName) {
  if (!routeName) return null;
  for (const group of groups) {
    const item = group.items.find((entry) => entry.name === routeName);
    if (item) {
      return { group, item };
    }
  }
  return null;
}
