<template>
  <div class="inbox-page">
    <PageHeader
      title="แชทลูกค้า LINE OA"
      subtitle="รับและตอบแชทลูกค้าจาก LINE Official Account พร้อมผูกบัญชีลูกหนี้ Bplus ERP ในหน้าเดียว"
    >
      <template #secondary>
        <button v-if="isManager" type="button" class="btn light" @click="openLineConfigModal">
          {{ lineStatus.configured ? '🟢 ตั้งค่า LINE OA (เชื่อมต่อแล้ว)' : '⚙️ ใส่ Token LINE OA' }}
        </button>
      </template>
      <template #primary>
        <button v-if="isManager" type="button" class="btn primary" @click="openSimulateModal">
          🧪 ทดสอบรับแชทจำลอง
        </button>
      </template>
    </PageHeader>

    <div v-if="!lineStatus.configured" class="notice" style="margin-bottom: 14px">
      <b>โหมดพร้อมใช้งาน / ทดสอบแชท:</b> ตอนนี้ยังไม่ได้ใส่ Channel Secret &amp; Channel Access Token —
      <button
        v-if="isManager"
        type="button"
        class="btn-link"
        style="text-decoration: underline; font-size: inherit"
        @click="openLineConfigModal"
      >
        คลิกที่นี่เพื่อกรอก Token LINE OA ทันที
      </button>
      หรือกด <b>🧪 ทดสอบรับแชทจำลอง</b> เพื่อทดลองตอบแชทและผูกลูกหนี้ Bplus ได้ทันที และเมื่อตั้ง Webhook URL เป็น
      <code>/api/line/webhook</code> แชทลูกค้าจริงจะไหลเข้าหน้านี้อัตโนมัติครับ
    </div>

    <div class="inbox-shell">
      <!-- LEFT PANE: Thread list -->
      <aside class="card inbox-list-pane">
        <div class="inbox-list-head">
          <input v-model="search" class="inbox-search" placeholder="ค้นหาชื่อลูกค้า, รหัสลูกหนี้, ข้อความ..." />
          <div class="inbox-filters">
            <button
              v-for="tab in filterTabs"
              :key="tab.value"
              type="button"
              class="inbox-chip"
              :class="{ active: filter === tab.value }"
              @click="filter = tab.value"
            >
              {{ tab.label }}
              <span v-if="tab.badge > 0" class="chip-badge">{{ tab.badge }}</span>
            </button>
          </div>
        </div>

        <div class="inbox-thread-scroll">
          <div v-if="threadsLoading && !threads.length" style="padding: 16px">
            <SkeletonBlock variant="list" :rows="4" />
          </div>

          <button
            v-for="t in threads"
            :key="t.id"
            type="button"
            class="thread-row"
            :class="{ active: selectedThreadId === t.id }"
            @click="selectThread(t.id)"
          >
            <div class="thread-avatar">
              <img v-if="t.picture_url" :src="t.picture_url" alt="" />
              <span v-else>{{ avatarText(t.display_name) }}</span>
            </div>
            <div class="thread-main">
              <div class="thread-top">
                <strong class="thread-name">{{ t.display_name || t.line_user_id }}</strong>
                <span class="thread-time">{{ shortTime(t.last_message_at) }}</span>
              </div>
              <div class="thread-preview">{{ t.last_message_preview || 'ยังไม่มีข้อความ' }}</div>
              <div class="thread-tags">
                <span v-if="t.erp_customer_code" class="tag blue">{{ t.erp_customer_code }}</span>
                <span v-else class="tag orange">ยังไม่ผูกลูกหนี้</span>
                <span v-if="t.status === 'resolved'" class="tag gray">ปิดเคสแล้ว</span>
                <span v-if="t.unread_count > 0" class="unread-dot">{{ t.unread_count }}</span>
              </div>
            </div>
          </button>

          <div v-if="!threadsLoading && !threads.length" class="inbox-empty-list">
            <div style="font-size: 32px; margin-bottom: 6px">💬</div>
            <div style="font-weight: 600; margin-bottom: 4px">ยังไม่มีห้องแชทลูกค้า</div>
            <div class="small muted" style="margin-bottom: 12px">
              กดปุ่มด้านล่างเพื่อจำลองข้อความเข้าจากลูกค้า LINE OA หรือเชื่อมต่อ Token ในหน้าตั้งค่า
            </div>
            <button v-if="isManager" type="button" class="btn primary" @click="openSimulateModal">
              🧪 สร้างแชททดสอบทันที
            </button>
          </div>
        </div>
      </aside>

      <!-- CENTER PANE: Conversation & Composer -->
      <section class="card inbox-chat-pane">
        <template v-if="activeThread">
          <div class="chat-head">
            <div class="chat-head-left">
              <div class="thread-avatar sm">
                <img v-if="activeThread.picture_url" :src="activeThread.picture_url" alt="" />
                <span v-else>{{ avatarText(activeThread.display_name) }}</span>
              </div>
              <div>
                <div class="chat-title">
                  <b>{{ activeThread.display_name || activeThread.line_user_id }}</b>
                  <span v-if="activeThread.erp_customer_code" class="tag green" style="margin-left: 6px">
                    {{ activeThread.erp_customer_code }} · {{ activeThread.erp_customer_name }}
                  </span>
                  <span v-else class="tag orange" style="margin-left: 6px">ยังไม่ผูกบัญชีลูกหนี้</span>
                </div>
                <div class="small muted">
                  LINE ID: {{ activeThread.line_user_id }}
                  <template v-if="activeThread.assigned_staff_name">
                    · ผู้ดูแล: {{ activeThread.assigned_staff_name }}
                  </template>
                </div>
              </div>
            </div>
            <div class="chat-head-actions">
              <button type="button" class="btn light" @click="toggleThreadStatus">
                {{ activeThread.status === 'resolved' ? '🔓 เปิดเคสอีกครั้ง' : '✓ ปิดเคสนี้' }}
              </button>
            </div>
          </div>

          <div ref="msgScrollRef" class="chat-messages">
            <div v-for="m in messages" :key="m.id" class="msg-row" :class="m.direction">
              <div v-if="m.direction === 'system'" class="msg-system">{{ m.body }} · {{ shortTime(m.created_at) }}</div>
              <div v-else class="msg-bubble" :class="m.direction">
                <div v-if="m.direction === 'outgoing' && m.sender_staff_name" class="msg-sender">
                  {{ m.sender_staff_name }}
                </div>
                <div class="msg-body">{{ m.body }}</div>
                <div class="msg-meta">
                  <span>{{ shortTime(m.created_at) }}</span>
                  <span v-if="m.direction === 'outgoing' && m.delivery_status === 'sent'" class="meta-ok"
                    >· ส่งผ่าน LINE แล้ว</span
                  >
                  <span v-else-if="m.direction === 'outgoing' && m.delivery_status === 'simulated'" class="meta-sim"
                    >· โหมดทดสอบ</span
                  >
                  <span
                    v-else-if="m.direction === 'outgoing' && m.delivery_status === 'failed'"
                    class="meta-err"
                    :title="m.delivery_error || ''"
                    >· ส่งไม่สำเร็จ</span
                  >
                </div>
                <div v-if="m.delivery_error" class="msg-error-text">{{ m.delivery_error }}</div>
              </div>
            </div>
            <div v-if="!messages.length" class="placeholder">ยังไม่มีข้อความในห้องแชทนี้</div>
          </div>

          <div class="chat-quick-replies">
            <button
              v-for="tpl in quickReplies"
              :key="tpl.label"
              type="button"
              class="quick-chip"
              @click="applyQuickReply(tpl.text)"
            >
              {{ tpl.label }}
            </button>
          </div>

          <form class="chat-composer" @submit.prevent="sendReply">
            <textarea
              v-model="replyText"
              rows="2"
              placeholder="พิมพ์ข้อความตอบกลับลูกค้า... (กด Enter เพื่อส่ง, Shift+Enter ขึ้นบรรทัดใหม่)"
              @keydown.enter.exact.prevent="sendReply"
            ></textarea>
            <button type="submit" class="btn primary chat-send-btn" :disabled="sending || !replyText.trim()">
              {{ sending ? 'กำลังส่ง...' : 'ส่งข้อความ ➤' }}
            </button>
          </form>
        </template>

        <div v-else class="chat-empty-state">
          <div style="font-size: 44px; margin-bottom: 8px">💬</div>
          <h3 style="margin: 0 0 6px">เลือกห้องแชททางซ้ายเพื่อเริ่มตอบลูกค้า</h3>
          <p class="muted small" style="max-width: 360px; text-align: center; margin: 0 0 14px">
            เมื่อลูกค้าทักเข้ามาทาง LINE OA ข้อความจะปรากฏที่นี่ทันที พร้อมให้ทีมงานผูกกับลูกหนี้ Bplus เพื่อดูยอดซื้อ บิลล่าสุด และแต้มสะสมได้พร้อมกัน
          </p>
          <button v-if="isManager" type="button" class="btn primary" @click="openSimulateModal">
            🧪 ทดสอบรับแชทจำลอง
          </button>
        </div>
      </section>

      <!-- RIGHT PANE: Bind Bplus Debtor + Customer 360 Quick View -->
      <aside class="card inbox-detail-pane">
        <template v-if="activeThread">
          <div class="detail-section">
            <div class="detail-head">
              <h4>🏢 ผูกกับลูกหนี้ Bplus ERP</h4>
              <button v-if="customerSnapshot && !editingLink" type="button" class="btn-link" @click="startEditLink">
                เปลี่ยนร้าน
              </button>
            </div>

            <div v-if="customerSnapshot && !editingLink" class="bound-card">
              <div class="bound-code">{{ customerSnapshot.erp_customer_code }}</div>
              <div class="bound-name">{{ customerSnapshot.customer_name }}</div>
              <div class="small muted">
                📞 {{ customerSnapshot.phone || '—' }} · 📍 {{ customerSnapshot.province || 'ไม่ระบุจังหวัด' }}
              </div>
              <div v-if="customerSnapshot.salesperson_name" class="small muted" style="margin-top: 2px">
                พนักงานขายดูแล: <b>{{ customerSnapshot.salesperson_name }}</b>
              </div>

              <div class="bound-kpis">
                <div class="bound-kpi">
                  <span>ยอดซื้อสุทธิปี 2026</span>
                  <strong>฿{{ fmtMoney(customerSnapshot.net_sales_before_vat) }}</strong>
                </div>
                <div class="bound-kpi">
                  <span>บิลขาย</span>
                  <strong>{{ fmt(customerSnapshot.sale_invoice_count) }} ใบ</strong>
                </div>
                <div class="bound-kpi">
                  <span>แต้มสมาชิก CRM</span>
                  <strong style="color: var(--green)">{{ fmt(customerSnapshot.points_balance) }} แต้ม</strong>
                </div>
              </div>

              <div style="display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px">
                <RouterLink
                  v-if="customerSnapshot.customer_master_id"
                  :to="`/customer-360/${customerSnapshot.customer_master_id}`"
                  class="btn light"
                  style="flex: 1; justify-content: center"
                >
                  🔍 ดูข้อมูล 360°
                </RouterLink>
                <button
                  type="button"
                  class="btn light"
                  style="flex: 1; justify-content: center"
                  @click="insertSummaryToChat"
                >
                  📤 ส่งสรุปยอดเข้าแชท
                </button>
              </div>
            </div>

            <div v-else class="bind-box">
              <div class="field" style="margin-bottom: 8px">
                <label>ค้นหาลูกหนี้ Bplus (รหัส AR / ชื่อร้าน / เบอร์โทร)</label>
                <input v-model="erpSearch" placeholder="พิมพ์ชื่อร้านหรือรหัสลูกหนี้..." />
              </div>
              <div class="field" style="margin-bottom: 8px">
                <select v-model="selectedErpId">
                  <option value="">-- เลือกลูกหนี้ Bplus --</option>
                  <option v-for="c in erpOptions" :key="c.id" :value="c.id">
                    {{ c.erp_customer_code }} · {{ c.customer_name }} ({{ c.phone || '—' }})
                  </option>
                </select>
              </div>
              <div style="display: flex; gap: 6px; flex-wrap: wrap">
                <button
                  type="button"
                  class="btn primary"
                  style="flex: 1; justify-content: center"
                  :disabled="!selectedErpId || linking"
                  @click="saveLinkCustomer"
                >
                  {{ linking ? 'กำลังผูก...' : '🔗 ผูกกับลูกหนี้รายนี้' }}
                </button>
                <button v-if="editingLink" type="button" class="btn light" @click="editingLink = false">ยกเลิก</button>
              </div>
              <button
                v-if="customerSnapshot && editingLink"
                type="button"
                class="btn-link danger"
                style="margin-top: 8px"
                @click="unlinkCustomer"
              >
                ยกเลิกการผูกลูกหนี้เดิม
              </button>
            </div>
          </div>

          <div v-if="customerSnapshot?.recent_invoices?.length" class="detail-section">
            <h4>🧾 บิลล่าสุด 5 ใบ</h4>
            <div class="inv-mini-list">
              <div
                v-for="inv in customerSnapshot.recent_invoices"
                :key="`${inv.doc_type}-${inv.invoice_no}`"
                class="inv-mini-row"
              >
                <div>
                  <div class="inv-no">{{ inv.doc_type }}-{{ inv.invoice_no }}</div>
                  <div class="small muted">{{ shortDate(inv.sold_at) }}</div>
                </div>
                <strong :style="{ color: inv.doc_category === 'return' ? 'var(--red)' : 'var(--ink)' }">
                  ฿{{ fmtMoney(inv.before_vat_amount) }}
                </strong>
              </div>
            </div>
          </div>

          <div class="detail-section">
            <h4>📝 บันทึกช่วยจำภายใน (ลูกค้าไม่เห็น)</h4>
            <textarea
              v-model="notesDraft"
              rows="3"
              placeholder="จดข้อมูลสำคัญของลูกค้ารายนี้ เช่น เงื่อนไขส่งของ, คนติดต่อ..."
              style="width: 100%; margin-bottom: 8px"
            ></textarea>
            <button
              type="button"
              class="btn light"
              style="width: 100%; justify-content: center"
              :disabled="savingNotes"
              @click="saveNotes"
            >
              {{ savingNotes ? 'กำลังบันทึก...' : '💾 บันทึกโน้ต' }}
            </button>
          </div>
        </template>

        <div v-else class="detail-empty">
          <div class="small muted">ข้อมูลลูกหนี้ Bplus และการผูกบัญชีจะแสดงที่นี่เมื่อเลือกห้องแชท</div>
        </div>
      </aside>
    </div>

    <!-- Simulate Incoming Customer Message Modal -->
    <BaseModal :open="simulateOpen" title="🧪 ทดสอบรับข้อความจากลูกค้า LINE OA" @close="simulateOpen = false">
      <div style="display: grid; gap: 10px">
        <div class="notice" style="margin-bottom: 0">
          จำลองเหตุการณ์เหมือนมีร้านค้าทักเข้ามาทาง LINE OA จริง เพื่อให้คุณทดสอบหน้าตอบแชทและผูกบัญชีลูกหนี้ Bplus ได้ทันที
        </div>
        <div class="field">
          <label>ชื่อบัญชี LINE ของลูกค้า *</label>
          <input v-model="simForm.display_name" placeholder="เช่น คุณหน่อย ร้านแม่ออไก่สด" />
        </div>
        <div class="field">
          <label>ข้อความที่ลูกค้าทักเข้ามา *</label>
          <textarea v-model="simForm.body" rows="3" placeholder="เช่น สวัสดีค่ะ ขอเช็คยอดซื้อกับแต้มสะสมเดือนนี้หน่อยค่ะ"></textarea>
        </div>
        <div class="field">
          <label>ทดลองผูกกับลูกหนี้ Bplus ล่วงหน้า (ไม่บังคับ — เว้นว่างไว้เพื่อลองผูกเองในหน้าแชทก็ได้)</label>
          <input v-model="simErpSearch" placeholder="พิมพ์ค้นหาร้านค้าใน Bplus..." style="margin-bottom: 6px" />
          <select v-model="simForm.erp_customer_id">
            <option value="">-- ยังไม่ผูก (ค่อยไปกดผูกในหน้าแชท) --</option>
            <option v-for="c in simErpOptions" :key="c.id" :value="c.id">
              {{ c.erp_customer_code }} · {{ c.customer_name }}
            </option>
          </select>
        </div>
      </div>
      <template #footer="{ requestClose }">
        <button type="button" class="btn" @click="requestClose">ยกเลิก</button>
        <button
          type="button"
          class="btn primary"
          :disabled="simulating || !simForm.display_name.trim() || !simForm.body.trim()"
          @click="submitSimulate"
        >
          {{ simulating ? 'กำลังส่ง...' : '📩 ส่งข้อความเข้า Inbox' }}
        </button>
      </template>
    </BaseModal>

    <!-- Inline LINE OA Token Configuration Modal (no redirect away from inbox) -->
    <BaseModal
      :open="lineConfigOpen"
      title="⚙️ ตั้งค่า Token LINE Official Account (LINE OA)"
      subtitle="วาง Channel Secret และ Channel Access Token (Long-lived) จาก LINE Developers Console เพื่อเปิดรับ-ส่งแชทจริงทันที"
      max-width="640px"
      @close="lineConfigOpen = false"
    >
      <div class="stack-md">
        <div class="notice row-between" style="margin-bottom: 0">
          <div style="min-width: 0; flex: 1 1 200px">
            <div class="small muted">Webhook URL สำหรับนำไปวางใน LINE Developers (Messaging API → Webhook URL):</div>
            <code class="webhook-url-code">{{ fullWebhookUrl }}</code>
          </div>
          <button type="button" class="btn light" @click="copyWebhookUrl">📋 คัดลอก Webhook</button>
        </div>

        <div class="form-grid-2">
          <div class="field">
            <label>LINE OA Basic ID (เช่น @popstar)</label>
            <input v-model="lineConfigForm.line_bot_basic_id" placeholder="@popstarfoods" />
          </div>
          <div class="field">
            <label>Messaging API Channel ID</label>
            <input v-model="lineConfigForm.line_channel_id" placeholder="2001234567" />
          </div>
        </div>

        <div class="field">
          <label>
            Channel Secret *
            <span v-if="lineStatus.secret_masked" class="tag green" style="margin-left: 6px">
              บันทึกไว้แล้ว ({{ lineStatus.secret_masked }})
            </span>
          </label>
          <input
            v-model="lineConfigForm.line_channel_secret"
            :type="showLineSecrets ? 'text' : 'password'"
            autocomplete="new-password"
            :placeholder="
              lineStatus.secret_configured ? 'เว้นว่างไว้หากไม่เปลี่ยนค่าเดิม' : 'วาง Channel Secret จากแท็บ Basic settings...'
            "
          />
        </div>

        <div class="field">
          <label>
            Channel Access Token (Long-lived) *
            <span v-if="lineStatus.access_token_masked" class="tag green" style="margin-left: 6px">
              บันทึกไว้แล้ว ({{ lineStatus.access_token_masked }})
            </span>
          </label>
          <textarea
            v-model="lineConfigForm.line_channel_access_token"
            rows="3"
            :placeholder="
              lineStatus.access_token_configured
                ? 'เว้นว่างไว้หากไม่เปลี่ยน Token เดิม'
                : 'วาง Channel Access Token (Long-lived) จากแท็บ Messaging API...'
            "
          ></textarea>
        </div>

        <div class="form-grid-2">
          <div class="field">
            <label>LIFF ID (ถ้ามี)</label>
            <input v-model="lineConfigForm.line_liff_id" placeholder="2001234567-AbCdEfGh" />
          </div>
          <div class="field secret-toggle-field">
            <label class="checkbox-inline-label">
              <input v-model="showLineSecrets" type="checkbox" />
              <span>แสดงข้อความ Channel Secret</span>
            </label>
          </div>
        </div>

        <div class="field">
          <label>ข้อความต้อนรับอัตโนมัติ (เมื่อลูกค้าทักครั้งแรก)</label>
          <textarea v-model="lineConfigForm.line_welcome_message" rows="2"></textarea>
        </div>
      </div>

      <template #footer="{ requestClose }">
        <button type="button" class="btn" @click="requestClose">ยกเลิก</button>
        <button type="button" class="btn primary" :disabled="savingLineConfig" @click="submitLineConfig">
          {{ savingLineConfig ? 'กำลังบันทึก Token...' : '💾 บันทึก Token LINE OA' }}
        </button>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { useQuery } from '@tanstack/vue-query';
