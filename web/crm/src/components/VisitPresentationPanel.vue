<template>
  <section class="card panel-pad presentation-panel">
    <div class="heading">
      <div>
        <span class="eyebrow">WEEKLY FIELD REPORT</span>
        <h3>สรุปออกตลาดแบบพรีเซนเทชัน</h3>
        <p class="small muted">เลือกช่วงเวลา พนักงาน และสาย แล้วเปิดหน้าสำหรับนำเสนอได้ทันที</p>
      </div>
      <div class="icon" aria-hidden="true">▣</div>
    </div>
    <DateRangePicker v-model:from="from" v-model:to="to" :allow-year="false" />
    <div class="filters">
      <label v-if="isManager"
        >พนักงาน
        <select v-model="staffId">
          <option value="">ทุกคน</option>
          <option v-for="person in staff" :key="person.id" :value="String(person.id)">{{ person.name }}</option>
        </select></label
      >
      <label
        >สาย / พื้นที่
        <select v-model="route">
          <option value="">ทุกสาย</option>
          <option v-for="r in routes" :key="r.route_code" :value="r.route_code">
            [{{ r.route_code }}] {{ r.route_name }}
          </option>
        </select></label
      >
    </div>
    <div class="bottom">
      <span class="small muted">เลือกได้สูงสุด 31 วัน · สายอ้างอิงลูกค้า ERP ที่เชื่อมแล้ว</span
      ><a
        :href="presentationUrl"
        target="_blank"
        rel="noopener"
        class="btn primary"
        :aria-disabled="!valid"
        @click="checkValid"
        >▶ เปิดหน้านำเสนอ</a
      >
    </div>
    <p v-if="message" role="alert" class="small error">{{ message }}</p>
  </section>
</template>
<script setup>
import { computed, onMounted, ref } from 'vue';
import DateRangePicker from './DateRangePicker.vue';
import { crmGet, isManager } from '../composables/useCrmSession.js';
const today = new Date();
const format = (date) =>
  `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
const monthStart = new Date(today.getFullYear(), today.getMonth(), 1);
const from = ref(format(monthStart)),
  to = ref(format(today)),
  staffId = ref(''),
  route = ref('');
const staff = ref([]),
  routes = ref([]),
  message = ref('');
const valid = computed(() => {
  if (!from.value || !to.value) return false;
  const days = (Date.parse(`${to.value}T00:00:00Z`) - Date.parse(`${from.value}T00:00:00Z`)) / 86400000;
  return days >= 0 && days < 31;
});
const presentationUrl = computed(() => {
  const p = new URLSearchParams({ from: from.value, to: to.value });
  if (staffId.value) p.set('staff_id', staffId.value);
  if (route.value) p.set('route_code', route.value);
  return `/presentation/visits?${p}`;
});
function checkValid(event) {
  if (!valid.value) {
    event.preventDefault();
    message.value = 'กรุณาเลือกช่วงวันที่ไม่เกิน 31 วัน';
  } else message.value = '';
}
onMounted(async () => {
  try {
    const params = new URLSearchParams({ from: from.value, to: to.value });
    const [initial, dimensions] = await Promise.all([
      crmGet(`/reports/visit-presentation?${params}`),
      crmGet('/reports/sales-dimensions?source_system=BPLUS'),
    ]);
    staff.value = initial.staff || [];
    routes.value = dimensions.routes || [];
  } catch {
    message.value = 'โหลดตัวเลือกพนักงานหรือสายไม่สำเร็จ ลองรีเฟรชหน้า';
  }
});
</script>
<style scoped>
.presentation-panel {
  margin-bottom: 16px;
  border: 1px solid #dce6f9;
  background: linear-gradient(110deg, #fff 38%, #f3f7ff);
}
.heading,
.bottom {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
}
.heading h3 {
  font-size: 20px;
  margin: 4px 0;
}
.heading p {
  margin: 4px 0;
}
.icon {
  height: 52px;
  width: 52px;
  display: grid;
  place-items: center;
  border-radius: 16px;
  background: #e8efff;
  color: #315feb;
  font-size: 27px;
}
.filters {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  margin: 18px 0;
}
.filters label {
  display: grid;
  gap: 6px;
  font-size: 13px;
  color: #52647c;
  min-width: 150px;
}
.bottom a {
  white-space: nowrap;
}
.eyebrow {
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.09em;
  color: #3764e6;
}
.error {
  color: #b42318;
}
</style>
