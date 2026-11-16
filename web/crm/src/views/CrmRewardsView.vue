<template>
  <div>
    <div class="pagehead">
      <div>
        <div class="eyebrow">REWARDS &amp; REDEMPTIONS</div>
        <h2>ของรางวัล &amp; คิวแลกของรางวัล</h2>
        <p>จัดการแคตตาล็อกของรางวัลสมาชิก พร้อมอนุมัติ ส่งมอบ (Fulfill) หรือยกเลิกคิวแลกรางวัล</p>
      </div>
      <div class="head-actions">
        <button type="button" class="btn" @click="refetchAll">⟳ รีเฟรช</button>
        <button type="button" class="btn primary" @click="rewardModalOpen = true">+ เพิ่มของรางวัลใหม่</button>
      </div>
    </div>

    <!-- Redemptions Queue -->
    <div class="card" style="margin-bottom:16px">
      <div class="section-head" style="padding:16px 18px 0">
        <div>
          <h3>คิวคำขอแลกของรางวัล ({{ (redemptions || []).length }})</h3>
          <small>กดอนุมัติ / ส่งมอบเมื่อแจกของให้ร้านค้า หรือกดยกเลิกเพื่อคืนแต้มให้สมาชิก</small>
        </div>
      </div>

      <SkeletonBlock v-if="redLoading && !(redemptions || []).length" variant="table" :rows="4" />
      <div v-else class="table-wrap desktop-table-only">
        <table class="table">
          <thead>
            <tr>
              <th>รหัสคูปอง (Voucher)</th>
              <th>สมาชิก / เบอร์โทร</th>
              <th>ของรางวัล</th>
              <th>แต้มที่ใช้</th>
              <th>สถานะ</th>
              <th>วันที่แลก</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in redemptions || []" :key="r.id">
              <td><span class="tag purple">{{ r.voucher_code }}</span></td>
              <td>
                <b>{{ r.member_name }}</b>
                <div class="small muted">{{ r.member_phone || '—' }}</div>
              </td>
              <td><b>{{ r.reward_title }}</b> <span class="small muted">({{ r.reward_code }})</span></td>
              <td>{{ fmt(r.points_spent) }} แต้ม</td>
              <td><span class="tag" :class="redStatusClass(r.status)">{{ r.status }}</span></td>
              <td>{{ r.created_at ? new Date(r.created_at).toLocaleString('th-TH') : '—' }}</td>
              <td>
                <div v-if="r.status === 'pending' || r.status === 'approved'" style="display:flex;gap:6px;flex-wrap:wrap">
                  <button v-if="r.status === 'pending'" type="button" class="btn light" @click="setStatus(r, 'approved')">อนุมัติ</button>
                  <button type="button" class="btn primary" @click="setStatus(r, 'fulfilled')">ส่งมอบแล้ว</button>
                  <button type="button" class="btn danger" @click="setStatus(r, 'cancelled')">ยกเลิก</button>
                </div>
              </td>
            </tr>
            <tr v-if="!(redemptions || []).length">
              <td colspan="7" class="placeholder">ยังไม่มีคำขอแลกของรางวัลจากสมาชิก</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="mob-cards" style="padding:12px">
        <div v-for="r in redemptions || []" :key="`red-m-${r.id}`" class="card mob-card">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
            <strong>{{ r.reward_title }}</strong>
            <span class="tag" :class="redStatusClass(r.status)">{{ r.status }}</span>
          </div>
          <div class="small muted" style="margin:6px 0">
            {{ r.voucher_code }} · โดย {{ r.member_name }} ({{ fmt(r.points_spent) }} แต้ม)
          </div>
          <div v-if="r.status === 'pending' || r.status === 'approved'" style="display:flex;gap:6px;flex-wrap:wrap">
            <button v-if="r.status === 'pending'" type="button" class="btn light" @click="setStatus(r, 'approved')">อนุมัติ</button>
            <button type="button" class="btn primary" @click="setStatus(r, 'fulfilled')">ส่งมอบแล้ว</button>
            <button type="button" class="btn danger" @click="setStatus(r, 'cancelled')">ยกเลิก</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Rewards Catalog -->
    <div class="card">
      <div class="section-head" style="padding:16px 18px 0">
        <div>
          <h3>แคตตาล็อกของรางวัลสมาชิก ({{ (rewards || []).length }})</h3>
          <small>รายการที่แสดงให้สมาชิกแลกบน Member Portal</small>
        </div>
      </div>

      <SkeletonBlock v-if="rewardsLoading && !(rewards || []).length" variant="table" :rows="4" />
      <div v-else class="table-wrap desktop-table-only">
        <table class="table">
          <thead>
            <tr>
              <th>รหัสรางวัล</th>
              <th>ชื่อของรางวัล</th>
              <th>แต้มที่ใช้</th>
              <th>คงเหลือ</th>
              <th>สถานะ</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="rw in rewards || []" :key="rw.id">
              <td><span class="tag blue">{{ rw.reward_code }}</span></td>
              <td>
                <b>{{ rw.title }}</b>
                <div v-if="rw.description" class="small muted">{{ rw.description }}</div>
              </td>
              <td><b>{{ fmt(rw.points_cost) }} แต้ม</b></td>
              <td>{{ fmt(rw.stock_qty) }}</td>
              <td><span class="tag" :class="rw.is_active ? 'green' : 'gray'">{{ rw.is_active ? 'เปิดให้แลก' : 'ปิด' }}</span></td>
            </tr>
            <tr v-if="!(rewards || []).length">
              <td colspan="5" class="placeholder">ยังไม่มีของรางวัลในแคตตาล็อก</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <BaseModal :open="rewardModalOpen" title="เพิ่มของรางวัลสมาชิก" @close="rewardModalOpen = false">
      <form id="newRewardForm" class="form" @submit.prevent="submitReward">
        <div class="field">
          <label>รหัสรางวัล *</label>
          <input v-model="newReward.reward_code" required placeholder="GIFT-100" />
        </div>
        <div class="field">
          <label>ชื่อของรางวัล *</label>
          <input v-model="newReward.title" required placeholder="คูปองส่วนลดสินค้า 100 บาท" />
        </div>
        <div class="field">
          <label>จำนวนแต้มที่ใช้แลก *</label>
          <input v-model.number="newReward.points_cost" type="number" min="1" required />
        </div>
        <div class="field">
          <label>สต็อกคงเหลือ *</label>
          <input v-model.number="newReward.stock_qty" type="number" min="0" required />
        </div>
        <div class="field span2">
          <label>คำอธิบายเงื่อนไข</label>
          <input v-model="newReward.description" placeholder="ใช้ได้กับสินค้าทุกหมวดผ่านพนักงานขาย" />
        </div>
      </form>
      <template #footer="{ requestClose }">
        <button type="button" class="btn" @click="requestClose">ยกเลิก</button>
        <button type="submit" form="newRewardForm" class="btn primary" :disabled="savingReward">
          {{ savingReward ? 'กำลังบันทึก...' : 'เพิ่มของรางวัล' }}
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