import BaseModal from '../../../shared/components/BaseModal.vue';
import PageHeader from '../../../shared/components/PageHeader.vue';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import { toast } from '../../../shared/composables/useUiStore.js';
import { crmGet, crmMutate, fmt, isManager } from '../composables/useCrmSession.js';

const search = ref('');
const filter = ref('all');
const selectedThreadId = ref(null);
const replyText = ref('');
const sending = ref(false);
const msgScrollRef = ref(null);

const editingLink = ref(false);
const erpSearch = ref('');
const selectedErpId = ref('');
const linking = ref(false);
const notesDraft = ref('');
const savingNotes = ref(false);

const simulateOpen = ref(false);
const simulating = ref(false);
const simErpSearch = ref('');
const simForm = ref({
  display_name: 'คุณหน่อย ร้านแม่ออไก่สด',
  body: 'สวัสดีค่ะ ขอสอบถามราคาสินค้าและเช็คแต้มสะสมของร้านหน่อยค่ะ',
  erp_customer_id: '',
});

const lineConfigOpen = ref(false);
const savingLineConfig = ref(false);
const showLineSecrets = ref(false);
const lineConfigForm = ref({
  line_bot_basic_id: '',
  line_channel_id: '',
  line_liff_id: '',
  line_channel_secret: '',
  line_channel_access_token: '',
  line_welcome_message: '',
});

