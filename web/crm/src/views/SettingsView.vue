<template>
  <div>
    <PageHeader title="ตั้งค่าผู้ใช้งาน & ระบบ" subtitle="จัดการบัญชีผู้ใช้ สิทธิ์ สายใบจอง และ LINE OA">
      <template #secondary>
        <button v-if="!isExecutive" type="button" class="btn" :disabled="syncingAuto" @click="triggerAutoSync">
          {{ syncingAuto ? 'กำลังซิงก์...' : '⚡ ซิงก์เซลล์จาก Bplus' }}
        </button>
      </template>
      <template #primary>
        <button v-if="!isExecutive" type="button" class="btn primary" @click="openCreateModal">＋ เพิ่มผู้ใช้งาน</button>
      </template>
    </PageHeader>

    <SkeletonBlock v-if="isLoading && !data" variant="cards" :rows="4" />
    <div v-else-if="isError" class="error">{{ error?.message || 'โหลดการตั้งค่าไม่สำเร็จ' }}</div>

    <template v-else-if="data">
      <!-- Clean 3-Tab Switcher -->
      <div class="segmented-tabs mb-md">
        <button
          type="button"
          class="btn"
          :class="activeTab === 'users' ? 'primary' : 'light'"
          @click="activeTab = 'users'"
        >
          👥 ผู้ใช้งาน ({{ (data.staff_users || []).length }})
        </button>
        <button
          type="button"
          class="btn"
          :class="activeTab === 'routes' ? 'primary' : 'light'"
          @click="activeTab = 'routes'"
        >
          📋 สายใบจอง ({{ (data.available_reservations || []).length }})
        </button>
        <button
          type="button"
          class="btn"
          :class="activeTab === 'line' ? 'primary' : 'light'"
          @click="activeTab = 'line'"
        >
          💬 LINE OA
          <span
            class="tag"
            :class="
              data.line_settings?.secret_configured && data.line_settings?.access_token_configured ? 'green' : 'orange'
            "
          >
            {{
              data.line_settings?.secret_configured && data.line_settings?.access_token_configured
                ? 'เชื่อมแล้ว'
                : 'รอตั้งค่า'
            }}
          </span>
        </button>
      </div>

      <template v-if="activeTab === 'users'">
        <!-- Main User Directory Card -->
        <div class="card panel-pad mb-md">
          <div class="staff-head">
            <div>
              <h3 style="margin: 0">รายชื่อผู้ใช้งาน ({{ filteredUsers.length }})</h3>
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
                placeholder="ค้นหาชื่อ, B16, อีเมล..."
              />
            </div>
          </div>

          <div class="table-wrap desktop-table-only" style="margin-top: 14px">
            <table class="table">
              <thead>
                <tr>
                  <th>ชื่อแสดงในระบบ</th>
                  <th>ชื่อที่ใช้ล็อกอินได้</th>
                  <th>ระดับสิทธิ์</th>
                  <th>สายใบจอง / รหัสเซลล์</th>
                  <th>สถานะ</th>
                  <th style="text-align: right">เปลี่ยนชื่อ / รีเซ็ตรหัสผ่าน</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="u in filteredUsers" :key="u.id">
                  <td>
                    <b>{{ u.name }}</b>
                  </td>
                  <td style="white-space: normal">
                    <div class="login-hint-Group">
                      <code class="login-code-pill">{{ primaryLoginHint(u) }}</code>
                      <span class="small muted">{{ u.email }}</span>
                    </div>
                  </td>
                  <td>
                    <span
                      class="tag"
                      :class="
                        u.role === 'admin'
                          ? 'purple'
                          : u.role === 'executive'
                            ? 'orange'
                            : u.role === 'manager'
                              ? 'blue'
                              : 'green'
                      "
                    >
                      {{ roleBadgeLabel(u.role) }}
                    </span>
                  </td>
                  <td style="white-space: normal">
                    <div v-if="(u.reservation_doc_types_list || []).length || u.salesperson_code" class="doc-chip-row">
                      <span v-for="doc in u.reservation_doc_types_list || []" :key="doc" class="tag orange">
                        ใบจอง {{ doc }}
                      </span>
                      <span v-if="u.salesperson_code" class="tag blue"> รหัสเซลล์ {{ u.salesperson_code }} </span>
                    </div>
                    <span v-else class="small muted">ดูได้ทุกสาย</span>
                  </td>
                  <td>
                    <span class="tag" :class="u.is_active ? 'green' : 'red'">
                      {{ u.is_active ? 'เปิดใช้งาน' : 'ระงับ' }}
                    </span>
                    <span v-if="u.must_change_password" class="tag orange" title="ยังใช้รหัสชั่วคราวหรือรหัสเดิมที่ไม่ปลอดภัย"
                      >ต้องเปลี่ยนรหัส</span
                    >
                  </td>
                  <td style="text-align: right">
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
          <div class="mob-cards" style="margin-top: 12px">
            <div v-for="u in filteredUsers" :key="`um-${u.id}`" class="card mob-card">
              <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px">
                <div>
                  <strong style="font-size: 15.5px">{{ u.name }}</strong>
                  <div class="small muted" style="margin-top: 2px">
                    ล็อกอินด้วย: <code class="login-code-pill">{{ primaryLoginHint(u) }}</code> หรือ {{ u.email }}
                  </div>
                </div>
                <span
                  class="tag"
                  :class="
                    u.role === 'admin'
                      ? 'purple'
                      : u.role === 'executive'
                        ? 'orange'
                        : u.role === 'manager'
                          ? 'blue'
                          : 'green'
                  "
                >
                  {{ roleBadgeLabel(u.role) }}
                </span>
              </div>

              <div style="display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px">
                <span v-for="doc in u.reservation_doc_types_list || []" :key="doc" class="tag orange">
                  ใบจอง {{ doc }}
                </span>
                <span v-if="u.salesperson_code" class="tag blue"> รหัสเซลล์ {{ u.salesperson_code }} </span>
                <span class="tag" :class="u.is_active ? 'green' : 'red'">
                  {{ u.is_active ? 'เปิดใช้งาน' : 'ระงับ' }}
                </span>
                <span v-if="u.must_change_password" class="tag orange" title="ยังใช้รหัสชั่วคราวหรือรหัสเดิมที่ไม่ปลอดภัย"
                  >ต้องเปลี่ยนรหัส</span
                >
              </div>

              <div v-if="!isExecutive" class="mob-card-actions">
                <button type="button" class="btn light" @click="openEditModal(u)">✏️ เปลี่ยนชื่อ / แก้ไข</button>
                <button type="button" class="btn" @click="openResetPasswordModal(u)">🔑 รีเซ็ตรหัสผ่าน</button>
              </div>
            </div>
          </div>
        </div>
      </template>

      <!-- Available Reservation Routes Directory -->
      <div v-if="activeTab === 'routes'" class="card panel-pad mb-md">
        <div class="staff-head">
          <div>
            <h3 style="margin: 0">สายใบจองใน Bplus ({{ (data.available_reservations || []).length }} สาย)</h3>
            <small class="muted">กดที่การ์ดเพื่อปรับการผูกสายใบจองกับเซลล์</small>
          </div>
        </div>

        <div class="res-route-grid" style="margin-top: 12px">
          <div v-for="r in data.available_reservations || []" :key="r.doc_type" class="res-route-card">
            <div class="res-route-top">
              <span class="tag orange">ใบจอง {{ r.doc_type }}</span>
              <span class="small muted">{{ fmt(r.customer_count) }} ลูกหนี้ · {{ fmt(r.doc_count) }} ใบจอง</span>
            </div>
            <div class="res-route-main">
              <strong>เซลล์หลัก: {{ r.main_sp_name || 'ไม่ระบุ' }}</strong>
              <span v-if="r.main_sp_code" class="small muted"> (รหัส {{ r.main_sp_code }})</span>
            </div>
            <div class="res-route-foot">
              <span class="small"
                >ยอดใบจองสะสม: <b>{{ fmt(r.total_before_vat) }} ฿</b></span
              >
              <button v-if="!isExecutive" type="button" class="btn light res-bind-btn" @click="openCreateFromRoute(r)">
                ✏️ ตั้งค่าสาย {{ r.doc_type }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- LINE Official Account (LINE OA) & Webhook Token Configuration -->
      <div
        v-if="activeTab === 'line'"
        id="line-settings"
        ref="lineSettingsEl"
        class="card panel-pad"
        style="margin-bottom: 16px"
      >
        <div class="staff-head">
          <div>
            <h3 style="margin: 0">💬 ตั้งค่าเชื่อมต่อ LINE Official Account (ผูก LINE สมาชิก &amp; โทเค็น)</h3>
            <small class="muted">
              นำค่า Channel ID, Channel Secret และ Channel Access Token จาก LINE Developers Console
              มาวางเพื่อเปิดระบบผูกบัญชีสมาชิกผ่าน LINE
            </small>
          </div>
          <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center">
            <span
              class="tag"
              :class="
                data.line_settings?.secret_configured && data.line_settings?.access_token_configured
                  ? 'green'
                  : 'orange'
              "
            >
              {{
                data.line_settings?.secret_configured && data.line_settings?.access_token_configured
                  ? '✓ ตั้งค่าโทเค็นครบพร้อมใช้งาน'
                  : '⏳ รอใส่โทเค็นจาก LINE Developers'
              }}
            </span>
            <span class="tag blue"> ผูก LINE แล้ว {{ fmt(data.line_settings?.bound_member_count || 0) }} บัญชี </span>
          </div>
        </div>

        <div class="line-webhook-box" style="margin-top: 12px">
          <div>
            <span class="small muted">Webhook URL สำหรับนำไปวางใน LINE Developers (Messaging API → Webhook URL):</span>
            <div style="margin-top: 2px">
              <code class="login-code-pill">{{ fullWebhookUrl }}</code>
            </div>
          </div>
          <button type="button" class="btn light row-btn" @click="copyWebhookUrl">📋 คัดลอก Webhook URL</button>
        </div>

        <form class="form" style="margin-top: 14px" @submit.prevent="saveLineConfig">
          <div class="field">
            <label>LINE OA Basic ID (ไอดีแอดเพื่อน เช่น @popstar)</label>
            <input v-model="lineForm.line_bot_basic_id" :disabled="isExecutive" placeholder="เช่น @popstarfoods" />
          </div>

          <div class="field">
            <label>Messaging API Channel ID</label>
            <input v-model="lineForm.line_channel_id" :disabled="isExecutive" placeholder="เช่น 2001234567" />
          </div>

          <div class="field">
            <label>
              Channel Secret
              <span v-if="data.line_settings?.secret_masked" class="tag green" style="margin-left: 6px">
                บันทึกแล้ว ({{ data.line_settings.secret_masked }})
              </span>
            </label>
            <input
              v-model="lineForm.line_channel_secret"
              type="password"
              :disabled="isExecutive"
              autocomplete="new-password"
              :placeholder="
                data.line_settings?.secret_configured
                  ? 'เว้นว่างไว้หากไม่ต้องการเปลี่ยน Channel Secret เดิม'
                  : 'วาง Channel Secret จาก LINE Developers...'
              "
            />
          </div>

          <div class="field">
            <label> LIFF ID (ถ้ามี สำหรับเปิดหน้าสมาชิกใน LINE) </label>
            <input v-model="lineForm.line_liff_id" :disabled="isExecutive" placeholder="เช่น 2001234567-AbCdEfGh" />
          </div>

          <div class="field span2">
            <label>
              Channel Access Token (Long-lived)
              <span v-if="data.line_settings?.access_token_masked" class="tag green" style="margin-left: 6px">
                บันทึกแล้ว ({{ data.line_settings.access_token_masked }})
              </span>
            </label>
            <input
              v-model="lineForm.line_channel_access_token"
              type="password"
              :disabled="isExecutive"
              autocomplete="new-password"
              :placeholder="
                data.line_settings?.access_token_configured
                  ? 'เว้นว่างไว้หากไม่ต้องการเปลี่ยน Access Token เดิม'
                  : 'วาง Channel Access Token (Long-lived) จากแท็บ Messaging API...'
              "
            />
          </div>

          <div class="field span2">
            <label>ข้อความตอบกลับต้อนรับอัตโนมัติ (เมื่อลูกค้าทักแชท LINE OA)</label>
            <textarea
              v-model="lineForm.line_welcome_message"
              rows="2"
              :disabled="isExecutive"
              placeholder="ยินดีต้อนรับสู่ Popstar สมาชิก..."
            />
          </div>

          <div v-if="!isExecutive" class="field span2" style="display: flex; justify-content: flex-end">
            <button type="submit" class="btn primary" :disabled="savingLine">
              {{ savingLine ? 'กำลังบันทึกการตั้งค่า LINE...' : '💾 บันทึกโทเค็นและการตั้งค่า LINE OA' }}
            </button>
          </div>
        </form>
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
            placeholder="รหัสชั่วคราว (ระบบสุ่มให้แล้ว)"
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
          <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer">
            <input v-model="form.is_active" type="checkbox" style="width: 20px; height: 20px; min-height: 20px" />
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
      <div v-if="resetTarget" style="display: flex; flex-direction: column; gap: 14px">
        <div class="notice" style="margin-bottom: 0">
          ระบบสุ่ม <b>รหัสชั่วคราว</b> ให้แล้ว กดยืนยันแล้วแจ้งรหัสนี้ให้ผู้ใช้ทางช่องทางส่วนตัว ผู้ใช้จะถูกบังคับให้ตั้งรหัสผ่านของตัวเองทันทีที่เข้าสู่ระบบ
        </div>

        <div class="field">
          <label>รหัสชั่วคราว (อย่างน้อย 8 ตัวอักษร)</label>
          <div style="display: flex; gap: 8px">
            <input v-model="newPassword" type="text" minlength="8" autocomplete="off" style="flex: 1" />
            <button
              type="button"
              class="btn light"
              :disabled="savingReset"
              @click="newPassword = generateTemporaryPassword()"
            >
              🎲 สุ่มใหม่
            </button>
          </div>
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
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { useQuery } from '@tanstack/vue-query';
import BaseModal from '../../../shared/components/BaseModal.vue';
import PageHeader from '../../../shared/components/PageHeader.vue';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import { toast } from '../../../shared/composables/useUiStore.js';
import {
  crmGet,
  crmMutate,
  fmt,
  invalidateCrmData,
  isExecutive,
  refreshSession,
} from '../composables/useCrmSession.js';

const route = useRoute();
const lineSettingsEl = ref(null);
const activeTab = ref(route.query.section === 'line' || route.hash === '#line-settings' ? 'line' : 'users');

watch(
  () => [route.query.section, route.hash],
  ([sec, hash]) => {
    activeTab.value = sec === 'line' || hash === '#line-settings' ? 'line' : 'users';
  }
);

const { data, isLoading, isError, error, refetch } = useQuery({
  queryKey: ['crm', 'settings-overview'],
  queryFn: ({ signal }) => crmGet('/settings/overview', { signal }),
});

const roleFilter = ref('all');
const searchQuery = ref('');

const savingLine = ref(false);
const lineForm = ref({
  line_bot_basic_id: '',
  line_channel_id: '',
  line_liff_id: '',
  line_channel_secret: '',
  line_channel_access_token: '',
  line_welcome_message: '',
});

watch(
  () => data.value?.line_settings,
  (ls) => {
    if (!ls) return;
    lineForm.value.line_bot_basic_id = ls.line_bot_basic_id || '';
    lineForm.value.line_channel_id = ls.line_channel_id || '';
    lineForm.value.line_liff_id = ls.line_liff_id || '';
    lineForm.value.line_welcome_message = ls.line_welcome_message || '';
  },
  { immediate: true }
);

const fullWebhookUrl = computed(() => {
  const origin = typeof window !== 'undefined' ? window.location.origin : 'https://popstar.localhost';
  return `${origin}${data.value?.line_settings?.webhook_path || '/api/line/webhook'}`;
});

async function copyWebhookUrl() {
  try {
    await navigator.clipboard.writeText(fullWebhookUrl.value);
    toast.success('คัดลอก Webhook URL แล้ว', fullWebhookUrl.value);
  } catch {
    toast.info('Webhook URL', fullWebhookUrl.value);
  }
}

async function saveLineConfig() {
  savingLine.value = true;
  try {
    await crmMutate('/settings/line', 'POST', lineForm.value);
    lineForm.value.line_channel_secret = '';
    lineForm.value.line_channel_access_token = '';
    toast.success('บันทึกโทเค็นและการตั้งค่า LINE OA เรียบร้อย');
    await refetch();
  } catch (err) {
    toast.error('บันทึกการตั้งค่า LINE ไม่สำเร็จ', err);
  } finally {
    savingLine.value = false;
  }
}

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

/**
 * Random temporary password the admin hands to the user once; the user must replace it at sign-in.
 * Avoids look-alike characters (0/O, 1/l/I) so it can be read out over the phone.
 */
function generateTemporaryPassword(length = 12) {
  const alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
  const bytes = new Uint32Array(length);
  window.crypto.getRandomValues(bytes);
  return Array.from(bytes, (n) => alphabet[n % alphabet.length]).join('');
}

const resetModalOpen = ref(false);
const resetTarget = ref(null);
const newPassword = ref('');
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
    const hay = [u.name, u.email, u.salesperson_code, ...(u.reservation_doc_types_list || [])]
      .filter(Boolean)
      .join(' ')
      .toLowerCase();
    return hay.includes(q);
  });
});

