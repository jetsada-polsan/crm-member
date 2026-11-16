<template>
  <div>
    <div class="pagehead">
      <div>
        <div class="eyebrow">LOYALTY RULES &amp; LEDGER</div>
        <h2>กติกาการให้แต้ม</h2>
        <p>ตั้งเงื่อนไขให้แต้มจากบิลขาย หักแต้มเมื่อมีใบลดหนี้ และดูประวัติแต้ม</p>
      </div>
      <div class="head-actions">
        <button type="button" class="btn" @click="refetchAll">⟳ รีเฟรช</button>
        <button type="button" class="btn primary" @click="ruleModalOpen = true">+ เพิ่มกติกาคะแนน</button>
      </div>
    </div>

    <!-- Loyalty Rules -->
    <div class="card" style="margin-bottom:16px">
      <div class="section-head" style="padding:16px 18px 0">
        <div>
          <h3>กติกาการคำนวณคะแนน ({{ (rules || []).length }})</h3>
          <small>อิงยอดซื้อสุทธิก่อน VAT และยกเว้นใบจอง 207 / ยกเลิกบิล</small>
        </div>
      </div>

      <SkeletonBlock v-if="rulesLoading && !(rules || []).length" variant="table" :rows="4" />
      <div v-else class="table-wrap desktop-table-only">
        <table class="table">
          <thead>
            <tr>
              <th>รหัสกติกา</th>
              <th>ชื่อกติกา</th>
              <th>อัตราแลกแต้ม</th>
              <th>ยอดขั้นต่ำ</th>
              <th>เงื่อนไข ERP</th>
              <th>สถานะ</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in rules || []" :key="r.id">
              <td><span class="tag blue">{{ r.rule_code }}</span></td>
              <td>
                <b>{{ r.name }}</b>
                <div v-if="r.description" class="small muted">{{ r.description }}</div>
              </td>
              <td>{{ fmt(r.baht_per_point) }} ฿ = {{ fmt(r.points_per_unit) }} แต้ม</td>
              <td>{{ fmt(r.min_invoice_amount || 0) }} ฿</td>
              <td>
                <div class="small">
                  {{ r.exclude_reservation ? 'ยกเว้นใบจอง 207' : 'รวมใบจอง' }} ·
                  {{ r.reverse_on_return ? 'Reverse เมื่อลดหนี้ 308' : 'ไม่ Reverse อัตโนมัติ' }}
                </div>
              </td>
              <td>
                <span class="tag" :class="r.is_active ? 'green' : 'gray'">
                  {{ r.is_active ? 'ใช้งานอยู่' : 'ปิดไว้' }}
                </span>
              </td>
              <td>
                <button type="button" class="btn light" @click="toggleRule(r)">
                  {{ r.is_active ? 'ปิดใช้งาน' : 'เปิดใช้งาน' }}
                </button>
              </td>
            </tr>
            <tr v-if="!(rules || []).length">
              <td colspan="7" class="placeholder">ยังไม่มีกติกาคะแนน — กด "+ เพิ่มกติกาคะแนน" เพื่อเริ่มต้น</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="mob-cards" style="padding:12px">
        <div v-for="r in rules || []" :key="`rm-${r.id}`" class="card mob-card">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
            <strong>{{ r.name }}</strong>
            <span class="tag" :class="r.is_active ? 'green' : 'gray'">{{ r.is_active ? 'ใช้งาน' : 'ปิด' }}</span>
          </div>
          <div class="small muted" style="margin:6px 0">
            {{ r.rule_code }} · {{ fmt(r.baht_per_point) }} ฿ = {{ fmt(r.points_per_unit) }} แต้ม
          </div>
          <button type="button" class="btn light" @click="toggleRule(r)">
            {{ r.is_active ? 'ปิดใช้งาน' : 'เปิดใช้งาน' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Loyalty Points Ledger -->
    <div class="card">
      <div class="section-head" style="padding:16px 18px 0">
        <div>
          <h3>สมุดบัญชีคะแนน (Points Ledger · {{ (ledger || []).length }} รายการล่าสุด)</h3>
          <small>รายการทุกบรรทัดตรวจสอบย้อนกลับได้ และสามารถ Reverse รายการที่ผิดพลาดได้ 1 ครั้งต่อรายการ</small>
        </div>
      </div>

      <SkeletonBlock v-if="ledgerLoading && !(ledger || []).length" variant="table" :rows="5" />
      <div v-else class="table-wrap desktop-table-only">
        <table class="table">
          <thead>
            <tr>
              <th>#</th>
              <th>วันที่</th>
              <th>สมาชิก</th>
              <th>ประเภท</th>
              <th>คะแนน (+/-)</th>
              <th>เหตุผล / อ้างอิง</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="l in ledger || []" :key="l.id">
              <td><span class="tag gray">#{{ l.id }}</span></td>
              <td>{{ l.created_at ? new Date(l.created_at).toLocaleString('th-TH') : '—' }}</td>
              <td>
                <b>{{ l.member_name }}</b>
                <div class="small muted">{{ l.member_email || `Member #${l.member_account_id}` }}</div>
              </td>
              <td><span class="tag blue">{{ l.entry_type }}</span></td>
              <td>
                <b :style="{ color: Number(l.points_delta) >= 0 ? 'var(--green)' : 'var(--red)' }">
                  {{ Number(l.points_delta) > 0 ? `+${fmt(l.points_delta)}` : fmt(l.points_delta) }}
                </b>
              </td>
              <td>
                <div>{{ l.reason }}</div>
                <div class="small muted">{{ l.event_key }} {{ l.note ? `· ${l.note}` : '' }}</div>
              </td>
              <td>
                <span v-if="l.is_reversed" class="tag red">ถูก Reverse แล้ว</span>
                <span v-else-if="l.reverses_ledger_id" class="tag purple">Reversal ของ #{{ l.reverses_ledger_id }}</span>
                <button
                  v-else
                  type="button"
                  class="btn danger"
                  style="padding:6px 10px"
                  @click="reverseEntry(l)"
                >
                  ↺ Reverse
                </button>
              </td>
            </tr>
            <tr v-if="!(ledger || []).length">
              <td colspan="7" class="placeholder">ยังไม่มีความเคลื่อนไหวคะแนนในระบบ</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="mob-cards" style="padding:12px">
        <div v-for="l in ledger || []" :key="`lm-${l.id}`" class="card mob-card">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
            <strong>{{ l.member_name }}</strong>
            <b :style="{ color: Number(l.points_delta) >= 0 ? 'var(--green)' : 'var(--red)' }">
              {{ Number(l.points_delta) > 0 ? `+${fmt(l.points_delta)}` : fmt(l.points_delta) }} แต้ม
            </b>
          </div>
          <div class="small muted" style="margin:6px 0">
            #{{ l.id }} · {{ l.entry_type }} · {{ l.reason }}
          </div>
          <button
            v-if="!l.is_reversed && !l.reverses_ledger_id"
            type="button"
            class="btn danger"
            style="padding:6px 10px"
            @click="reverseEntry(l)"
          >
            ↺ Reverse
          </button>
        </div>
      </div>
    </div>

    <!-- Create Loyalty Rule Modal -->
    <BaseModal :open="ruleModalOpen" title="สร้างกติกาคะแนนสะสมใหม่" @close="ruleModalOpen = false">
      <form id="createRuleForm" class="form" @submit.prevent="submitRule">
        <div class="field">
          <label>รหัสกติกา (Rule Code) *</label>
          <input v-model="newRule.rule_code" required placeholder="STD-100-1" />
        </div>
        <div class="field">
          <label>ชื่อกติกา *</label>
          <input v-model="newRule.name" required placeholder="ทุก 100 บาทก่อน VAT ได้ 1 แต้ม" />
        </div>
        <div class="field">
          <label>ยอดซื้อ (บาท) ต่อรอบ *</label>
          <input v-model.number="newRule.baht_per_point" type="number" min="1" step="0.01" required />
        </div>
        <div class="field">
          <label>จำนวนแต้มที่ได้ *</label>
          <input v-model.number="newRule.points_per_unit" type="number" min="1" required />
        </div>
        <div class="field">
          <label>ยอดบิลขั้นต่ำ (บาท)</label>
          <input v-model.number="newRule.min_invoice_amount" type="number" min="0" step="0.01" />
        </div>
        <div class="field span2">
          <label>รายละเอียดเพิ่มเติม</label>
          <input v-model="newRule.description" placeholder="ไม่รวมใบจอง 207 และหักแต้มเมื่อลดหนี้ 308" />
        </div>
      </form>
      <div class="notice" style="margin-top:12px">
        กติกาใหม่จะเริ่มต้นเป็นปิดไว้ ประวัติซื้อแสดงตามใบขายจริงและใบคืนแยกจากคะแนนสะสม ขณะนี้ข้อมูลที่ซิงก์ยังไม่มีหลักฐานการชำระเงิน จึงอย่าเปิดกติกาจนกว่าจะยืนยันนโยบายการให้แต้มของบริษัท
      </div>
      <template #footer="{ requestClose }">
        <button type="button" class="btn" @click="requestClose">ยกเลิก</button>
        <button type="submit" form="createRuleForm" class="btn primary" :disabled="savingRule">
          {{ savingRule ? 'กำลังบันทึก...' : 'สร้างกติกา' }}
        </button>
      </template>
    </BaseModal>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import BaseModal from '../../../shared/components/BaseModal.vue';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import { askConfirm, toast } from '../../../shared/composables/useUiStore.js';
import { crmGet, crmMutate, fmt } from '../composables/useCrmSession.js';

const ruleModalOpen = ref(false);
const savingRule = ref(false);
const newRule = ref({
  rule_code: '',
  name: '',
  description: '',
  baht_per_point: 100,
  points_per_unit: 1,
  min_invoice_amount: 0,
  exclude_reservation: true,
  exclude_cancelled: true,
  reverse_on_return: true,
  is_active: false,
});

const { data: rules, isLoading: rulesLoading, refetch: refetchRules } = useQuery({
  queryKey: ['crm', 'loyalty-rules'],
  queryFn: ({ signal }) => crmGet('/loyalty/rules', { signal }),
});

const { data: ledger, isLoading: ledgerLoading, refetch: refetchLedger } = useQuery({
  queryKey: ['crm', 'loyalty-ledger'],
  queryFn: ({ signal }) => crmGet('/loyalty/ledger', { signal }),
});

function refetchAll() {
  refetchRules();
  refetchLedger();
}

async function submitRule() {
  savingRule.value = true;
  try {
    await crmMutate('/loyalty/rules', 'POST', newRule.value);
    ruleModalOpen.value = false;
    newRule.value = {
      rule_code: '',
      name: '',
      description: '',
      baht_per_point: 100,
      points_per_unit: 1,
      min_invoice_amount: 0,
      exclude_reservation: true,
      exclude_cancelled: true,
      reverse_on_return: true,
      is_active: false,
    };
    toast.success('เพิ่มกติกาคะแนนเรียบร้อยแล้ว');
    await refetchRules();
  } catch (err) {
    toast.error('สร้างกติกาไม่สำเร็จ', err);
  } finally {
    savingRule.value = false;
  }
}

async function toggleRule(rule) {
  try {
    await crmMutate(`/loyalty/rules/${rule.id}`, 'PATCH', { is_active: !rule.is_active });
    toast.success('อัปเดตสถานะกติกาแล้ว');
    await refetchRules();
  } catch (err) {
    toast.error('อัปเดตกติกาไม่สำเร็จ', err);
  }
}

async function reverseEntry(entry) {
  const ok = await askConfirm({
    title: `ยืนยัน Reverse คะแนน #${entry.id}`,
    message: `สร้างรายการคืนยอด ${Number(entry.points_delta) * -1} แต้มให้สมาชิก ${entry.member_name}?`,
    confirmLabel: 'ยืนยัน Reverse',
    tone: 'danger',
  });
  if (!ok) return;
  try {
    await crmMutate('/loyalty/ledger/reverse', 'POST', {
      ledger_id: entry.id,
      reason: 'ผู้ดูแลระบบปรับปรุง/ยกเลิกรายการคะแนน',
    });
    toast.success(`Reverse รายการ #${entry.id} เรียบร้อย`);
    await refetchLedger();
  } catch (err) {
    toast.error('Reverse ไม่สำเร็จ', err);
  }
}
</script>
