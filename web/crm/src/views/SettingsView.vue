<template>
  <div>
    <PageHeader
      title="ตั้งค่าผู้ใช้งาน & รีเซ็ตรหัสผ่าน"
      subtitle="ดูรายชื่อทุกคนในระบบ เปลี่ยนชื่อ ตั้งรหัสผ่านใหม่ เปลี่ยนสิทธิ์ และผูกสายใบจองได้ครบในหน้าเดียว"
    >
      <template #secondary>
        <button
          v-if="!isExecutive"
          type="button"
          class="btn"
          :disabled="syncingAuto"
          @click="triggerAutoSync"
        >
          {{ syncingAuto ? 'กำลังซิงก์...' : '⚡ ซิงก์รายชื่อจาก Bplus อัตโนมัติ' }}
        </button>
      </template>
      <template #primary>
        <button v-if="!isExecutive" type="button" class="btn primary" @click="openCreateModal">
          ＋ เพิ่มผู้ใช้งานใหม่
        </button>
      </template>
    </PageHeader>

    <SkeletonBlock v-if="isLoading && !data" variant="cards" :rows="4" />
    <div v-else-if="isError" class="error">{{ error?.message || 'โหลดการตั้งค่าและผู้ใช้งานไม่สำเร็จ' }}</div>

    <template v-else-if="data">
      <!-- Role Summary KPIs (Clickable to filter) -->
      <div class="kpis" style="margin-bottom:16px">
        <button
          type="button"
          :class="['card kpi kpi-filter-btn', { active: roleFilter === 'all' }]"
          @click="roleFilter = 'all'"
        >
          <div class="kpi-top">
            <div class="kpi-icon">👥</div>
            <span class="tag blue">ทั้งหมด</span>
          </div>
          <div class="kpi-label">ผู้ใช้งานทั้งหมดในระบบ</div>
          <div class="kpi-value">{{ (data.staff_users || []).length }} <span class="kpi-unit">คน</span></div>
          <div class="kpi-foot">กดเพื่อดูรายชื่อทุกคน</div>
        </button>

        <button
          type="button"
          :class="['card kpi kpi-filter-btn', { active: roleFilter === 'admin' }]"
          @click="roleFilter = 'admin'"
        >
          <div class="kpi-top">
            <div class="kpi-icon purple">👑</div>
            <span class="tag purple">แก้ไขระบบได้</span>
          </div>
          <div class="kpi-label">แอดมินระบบ (Admin)</div>
          <div class="kpi-value">{{ countByRole('admin') }} <span class="kpi-unit">คน</span></div>
          <div class="kpi-foot">เห็นทั้งหมด + จัดการผู้ใช้/รหัสผ่าน</div>
        </button>

        <button
          type="button"
          :class="['card kpi kpi-filter-btn', { active: roleFilter === 'executive' }]"
          @click="roleFilter = 'executive'"
        >
          <div class="kpi-top">
            <div class="kpi-icon orange">👔</div>
            <span class="tag orange">ดูอย่างเดียว</span>
          </div>
          <div class="kpi-label">ผู้บริหาร / เสี่ย / เจ๊ / ผจก.</div>
          <div class="kpi-value">{{ countByRole('executive') + countByRole('manager') }} <span class="kpi-unit">คน</span></div>
          <div class="kpi-foot">เห็นข้อมูลทุกคน · แก้ไขระบบไม่ได้</div>
        </button>

        <button
          type="button"
          :class="['card kpi kpi-filter-btn', { active: roleFilter === 'sales' }]"
          @click="roleFilter = 'sales'"
        >
          <div class="kpi-top">
            <div class="kpi-icon green">🚀</div>
            <span class="tag green">ผูกตามใบจอง</span>
          </div>
          <div class="kpi-label">พนักงานขาย / เซลล์ (Sales)</div>
          <div class="kpi-value">{{ countByRole('sales') }} <span class="kpi-unit">คน</span></div>
          <div class="kpi-foot">เห็นเฉพาะใบจองและลูกหนี้ของตัวเอง</div>
        </button>
      </div>

      <!-- Main User Directory Card -->
      <div class="card panel-pad" style="margin-bottom:16px">
        <div class="staff-head">
          <div>
            <h3 style="margin:0">รายชื่อผู้ใช้งานทั้งหมด ({{ filteredUsers.length }} / {{ (data.staff_users || []).length }} คน)</h3>
            <small class="muted">สามารถกด "✏️ เปลี่ยนชื่อ / แก้ไข" หรือ "🔑 รีเซ็ตรหัสผ่าน" รายคนได้ทันที</small>
          </div>

          <div class="staff-filter-bar">
            <div class="role-pills">
              <button
                v-for="tab in ROLE_TABS"
                :key="tab.id"
                type="button"
                :class="['role-pill', { active: roleFilter === tab.id }]"
                @click="roleFilter = tab.id"
              >
                {{ tab.label }} ({{ tab.count }})
              </button>
            </div>
            <input
              v-model="searchQuery"
              type="search"
              class="staff-search-input"
              placeholder="ค้นหาชื่อ, B16, เสี่ย, อีเมล..."
            />
          </div>
        </div>

        <div class="table-wrap desktop-table-only" style="margin-top:14px">
          <table class="table">
            <thead>
              <tr>
                <th>ชื่อแสดงในระบบ</th>
                <th>ชื่อที่ใช้ล็อกอินได้</th>
                <th>ระดับสิทธิ์</th>
                <th>สายใบจอง / รหัสเซลล์</th>
                <th>สถานะ</th>
                <th style="text-align:right">เปลี่ยนชื่อ / รีเซ็ตรหัสผ่าน</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="u in filteredUsers" :key="u.id">
                <td>
                  <b>{{ u.name }}</b>
                </td>
                <td style="white-space:normal">
                  <div class="login-hint-Group">
                    <code class="login-code-pill">{{ primaryLoginHint(u) }}</code>
                    <span class="small muted">{{ u.email }}</span>
                  </div>
                </td>
                <td>
                  <span
                    class="tag"
                    :class="u.role === 'admin' ? 'purple' : u.role === 'executive' ? 'orange' : u.role === 'manager' ? 'blue' : 'green'"
                  >
                    {{ roleBadgeLabel(u.role) }}
                  </span>
                </td>
                <td style="white-space:normal">
                  <div v-if="(u.reservation_doc_types_list || []).length || u.salesperson_code" class="doc-chip-row">
                    <span
                      v-for="doc in u.reservation_doc_types_list || []"
                      :key="doc"
                      class="tag orange"
                    >
                      ใบจอง {{ doc }}
                    </span>
                    <span v-if="u.salesperson_code" class="tag blue">
                      รหัสเซลล์ {{ u.salesperson_code }}
                    </span>
                  </div>
                  <span v-else class="small muted">ดูได้ทุกสาย</span>
                </td>
                <td>
                  <span class="tag" :class="u.is_active ? 'green' : 'red'">
                    {{ u.is_active ? 'เปิดใช้งาน' : 'ระงับ' }}
                  </span>
                </td>
                <td style="text-align:right">
                  <div v-if="!isExecutive" class="row-action-btns">
                    <button type="button" class="btn light row-btn" @click="openEditModal(u)">
                      ✏️ เปลี่ยนชื่อ / สิทธิ์
                    </button>
                    <button type="button" class="btn row-btn" @click="openResetPasswordModal(u)">
                      🔑 รีเซ็ตรหัสผ่าน
                    </button>
                  </div>
                  <span v-else class="small muted">ดูอย่างเดียว</span>
                </td>
              </tr>
              <tr v-if="!filteredUsers.length">
                <td colspan="6" class="placeholder">ไม่พบรายชื่อที่ค้นหา</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Mobile Cards -->
        <div class="mob-cards" style="margin-top:12px">
          <div v-for="u in filteredUsers" :key="`um-${u.id}`" class="card mob-card">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">
              <div>
                <strong style="font-size:15.5px">{{ u.name }}</strong>
                <div class="small muted" style="margin-top:2px">
                  ล็อกอินด้วย: <code class="login-code-pill">{{ primaryLoginHint(u) }}</code> หรือ {{ u.email }}
                </div>
              </div>
              <span
                class="tag"
                :class="u.role === 'admin' ? 'purple' : u.role === 'executive' ? 'orange' : u.role === 'manager' ? 'blue' : 'green'"
              >
                {{ roleBadgeLabel(u.role) }}
              </span>
            </div>

            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px">
              <span
                v-for="doc in u.reservation_doc_types_list || []"
                :key="doc"
                class="tag orange"
              >
                ใบจอง {{ doc }}
              </span>
              <span v-if="u.salesperson_code" class="tag blue">
                รหัสเซลล์ {{ u.salesperson_code }}
              </span>
              <span class="tag" :class="u.is_active ? 'green' : 'red'">
                {{ u.is_active ? 'เปิดใช้งาน' : 'ระงับ' }}
              </span>
            </div>

            <div v-if="!isExecutive" style="margin-top:10px;display:grid;grid-template-columns:1fr 1fr;gap:8px">
              <button type="button" class="btn light" @click="openEditModal(u)">
                ✏️ เปลี่ยนชื่อ / แก้ไข
              </button>
              <button type="button" class="btn" @click="openResetPasswordModal(u)">
                🔑 รีเซ็ตรหัสผ่าน
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Available Reservation Routes Directory -->
      <div class="card panel-pad" style="margin-bottom:16px">
        <div class="staff-head">
          <div>
            <h3 style="margin:0">สายใบจองในระบบ Bplus ({{ (data.available_reservations || []).length }} สาย)</h3>
            <small class="muted">ระบบผูกใบจองกับเซลล์ให้อัตโนมัติแล้ว หรือกดที่การ์ดเพื่อปรับแต่งเพิ่มเติมได้</small>
          </div>
        </div>

        <div class="res-route-grid" style="margin-top:12px">
          <div
            v-for="r in data.available_reservations || []"
            :key="r.doc_type"
            class="res-route-card"
          >
            <div class="res-route-top">
              <span class="tag orange">ใบจอง {{ r.doc_type }}</span>
              <span class="small muted">{{ fmt(r.customer_count) }} ลูกหนี้ · {{ fmt(r.doc_count) }} ใบจอง</span>
            </div>
            <div class="res-route-main">
              <strong>เซลล์หลัก: {{ r.main_sp_name || 'ไม่ระบุ' }}</strong>
              <span v-if="r.main_sp_code" class="small muted"> (รหัส {{ r.main_sp_code }})</span>
            </div>
            <div class="res-route-foot">
              <span class="small">ยอดใบจองสะสม: <b>{{ fmt(r.total_before_vat) }} ฿</b></span>
              <button v-if="!isExecutive" type="button" class="btn light res-bind-btn" @click="openCreateFromRoute(r)">
                ✏️ ตั้งค่าสาย {{ r.doc_type }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- 1. Modal: Edit Name / Role / Reservation Binding -->
    <BaseModal
      :open="modalOpen"
      :title="editingId ? `แก้ไขชื่อ & สิทธิ์ผู้ใช้งาน: ${form.name}` : 'เพิ่มผู้ใช้งานใหม่'"
      subtitle="เปลี่ยนชื่อที่แสดง อีเมล ระดับสิทธิ์ (แอดมิน / ผู้บริหาร / เซลล์) และการผูกสายใบจอง"
      max-width="680px"
      @close="modalOpen = false"
    >
      <form class="form" @submit.prevent="saveStaff">
        <div class="field">
          <label>ชื่อผู้ใช้งาน (เปลี่ยนชื่อได้) *</label>
          <input v-model="form.name" required placeholder="เช่น เสี่ยสมชาย / เก่ง (B16)" />
        </div>
        <div class="field">
          <label>อีเมลล็อกอิน *</label>
          <input v-model="form.email" type="email" required placeholder="เช่น sia@popstar.local" />
        </div>
        <div class="field">
          <label>{{ editingId ? 'ตั้งรหัสผ่านใหม่ (เว้นว่างถ้าไม่เปลี่ยน)' : 'รหัสผ่าน (อย่างน้อย 8 ตัวอักษร) *' }}</label>
          <input
            v-model="form.password"
            type="text"
            :required="!editingId"
            minlength="8"
            placeholder="เช่น Popstar2026!"
          />
        </div>
        <div class="field">
          <label>ระดับสิทธิ์ (Role) *</label>
          <select v-model="form.role">
            <option value="sales">🚀 พนักงานขาย / เซลล์ (เห็นเฉพาะใบจอง &amp; ยอดตัวเอง)</option>
            <option value="executive">👔 ผู้บริหาร / เสี่ย / เจ๊ / ผจก. (ดูข้อมูลทุกคน · แก้ไขระบบไม่ได้)</option>
            <option value="manager">📊 ผู้จัดการฝ่ายขาย (เห็นทั้งหมด)</option>
            <option value="admin">👑 แอดมินระบบ (เห็นทั้งหมด + ตั้งค่าระบบ)</option>
          </select>
        </div>

        <template v-if="form.role === 'sales'">
          <div class="field span2">
            <label>รหัสพนักงานขายใน Bplus (Salesperson Code)</label>
            <select v-model="form.salesperson_code">
              <option value="">-- ผูกตามรหัสใบจองด้านล่าง --</option>
              <option
                v-for="sp in data?.available_salespersons || []"
                :key="sp.salesperson_code"
                :value="sp.salesperson_code"
              >
                รหัส {{ sp.salesperson_code }} — {{ sp.salesperson_name }}
              </option>
            </select>
          </div>

          <div class="field span2">
            <label>สายใบจองที่รับผิดชอบ (เลือกได้มากกว่า 1 ใบจอง)</label>
            <div class="doc-picker-grid">
              <button
                v-for="r in data?.available_reservations || []"
                :key="`pick-${r.doc_type}`"
                type="button"
                :class="['doc-pick-chip', { selected: form.reservation_doc_types.includes(r.doc_type) }]"
                @click="toggleDocType(r)"
              >
                <div class="doc-pick-top">
                  <strong>{{ r.doc_type }}</strong>
                  <span>{{ r.main_sp_name || 'สายขาย' }}</span>
                </div>
                <small>{{ fmt(r.customer_count) }} ลูกหนี้ · {{ fmt(r.doc_count) }} ใบ</small>
              </button>
            </div>
          </div>
        </template>

        <div class="field span2">
          <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer">
            <input v-model="form.is_active" type="checkbox" style="width:20px;height:20px;min-height:20px" />
            <span>เปิดใช้งานบัญชีนี้ (หากเอาติ๊กออก ผู้ใช้นี้จะล็อกอินไม่ได้ชั่วคราว)</span>
          </label>
        </div>
      </form>

      <template #footer>
        <button type="button" class="btn" @click="modalOpen = false">ยกเลิก</button>
        <button type="button" class="btn primary" :disabled="saving" @click="saveStaff">
          {{ saving ? 'กำลังบันทึก...' : '💾 บันทึกการเปลี่ยนแปลง' }}
        </button>
      </template>
    </BaseModal>

    <!-- 2. Modal: Quick Password Reset -->
    <BaseModal
      :open="resetModalOpen"
      :title="`🔑 รีเซ็ตรหัสผ่าน: ${resetTarget?.name || ''}`"
      :subtitle="`ล็อกอินด้วย: ${primaryLoginHint(resetTarget)} หรือ ${resetTarget?.email || ''}`"
      max-width="480px"
      @close="resetModalOpen = false"
    >
      <div v-if="resetTarget" style="display:flex;flex-direction:column;gap:14px">
        <div class="notice" style="margin-bottom:0">
          กดปุ่มด้านล่างเพื่อรีเซ็ตกลับเป็นรหัสมาตรฐาน <b>Popstar2026!</b> ในคลิกเดียว หรือพิมพ์รหัสผ่านใหม่ที่ต้องการตั้งให้ผู้ใช้นี้
        </div>

        <button
          type="button"
          class="btn light"
          style="width:100%;justify-content:center"
          :disabled="savingReset"
          @click="newPassword = 'Popstar2026!'; confirmResetPassword()"
        >
          ⚡ รีเซ็ตรหัสผ่านเป็น "Popstar2026!" ทันที
        </button>

        <div class="field">
          <label>หรือกำหนดรหัสผ่านใหม่เอง (อย่างน้อย 8 ตัวอักษร)</label>
          <input
            v-model="newPassword"
            type="text"
            minlength="8"
            placeholder="พิมพ์รหัสผ่านใหม่ที่ต้องการ..."
          />
        </div>
      </div>

      <template #footer>
        <button type="button" class="btn" @click="resetModalOpen = false">ยกเลิก</button>
        <button
          type="button"
          class="btn primary"
          :disabled="savingReset || newPassword.length < 8"
          @click="confirmResetPassword"
        >
          {{ savingReset ? 'กำลังรีเซ็ต...' : '✓ ยืนยันเปลี่ยนรหัสผ่าน' }}
        </button>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import BaseModal from '../../../shared/components/BaseModal.vue';
import PageHeader from '../../../shared/components/PageHeader.vue';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import { toast } from '../../../shared/composables/useUiStore.js';
import { crmGet, crmMutate, fmt, invalidateCrmData, isExecutive, refreshSession } from '../composables/useCrmSession.js';

const { data, isLoading, isError, error, refetch } = useQuery({
  queryKey: ['crm', 'settings-overview'],
  queryFn: ({ signal }) => crmGet('/settings/overview', { signal }),
});

const roleFilter = ref('all');
const searchQuery = ref('');

const modalOpen = ref(false);
const editingId = ref(null);
const saving = ref(false);
const syncingAuto = ref(false);
const form = ref({
  name: '',
  email: '',
  password: '',
  role: 'sales',
  salesperson_code: '',
  reservation_doc_types: [],
  is_active: true,
});

const resetModalOpen = ref(false);
const resetTarget = ref(null);
const newPassword = ref('Popstar2026!');
const savingReset = ref(false);

function countByRole(role) {
  return (data.value?.staff_users || []).filter((u) => u.role === role).length;
}

const ROLE_TABS = computed(() => [
  { id: 'all', label: 'ทั้งหมด', count: (data.value?.staff_users || []).length },
  { id: 'admin', label: '👑 แอดมิน', count: countByRole('admin') },
  { id: 'executive', label: '👔 ผู้บริหาร/ผจก.', count: countByRole('executive') + countByRole('manager') },
  { id: 'sales', label: '🚀 เซลล์', count: countByRole('sales') },
]);

const filteredUsers = computed(() => {
  const q = searchQuery.value.trim().toLowerCase();
  return (data.value?.staff_users || []).filter((u) => {
    if (roleFilter.value === 'admin' && u.role !== 'admin') return false;
    if (roleFilter.value === 'executive' && !['executive', 'manager'].includes(u.role)) return false;
    if (roleFilter.value === 'sales' && u.role !== 'sales') return false;
    if (!q) return true;
    const hay = [
      u.name,
      u.email,
      u.salesperson_code,
      ...(u.reservation_doc_types_list || []),
    ]
      .filter(Boolean)
      .join(' ')
      .toLowerCase();
    return hay.includes(q);
  });
});

function roleBadgeLabel(role) {
  if (role === 'admin') return '👑 แอดมิน (แก้ไขระบบได้)';
  if (role === 'executive') return '👔 ผู้บริหาร (ดูทุกคน · ห้ามแก้ไข)';
  if (role === 'manager') return '📊 ผู้จัดการ (เห็นทั้งหมด)';
  return '🚀 เซลล์ (เฉพาะสายตัวเอง)';
}

function primaryLoginHint(u) {
  if (!u) return '';
  if ((u.reservation_doc_types_list || []).length) {
    return u.reservation_doc_types_list[0];
  }
  if (u.salesperson_code) {
    return u.salesperson_code;
  }
  if (u.email?.startsWith('sia@')) return 'เสี่ย';
  if (u.email?.startsWith('jae@')) return 'เจ๊';
  if (u.email?.startsWith('gm@')) return 'ผู้จัดการทั่วไป';
  if (u.email?.startsWith('wh-mgr@')) return 'ผู้จัดการคลัง';
  if (u.email?.startsWith('branch-mgr@')) return 'ผู้จัดการสาขา';
  return u.email || '';
}

async function triggerAutoSync() {
  syncingAuto.value = true;
  try {
    const res = await crmMutate('/settings/staff/auto-sync', 'POST', {});
    toast.success(
      'ซิงก์รายชื่อและผูกใบจองอัตโนมัติแล้ว',
      `เซลล์ ${res.total_sales_staff} คน · ผู้บริหาร ${res.total_executive_staff || 5} คน`
    );
    await refetch();
    invalidateCrmData();
  } catch (err) {
    toast.error('ผูกอัตโนมัติไม่สำเร็จ', err);
  } finally {
    syncingAuto.value = false;
  }
}

function openCreateModal() {
  editingId.value = null;
  form.value = {
    name: '',
    email: '',
    password: 'Popstar2026!',
    role: 'executive',
    salesperson_code: '',
    reservation_doc_types: [],
    is_active: true,
  };
  modalOpen.value = true;
}

function openCreateFromRoute(routeRow) {
  const existing = (data.value?.staff_users || []).find(
    (u) =>
      (u.reservation_doc_types_list || []).includes(routeRow.doc_type) ||
      (routeRow.main_sp_code && String(u.salesperson_code) === String(routeRow.main_sp_code))
  );
  if (existing) {
    openEditModal(existing);
    if (!form.value.reservation_doc_types.includes(routeRow.doc_type)) {
      form.value.reservation_doc_types.push(routeRow.doc_type);
    }
    return;
  }

  editingId.value = null;
  const codeLower = String(routeRow.doc_type || 'sales').toLowerCase();
  form.value = {
    name: `${routeRow.main_sp_name || routeRow.doc_type} (${routeRow.doc_type})`,
    email: `${codeLower}@popstar.local`,
    password: 'Popstar2026!',
    role: 'sales',
    salesperson_code: routeRow.main_sp_code || '',
    reservation_doc_types: [routeRow.doc_type],
    is_active: true,
  };
  modalOpen.value = true;
}

function openEditModal(u) {
  editingId.value = u.id;
  form.value = {
    name: u.name || '',
    email: u.email || '',
    password: '',
    role: u.role || 'sales',
    salesperson_code: u.salesperson_code || '',
    reservation_doc_types: [...(u.reservation_doc_types_list || [])],
    is_active: Boolean(u.is_active),
  };
  modalOpen.value = true;
}

function openResetPasswordModal(u) {
  resetTarget.value = u;
  newPassword.value = 'Popstar2026!';
  resetModalOpen.value = true;
}

async function confirmResetPassword() {
  if (!resetTarget.value || newPassword.value.length < 8) {
    toast.warning('รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร');
    return;
  }
  savingReset.value = true;
  try {
    await crmMutate(`/settings/staff/${resetTarget.value.id}`, 'PATCH', {
      password: newPassword.value,
    });
    toast.success(
      `รีเซ็ตรหัสผ่านของ "${resetTarget.value.name}" เรียบร้อย`,
      `รหัสผ่านใหม่คือ: ${newPassword.value}`
    );
    resetModalOpen.value = false;
    await refetch();
  } catch (err) {
    toast.error('รีเซ็ตรหัสผ่านไม่สำเร็จ', err);
  } finally {
    savingReset.value = false;
  }
}

function toggleDocType(routeRow) {
  const code = routeRow.doc_type;
  const idx = form.value.reservation_doc_types.indexOf(code);
  if (idx >= 0) {
    form.value.reservation_doc_types.splice(idx, 1);
  } else {
    form.value.reservation_doc_types.push(code);
    if (!form.value.salesperson_code && routeRow.main_sp_code) {
      form.value.salesperson_code = routeRow.main_sp_code;
    }
  }
}

async function saveStaff() {
  if (!form.value.name.trim() || !form.value.email.trim()) {
    toast.warning('กรุณากรอกชื่อและอีเมลให้ครบ');
    return;
  }
  if (!editingId.value && (!form.value.password || form.value.password.length < 8)) {
    toast.warning('กรุณาตั้งรหัสผ่านอย่างน้อย 8 ตัวอักษร');
    return;
  }

  saving.value = true;
  try {
    const payload = {
      name: form.value.name.trim(),
      email: form.value.email.trim(),
      role: form.value.role,
      salesperson_code: form.value.role === 'sales' ? (form.value.salesperson_code || null) : null,
      reservation_doc_types: form.value.role === 'sales' ? form.value.reservation_doc_types : [],
      is_active: form.value.is_active,
      ...(form.value.password ? { password: form.value.password } : {}),
    };

    if (editingId.value) {
      await crmMutate(`/settings/staff/${editingId.value}`, 'PATCH', payload);
      toast.success(`บันทึกข้อมูลของ "${form.value.name}" เรียบร้อย`);
    } else {
      await crmMutate('/settings/staff', 'POST', payload);
      toast.success(`เพิ่มบัญชี "${form.value.name}" เรียบร้อย`);
    }

    modalOpen.value = false;
    await refetch();
    await refreshSession();
    invalidateCrmData();
  } catch (err) {
    toast.error('บันทึกข้อมูลผู้ใช้งานไม่สำเร็จ', err);
  } finally {
    saving.value = false;
  }
}
</script>

<style scoped>
.kpi-filter-btn {
  text-align: left;
  cursor: pointer;
  transition: transform 0.14s ease, border-color 0.14s ease, box-shadow 0.14s ease;
}

.kpi-filter-btn:hover {
  border-color: #93b4ff;
  transform: translateY(-2px);
}

.kpi-filter-btn.active {
  border-color: var(--blue);
  box-shadow: 0 0 0 3px rgba(47, 99, 232, 0.16);
}

.staff-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}

