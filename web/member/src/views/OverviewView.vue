<template>
  <div>
    <PageHeader title="บัตรสมาชิกของฉัน" subtitle="แต้มคงเหลือ ยอดซื้อ และสิทธิพิเศษของคุณ">
      <template #secondary>
        <RouterLink to="/invoices" class="btn light">🧾 ประวัติยอดซื้อ</RouterLink>
        <RouterLink to="/binding" class="btn">🔗 ผูกบัญชี LINE &amp; ร้านค้า</RouterLink>
      </template>
      <template #primary>
        <RouterLink to="/rewards" class="btn primary">🎁 แลกของรางวัล</RouterLink>
      </template>
    </PageHeader>

    <!-- Primary Hero: Digital Member Card + Points Balance + Redeem Rewards CTA -->
    <div class="dash-grid" style="margin-bottom: 18px">
      <div class="digital-member-card">
        <div class="member-card-head">
          <span class="small">บัตรสมาชิก POPSTAR Connect</span>
          <span class="tag member-card-badge">
            {{ isMemberVerified ? '✓ ยืนยันสิทธิ์แล้ว' : 'รอการยืนยันสิทธิ์' }}
          </span>
        </div>
        <h2 class="member-card-title">{{ me?.erp_link?.erp_customer_name || me?.name }}</h2>
        <div class="member-card-meta">
          รหัสสมาชิก #{{ me?.id }} · {{ me?.phone || me?.email || '-' }}
          <span v-if="me?.erp_link?.erp_customer_code"> · รหัสลูกค้า {{ me.erp_link.erp_customer_code }}</span>
        </div>
        <div class="member-card-foot">
          <div class="member-points-group">
            <div>
              <div class="member-points-label">แต้มคงเหลือ</div>
              <div class="member-points-num">{{ isMemberVerified ? fmt(pointsData?.balance || 0) : '🔒 รอยืนยัน' }}</div>
            </div>
            <div v-if="isMemberVerified && pointsData?.erp_point_balance != null">
              <div class="member-points-label">แต้มใน Bplus</div>
              <div class="member-points-num">{{ fmt(pointsData.erp_point_balance) }}</div>
            </div>
          </div>
          <RouterLink to="/rewards" class="btn member-card-cta"> 🎁 แลกของรางวัล </RouterLink>
        </div>
      </div>

      <div class="member-kpis-two">
        <div class="card kpi">
          <div class="kpi-top">
            <div class="kpi-icon">🧾</div>
            <span class="tag blue">ยอดซื้อ</span>
          </div>
          <div class="kpi-label">บิลขายจริงที่เชื่อมแล้ว</div>
          <div class="kpi-value">{{ isMemberVerified ? fmt(me?.purchase_summary?.sale_invoice_count || 0) : '—' }}</div>
          <div v-if="isMemberVerified" class="kpi-foot">
            ซื้อ {{ fmt(me?.purchase_summary?.gross_purchase_before_vat) }} ฿ · คืน
            {{ fmt(me?.purchase_summary?.return_amount_before_vat) }} ฿
          </div>
          <div class="kpi-foot">{{ isMemberVerified ? `ยอดรวม ${fmt(totalNetSales)} ฿` : 'รอทีมงานอนุมัติสิทธิ์' }}</div>
        </div>

        <div class="card kpi">
          <div class="kpi-top">
            <div class="kpi-icon green">💬</div>
            <span :class="['tag', me?.line_binding ? 'green' : 'gray']">
              {{ me?.line_binding ? 'ผูกแล้ว' : 'ยังไม่ผูก' }}
            </span>
          </div>
          <div class="kpi-label">บัญชี LINE</div>
          <div class="kpi-value" style="font-size: 16px; padding-top: 6px">
            {{ me?.line_binding?.line_display_name || (me?.line_binding ? 'เชื่อมต่อแล้ว' : 'ยังไม่เชื่อม LINE') }}
          </div>
          <div class="kpi-foot">รับแจ้งเตือนบิลและแต้มทาง LINE</div>
        </div>
      </div>
    </div>

    <!-- Verification Progress / Unverified Banner -->
    <div class="card panel-pad" style="margin-bottom: 18px">
      <div class="stepper" style="margin-bottom: 12px">
        <div class="step-pill done">1. สมัครบัญชีสมาชิก</div>
        <div :class="['step-pill', me?.line_binding ? 'done' : 'active']">
          2. ผูก LINE ({{ me?.line_binding ? 'ผูกแล้ว' : 'ยังไม่ผูก' }})
        </div>
        <div :class="['step-pill', me?.erp_link?.erp_status === 'linked' ? 'done' : 'active']">
          3. รหัสลูกค้าร้าน ({{ me?.erp_link?.erp_customer_code || 'รอเชื่อมร้าน' }})
        </div>
        <div :class="['step-pill', isMemberVerified ? 'done' : 'active']">
          4. ยืนยันเจ้าของร้าน ({{ isMemberVerified ? 'ยืนยันแล้ว' : 'รอตรวจสอบ' }})
        </div>
      </div>

      <div v-if="!isMemberVerified" class="notice" style="margin-bottom: 0">
        <strong>บัญชียังรอการยืนยันเจ้าของร้าน:</strong>
        กรุณาส่งคำขอยืนยันในหน้า <RouterLink to="/binding"><b>ผูกบัญชี LINE &amp; ร้านค้า</b></RouterLink>
        เพื่อให้ทีม POPSTAR ตรวจสอบก่อนเปิดดูยอดซื้อและใช้แต้มสะสม
      </div>
    </div>

    <div v-if="isMemberVerified" class="middle">
      <div class="card panel-pad">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px">
          <h3 style="margin: 0">ประวัติยอดซื้อล่าสุด</h3>
          <RouterLink to="/invoices" class="small">ดูทั้งหมด →</RouterLink>
        </div>
        <div v-if="loadingInv"><SkeletonBlock variant="list" :rows="4" /></div>
        <div v-else-if="!(invoices || []).length" class="placeholder">ยังไม่มีบิลขายที่แสดงได้</div>
        <div v-else>
          <div v-for="inv in (invoices || []).slice(0, 5)" :key="inv.invoice_no" class="minirow">
            <div>
              <strong>{{ inv.invoice_no }}</strong>
              <div class="small muted">
                {{ inv.sold_at }} · {{ inv.doc_category === 'return' ? 'ใบลดหนี้' : `ใบขาย (${inv.doc_type})` }}
              </div>
            </div>
            <strong :style="{ color: inv.doc_category === 'return' ? 'var(--red)' : 'var(--ink)' }">
              ฿{{ fmt(inv.net_amount) }}
            </strong>
          </div>
        </div>
      </div>

      <div class="card panel-pad">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px">
          <h3 style="margin: 0">ความเคลื่อนไหวแต้มล่าสุด</h3>
          <RouterLink to="/rewards" class="small">แลกรางวัล →</RouterLink>
        </div>
        <div v-if="loadingPts"><SkeletonBlock variant="list" :rows="4" /></div>
        <div v-else-if="!(pointsData?.ledger || []).length" class="placeholder">ยังไม่มีประวัติแต้มสะสม</div>
        <div v-else>
          <div v-for="row in (pointsData?.ledger || []).slice(0, 5)" :key="row.id" class="minirow">
            <div>
              <strong>{{ row.reason || row.event_key }}</strong>
              <div class="small muted">{{ row.created_at }}</div>
            </div>
            <span :class="['tag', Number(row.points_delta) >= 0 ? 'green' : 'orange']">
              {{ Number(row.points_delta) >= 0 ? '+' : '' }}{{ fmt(row.points_delta) }} คะแนน
            </span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import { useQuery } from '@tanstack/vue-query';
import PageHeader from '../../../shared/components/PageHeader.vue';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import { fmt, isMemberVerified, me, memberGet } from '../composables/useMemberSession.js';

const { data: invoices, isLoading: loadingInv } = useQuery({
  queryKey: ['member', 'invoices'],
  queryFn: ({ signal }) => memberGet('/invoices', { signal }),
  enabled: isMemberVerified,
});

const { data: pointsData, isLoading: loadingPts } = useQuery({
  queryKey: ['member', 'points'],
  queryFn: ({ signal }) => memberGet('/points', { signal }),
  enabled: isMemberVerified,
});

const totalNetSales = computed(() => Number(me.value?.purchase_summary?.net_purchase_before_vat || 0));
</script>
