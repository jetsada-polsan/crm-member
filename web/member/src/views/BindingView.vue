<template>
  <div>
    <PageHeader title="ผูกบัญชี LINE & ร้านค้า" subtitle="ยืนยันว่าคุณเป็นเจ้าของร้าน เพื่อดูยอดซื้อและสะสมแต้ม (ทีม POPSTAR ตรวจสอบให้)" />

    <div class="dash-grid">
      <!-- LINE OA Binding -->
      <div class="card panel-pad">
        <h3 style="margin-top: 0">ผูกบัญชี LINE Official Account</h3>
        <p class="small muted">สร้างรหัสชั่วคราวสำหรับเชื่อมบัญชี แล้วนำไปส่งในแชท LINE OA ของ POPSTAR</p>

        <div v-if="me?.line_binding" class="success">
          <strong>ผูกบัญชี LINE แล้ว:</strong>
          {{ me.line_binding.line_display_name || me.line_binding.line_user_id }}
          <span class="tag green" style="margin-left: 6px">{{ me.line_binding.binding_status }}</span>
        </div>

        <div v-if="linkToken" class="notice">
          <div>คำสั่งเชื่อมบัญชีใน LINE OA:</div>
          <strong style="font-size: 18px; letter-spacing: 1.2px; display: block; margin: 6px 0">
            {{ linkToken.command || `LINK:${linkToken.link_code}` }}
          </strong>
          <div class="small muted">หมดอายุ: {{ linkToken.expires_at }}</div>
        </div>

        <div style="display: flex; gap: 10px; flex-wrap: wrap">
          <button type="button" class="btn primary" :disabled="lineBusy" @click="requestLineToken">
            {{ lineBusy ? 'กำลังสร้างรหัส...' : '💬 ขอรหัสเชื่อม LINE OA' }}
          </button>
          <button v-if="me?.line_binding" type="button" class="btn danger" :disabled="lineBusy" @click="unlinkLine">
            ยกเลิกการผูก LINE
          </button>
        </div>
      </div>

      <!-- ERP Customer Verification Request -->
      <div class="card panel-pad">
        <h3 style="margin-top: 0">ส่งคำขอยืนยันความเป็นเจ้าของร้านค้า ERP</h3>
        <div class="notice">ระบบแยกบัญชีสมาชิกออกจากข้อมูล Leads และบิล ERP เพื่อป้องกันการเปิดข้อมูลการเงินผิดร้าน</div>

        <form @submit.prevent="submitVerification">
          <div class="formfield">
            <label>ERP Source ของรหัสที่แจ้ง (ถ้ามี)</label>
            <input v-model.trim="verifyForm.requested_source_system" placeholder="BPLUS" />
          </div>
          <div class="formfield">
            <label>รหัสลูกค้า Bplus ERP (ถ้าทราบ)</label>
            <input v-model.trim="verifyForm.requested_customer_code" placeholder="เช่น AR001258" />
          </div>
          <div class="formfield">
            <label>รหัสสมาชิกเดิมใน ERP (ถ้ามี)</label>
            <input v-model.trim="verifyForm.requested_member_code" placeholder="เช่น MB-001" />
          </div>
          <div class="formfield">
            <label>เบอร์โทรร้านค้าที่แจ้งไว้กับ Popstar</label>
            <input v-model.trim="verifyForm.requested_phone" type="tel" inputmode="tel" placeholder="08x-xxx-xxxx" />
          </div>
          <div class="formfield">
            <label>ข้อมูลยืนยันเพิ่มเติม / ชื่อร้าน / สาขา</label>
            <textarea
              v-model.trim="verifyForm.evidence_note"
              rows="2"
              placeholder="ระบุชื่อร้านค้าหรือพนักงานขายที่ดูแล..."
            ></textarea>
          </div>
          <button type="submit" class="btn primary" style="width: 100%" :disabled="verifyBusy">
            {{ verifyBusy ? 'กำลังส่งคำขอ...' : '📩 ส่งคำขอยืนยันร้านค้า' }}
          </button>
        </form>

        <div v-if="me?.erp_link || me?.latest_verification_request" style="margin-top: 16px">
          <strong class="small">สถานะการเชื่อม ERP ปัจจุบัน:</strong>
          <div v-if="me?.erp_link" class="minirow">
            <div>
              <strong>{{ me.erp_link.erp_customer_code || 'รอเชื่อมรหัส ERP' }}</strong>
              <div class="small muted">{{ me.erp_link.erp_customer_name || 'รอการยืนยันสิทธิ์' }}</div>
            </div>
            <span :class="['tag', me.erp_link.verification_status === 'verified' ? 'green' : 'orange']">
              {{ me.erp_link.verification_status }}
            </span>
          </div>
          <div v-if="me?.latest_verification_request" class="minirow">
            <div>
              <strong
                >คำขอ #{{ me.latest_verification_request.id }} ({{
                  me.latest_verification_request.requested_customer_code || 'ไม่ระบุรหัส ERP'
                }})</strong
              >
              <div class="small muted">{{ me.latest_verification_request.created_at }}</div>
            </div>
            <span class="tag blue">{{ me.latest_verification_request.status }}</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { reactive, ref } from 'vue';