const fullWebhookUrl = computed(() => {
  const origin = typeof window !== 'undefined' ? window.location.origin : 'https://popstar.localhost';
  return `${origin}${lineStatus.value?.webhook_path || '/api/line/webhook'}`;
});

function openLineConfigModal() {
  const ls = lineStatus.value || {};
  lineConfigForm.value = {
    line_bot_basic_id: ls.bot_basic_id || '',
    line_channel_id: ls.line_channel_id || '',
    line_liff_id: ls.line_liff_id || '',
    line_channel_secret: '',
    line_channel_access_token: '',
    line_welcome_message: ls.line_welcome_message || '',
  };
  lineConfigOpen.value = true;
}

async function copyWebhookUrl() {
  try {
    await navigator.clipboard.writeText(fullWebhookUrl.value);
    toast.success('คัดลอก Webhook URL แล้ว', fullWebhookUrl.value);
  } catch {
    toast.info('Webhook URL', fullWebhookUrl.value);
  }
}

async function submitLineConfig() {
  savingLineConfig.value = true;
  try {
    await crmMutate('/settings/line', 'POST', lineConfigForm.value);
    lineConfigForm.value.line_channel_secret = '';
    lineConfigForm.value.line_channel_access_token = '';
    lineConfigOpen.value = false;
    toast.success('บันทึก Token LINE OA เรียบร้อยแล้ว');
    await refetchThreads();
  } catch (err) {
    toast.error('บันทึก Token LINE OA ไม่สำเร็จ', err);
  } finally {
    savingLineConfig.value = false;
  }
}

