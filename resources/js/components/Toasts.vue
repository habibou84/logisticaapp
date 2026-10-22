<template>
  <div class="fixed top-3 right-3 left-3 sm:left-auto z-[60] flex flex-col gap-2 sm:w-96">
    <div
      v-for="toast in toasts.items"
      :key="toast.id"
      :class="['rounded-lg shadow-lg px-4 py-3 text-sm border cursor-pointer', classes[toast.type]]"
      role="status"
      @click="open(toast)"
    >
      <p v-if="toast.title" class="font-semibold">{{ toast.title }}</p>
      <p>{{ toast.message }}</p>
    </div>
  </div>
</template>

<script setup>
import { useRouter } from 'vue-router'
import { useToastStore } from '../stores/toasts'
import { useAuthStore } from '../stores/auth'

const toasts = useToastStore()
const auth = useAuthStore()
const router = useRouter()

const classes = {
  info: 'bg-white border-slate-200 text-slate-800',
  success: 'bg-emerald-50 border-emerald-200 text-emerald-800',
  error: 'bg-red-50 border-red-200 text-red-800',
}

function open(toast) {
  toasts.dismiss(toast.id)
  if (toast.href) router.push(toast.href)
  else if (toast.to) router.push(`${auth.homeRoute}/courses/${toast.to}`)
}
</script>