function roleBadgeLabel(role) {
  if (role === 'admin') return '👑 แอดมิน';
  if (role === 'executive') return '👔 ผู้บริหาร';
  if (role === 'manager') return '📊 ผู้จัดการ';
  return '🚀 เซลล์';
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
    password: generateTemporaryPassword(),
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
    password: generateTemporaryPassword(),
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
  newPassword.value = generateTemporaryPassword();
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
      `ตั้งรหัสชั่วคราวให้ "${resetTarget.value.name}" แล้ว`,
      `รหัสชั่วคราว: ${newPassword.value} — แจ้งผู้ใช้ทางช่องทางส่วนตัว ระบบจะบังคับให้ตั้งรหัสใหม่ตอนเข้าสู่ระบบ`
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
      salesperson_code: form.value.role === 'sales' ? form.value.salesperson_code || null : null,
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
  transition:
    transform 0.14s ease,
    border-color 0.14s ease,
    box-shadow 0.14s ease;
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
  max-width: 100%;
  word-break: break-all;
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

.line-webhook-box {
  background: #f8fafd;
  border: 1px solid var(--border);
  border-radius: 14px;
  padding: 12px 15px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}

.res-route-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(230px, 100%), 1fr));
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
  grid-template-columns: repeat(auto-fill, minmax(min(145px, 100%), 1fr));
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
    min-width: 0;
    width: 100%;
  }
  .res-route-top {
    flex-wrap: wrap;
  }
}
</style>