const {
  data: threadsData,
  isLoading: threadsLoading,
  refetch: refetchThreads,
} = useQuery({
  queryKey: computed(() => ['crm', 'line-chat-threads', filter.value, search.value]),
  queryFn: ({ signal }) => {
    const p = new URLSearchParams();
    if (filter.value !== 'all') p.set('filter', filter.value);
    if (search.value.trim()) p.set('q', search.value.trim());
    return crmGet(`/line-chat/threads?${p.toString()}`, { signal });
  },
  refetchInterval: 5000,
});

const threads = computed(() => threadsData.value?.threads || []);
const lineStatus = computed(() => threadsData.value?.line_status || { configured: false });
const summary = computed(
  () => threadsData.value?.summary || { total_threads: 0, open_threads: 0, total_unread: 0, unlinked_threads: 0 }
);

const filterTabs = computed(() => [
  { value: 'all', label: 'ทั้งหมด', badge: 0 },
  { value: 'open', label: 'ยังไม่ปิดเคส', badge: summary.value.open_threads },
  { value: 'unlinked', label: 'ยังไม่ผูกลูกหนี้', badge: summary.value.unlinked_threads },
  { value: 'unread', label: 'ยังไม่อ่าน', badge: summary.value.total_unread },
]);

watch(
  threads,
  (list) => {
    if (!selectedThreadId.value && list.length > 0) {
      selectedThreadId.value = list[0].id;
    }
  },
  { immediate: true }
);

