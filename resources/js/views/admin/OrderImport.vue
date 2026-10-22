<template>
  <div class="max-w-5xl space-y-4">
    <div class="flex items-center gap-3">
      <RouterLink to="/admin/courses" class="btn-secondary">← Courses</RouterLink>
      <h1 class="text-xl font-bold">Importer des courses</h1>
    </div>
    <OrderImport />
  </div>
</template>

<script setup>
import OrderImport from '../../components/OrderImport.vue'
</script>
