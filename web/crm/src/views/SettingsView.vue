<template>
  <div>
    <PageHeader
      title="ตั้งค่า & จัดการสิทธิ์เซลล์ตามใบจอง"
      subtitle="แอดมินเห็นข้อมูลทั้งระบบ · เซลล์เห็นเฉพาะลูกหนี้และยอดขายในสายใบจอง / รหัสเซลล์ของตัวเอง"
    >
      <template #secondary>
        <button
          v-if="!isExecutive"
          type="button"
          class="btn"
          :disabled="syncingAuto"
          @click="triggerAutoSync"
        >
          {{ syncingAuto ? 'กำลังผูกอัตโนมัติ...' : '⚡ ผูกเซลล์ & ใบจองจากบิล Bplus อัตโนมัติ' }}
        </button>
      </template>
      <template #primary>
        <button v-if="!isExecutive" type="button" class="btn primary" @click="openCreateModal">
          ＋ เพิ่มบัญชีพนักงาน / ผู้บริหาร
        </button>
      </template>
    </PageHeader>

    <SkeletonBlock v-if="isLoading && !data" variant="cards" :rows="4" />
    <div v-else-if="isError" class="error">{{ error?.message || 'โหลดการตั้งค่าและผู้ใช้งานไม่สำเร็จ' }}</div>

    <template v-else-if="data">
      <!-- Role Scope Rule Summary -->
      <div class="card panel-pad scope-rule-banner" style="margin-bottom:16px">
        <div class="scope-rule-grid">
          <div class="scope-rule-box admin">
            <span class="tag purple">👑 แอดมิน / ผู้จัดการ (admin, manager)</span>
            <strong>เห็นข้อมูลทั้งหมดในระบบ 100%</strong>
            <p class="small muted">
              ดูยอดขายทุกสายใบจอง ลูกหนี้ทุกร้าน แผนที่ทุกร้าน และจัดการผูกใบจองให้บัญชีพนักงานขายได้
            </p>
          </div>
          <div class="scope-rule-box sales">
            <span class="tag blue">🚀 พนักงานขาย / เซลล์ (sales)</span>
            <strong>เห็นเฉพาะลูกหนี้และยอดขายของตัวเองเท่านั้น</strong>
            <p class="small muted">
              ระบบกรองอัตโนมัติตาม <b>รหัสใบจอง (เช่น B16, B32, B33)</b> และ <b>รหัสเซลล์ Bplus</b> ที่ผูกไว้ ไม่เห็นข้อมูลของสายอื่น
            </p>
          </div>
        </div>
      </div>

      <!-- Staff Users & Reservation Binding Table -->
      <div class="card panel-pad" style="margin-bottom:16px">
        <div class="staff-head">
          <div>
            <h3 style="margin:0">บัญชีผู้ใช้งาน &amp; การผูกสายใบจอง ({{ (data.staff_users || []).length }})</h3>
            <small class="muted">กด "ผูกใบจอง / แก้ไข" เพื่อกำหนดว่าเซลล์แต่ละคนดูแลใบจองสายไหนและเห็นลูกหนี้กลุ่มใด</small>
          </div>
          <button type="button" class="btn primary" @click="openCreateModal">
            ＋ เพิ่มบัญชีพนักงาน
          </button>
        </div>

        <div class="table-wrap desktop-table-only" style="margin-top:12px">
          <table class="table">
            <thead>
              <tr>
                <th>ชื่อผู้ใช้งาน</th>
                <th>อีเมลล็อกอิน</th>
                <th>สิทธิ์ (Role)</th>
                <th>รหัสเซลล์ Bplus</th>
                <th>สายใบจองที่ผูก (207)</th>
                <th>สถานะ</th>
                <th style="text-align:right">จัดการ</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="u in data.staff_users || []" :key="u.id">
                <td><b>{{ u.name }}</b></td>
                <td>{{ u.email }}</td>
                <td>
                  <span
                    class="tag"
                    :class="u.role === 'admin' ? 'purple' : u.role === 'executive' ? 'orange' : u.role === 'manager' ? 'blue' : 'green'"
                  >
                    {{
                      u.role === 'admin'
                        ? 'แอดมิน (เห็นทั้งหมด + แก้ไขระบบ)'
                        : u.role === 'executive'
                          ? 'ผู้บริหาร (ดูทุกคน · ห้ามแก้ไข)'
                          : u.role === 'manager'
                            ? 'ผู้จัดการ (เห็นทั้งหมด)'
                            : 'เซลล์ (เฉพาะตัวเอง)'
                    }}
                  </span>
                </td>
                <td>
                  <span v-if="u.salesperson_code" class="tag blue">
                    {{ u.salesperson_code }} · {{ salespersonNameByCode(u.salesperson_code) }}
                  </span>
                  <span v-else class="small muted">—</span>
                </td>
                <td style="white-space:normal">
                  <div v-if="(u.reservation_doc_types_list || []).length" class="doc-chip-row">
                    <span
                      v-for="doc in u.reservation_doc_types_list"
                      :key="doc"
                      class="tag orange"
                    >
                      ใบจอง {{ doc }}
                    </span>
                  </div>
                  <span v-else-if="u.role === 'sales'" class="small" style="color:var(--orange)">ยังไม่ผูกใบจอง</span>
                  <span v-else class="small muted">ทุกใบจอง</span>
                </td>
                <td>
                  <span class="tag" :class="u.is_active ? 'green' : 'red'">
                    {{ u.is_active ? 'ใช้งานปกติ' : 'ระงับ' }}
                  </span>
                </td>
                <td style="text-align:right">
                  <button v-if="!isExecutive" type="button" class="btn light" @click="openEditModal(u)">
                    ✏️ ผูกใบจอง / แก้ไข
                  </button>
                  <span v-else class="small muted">ดูอย่างเดียว</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="mob-cards" style="margin-top:12px">
          <div v-for="u in data.staff_users || []" :key="`um-${u.id}`" class="card mob-card">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">
              <div>
                <strong>{{ u.name }}</strong>
                <div class="small muted">{{ u.email }}</div>
              </div>
              <span class="tag" :class="u.role === 'admin' ? 'purple' : u.role === 'manager' ? 'blue' : 'green'">
                {{ u.role === 'sales' ? 'เซลล์' : u.role }}
              </span>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px">
              <span v-if="u.salesperson_code" class="tag blue">
                รหัสเซลล์: {{ u.salesperson_code }} ({{ salespersonNameByCode(u.salesperson_code) }})
              </span>
              <span
                v-for="doc in u.reservation_doc_types_list || []"
                :key="doc"
                class="tag orange"
              >
                ใบจอง {{ doc }}
              </span>
              <span
                v-if="u.role === 'sales' && !u.salesperson_code && !(u.reservation_doc_types_list || []).length"
                class="tag red"
              >
                ยังไม่ผูกใบจอง/รหัสเซลล์
              </span>
            </div>
            <div style="margin-top:10px;display:flex;justify-content:flex-end">
              <button type="button" class="btn light" @click="openEditModal(u)">
                ✏️ ผูกใบจอง / แก้ไข
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Available Reservation Routes Quick-Create Directory -->
      <div class="card panel-pad" style="margin-bottom:16px">
        <div class="staff-head">
          <div>
            <h3 style="margin:0">สายใบจองในระบบ Bplus ({{ (data.available_reservations || []).length }} สาย)</h3>
            <small class="muted">กด "สร้าง/ผูกบัญชีเซลล์สายนี้" เพื่อตั้งค่าบัญชีเซลล์ให้ตรงกับใบจองและลูกหนี้ในสายนั้นทันที</small>
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
              <button type="button" class="btn light res-bind-btn" @click="openCreateFromRoute(r)">
                ＋ ผูกเซลล์สาย {{ r.doc_type }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Security Posture & Audit Logs -->
      <div class="split">
        <div class="card panel-pad">
          <h3 style="margin:0 0 10px">สถานะความปลอดภัยของระบบ</h3>
          <div class="minirow">
            <span>ERP Mirror Read-Only</span>
            <span class="tag green">{{ data.security_posture?.erp_mirror_readonly ? 'บังคับ Read-Only' : 'ตรวจสอบ' }}</span>
          </div>
          <div class="minirow">
            <span>แยกสิทธิ์ Admin vs Salesperson</span>
            <span class="tag blue">กรองตามใบจอง &amp; รหัสเซลล์</span>
          </div>
          <div class="minirow">
            <span>แยก Guard พนักงาน vs สมาชิก</span>
            <span class="tag green">{{ data.security_posture?.staff_member_auth_separated ? 'แยกอิสระ' : 'ใช้ร่วม' }}</span>
          </div>
        </div>

        <div class="card panel-pad">
          <h3 style="margin:0 0 10px">CRM Audit Logs ล่าสุด ({{ (data.audit_logs || []).length }})</h3>
          <div class="timeline" style="max-height:280px;overflow:auto">
            <div v-for="log in (data.audit_logs || []).slice(0, 15)" :key="log.id" class="timeline-item">
              <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
                <b>{{ log.entity_type }} #{{ log.entity_id }} · {{ log.action }}</b>
                <span class="tag gray">{{ log.actor_name || 'System' }}</span>
              </div>
              <div class="small muted">{{ log.created_at ? new Date(log.created_at).toLocaleString('th-TH') : '' }}</div>
              <div v-if="log.changes" class="small muted" style="word-break:break-word">{{ log.changes }}</div>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- Create / Edit Staff Modal -->
    <BaseModal
      :open="modalOpen"
      :title="editingId ? `แก้ไขผู้ใช้ & ผูกใบจอง: ${form.name}` : 'เพิ่มบัญชีพนักงาน / ผูกสายใบจอง'"
      subtitle="กำหนดบทบาท รหัสพนักงานขาย Bplus และรหัสใบจองเพื่อให้เห็นเฉพาะลูกหนี้และยอดขายของตัวเอง"
      max-width="680px"
      @close="modalOpen = false"
    >
      <form class="form" @submit.prevent="saveStaff">
        <div class="field">
          <label>ชื่อพนักงาน *</label>
          <input v-model="form.name" required placeholder="เช่น เก่ง (สายตระการ B16)" />
        </div>
        <div class="field">
          <label>อีเมลสำหรับล็อกอิน *</label>
          <input v-model="form.email" type="email" required placeholder="เช่น b16@popstar.local" />
        </div>
        <div class="field">
          <label>{{ editingId ? 'รหัสผ่านใหม่ (เว้นว่างถ้าไม่เปลี่ยน)' : 'รหัสผ่าน (อย่างน้อย 8 ตัวอักษร) *' }}</label>
          <input
            v-model="form.password"
            type="password"
            :required="!editingId"
            minlength="8"
            placeholder="อย่างน้อย 8 ตัวอักษร"
          />
        </div>
        <div class="field">
          <label>ระดับสิทธิ์ (Role) *</label>
          <select v-model="form.role">
            <option value="sales">พนักงานขาย / เซลล์ (เห็นเฉพาะใบจอง &amp; ยอดตัวเอง)</option>
            <option value="executive">ผู้บริหาร / เสี่ย / เจ๊ / ผจก. (ดูข้อมูลทุกคน · แก้ไขระบบไม่ได้)</option>
            <option value="manager">ผู้จัดการฝ่ายขาย (เห็นทั้งหมด)</option>
            <option value="admin">แอดมินระบบ (เห็นทั้งหมด + ตั้งค่าระบบ)</option>
          </select>
        </div>

        <template v-if="form.role === 'sales'">
          <div class="field span2">
            <label>รหัสพนักงานขายใน Bplus (Salesperson Code)</label>
            <select v-model="form.salesperson_code">
              <option value="">-- ไม่ระบุ / ใช้การผูกตามรหัสใบจองด้านล่าง --</option>
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
            <label>ผูกสายใบจองประจำตัว (เลือกได้มากกว่า 1 ใบจอง — ลูกหนี้ในใบจองจะขึ้นให้เซลล์คนนี้ทันที)</label>
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
            <span>เปิดใช้งานบัญชีนี้ (Active)</span>
          </label>
        </div>
      </form>

      <template #footer>
        <button type="button" class="btn" @click="modalOpen = false">ยกเลิก</button>
        <button type="button" class="btn primary" :disabled="saving" @click="saveStaff">
          {{ saving ? 'กำลังบันทึก...' : '💾 บันทึกสิทธิ์และการผูกใบจอง' }}
        </button>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { ref } from 'vue';
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

function salespersonNameByCode(code) {
  if (!code) return '';
  const found = (data.value?.available_salespersons || []).find((x) => String(x.salesperson_code) === String(code));
  return found?.salesperson_name || '';
}

async function triggerAutoSync() {
  syncingAuto.value = true;
  try {
    const res = await crmMutate('/settings/staff/auto-sync', 'POST', {});
    toast.success(
      'ผูกเซลล์และใบจองจาก Bplus อัตโนมัติแล้ว',
      `บัญชีเซลล์พร้อมใช้งานทั้งหมด ${res.total_sales_staff} บัญชี (สร้างใหม่ ${res.created} · อัปเดต ${res.updated})`
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
    password: '',
    role: 'sales',
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
    password: '',
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
      toast.success('อัปเดตสิทธิ์และการผูกใบจองเรียบร้อย');
    } else {
      await crmMutate('/settings/staff', 'POST', payload);
      toast.success('สร้างบัญชีพนักงานและผูกใบจองเรียบร้อย');
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
.scope-rule-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 14px;
}

.scope-rule-box {
  border: 1px solid var(--border);
  border-radius: 14px;
  padding: 14px 16px;
  background: #f8fafd;
  display: flex;
  flex-direction: column;
  gap: 6px;
  align-items: flex-start;
}

.scope-rule-box strong {
  font-size: 15px;
  color: var(--ink);
}

.scope-rule-box p {
  margin: 0;
}

.staff-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
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
  .scope-rule-grid {
    grid-template-columns: 1fr;
  }
}
</style>