const { data: detailData, refetch: refetchMessages } = useQuery({
  queryKey: computed(() => ['crm', 'line-chat-messages', selectedThreadId.value]),
  queryFn: ({ signal }) => crmGet(`/line-chat/threads/${selectedThreadId.value}/messages`, { signal }),
  enabled: computed(() => Boolean(selectedThreadId.value)),
  refetchInterval: 5000,
});

const activeThread = computed(() => detailData.value?.thread || null);
const messages = computed(() => detailData.value?.messages || []);
const customerSnapshot = computed(() => detailData.value?.customer_snapshot || null);

watch(activeThread, (t) => {
  if (t) {
    notesDraft.value = t.notes || '';
  }
});

watch(
  () => messages.value.length,
  async () => {
    await nextTick();
    if (msgScrollRef.value) {
      msgScrollRef.value.scrollTop = msgScrollRef.value.scrollHeight;
    }
  }
);

const { data: erpPickerData } = useQuery({
  queryKey: computed(() => ['crm', 'line-chat-erp-picker', erpSearch.value]),
  queryFn: ({ signal }) => {
    const p = new URLSearchParams({ picker: '1', per_page: '25' });
    if (erpSearch.value.trim()) p.set('q', erpSearch.value.trim());
    return crmGet(`/customers?${p.toString()}`, { signal });
  },
  enabled: computed(() => Boolean(activeThread.value) && (!customerSnapshot.value || editingLink.value)),
});
const erpOptions = computed(() => erpPickerData.value?.data || []);

