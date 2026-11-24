<template>
  <div>
    <PageHeader title="สมาชิก & แต้มสะสม" subtitle="อนุมัติคำขอยืนยันเจ้าของร้าน (ตรวจมากกว่าแค่เบอร์โทร)" />

    <div v-if="!isManager" class="error">เฉพาะผู้จัดการหรือผู้ดูแลระบบเท่านั้นที่เข้าถึงส่วนจัดการสมาชิกและแต้มสะสมได้</div>
    <SkeletonBlock v-else-if="isLoading" variant="cards" :rows="3" />

    <template v-else>
      <div class="card" style="margin-bottom: 18px">
        <div class="section-head" style="padding-bottom: 12px">
          <h3>คำขอยืนยันบัญชีร้านค้า ERP ({{ (verifications || []).length }})</h3>
        </div>
        <div class="table-wrap desktop-table-only">
          <table class="table">
            <thead>
              <tr>
                <th>สมาชิก</th>
                <th>รหัสลูกค้าที่ขอ</th>
                <th>เบอร์ติดต่อ</th>
                <th>สถานะ</th>
                <th>ดำเนินการ</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="v in verifications || []" :key="v.id">
                <td>
                  <b>{{ v.member_name }}</b>
                  <div class="small muted">{{ v.member_email }}</div>
                </td>
                <td>
                  <span v-if="v.requested_customer_code" class="tag blue"
                    >{{ v.requested_source_system || 'Source?' }} / {{ v.requested_customer_code }}</span
                  >
                  <span v-else class="tag orange">ยังไม่มี ERP Code</span>
                  <div v-if="v.status === 'pending'" class="small muted" style="margin-top: 6px">
                    {{ selectedMasterIds[v.id] ? 'เลือกร้านหลัก #' + selectedMasterIds[v.id] : 'ต้องเลือกร้านค้าหลักก่อนอนุมัติ' }}
                    <button
                      type="button"
                      class="btn light"
                      style="margin-left: 6px; padding: 4px 8px"
                      @click="openCustomerPicker(v)"
                    >
                      ค้นหาร้าน
                    </button>
                  </div>
                </td>
                <td>{{ v.requested_phone || '—' }}</td>
                <td>
                  <span
                    class="tag"
                    :class="v.status === 'approved' ? 'green' : v.status === 'rejected' ? 'red' : 'orange'"
                    >{{ v.status }}</span
                  >
                </td>
                <td v-if="v.status === 'pending'" style="display: flex; gap: 6px">
                  <button type="button" class="btn primary" @click="reviewVerification(v, 'approved')">อนุมัติ</button>
                  <button type="button" class="btn danger" @click="reviewVerification(v, 'rejected')">ปฏิเสธ</button>
                </td>
                <td v-else class="small muted">{{ v.review_note || 'ดำเนินการแล้ว' }}</td>
              </tr>
              <tr v-if="!(verifications || []).length">
                <td colspan="5" class="placeholder">ยังไม่มีคำขอยืนยันสมาชิก</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="mob-cards panel-pad">
          <div v-for="v in verifications || []" :key="`mlv-m-${v.id}`" class="card mob-card">
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px">
              <strong>{{ v.member_name }}</strong>
              <span
                class="tag"
                :class="v.status === 'approved' ? 'green' : v.status === 'rejected' ? 'red' : 'orange'"
                >{{ v.status }}</span
              >
            </div>
            <div class="small muted" style="margin: 6px 0">
              {{ v.requested_customer_code || 'ยังไม่มีรหัส ERP' }} · โทร: {{ v.requested_phone || '—' }}
            </div>
            <div v-if="v.status === 'pending'" style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px">
              <button type="button" class="btn light" @click="openCustomerPicker(v)">ค้นหาร้าน</button>
              <button type="button" class="btn primary" @click="reviewVerification(v, 'approved')">อนุมัติ</button>
              <button type="button" class="btn danger" @click="reviewVerification(v, 'rejected')">ปฏิเสธ</button>
            </div>
          </div>
          <div v-if="!(verifications || []).length" class="placeholder">ยังไม่มีคำขอยืนยันสมาชิก</div>
        </div>
      </div>

      <div class="split">
        <div class="card panel-pad">
          <h3 style="margin-top: 0">กติกาคะแนนสะสม (Loyalty Rules)</h3>
          <div v-for="r in rules || []" :key="r.id" class="minirow">
            <div>
              <b>{{ r.name }}</b> <span class="tag gray">{{ r.rule_code }}</span>
              <div class="small muted">{{ r.baht_per_point }} ฿ = {{ r.points_per_unit }} แต้ม</div>
            </div>
            <button type="button" class="btn" :class="{ primary: r.is_active }" @click="toggleRule(r)">
              {{ r.is_active ? 'เปิดใช้งานอยู่' : 'ปิดใช้งาน' }}
            </button>
          </div>
          <div v-if="!(rules || []).length" class="small muted">ยังไม่มีกติกาคะแนนสะสม</div>
        </div>

        <div class="card panel-pad">
          <h3 style="margin-top: 0">ของรางวัลแลกแต้ม ({{ (rewards || []).length }})</h3>
          <form class="form" style="margin-bottom: 14px" @submit.prevent="createReward">
            <div class="field">
              <label>รหัสรางวัล *</label><input v-model="newReward.reward_code" required placeholder="RW01" />
            </div>
            <div class="field">
              <label>แต้มที่ใช้ *</label><input v-model.number="newReward.points_cost" type="number" min="1" required />
            </div>
            <div class="field span2">
              <label>ชื่อรางวัล *</label><input v-model="newReward.title" required placeholder="บัตรกำนัล 100 บาท" />
            </div>
            <div class="field span2"><button type="submit" class="btn primary">＋ เพิ่มของรางวัล</button></div>
          </form>
          <div v-for="rw in rewards || []" :key="rw.id" class="minirow">
            <b>{{ rw.title }}</b>
            <span class="tag blue">{{ fmt(rw.points_cost) }} แต้ม</span>
          </div>
        </div>
      </div>
    </template>
    <BaseModal :open="customerPickerOpen" title="เลือก Internal Customer" @close="customerPickerOpen = false">
      <p class="small muted">ค้นหาจากทะเบียน CRM/ERP เพื่อให้เจ้าหน้าที่เลือกบัญชีที่ถูกต้อง ระบบจะไม่เลือกจากเบอร์โทรหรือชื่อโดยอัตโนมัติ</p>
      <div style="display: flex; gap: 8px; margin-bottom: 12px">
        <input
          v-model="customerSearch"
          placeholder="ชื่อร้าน เบอร์ หรือรหัสภายใน"
          style="flex: 1"
          @keyup.enter="searchCustomers"
        />
        <button type="button" class="btn" @click="searchCustomers">ค้นหา</button>
      </div>
      <div v-for="customer in customerResults" :key="customer.customer_master_id" class="minirow">
        <span
          ><b>{{ customer.display_name }}</b>
          <div class="small muted">
            {{ customer.internal_customer_code }} · {{ customer.phone || 'ไม่มีเบอร์' }} · ERP
            {{ customer.erp_customer_code || 'รอ ERP' }}
          </div></span
        >
        <button type="button" class="btn primary" @click="selectCustomer(customer)">เลือกบัญชีนี้</button>
      </div>
      <div v-if="searchedCustomers && !customerResults.length" class="placeholder">ไม่พบ Customer Master</div>
    </BaseModal>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import BaseModal from '../../../shared/components/BaseModal.vue';
