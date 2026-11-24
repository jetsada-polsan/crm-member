<template>
  <SlideOverDrawer
    :open="uiState.errorDetail.open"
    :title="uiState.errorDetail.title || 'รายละเอียดข้อผิดพลาด'"
    subtitle="ข้อมูลวิเคราะห์สำหรับตรวจสอบปัญหา (ไม่แสดงข้อมูลลับ)"
    @close="closeErrorDetail"
  >
    <template v-if="err">
      <div class="error" style="margin-bottom: 14px">
        <strong>{{ err.message }}</strong>
      </div>
      <div class="minirow">
        <span>สถานะ HTTP</span>
        <span class="tag red">{{ err.status || 'Network Error' }}</span>
      </div>
      <div v-if="err.method || err.url" class="minirow">
        <span>Endpoint</span>
        <code class="small">{{ err.method }} {{ err.url }}</code>
      </div>
      <div v-if="err.timestamp" class="minirow">
        <span>เวลาที่เกิด</span>
        <span class="small muted">{{ err.timestamp }}</span>
      </div>

      <div v-if="validationList.length" style="margin-top: 16px">
        <strong class="small" style="display: block; margin-bottom: 8px">รายการข้อมูลที่ไม่ถูกต้อง:</strong>
        <ul class="ui-err-list">
          <li v-for="(item, idx) in validationList" :key="idx">
            <b>{{ item.field }}:</b> {{ item.msg }}
          </li>
        </ul>
      </div>

      <div class="notice" style="margin-top: 16px">
        คำแนะนำ:
        <span v-if="err.status === 0">ตรวจสอบการเชื่อมต่ออินเทอร์เน็ตหรือข้อมูล Draft Offline ระบบจะเก็บข้อมูลค้างส่งไว้ให้</span>
        <span v-else-if="err.status === 401">Session หมดอายุ กรุณาเข้าสู่ระบบใหม่</span>
        <span v-else-if="err.status === 403">บัญชีนี้ไม่มีสิทธิ์ใช้งานส่วนนี้</span>
        <span v-else-if="err.status === 409">พบข้อมูลซ้ำหรือขัดแย้งในระบบ กรุณาตรวจสอบรายการที่แสดง</span>
        <span v-else>ตรวจสอบข้อมูลที่กรอกแล้วลองบันทึกอีกครั้ง</span>
      </div>
    </template>
  </SlideOverDrawer>
</template>

<script setup>
import { computed } from 'vue';
import SlideOverDrawer from './SlideOverDrawer.vue';
import { closeErrorDetail, uiState } from '../composables/useUiStore.js';

const err = computed(() => uiState.errorDetail.error);

const validationList = computed(() => {
  const errs = err.value?.errors;
  if (!errs || typeof errs !== 'object') return [];
  const out = [];
  for (const [field, msgs] of Object.entries(errs)) {
    const arr = Array.isArray(msgs) ? msgs : [String(msgs)];
    for (const m of arr) {
      out.push({ field, msg: m });
    }
  }
  return out;
});
</script>