const { data: simErpData } = useQuery({
  queryKey: computed(() => ['crm', 'line-chat-sim-erp', simErpSearch.value]),
  queryFn: ({ signal }) => {
    const p = new URLSearchParams({ picker: '1', per_page: '20' });
    if (simErpSearch.value.trim()) p.set('q', simErpSearch.value.trim());
    return crmGet(`/customers?${p.toString()}`, { signal });
  },
  enabled: computed(() => simulateOpen.value),
});
const simErpOptions = computed(() => simErpData.value?.data || []);

const memberPortalUrl = computed(() => {
  if (typeof window === 'undefined') return 'http://member.popstar.localhost/';
  const { protocol, hostname, port } = window.location;
  if (hostname === 'popstar.localhost') return `${protocol}//member.popstar.localhost${port ? `:${port}` : ''}/`;
  return `${protocol}//member.${hostname}${port ? `:${port}` : ''}/`;
});

const quickReplies = computed(() => [
  { label: '👋 ทักทาย', text: 'สวัสดีครับ POPSTAR ยินดีให้บริการครับ สอบถามสินค้าหรือเช็คยอดบิลแจ้งได้เลยครับ' },
  { label: '📦 รับออเดอร์', text: 'ได้รับรายการเรียบร้อยครับ เดี๋ยวแอดมินรีบเช็คสต็อกและแจ้งยืนยันกลับนะครับ' },
  { label: '🪪 ส่งลิงก์สมาชิก', text: `ลูกค้าสามารถเข้าดูบิลขายและแต้มสะสมได้ที่หน้าสมาชิก POPSTAR ครับ: ${memberPortalUrl.value}` },
]);

function selectThread(id) {
  selectedThreadId.value = id;
  editingLink.value = false;
  selectedErpId.value = '';
}

function startEditLink() {
  editingLink.value = true;
  erpSearch.value = customerSnapshot.value?.erp_customer_code || '';
  selectedErpId.value = String(customerSnapshot.value?.erp_customer_id || '');
}

function applyQuickReply(text) {
  replyText.value = text;
}

function insertSummaryToChat() {
  if (!customerSnapshot.value) return;
  const s = customerSnapshot.value;
  replyText.value = `สรุปข้อมูลร้าน ${s.customer_name} (${s.erp_customer_code})\n- ยอดซื้อสุทธิปี 2026: ฿${fmtMoney(s.net_sales_before_vat)} (${fmt(s.sale_invoice_count)} บิล)\n- แต้มสะสมปัจจุบัน: ${fmt(s.points_balance)} แต้ม\nดูรายละเอียดเพิ่มเติมได้ที่ ${memberPortalUrl.value}`;
}

async function sendReply() {
  if (!activeThread.value || !replyText.value.trim() || sending.value) return;
  sending.value = true;
  try {
    const res = await crmMutate(`/line-chat/threads/${activeThread.value.id}/reply`, 'POST', {
      body: replyText.value.trim(),
    });
    replyText.value = '';
    if (res?.delivery_status === 'failed') {
      toast.error('บันทึกข้อความแล้ว แต่ LINE API ส่งไม่สำเร็จ', res.delivery_error || '');
    }
    await Promise.all([refetchMessages(), refetchThreads()]);
  } catch (err) {
    toast.error('ส่งข้อความไม่สำเร็จ', err);
  } finally {
    sending.value = false;
  }
}

async function saveLinkCustomer() {
  if (!activeThread.value || !selectedErpId.value) return;
  linking.value = true;
  try {
    await crmMutate(`/line-chat/threads/${activeThread.value.id}/link-customer`, 'POST', {
      erp_customer_id: Number(selectedErpId.value),
    });
    editingLink.value = false;
    toast.success('ผูกแชทลูกค้ากับลูกหนี้ Bplus เรียบร้อยแล้ว');
    await Promise.all([refetchMessages(), refetchThreads()]);
  } catch (err) {
    toast.error('ผูกลูกหนี้ไม่สำเร็จ', err);
  } finally {
    linking.value = false;
  }
}

async function unlinkCustomer() {
  if (!activeThread.value) return;
  linking.value = true;
  try {
    await crmMutate(`/line-chat/threads/${activeThread.value.id}/link-customer`, 'POST', {
      erp_customer_id: null,
    });
    editingLink.value = false;
    selectedErpId.value = '';
    toast.success('ยกเลิกการผูกลูกหนี้แล้ว');
    await Promise.all([refetchMessages(), refetchThreads()]);
  } catch (err) {
    toast.error('ยกเลิกไม่สำเร็จ', err);
  } finally {
    linking.value = false;
  }
}

