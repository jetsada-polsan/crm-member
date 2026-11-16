<template>
  <div v-if="variant === 'kpis'" class="kpis" aria-hidden="true">
    <div v-for="i in 4" :key="i" class="card kpi">
      <div class="skel skel-sm" style="width:42px;height:42px;border-radius:12px" />
      <div class="skel skel-sm" style="width:65%;margin-top:14px" />
      <div class="skel skel-lg" style="width:48%;margin-top:10px" />
    </div>
  </div>
  <div v-else-if="variant === 'cards'" class="ui-skel-grid" aria-hidden="true">
    <div v-for="i in rows" :key="i" class="card panel-pad">
      <div class="skel skel-md" style="width:55%" />
      <div class="skel skel-sm" style="width:82%;margin-top:10px" />
      <div class="skel skel-sm" style="width:40%;margin-top:8px" />
    </div>
  </div>
  <div v-else class="card panel-pad" aria-hidden="true">
    <div v-for="i in rows" :key="i" class="ui-skel-row">
      <div class="skel skel-md" style="width:28%" />
      <div class="skel skel-sm" style="width:22%" />
      <div class="skel skel-sm" style="width:18%" />
      <div class="skel skel-sm" style="width:14%" />
    </div>
  </div>
</template>

<script setup>
defineProps({
  variant: { type: String, default: 'table' },
  rows: { type: Number, default: 6 },
});
</script>