import PageHeader from '../../../shared/components/PageHeader.vue';
import { askConfirm, toast } from '../../../shared/composables/useUiStore.js';
import { crmGet, crmMutate, fmt, isManager } from '../composables/useCrmSession.js';

const newReward = ref({ reward_code: '', title: '', points_cost: 50, stock_qty: 20, is_active: true });
const customerPickerOpen = ref(false);
const customerSearch = ref('');
const customerResults = ref([]);
const searchedCustomers = ref(false);
const activeVerification = ref(null);
const selectedMasterIds = ref({});

const {
  data: verifications,
  isLoading: l1,
  refetch: r1,
} = useQuery({
  queryKey: ['crm', 'loyalty-verifications'],
  queryFn: ({ signal }) => crmGet('/loyalty/verifications?status=all', { signal }),
  enabled: isManager,
});
const {
  data: rules,
  isLoading: l2,
  refetch: r2,
} = useQuery({
  queryKey: ['crm', 'loyalty-rules'],
  queryFn: ({ signal }) => crmGet('/loyalty/rules', { signal }),
  enabled: isManager,
});
const {
  data: rewards,
  isLoading: l3,
  refetch: r3,
} = useQuery({
  queryKey: ['crm', 'loyalty-rewards'],
  queryFn: ({ signal }) => crmGet('/loyalty/rewards', { signal }),
  enabled: isManager,
});

