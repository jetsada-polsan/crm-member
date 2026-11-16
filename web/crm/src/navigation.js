/**
 * Single source of truth for POPSTAR Connect CRM navigation
 * organized in clean PEAK-style expandable modules.
 */

export const CRM_NAV_GROUPS = [
  {
    id: 'overview',
    title: 'ภาพรวมวันนี้',
    subtitle: 'สรุปยอดขาย ใบจอง และแผนที่ร้านค้า',
    icon: '🏠',
    tone: 'blue',
    directPath: '/dashboard',
    directRouteName: 'dashboard',
    showInMore: false,
    items: [
      {
        name: 'dashboard',
        path: '/dashboard',
        icon: '🏠',
        tone: 'blue',
        label: 'ภาพรวมวันนี้ (Dashboard)',
        desc: 'สรุปงานวันนี้ ยอดตามใบจอง & แผนที่ร้าน',
        keywords: 'dashboard today งานวันนี้ หน้าแรก ยอดขาย งานค้าง',
      },
    ],
  },
  {
    id: 'contacts',
    title: 'ลูกค้า & แผนที่ร้าน',
    subtitle: 'ทะเบียนลูกค้า ข้อมูล 360° และพิกัด GPS',
    icon: '🏪',
    tone: 'blue',
    showInMore: true,
    items: [
      {
        name: 'customers',
        path: '/customers',
        icon: '🏪',
        tone: 'blue',
        label: 'รายชื่อลูกค้าทั้งหมด',
        desc: 'ค้นหาร้านค้าและประวัติการซื้อ',
        keywords: 'customers ร้านค้า ค้นหาลูกค้า ทะเบียนร้าน',
      },
      {
        name: 'customer-360',
        path: '/customer-360',
        icon: '🔍',
        tone: 'indigo',
        label: 'ข้อมูลลูกค้า 360°',
        desc: 'เจาะลึกบิลซื้อ แต้ม และประวัติรายร้าน',
        keywords: 'ข้อมูลลูกค้า ประวัติซื้อ บิล ข้อมูลเชิงลึก',
      },
      {
        name: 'map',
        path: '/map',
        icon: '🗺️',
        tone: 'emerald',
        label: 'แผนที่ร้านค้า (GPS)',
        desc: 'ดูหมุดพิกัดร้านและรูปถ่ายหน้างาน',
        keywords: 'แผนที่ ร้านค้า พิกัด ปักหมุด',
      },
      {
        name: 'quick-add',
        path: '/quick-add',
        icon: '➕',
        tone: 'amber',
        label: 'เพิ่มร้านค้าใหม่',
        desc: 'ลงทะเบียนร้านใหม่พร้อมตรวจเบอร์ซ้ำ',
        keywords: 'เพิ่มร้าน ใหม่ ร้านใหม่ ตรวจเบอร์ซ้ำ',
      },
    ],
  },
  {
    id: 'field-sales',
    title: 'งานขาย & ออกตลาด',
    subtitle: 'บันทึกเข้าพบ แผนเดินรถ และงานติดตามผล',
    icon: '📍',
    tone: 'indigo',
    showInMore: true,
    items: [
      {
        name: 'visits',
        path: '/visits',
        icon: '📸',
        tone: 'blue',
        label: 'ประวัติบันทึกเข้าพบ',
        desc: 'รายการเช็คอินและรูปหลักฐานเข้าพบ',
        keywords: 'visits เข้าพบ ออกตลาด เช็คอิน รูปถ่าย หลักฐาน',
      },
      {
        name: 'plans',
        path: '/plans',
        icon: '📅',
        tone: 'indigo',
        label: 'แผนออกตลาดรายวัน',
        desc: 'จัดตารางและเส้นทางเข้าเยี่ยมร้านค้า',
        keywords: 'แผนออกตลาด ตารางเยี่ยม เยี่ยมร้าน',
      },
      {
        name: 'followups',
        path: '/followups',
        icon: '✅',
        tone: 'emerald',
        label: 'งานติดตามผล',
        desc: 'งานนัดหมายและรายการต้องโทรติดตาม',
        keywords: 'งานติดตามผล งานค้าง นัดหมาย เตือนงาน',
      },
      {
        name: 'pipeline',
        path: '/pipeline',
        icon: '🎯',
        tone: 'amber',
        label: 'โอกาสการขาย (Lead)',
        desc: 'บอร์ดติดตามสถานะลูกค้าใหม่',
        keywords: 'โอกาสการขาย ลีด ติดตามสถานะ เสนอราคา',
      },
    ],
  },
  {
    id: 'reports-group',
    title: 'รายงาน & วิเคราะห์ยอด',
    subtitle: 'รายงานยอดขายจริง ใบจอง และผลงานทีมขาย',
    icon: '📈',
    tone: 'emerald',
    showInMore: true,
    items: [
      {
        name: 'reports',
        path: '/reports',
        icon: '📈',
        tone: 'emerald',
        label: 'รายงานยอดขาย & ออกตลาด',
        desc: 'วิเคราะห์ยอดขาย Bplus และสรุปผลเข้าพบ',
        keywords: 'รายงาน ยอดขาย สถิติ ออกตลาด',
      },
    ],
  },
  {
    id: 'members',
    title: 'สมาชิก & สะสมแต้ม',
    subtitle: 'ดูแลสมาชิกร้านค้า คะแนนสะสม และของรางวัล',
    icon: '💎',
    tone: 'purple',
    showInMore: true,
    items: [
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
        label: 'กติกาแต้มสะสม',
        desc: 'ตั้งค่าอัตราแต้มและสมุดบัญชีแต้ม',
        adminOnly: true,
        keywords: 'กติกาแต้ม คะแนนสะสม รายการแต้ม',
      },
      {
        name: 'rewards',
        path: '/rewards',
        icon: '🎁',
        tone: 'emerald',
        label: 'ของรางวัลแลกแต้ม',
        desc: 'จัดการของรางวัลและคำขอแลกแต้ม',
        adminOnly: true,
        keywords: 'ของรางวัล แลกแต้ม สิทธิพิเศษ',
      },
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
        label: 'เปิดหน้าเว็บสมาชิก',
        desc: 'สลับไปหน้าเว็บฝั่งลูกค้าสมาชิก',
        keywords: 'เปิดหน้าสมาชิก หน้าลูกค้า ลิงก์สมาชิก',
      },
    ],
  },
  {
    id: 'erp',
    title: 'เชื่อมต่อ Bplus ERP',
    subtitle: 'ทะเบียนลูกหนี้และการซิงก์ฐานข้อมูล Bplus',
    icon: '🏢',
    tone: 'amber',
    showInMore: true,
    items: [
      {
        name: 'erp-customers',
        path: '/erp-customers',
        icon: '🏢',
        tone: 'amber',
        label: 'ทะเบียนลูกหนี้ ERP',
        desc: 'ข้อมูลลูกหนี้และยอดซื้อจาก Bplus',
        keywords: 'ลูกค้าใน ERP ลูกหนี้ ข้อมูลบัญชี',
      },
      {
        name: 'erp-requests',
        path: '/erp-requests',
        icon: '📨',
        tone: 'blue',
        label: 'คำขอเปิดรหัสลูกหนี้',
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
        desc: 'ตรวจสถานะซิงก์บิลขายและลูกหนี้',
        adminOnly: true,
        keywords: 'สถานะดึงข้อมูล ERP ซิงก์ ตรวจสอบข้อมูล',
      },
    ],
  },
  {
    id: 'system',
    title: 'ตั้งค่าระบบ & ผู้ใช้',
    subtitle: 'จัดการสิทธิ์ รีเซ็ตรหัสผ่าน และเชื่อมต่อ LINE',
    icon: '⚙️',
    tone: 'slate',
    showInMore: true,
    items: [
      {
        name: 'settings',
        path: '/settings',
        icon: '⚙️',
        tone: 'slate',
        label: 'ตั้งค่าผู้ใช้ & ผูกใบจอง',
        desc: 'เปลี่ยนชื่อ รีเซ็ตรหัสผ่าน และตั้งค่า LINE',
        adminOnly: true,
        keywords: 'ตั้งค่า ผู้ใช้งาน สิทธิ์ บัญชีทีมขาย รีเซ็ตรหัสผ่าน ไลน์',
      },
      {
        name: 'profile',
        path: '/profile',
        icon: '📱',
        tone: 'blue',
        label: 'โปรไฟล์ & ข้อมูลค้างส่ง',
        desc: 'เช็คสถานะออนไลน์และคิวส่งข้อมูล',
        keywords: 'โปรไฟล์ ข้อมูลค้างส่ง บัญชีผู้ใช้ มือถือ',
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