.staff-filter-bar {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.role-pills {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.role-pill {
  border: 1px solid var(--border);
  background: #f8fafc;
  color: var(--muted);
  border-radius: 99px;
  padding: 7px 12px;
  font-size: 13px;
  font-weight: 600;
  min-height: 36px;
}

.role-pill.active {
  background: var(--ink);
  border-color: var(--ink);
  color: #ffffff;
}

.staff-search-input {
  border: 1px solid var(--border);
  border-radius: 11px;
  padding: 8px 12px;
  font-size: 14px;
  min-height: 40px;
  min-width: 210px;
}

.login-hint-Group {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.login-code-pill {
  display: inline-block;
  width: fit-content;
  background: #eff6ff;
  color: #1d4ed8;
  border: 1px solid #bfdbfe;
  border-radius: 7px;
  padding: 1px 8px;
  font-size: 13px;
  font-weight: 700;
}

.row-action-btns {
  display: inline-flex;
  gap: 6px;
  justify-content: flex-end;
  flex-wrap: wrap;
}

.row-btn {
  padding: 6px 11px;
  min-height: 36px;
  font-size: 13px;
}

.doc-chip-row {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.res-route-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
  gap: 12px;
}

.res-route-card {
  border: 1px solid var(--border);
  border-radius: 14px;
  padding: 13px 14px;
  background: #ffffff;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.res-route-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
}

.res-route-main {
  font-size: 14px;
  color: var(--ink);
}

.res-route-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  flex-wrap: wrap;
  padding-top: 6px;
  border-top: 1px solid #edf1f6;
}

.res-bind-btn {
  padding: 6px 10px;
  min-height: 34px;
  font-size: 12.5px;
}

.doc-picker-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(145px, 1fr));
  gap: 8px;
}

.doc-pick-chip {
  border: 1px solid var(--border);
  background: #f8fafc;
  border-radius: 12px;
  padding: 9px 11px;
  text-align: left;
  display: flex;
  flex-direction: column;
  gap: 3px;
  transition: all 0.14s ease;
}

.doc-pick-chip.selected {
  background: #eff6ff;
  border-color: var(--blue);
  box-shadow: 0 0 0 2px rgba(47, 99, 232, 0.18);
}

.doc-pick-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
  font-size: 13.5px;
  color: var(--ink);
}

.doc-pick-chip small {
  font-size: 12px;
  color: var(--muted);
}

@media (max-width: 760px) {
  .staff-search-input {
    width: 100%;
  }
}
</style>
