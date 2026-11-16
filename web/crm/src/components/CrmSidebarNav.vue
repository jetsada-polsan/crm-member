<template>
  <div class="navroot-flat">
    <button
      type="button"
      class="btn primary sidebar-visit-cta"
      @click="$emit('open-visit')"
    >
      <span aria-hidden="true">📸</span>
      <span>บันทึกเข้าพบ</span>
    </button>

    <nav class="navflat" aria-label="เมนูหลัก POPSTAR Connect">
      <div v-for="group in groups" :key="group.id" class="navflat-group">
        <div class="navheading">{{ group.title }}</div>
        <RouterLink
          v-for="item in group.items"
          :key="item.name"
          :to="item.path"
          class="navitem"
          active-class="active"
          @mouseenter="$emit('prefetch', item.name)"
          @focus="$emit('prefetch', item.name)"
          @click="$emit('navigate')"
        >
          <span class="ni" aria-hidden="true">{{ item.icon }}</span>
          <span class="navitem-label">{{ item.label }}</span>
          <span
            v-if="item.name === 'profile' && offlineDraftCount > 0"
            class="tag orange navbadge"
          >
            {{ offlineDraftCount }}
          </span>
        </RouterLink>
      </div>
    </nav>
  </div>
</template>

<script setup>
import { RouterLink } from 'vue-router';

defineProps({
  groups: { type: Array, required: true },
  activeRouteName: { type: String, default: '' },
  offlineDraftCount: { type: Number, default: 0 },
});

defineEmits(['prefetch', 'navigate', 'open-visit']);
</script>

<style scoped>
.navroot-flat {
  display: flex;
  flex-direction: column;
  gap: 14px;
  margin-bottom: 16px;
}

.sidebar-visit-cta {
  width: 100%;
  min-height: 48px;
  font-size: 15px;
  font-weight: 700;
}

.navflat {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.navflat-group + .navflat-group {
  margin-top: 6px;
}

.navitem-label {
  flex: 1;
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.navbadge {
  flex-shrink: 0;
}
</style>
