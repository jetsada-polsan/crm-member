<template>
  <div>
    <div class="pagehead">
      <div>
        <div class="eyebrow">SECURITY &amp; ACCESS CONTROL</div>
        <h2>Settings &amp; Access Control Overview</h2>
        <p>ตรวจสอบสิทธิ์ผู้ใช้งาน CRM (Staff Roles), สถานะความปลอดภัยและการแยกฐานข้อมูล รวมถึง Audit Log ล่าสุด (ไม่แสดงรหัสผ่านหรือ Token ลับ)</p>
      </div>
      <div class="head-actions">
        <button type="button" class="btn" @click="refetch">⟳ รีเฟรช</button>
      </div>
    </div>

    <SkeletonBlock v-if="isLoading && !data" variant="cards" :rows="4" />
    <div v-else-if="isError" class="error">{{ error?.message || 'โหลดการตั้งค่าและ Audit Log ไม่สำเร็จ' }}</div>

    <template v-else-if="data">
      <!-- Security & Architecture Posture -->
      <div class="kpis" style="margin-bottom:16px">
        <div class="card kpi">
          <div class="kpi-label">ERP Mirror Policy</div>
          <div class="kpi-value" style="font-size:18px;color:var(--green)">
            {{ data.security_posture?.erp_mirror_readonly ? 'Read-Only Enforced' : 'Check Needed' }}
          </div>
          <div class="kpi-foot">CRM ไม่เขียนกลับฐาน Bplus ERP</div>
        </div>
        <div class="card kpi">
          <div class="kpi-label">Auth Guard Separation</div>
          <div class="kpi-value" style="font-size:18px;color:var(--blue)">
            {{ data.security_posture?.staff_member_auth_separated ? 'Staff ≠ Member' : 'Shared' }}
          </div>
          <div class="kpi-foot">Session และ Cookie แยกอิสระ</div>
        </div>
        <div class="card kpi">
          <div class="kpi-label">Member Phone Safety</div>
          <div class="kpi-value" style="font-size:18px;color:var(--teal)">
            {{ data.security_posture?.phone_only_never_auto_verifies ? 'Manual Verify Only' : 'Auto' }}
          </div>
          <div class="kpi-foot">เบอร์ตรงกันอย่างเดียวไม่อนุมัติอัตโนมัติ</div>
        </div>
        <div class="card kpi">
          <div class="kpi-label">Bridge / Sync / LINE Config</div>
          <div class="kpi-value" style="font-size:15px">
            Bridge: {{ data.security_posture?.bridge_url_configured ? 'OK' : '—' }} ·
            LINE: {{ data.security_posture?.line_channel_configured ? 'OK' : 'ยังไม่ตั้งค่า' }}
          </div>
          <div class="kpi-foot">กติกาแต้มใช้งาน {{ data.security_posture?.active_loyalty_rules ?? 0 }} / {{ data.security_posture?.total_loyalty_rules ?? 0 }}</div>
        </div>
      </div>

      <div class="split">
        <!-- Staff Access Control -->
        <div class="card panel-pad">
          <h3 style="margin:0 0 10px">ผู้ใช้งานระบบ CRM ({{ (data.staff_users || []).length }})</h3>
          <div class="table-wrap desktop-table-only">
            <table class="table">
              <thead>
                <tr>
                  <th>ชื่อ</th>
                  <th>อีเมล</th>
                  <th>Role</th>
                  <th>รหัสพนักงานขาย</th>
                  <th>สถานะ</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="u in data.staff_users || []" :key="u.id">
                  <td><b>{{ u.name }}</b></td>
                  <td>{{ u.email }}</td>
                  <td><span class="tag" :class="u.role === 'admin' ? 'purple' : u.role === 'manager' ? 'blue' : 'gray'">{{ u.role }}</span></td>
                  <td>{{ u.salesperson_code || '—' }}</td>
                  <td><span class="tag" :class="u.is_active ? 'green' : 'red'">{{ u.is_active ? 'Active' : 'Inactive' }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="mob-cards">
            <div v-for="u in data.staff_users || []" :key="`um-${u.id}`" class="card mob-card">
              <div style="display:flex;justify-content:space-between;align-items:center">
                <strong>{{ u.name }}</strong>
                <span class="tag blue">{{ u.role }}</span>
              </div>
              <div class="small muted">{{ u.email }} · {{ u.salesperson_code || 'No SL Code' }}</div>
            </div>
          </div>
        </div>

        <!-- Audit Logs -->
        <div class="card panel-pad">
          <h3 style="margin:0 0 10px">CRM Audit Logs ล่าสุด ({{ (data.audit_logs || []).length }})</h3>
          <div class="timeline">
            <div v-for="log in (data.audit_logs || []).slice(0, 20)" :key="log.id" class="timeline-item">
              <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
                <b>{{ log.entity_type }} #{{ log.entity_id }} · {{ log.action }}</b>
                <span class="tag gray">{{ log.actor_name || 'System' }}</span>
              </div>
              <div class="small muted">{{ log.created_at ? new Date(log.created_at).toLocaleString('th-TH') : '' }}</div>
              <div v-if="log.changes" class="small muted" style="word-break:break-word">{{ log.changes }}</div>
            </div>
            <div v-if="!(data.audit_logs || []).length" class="small muted">ยังไม่มีประวัติ Audit</div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { useQuery } from '@tanstack/vue-query';
import SkeletonBlock from '../../../shared/components/SkeletonBlock.vue';
import { crmGet } from '../composables/useCrmSession.js';

const { data, isLoading, isError, error, refetch } = useQuery({
  queryKey: ['crm', 'settings-overview'],
  queryFn: ({ signal }) => crmGet('/settings/overview', { signal }),
});
</script>
