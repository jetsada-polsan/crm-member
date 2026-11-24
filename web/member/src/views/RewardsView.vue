<template>
  <div>
    <PageHeader title="แลกของรางวัล" subtitle="ใช้แต้มแลกของรางวัล และดูคูปองของคุณ">
      <template #secondary>
        <div class="card" style="padding: 10px 16px; display: inline-flex; align-items: center; gap: 8px">
          <span>คะแนนของคุณ:</span>
          <strong style="font-size: 18px; color: var(--blue)">
            {{ isMemberVerified ? fmt(pointsData?.balance || 0) : '🔒 รอยืนยันสิทธิ์' }}
          </strong>
        </div>
      </template>
    </PageHeader>

    <div v-if="!isMemberVerified" class="notice" style="margin-bottom: 16px">
      บัญชีที่ยังไม่ผ่านการยืนยันตัวตนร้านค้า จะดูรายการของรางวัลได้แต่ยังไม่สามารถกดแลกของรางวัลจนกว่าผู้ดูแลจะอนุมัติสิทธิ์ ERP
    </div>

    <div class="dash-grid">
      <div class="card panel-pad">
        <h3 style="margin-top: 0">รายการของรางวัล</h3>
        <div v-if="loadingRewards"><SkeletonBlock variant="cards" :rows="3" /></div>
        <div v-else-if="!(rewards || []).length" class="placeholder">ยังไม่มีของรางวัลที่เปิดให้แลก</div>
        <div v-else style="display: grid; gap: 12px">
          <div
            v-for="r in rewards"
            :key="r.id"
            class="card panel-pad"
            style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap"
          >
            <div>
              <strong>{{ r.title }}</strong> <span class="tag gray">{{ r.reward_code }}</span>
              <div class="small muted">{{ r.description || '-' }}</div>
              <div style="margin-top: 6px; display: flex; gap: 6px; flex-wrap: wrap">
                <span class="tag blue">{{ fmt(r.points_cost) }} คะแนน</span>
                <span v-if="r.stock_qty != null" class="tag gray">คงเหลือ {{ r.stock_qty }}</span>
              </div>
            </div>
            <button
              type="button"
              class="btn primary"
              :disabled="
                !isMemberVerified ||
                redeemingId === r.id ||
                (pointsData?.balance || 0) < r.points_cost ||
                (r.stock_qty != null && r.stock_qty <= 0)
              "
              @click="redeem(r)"
            >
              {{ redeemingId === r.id ? 'กำลังทำรายการ...' : 'แลกรางวัล' }}
            </button>
          </div>
        </div>
      </div>

      <div class="card panel-pad">
        <h3 style="margin-top: 0">ประวัติการแลกรางวัล</h3>
        <div v-if="loadingRedemptions"><SkeletonBlock variant="list" :rows="4" /></div>
        <div v-else-if="!(redemptions || []).length" class="placeholder">ยังไม่มีประวัติการแลกรางวัล</div>
        <div v-else>
          <div v-for="x in redemptions" :key="x.id" class="minirow">
            <div>
              <strong>{{ x.reward_title }}</strong>
              <div class="small muted">รหัสคูปอง: {{ x.voucher_code }} · {{ fmt(x.points_spent) }} คะแนน</div>
            </div>
            <span :class="['tag', x.status === 'fulfilled' ? 'green' : x.status === 'cancelled' ? 'red' : 'blue']">
              {{ x.status }}
            </span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import PageHeader from '../../../shared/components/PageHeader.vue';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import { askConfirm, toast } from '../../../shared/composables/useUiStore.js';
import {
  fmt,
  invalidateMemberData,
  isMemberVerified,
  memberGet,
  memberMutate,
} from '../composables/useMemberSession.js';

const redeemingId = ref(null);

const { data: pointsData } = useQuery({
  queryKey: ['member', 'points'],
  queryFn: ({ signal }) => memberGet('/points', { signal }),
  enabled: isMemberVerified,
});

const { data: rewards, isLoading: loadingRewards } = useQuery({
  queryKey: ['member', 'rewards'],
  queryFn: ({ signal }) => memberGet('/rewards', { signal }),
});

const { data: redemptions, isLoading: loadingRedemptions } = useQuery({
  queryKey: ['member', 'redemptions'],
  queryFn: ({ signal }) => memberGet('/redemptions', { signal }),
});

async function redeem(reward) {
  const ok = await askConfirm({
    title: 'ยืนยันการแลกรางวัล',
    message: `ต้องการใช้ ${fmt(reward.points_cost)} คะแนน เพื่อแลก "${reward.title}" ใช่หรือไม่?`,
    confirmLabel: 'ยืนยันแลกของรางวัล',
  });
  if (!ok) return;

  redeemingId.value = reward.id;
  try {
    const res = await memberMutate(`/rewards/${reward.id}/redeem`, 'POST', {});
    invalidateMemberData(['points', 'redemptions', 'rewards']);
    toast.success(
      'แลกของรางวัลสำเร็จ',
      `รหัสคูปอง ${res?.voucher_code || ''} · คงเหลือ ${fmt(res?.remaining_balance ?? 0)} คะแนน`
    );
  } catch (err) {
    toast.error('แลกรางวัลไม่สำเร็จ', err);
  } finally {
    redeemingId.value = null;
  }
}
</script>