async function toggleThreadStatus() {
  if (!activeThread.value) return;
  const nextStatus = activeThread.value.status === 'resolved' ? 'open' : 'resolved';
  try {
    await crmMutate(`/line-chat/threads/${activeThread.value.id}`, 'PATCH', { status: nextStatus });
    toast.success(nextStatus === 'resolved' ? 'ปิดเคสแชทนี้แล้ว' : 'เปิดเคสแชทอีกครั้งแล้ว');
    await Promise.all([refetchMessages(), refetchThreads()]);
  } catch (err) {
    toast.error('เปลี่ยนสถานะไม่สำเร็จ', err);
  }
}

async function saveNotes() {
  if (!activeThread.value) return;
  savingNotes.value = true;
  try {
    await crmMutate(`/line-chat/threads/${activeThread.value.id}`, 'PATCH', { notes: notesDraft.value });
    toast.success('บันทึกโน้ตแล้ว');
    refetchMessages();
  } catch (err) {
    toast.error('บันทึกโน้ตไม่สำเร็จ', err);
  } finally {
    savingNotes.value = false;
  }
}

function openSimulateModal() {
  simulateOpen.value = true;
}

async function submitSimulate() {
  simulating.value = true;
  try {
    const res = await crmMutate('/line-chat/simulate-incoming', 'POST', {
      display_name: simForm.value.display_name.trim(),
      body: simForm.value.body.trim(),
      erp_customer_id: simForm.value.erp_customer_id ? Number(simForm.value.erp_customer_id) : null,
    });
    simulateOpen.value = false;
    if (res?.thread_id) {
      selectedThreadId.value = res.thread_id;
    }
    toast.success('รับข้อความทดสอบเข้า Inbox เรียบร้อยแล้ว');
    await Promise.all([refetchThreads(), refetchMessages()]);
  } catch (err) {
    toast.error('สร้างแชทจำลองไม่สำเร็จ', err);
  } finally {
    simulating.value = false;
  }
}

function avatarText(name) {
  const s = String(name || 'L').trim();
  return s ? s.slice(0, 2).toUpperCase() : 'L';
}

