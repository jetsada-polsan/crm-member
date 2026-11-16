/**
 * Single source of truth for POPSTAR Connect CRM navigation,
 * role-based filtering, More hub grid, and Ctrl+K quick search.
 */

export const CRM_NAV_GROUPS = [
  {
    id: 'daily',
    title: 'งานประจำวัน',
    subtitle: 'หน้าหลักที่ใช้บ่อยตลอดวัน',
    icon: '⚡',
    tone: 'blue',
    showInMore: false,
    items: [
      {
        name: 'dashboard',
        path: '/dashboard',
        icon: '🏠',
        tone: 'blue',
        label: 'วันนี้',
        desc: 'สรุปงานวันนี้ แผนที่ & ยอดขาย',
        keywords: 'dashboard today งานวันนี้ หน้าแรก ยอดขาย งานค้าง',
      },
      {
        name: 'customers',
        path: '/customers',
        icon: '🏪',
        tone: 'emerald',
        label: 'ลูกค้า',
        desc: 'ค้นหาร้านค้าและประวัติการซื้อ',
        keywords: 'customers ร้านค้า ค้นหาลูกค้า ทะเบียนร้าน',
      },
      {
        name: 'visits',
        path: '/visits',
        icon: '📍',
        tone: 'indigo',
        label: 'ออกตลาด',
        desc: 'ประวัติเช็คอินและรูปหลักฐาน',
        keywords: 'visits เข้าพบ ออกตลาด เช็คอิน รูปถ่าย หลักฐาน',
      },
      {
        name: 'quick-add',
        path: '/quick-add',
        icon: '➕',
        tone: 'amber',
        label: 'เพิ่มร้านใหม่',
        desc: 'ลงทะเบียนร้านใหม่พร้อมตรวจซ้ำ',
        keywords: 'เพิ่มร้าน ใหม่ ร้านใหม่ ตรวจเบอร์ซ้ำ',
      },
    ],
  },
  {
    id: 'sales',
    title: 'งานขาย & ออกตลาด',
    subtitle: 'แผนที่ เส้นทาง การติดตามผล และรายงาน',
    icon: '💼',
    tone: 'blue',
    showInMore: true,
    items: [
      {
        name: 'map',
        path: '/map',
        icon: '🗺️',
        tone: 'blue',
        label: 'แผนที่ร้าน',
        desc: 'ดูหมุด GPS และรูปถ่ายหน้าร้าน',
        keywords: 'แผนที่ ร้านค้า พิกัด ปักหมุด',
      },
      {
        name: 'plans',
        path: '/plans',
        icon: '📅',
        tone: 'indigo',
        label: 'แผนออกตลาด',
        desc: 'จัดตารางและเส้นทางเยี่ยมร้าน',
        keywords: 'แผนออกตลาด ตารางเยี่ยม เยี่ยมร้าน',
      },
      {
        name: 'followups',
        path: '/followups',
        icon: '✅',
        tone: 'emerald',
        label: 'งานติดตามผล',
        desc: 'งานนัดหมายและรายการต้องโทรตาม',
        keywords: 'งานติดตามผล งานค้าง นัดหมาย เตือนงาน',
      },
      {
        name: 'pipeline',
        path: '/pipeline',
        icon: '🎯',
        tone: 'amber',
        label: 'โอกาสการขาย',
        desc: 'บอร์ดสถานะลูกค้าใหม่ (Lead)',
        keywords: 'โอกาสการขาย ลีด ติดตามสถานะ เสนอราคา',
      },
      {
        name: 'customer-360',
        path: '/customer-360',
        icon: '🔍',
        tone: 'purple',
        label: 'ข้อมูลลูกค้า 360°',
        desc: 'เจาะลึกบิลซื้อ แต้ม และประวัติ',
        keywords: 'ข้อมูลลูกค้า ประวัติซื้อ บิล ข้อมูลเชิงลึก',
      },
      {
        name: 'reports',
        path: '/reports',
        icon: '📈',
        tone: 'blue',
        label: 'รายงานยอดขาย',
        desc: 'สรุปผลงานทีมขายและยอด Bplus',
        keywords: 'รายงาน ยอดขาย สถิติ ออกตลาด',
      },
    ],
  },
  {
    id: 'members',
    title: 'สมาชิก & สะสมแต้ม',
    subtitle: 'ดูแลสมาชิกร้านค้า คะแนน และของรางวัล',
    icon: '💎',
    tone: 'purple',
    showInMore: true,
    items: [
      {
        name: 'member-registration',
        path: '/member-registration',
        icon: '📝',
        tone: 'purple',
        label: 'วิธีสมัครสมาชิก',
        desc: 'ขั้นตอนแนะนำลูกค้าสมัครสมาชิก',
        keywords: 'วิธีสมัครสมาชิก สมัครสมาชิก ใหม่',
      },
      {
        name: 'member-portal',
        path: '/member-portal',
        icon: '🪪',
        tone: 'indigo',
        label: 'เปิดหน้าสมาชิก',
        desc: 'สลับไปหน้าเว็บฝั่งลูกค้าสมาชิก',
        keywords: 'เปิดหน้าสมาชิก หน้าลูกค้า ลิงก์สมาชิก',
      },
      {
        name: 'members',
        path: '/members',
        icon: '👥',
        tone: 'purple',
        label: 'ทะเบียนสมาชิก',
        desc: 'ตรวจสอบและอนุมัติการผูกสมาชิก',
        adminOnly: true,
        keywords: 'ทะเบียนสมาชิก ยืนยันตัวตน ร้านค้าสมาชิก',
      },
      {
        name: 'loyalty',
        path: '/loyalty',
        icon: '💎',
        tone: 'amber',
        label: 'กติกาแต้ม',
        desc: 'ตั้งค่าอัตราแต้มและสมุดบัญชีแต้ม',
        adminOnly: true,
        keywords: 'กติกาแต้ม คะแนนสะสม รายการแต้ม',
      },
      {
        name: 'rewards',
        path: '/rewards',
        icon: '🎁',
        tone: 'emerald',
        label: 'ของรางวัล',
        desc: 'จัดการของรางวัลและคำขอแลกแต้ม',
        adminOnly: true,
        keywords: 'ของรางวัล แลกแต้ม สิทธิพิเศษ',
      },
    ],
  },
  {
    id: 'erp',
    title: 'เชื่อมต่อ ERP (Bplus)',
    subtitle: 'ข้อมูลลูกหนี้และการซิงก์ฐานข้อมูล',
    icon: '🏢',
    tone: 'amber',
    showInMore: true,
    items: [
      {
        name: 'erp-customers',
        path: '/erp-customers',
        icon: '🏢',
        tone: 'amber',
        label: 'ลูกค้าใน ERP',
        desc: 'ทะเบียนลูกหนี้จากระบบ Bplus',
        keywords: 'ลูกค้าใน ERP ลูกหนี้ ข้อมูลบัญชี',
      },
      {
        name: 'erp-requests',
        path: '/erp-requests',
        icon: '📨',
        tone: 'blue',
        label: 'คำขอเปิดรหัส ERP',
        desc: 'รายการขอเปิดหน้าบัญชีลูกหนี้ใหม่',
        adminOnly: true,
        keywords: 'คำขอเปิดรหัส ERP ขอรหัส เปิดบัญชี อนุมัติ',
      },
      {
        name: 'matching',
        path: '/matching',
        icon: '🔗',
        tone: 'indigo',
        label: 'จับคู่ลูกค้ากับ ERP',
        desc: 'เชื่อมร้านค้า CRM เข้ากับรหัส Bplus',
        adminOnly: true,
        keywords: 'จับคู่ลูกค้า ERP เชื่อมรหัส จับคู่ร้าน',
      },
      {
        name: 'sync',
        path: '/sync',
        icon: '🔄',
        tone: 'emerald',
        label: 'สถานะดึงข้อมูล ERP',
        desc: 'ตรวจสถานะซิงก์บิลขายและลูกค้า',
        adminOnly: true,
        keywords: 'สถานะดึงข้อมูล ERP ซิงก์ ตรวจสอบข้อมูล',
      },
    ],
  },
  {
    id: 'system',
    title: 'บัญชี & ตั้งค่าระบบ',
    subtitle: 'ข้อมูลผู้ใช้งาน ข้อมูลค้างส่ง และสิทธิ์ระบบ',
    icon: '⚙️',
    tone: 'slate',
    showInMore: true,
    items: [
      {
        name: 'profile',
        path: '/profile',
        icon: '📱',
        tone: 'blue',
        label: 'โปรไฟล์ & ข้อมูลค้างส่ง',
        desc: 'เช็คสถานะออนไลน์และคิวส่งข้อมูล',
        keywords: 'โปรไฟล์ ข้อมูลค้างส่ง บัญชีผู้ใช้ มือถือ',
      },
      {
        name: 'settings',
        path: '/settings',
        icon: '⚙️',
        tone: 'slate',
        label: 'ตั้งค่า & ผู้ใช้งาน',
        desc: 'จัดการพนักงานขายและตั้งค่าระบบ',
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