const rewardModalOpen = ref(false);
const savingReward = ref(false);
const newReward = ref({
  reward_code: '',
  title: '',
  description: '',
  points_cost: 50,
  stock_qty: 20,
  is_active: true,
});

const { data: rewards, isLoading: rewardsLoading, refetch: refetchRewards } = useQuery({
  queryKey: ['crm', 'loyalty-rewards'],
  queryFn: ({ signal }) => crmGet('/loyalty/rewards', { signal }),
});

const { data: redemptions, isLoading: redLoading, refetch: refetchRedemptions } = useQuery({
  queryKey: ['crm', 'loyalty-redemptions'],
  queryFn: ({ signal }) => crmGet('/loyalty/redemptions', { signal }),
});

function refetchAll() {
  refetchRewards();
  refetchRedemptions();
}

function redStatusClass(st) {
  if (st === 'fulfilled') return 'green';
  if (st === 'approved') return 'blue';
  if (st === 'cancelled') return 'red';
  return 'orange';
}

async function submitReward() {
  savingReward.value = true;
  try {
    await crmMutate('/loyalty/rewards', 'POST', newReward.value);
    rewardModalOpen.value = false;
    newReward.value = { reward_code: '', title: '', description: '', points_cost: 50, stock_qty: 20, is_active: true };
    toast.success('เพิ่มของรางวัลแล้ว');
    await refetchRewards();
  } catch (err) {
    toast.error('เพิ่มรางวัลไม่สำเร็จ', err);
  } finally {
    savingReward.value = false;
  }
}

async function setStatus(red, status) {
  const ok = await askConfirm({
    title: `อัปเดตสถานะการแลกรางวัล (${red.voucher_code})`,
    message: `เปลี่ยนสถานะคำขอของ ${red.member_name} เป็น "${status}"?`,
    confirmLabel: 'ยืนยัน',
    tone: status === 'cancelled' ? 'danger' : 'primary',
  });
  if (!ok) return;
  try {
    await crmMutate(`/loyalty/redemptions/${red.id}/status`, 'POST', {
      status,
      note: `อัปเดตเป็น ${status} โดยผู้ดูแลระบบ`,
    });
    toast.success(`อัปเดตคูปอง ${red.voucher_code} เป็น ${status} แล้ว`);
    await refetchRedemptions();
  } catch (err) {
    toast.error('อัปเดตสถานะไม่สำเร็จ', err);
  }
}
</script>
