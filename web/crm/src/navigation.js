/**
 * Single source of truth for CRM navigation categories, quick search keywords,
 * and role-filtered route metadata.
 */

export const CRM_NAV_GROUPS = [
  {
    id: 'workspace',
    title: 'งานขาย & ลูกค้า',
    icon: '🧭',
    defaultOpen: true,
    items: [
      {
        name: 'dashboard',
        path: '/dashboard',
        icon: '📊',
        label: 'ภาพรวมธุรกิจ',
        keywords: 'dashboard kpi sales overview ยอดขาย สถิติ ภาพรวม หน้าหลัก',
      },
      {
        name: 'customers',
        path: '/customers',
        icon: '🏪',
        label: 'ทะเบียนลูกค้า',
        keywords: 'customers master registry ลูกค้า ร้านค้า ทะเบียน',
      },
      {
        name: 'pipeline',
        path: '/pipeline',
        icon: '🧭',
        label: 'Lead Pipeline',
        keywords: 'lead pipeline kanban stage ลีด โอกาสขาย ติดตามสถานะ',
      },
      {
        name: 'customer-360',
        path: '/customer-360',
        icon: '🔍',
        label: 'Customer 360°',
        keywords: 'customer 360 profile history ข้อมูลลูกค้า เชิงลึก ประวัติ',
      },
      {
        name: 'map',
        path: '/map',
        icon: '🗺️',
        label: 'แผนที่ร้านค้า',
        keywords: 'map gps location pin พิกัด แผนที่ ร้านค้า',
      },
    ],
  },
  {
    id: 'field',
    title: 'งานออกตลาดภาคสนาม',
    icon: '📍',
    defaultOpen: true,
    items: [
      {
        name: 'visits',
        path: '/visits',
        icon: '📸',
        label: 'บันทึกเข้าพบร้านค้า',
        keywords: 'visits checkin photo evidence เข้าพบ เช็คอิน หลักฐาน ภาพถ่าย',
      },
      {
        name: 'quick-add',
        path: '/quick-add',
        icon: '➕',
        label: 'เพิ่มร้านค้าใหม่',
        keywords: 'quick add create shop lead เพิ่มร้าน ใหม่ ตรวจซ้ำ',
      },
      {
        name: 'plans',
        path: '/plans',
        icon: '📅',
        label: 'แผนออกตลาด',
        keywords: 'visit plan schedule แผน ออกตลาด เยี่ยม ตาราง',
      },
      {
        name: 'followups',
        path: '/followups',
        icon: '✅',
        label: 'งานติดตามผล',
        keywords: 'followup task reminder ติดตามผล งานค้าง แจ้งเตือน',
      },
      {
        name: 'profile',
        path: '/profile',
        icon: '📱',
        label: 'โหมดออฟไลน์ & มือถือ',
        keywords: 'mobile ipad offline draft sync มือถือ ออฟไลน์',
      },
    ],
  },
  {
    id: 'erp',
    title: 'ระบบ ERP (Bplus)',
    icon: '🏢',
    defaultOpen: false,
    items: [
      {
        name: 'erp-customers',
        path: '/erp-customers',
        icon: '🏢',
        label: 'ลูกค้า ERP (ดูอย่างเดียว)',
        keywords: 'erp bplus mirror customer read only ลูกหนี้',
      },
      {
        name: 'erp-requests',
        path: '/erp-requests',
        icon: '📨',
        label: 'คำขอเปิดรหัส ERP',
        adminOnly: true,
        keywords: 'erp creation request ขอรหัส เปิดบัญชี อนุมัติ',
      },
      {
        name: 'matching',
        path: '/matching',
        icon: '🔗',
        label: 'จับคู่ลูกค้า CRM ↔ ERP',
        adminOnly: true,
        keywords: 'erp matching candidate link จับคู่ เชื่อมรหัส',
      },
      {
        name: 'sync',
        path: '/sync',
        icon: '🔄',
        label: 'ERP Sync Monitor',
        adminOnly: true,
        keywords: 'sync monitor bridge sql bplus ซิงก์ สถานะ',
      },
    ],
  },
  {
    id: 'loyalty',
    title: 'สมาชิก & สะสมแต้ม',
    icon: '🎁',
    defaultOpen: false,
    items: [
      {
        name: 'members',
        path: '/members',
        icon: '👥',
        label: 'ทะเบียนสมาชิก',
        adminOnly: true,
        keywords: 'members verify line owner สมาชิก ยืนยันตัวตน',
      },
      {
        name: 'loyalty',
        path: '/loyalty',
        icon: '💎',
        label: 'กติกา & บัญชีแต้ม',
        adminOnly: true,
        keywords: 'loyalty points rules ledger กติกา แต้ม คะแนน',
      },
      {
        name: 'rewards',
        path: '/rewards',
        icon: '🎁',
        label: 'ของรางวัล & แลกแต้ม',
        adminOnly: true,
        keywords: 'rewards redemption gift ของรางวัล แลกแต้ม',
      },
      {
        name: 'member-registration',
        path: '/member-registration',
        icon: '📝',
        label: 'ขั้นตอนสมัครสมาชิก',
        keywords: 'member registration qr signup สมัครสมาชิก คู่มือ',
      },
      {
        name: 'member-portal',
        path: '/member-portal',
        icon: '🪪',
        label: 'เปิด Member Portal',
        keywords: 'member portal handoff พอร์ทัลลูกค้า ลิงก์',
      },
    ],
  },
  {
    id: 'admin',
    title: 'รายงาน & ตั้งค่าระบบ',
    icon: '⚙️',
    defaultOpen: false,
    items: [
      {
        name: 'reports',
        path: '/reports',
        icon: '📈',
        label: 'รายงานและวิเคราะห์',
        keywords: 'reports analytics sales performance รายงาน วิเคราะห์ ยอดขาย',
      },
      {
        name: 'settings',
        path: '/settings',
        icon: '⚙️',
        label: 'ตั้งค่า & สิทธิ์ใช้งาน',
        adminOnly: true,
        keywords: 'settings access control rbac staff role audit ตั้งค่า สิทธิ์ ผู้ใช้',
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