import PageHeader from '../../../shared/components/PageHeader.vue';
import { askConfirm, toast } from '../../../shared/composables/useUiStore.js';
import { me, memberMutate, refreshMemberSession } from '../composables/useMemberSession.js';

const lineBusy = ref(false);
const verifyBusy = ref(false);
const linkToken = ref(null);

const verifyForm = reactive({
  requested_source_system: 'BPLUS',
  requested_customer_code: '',
  requested_member_code: '',
  requested_phone: '',
  evidence_note: '',
});

async function requestLineToken() {
  lineBusy.value = true;
  try {
    linkToken.value = await memberMutate('/line/link-token', 'POST', {});
    toast.success('สร้างคำสั่งเชื่อม LINE OA แล้ว');
  } catch (err) {
    toast.error('สร้างรหัส LINE ไม่สำเร็จ', err);
  } finally {
    lineBusy.value = false;
  }
}

async function unlinkLine() {
  const ok = await askConfirm({
    title: 'ยกเลิกการผูก LINE OA',
    message: 'ต้องการยกเลิกการเชื่อมต่อบัญชี LINE OA ออกจากสมาชิกนี้ใช่หรือไม่?',
    confirmLabel: 'ยกเลิกการผูก',
    tone: 'danger',
  });
  if (!ok) return;

  lineBusy.value = true;
  try {
    await memberMutate('/line/binding', 'DELETE');
    await refreshMemberSession();
    toast.success('ยกเลิกการผูก LINE OA แล้ว');
  } catch (err) {
    toast.error('ไม่สามารถยกเลิกการผูก LINE', err);
  } finally {
    lineBusy.value = false;
  }
}

async function submitVerification() {
  if (!verifyForm.requested_customer_code && !verifyForm.requested_phone && !verifyForm.evidence_note) {
    toast.warning('กรุณากรอกรหัสลูกค้า เบอร์โทร หรือรายละเอียดร้านค้าอย่างน้อย 1 ช่อง');
    return;
  }
  verifyBusy.value = true;
  try {
    await memberMutate('/verification-request', 'POST', { ...verifyForm });
    await refreshMemberSession();
    verifyForm.requested_customer_code = '';
    verifyForm.requested_member_code = '';
    verifyForm.requested_phone = '';
    verifyForm.evidence_note = '';
    toast.success('ส่งคำขอยืนยันร้านค้าแล้ว', 'ทีม Popstar จะตรวจสอบและอนุมัติสิทธิ์ให้คุณ');
  } catch (err) {
    toast.error('ส่งคำขอไม่สำเร็จ', err);
  } finally {
    verifyBusy.value = false;
  }
}
</script>