function fmtMoney(v) {
  return Number(v || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function shortTime(ts) {
  if (!ts) return '';
  const d = new Date(ts);
  if (Number.isNaN(d.getTime())) return String(ts).slice(0, 16);
  return d.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit' });
}

function shortDate(ts) {
  if (!ts) return '—';
  const d = new Date(ts);
  if (Number.isNaN(d.getTime())) return String(ts).slice(0, 10);
  return d.toLocaleDateString('th-TH', { day: '2-digit', month: 'short', year: '2-digit' });
}
</script>

<style scoped>
.inbox-shell {
  display: grid;
  grid-template-columns: 310px minmax(0, 1fr) 310px;
  gap: 14px;
  min-height: 640px;
  height: calc(100vh - 220px);
  max-height: 820px;
}

.inbox-list-pane,
.inbox-chat-pane,
.inbox-detail-pane {
  display: flex;
  flex-direction: column;
  overflow: hidden;
  padding: 0;
}

.inbox-list-head {
  padding: 12px;
  border-bottom: 1px solid var(--border);
  display: grid;
  gap: 8px;
}

.inbox-search {
  width: 100%;
}

.inbox-filters {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.inbox-chip {
  border: 1px solid var(--border);
  background: var(--bg);
  color: var(--muted);
  border-radius: 999px;
  padding: 4px 10px;
  font-size: 12px;
  font-weight: 500;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  transition: all 0.15s;
}

.inbox-chip.active {
  background: var(--blue);
  border-color: var(--blue);
  color: #fff;
}

.chip-badge {
  background: rgba(15, 23, 42, 0.14);
  border-radius: 999px;
  padding: 0 6px;
  font-size: 11px;
}

.inbox-chip.active .chip-badge {
  background: rgba(255, 255, 255, 0.25);
}

.inbox-thread-scroll {
  flex: 1;
  overflow-y: auto;
}

.thread-row {
  width: 100%;
  border: 0;
  border-bottom: 1px solid var(--border);
  background: transparent;
  padding: 12px;
  display: flex;
  gap: 10px;
  text-align: left;
  cursor: pointer;
  transition: background 0.15s;
}

.thread-row:hover {
  background: #f8fafc;
}

.thread-row.active {
  background: #eff6ff;
  border-left: 3px solid var(--blue);
}

.thread-avatar {
  width: 42px;
  height: 42px;
  border-radius: 50%;
  background: linear-gradient(135deg, #06c755, #059669);
  color: #fff;
  font-weight: 700;
  font-size: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  overflow: hidden;
}

.thread-avatar.sm {
  width: 38px;
  height: 38px;
}

.thread-avatar img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.thread-main {
  flex: 1;
  min-width: 0;
}

.thread-top {
  display: flex;
  justify-content: space-between;
  gap: 6px;
  align-items: center;
}

.thread-name {
  font-size: 14px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.thread-time {
  font-size: 11px;
  color: var(--muted);
  flex-shrink: 0;
}

.thread-preview {
  font-size: 12.5px;
  color: var(--muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  margin: 3px 0 5px;
}

.thread-tags {
  display: flex;
  align-items: center;
  gap: 5px;
  flex-wrap: wrap;
}

.unread-dot {
  margin-left: auto;
  background: #06c755;
  color: #fff;
  border-radius: 999px;
  min-width: 20px;
  height: 20px;
  padding: 0 6px;
  font-size: 11px;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.inbox-empty-list,
.chat-empty-state,
.detail-empty {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 24px;
  text-align: center;
}

.chat-head {
  padding: 12px 16px;
  border-bottom: 1px solid var(--border);
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
  background: #fff;
}

.chat-head-left {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}

.chat-title {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 4px;
}

.chat-messages {
  flex: 1;
  overflow-y: auto;
  padding: 16px;
  background: #f1f5f9;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.msg-row {
  display: flex;
}

.msg-row.incoming {
  justify-content: flex-start;
}

.msg-row.outgoing {
  justify-content: flex-end;
}

.msg-row.system {
  justify-content: center;
}

.msg-system {
  background: rgba(15, 23, 42, 0.08);
  color: var(--muted);
  font-size: 12px;
  padding: 4px 12px;
  border-radius: 999px;
}

.msg-bubble {
  max-width: 76%;
  padding: 10px 14px;
  border-radius: 16px;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
}

.msg-bubble.incoming {
  background: #fff;
  border-bottom-left-radius: 4px;
  color: var(--ink);
}

.msg-bubble.outgoing {
  background: #0284c7;
  color: #fff;
  border-bottom-right-radius: 4px;
}

.msg-sender {
  font-size: 11px;
  opacity: 0.85;
  margin-bottom: 2px;
  font-weight: 600;
}

.msg-body {
  white-space: pre-wrap;
  word-break: break-word;
  font-size: 14px;
  line-height: 1.5;
}

.msg-meta {
  font-size: 11px;
  margin-top: 4px;
  opacity: 0.78;
  display: flex;
  gap: 4px;
  justify-content: flex-end;
}

.meta-err {
  color: #fecaca;
  font-weight: 600;
}

.msg-error-text {
  font-size: 11px;
  margin-top: 4px;
  background: rgba(239, 68, 68, 0.2);
  padding: 4px 8px;
  border-radius: 8px;
}

.chat-quick-replies {
  padding: 8px 12px;
  border-top: 1px solid var(--border);
  background: #f8fafc;
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.quick-chip {
  border: 1px solid var(--border);
  background: #fff;
  color: var(--ink);
  border-radius: 999px;
  padding: 4px 11px;
  font-size: 12px;
  cursor: pointer;
}

.quick-chip:hover {
  border-color: var(--blue);
  color: var(--blue);
}

.chat-composer {
  padding: 10px 12px;
  border-top: 1px solid var(--border);
  display: flex;
  gap: 8px;
  align-items: flex-end;
  background: #fff;
}

.chat-composer textarea {
  flex: 1;
  resize: none;
  min-height: 44px;
}

.chat-send-btn {
  height: 44px;
  flex-shrink: 0;
}

.inbox-detail-pane {
  overflow-y: auto;
  padding: 14px;
  gap: 14px;
}

.detail-section {
  border-bottom: 1px solid var(--border);
  padding-bottom: 14px;
}

.detail-section:last-child {
  border-bottom: 0;
  padding-bottom: 0;
}

.detail-section h4 {
  margin: 0 0 8px;
  font-size: 14px;
}

.detail-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
}

.detail-head h4 {
  margin: 0;
}

.btn-link {
  border: 0;
  background: transparent;
  color: var(--blue);
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
  padding: 0;
}

.btn-link.danger {
  color: var(--red);
}

.bound-card {
  background: #f8fafc;
  border: 1px solid var(--border);
  border-radius: 14px;
  padding: 12px;
}

.bound-code {
  font-size: 12px;
  font-weight: 700;
  color: var(--blue);
}

.bound-name {
  font-size: 15px;
  font-weight: 700;
  margin: 2px 0 4px;
}

.bound-kpis {
  display: grid;
  grid-template-columns: 1fr;
  gap: 6px;
  margin-top: 10px;
}

.bound-kpi {
  background: #fff;
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 7px 10px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 12.5px;
}

.inv-mini-list {
  display: grid;
  gap: 6px;
}

.inv-mini-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: #f8fafc;
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 7px 10px;
  font-size: 12.5px;
}

.inv-no {
  font-weight: 600;
}

.webhook-url-code {
  display: inline-block;
  font-weight: 700;
  font-size: 13px;
  word-break: break-all;
}

.secret-toggle-field {
  display: flex;
  align-items: flex-end;
}

.field .checkbox-inline-label {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  margin-bottom: 8px;
}

@media (max-width: 1120px) {
  .inbox-shell {
    grid-template-columns: 270px minmax(0, 1fr);
    height: auto;
    max-height: none;
  }
  .inbox-detail-pane {
    grid-column: 1 / -1;
  }
}

@media (max-width: 760px) {
  .inbox-shell {
    grid-template-columns: 1fr;
  }
  .inbox-list-pane {
    max-height: 260px;
  }
  .inbox-chat-pane {
    min-height: 380px;
  }
  .chat-head {
    flex-wrap: wrap;
    padding: 10px 12px;
  }
  .msg-bubble {
    max-width: 88%;
  }
  .chat-quick-replies {
    flex-wrap: nowrap;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
  }
  .quick-chip {
    flex-shrink: 0;
    min-height: 36px;
    padding: 6px 12px;
  }
  .chat-composer {
    flex-wrap: wrap;
  }
  .chat-composer textarea {
    flex: 1 1 200px;
    min-width: 0;
  }
  .chat-send-btn {
    flex: 0 0 auto;
  }
}
</style>