const isLoading = computed(() => l1.value || l2.value || l3.value);

async function toggleRule(rule) {
  try {
    await crmMutate(`/loyalty/rules/${rule.id}`, 'PATCH', { is_active: !rule.is_active });
    toast.success('อัปเดตสถานะกติกาแล้ว');
    await r2();
  } catch (err) {
    toast.error('อัปเดตกติกาไม่สำเร็จ', err);
  }
}

async function reviewVerification(v, decision) {
  const selectedMasterId = selectedMasterIds.value[v.id] || v.crm_customer_master_id || null;
  if (decision === 'approved' && !selectedMasterId && !v.requested_customer_code) {
    toast.error('กรุณาค้นหาและเลือก Customer Master ก่อนอนุมัติ');
    return;
  }
  const ok = await askConfirm({
    title: decision === 'approved' ? 'ยืนยันเจ้าของบัญชีลูกค้า' : 'ปฏิเสธคำขอยืนยัน',
    message:
      'สมาชิก ' +
      v.member_name +
      ' · ERP ' +
      (v.requested_source_system || '') +
      ' / ' +
      (v.requested_customer_code || 'ยังไม่มี ERP Code'),
    confirmLabel: decision === 'approved' ? 'อนุมัติ' : 'ปฏิเสธ',
    tone: decision === 'approved' ? 'primary' : 'danger',
  });
  if (!ok) return;
  try {
    await crmMutate('/loyalty/verifications/' + v.id + '/review', 'POST', {
      decision,
      crm_customer_master_id: selectedMasterId,
      erp_source_system: v.requested_source_system || null,
      review_note: decision === 'approved' ? 'อนุมัติโดยผู้ดูแลระบบ' : 'ข้อมูลไม่ตรงกัน',
    });
    toast.success('บันทึกผลคำขอ #' + v.id + ' เรียบร้อย');
    await r1();
  } catch (err) {
    toast.error('ดำเนินการไม่สำเร็จ', err);
  }
}

function openCustomerPicker(v) {
  activeVerification.value = v;
  customerSearch.value = '';
  customerResults.value = [];
  searchedCustomers.value = false;
  customerPickerOpen.value = true;
}

async function searchCustomers() {
  try {
    const response = await crmGet(
      '/customer-master?q=' + encodeURIComponent(customerSearch.value.trim()) + '&per_page=25&page=1'
    );
    customerResults.value = response?.data || [];
    searchedCustomers.value = true;
  } catch (err) {
    toast.error('ค้นหา Customer Master ไม่สำเร็จ', err);
  }
}

function selectCustomer(customer) {
  if (!activeVerification.value) return;
  selectedMasterIds.value = { ...selectedMasterIds.value, [activeVerification.value.id]: customer.customer_master_id };
  customerPickerOpen.value = false;
}
async function createReward() {
  try {
    await crmMutate('/loyalty/rewards', 'POST', newReward.value);
    newReward.value = { reward_code: '', title: '', points_cost: 50, stock_qty: 20, is_active: true };
    toast.success('เพิ่มของรางวัลแล้ว');
    await r3();
  } catch (err) {
    toast.error('เพิ่มรางวัลไม่สำเร็จ', err);
  }
}
</script>
