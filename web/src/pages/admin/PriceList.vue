<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { priceListApi, type PriceListItem } from '@/api/priceList'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { codebooksApi, type Currency } from '@/api/codebooks'

const { t } = useI18n()
const router = useRouter()
const toast = useToast()

const items = ref<PriceListItem[]>([])
const loading = ref(false)
const error = ref('')
const query = ref('')
const currency = ref('')
const status = ref<'active' | 'archived' | 'all'>('active')
const currencies = ref<Currency[]>([])

async function load() {
  loading.value = true
  error.value = ''
  try {
    const result = await priceListApi.list({
      q: query.value || undefined,
      currency: currency.value || undefined,
      include_archived: status.value !== 'active',
      per_page: 200,
    })
    items.value = status.value === 'archived'
      ? result.data.filter(item => item.archived)
      : result.data
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

function usageCount(item: PriceListItem) {
  return item.usage.reduce((sum, row) => sum + row.count, 0)
}

function createItem() {
  router.push({ name: 'admin-price-list-new' })
}

function editItem(item: PriceListItem) {
  router.push({ name: 'admin-price-list-edit', params: { id: item.id } })
}

async function removeItem(item: PriceListItem) {
  if (!window.confirm(t('price_list.delete_confirm', { name: item.name }))) return
  try {
    const result = await priceListApi.delete(item.id)
    toast.success(result.archived ? t('price_list.archived') : t('price_list.deleted'))
    await load()
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

onMounted(async () => {
  currencies.value = (await codebooksApi.currencies()).filter(row => row.is_active)
  await load()
})
</script>

<template>
  <div class="w-full">
    <header class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('price_list.title') }}</h1>
        <p class="mt-1 text-sm text-neutral-500">{{ t('price_list.subtitle') }}</p>
      </div>
      <button type="button" class="cursor-pointer inline-flex items-center justify-center h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md" @click="createItem">
        {{ t('price_list.new') }}
      </button>
    </header>

    <div v-if="error" class="mb-4 rounded-md border border-danger-300 bg-danger-50 px-4 py-3 text-sm text-danger-700">
      {{ error }}
    </div>

    <div class="mb-4 w-full border border-neutral-200 bg-surface p-3 shadow-sm rounded-lg">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex flex-1 gap-2">
          <input v-model="query" type="search" :placeholder="t('price_list.search')" class="h-9 w-full max-w-lg px-3 border border-neutral-300 rounded-md bg-surface text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" @keyup.enter="load" />
          <button type="button" class="cursor-pointer inline-flex items-center justify-center h-9 px-3 border border-neutral-300 bg-surface hover:bg-neutral-50 text-sm font-medium rounded-md" @click="load">{{ t('common.search') }}</button>
        </div>
        <div class="flex flex-wrap gap-2">
          <select v-model="currency" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm" @change="load">
            <option value="">{{ t('price_list.all_currencies') }}</option>
            <option v-for="row in currencies" :key="row.code" :value="row.code">{{ row.code }}</option>
          </select>
          <select v-model="status" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm" @change="load">
            <option value="active">{{ t('price_list.active') }}</option>
            <option value="archived">{{ t('price_list.inactive') }}</option>
            <option value="all">{{ t('price_list.all_statuses') }}</option>
          </select>
        </div>
      </div>
    </div>

    <div v-if="loading" class="py-12 text-center text-neutral-400">…</div>
    <div v-else-if="items.length === 0" class="border border-dashed border-neutral-300 bg-surface p-8 text-center shadow-sm rounded-lg">
      <p class="mb-4 text-neutral-500">{{ t('price_list.empty') }}</p>
      <button type="button" class="cursor-pointer inline-flex items-center justify-center h-10 px-4 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md" @click="createItem">
        {{ t('price_list.new') }}
      </button>
    </div>

    <section v-else class="w-full overflow-hidden border border-neutral-200 bg-surface shadow-sm rounded-lg">
      <div class="hidden overflow-x-auto md:block">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
            <tr>
              <th class="px-4 py-2.5 text-left font-medium">{{ t('price_list.code') }}</th>
              <th class="px-4 py-2.5 text-left font-medium">{{ t('price_list.name') }}</th>
              <th class="px-4 py-2.5 text-left font-medium">{{ t('price_list.unit') }}</th>
              <th class="px-4 py-2.5 text-left font-medium">{{ t('price_list.prices') }}</th>
              <th class="px-4 py-2.5 text-left font-medium">{{ t('price_list.vat_rate') }}</th>
              <th class="px-4 py-2.5 text-center font-medium">{{ t('price_list.usage') }}</th>
              <th class="px-4 py-2.5 text-center font-medium">{{ t('price_list.status') }}</th>
              <th class="px-4 py-2.5"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="item in items" :key="item.id" class="hover:bg-neutral-50/50">
              <td class="px-4 py-3 font-mono">{{ item.code }}</td>
              <td class="px-4 py-3">
                <div class="font-medium">{{ item.name }}</div>
                <div class="max-w-xl truncate text-xs text-neutral-500">{{ item.description }}</div>
              </td>
              <td class="px-4 py-3">{{ item.unit }}</td>
              <td class="px-4 py-3">
                <div class="flex flex-wrap gap-1">
                  <span v-for="price in item.prices.filter(row => !row.archived)" :key="price.currency_code" class="status-badge bg-neutral-100 text-neutral-700 font-mono">
                    {{ price.currency_code }} {{ Number(price.unit_price).toFixed(2) }}
                  </span>
                  <span v-if="item.allow_exchange_rate_conversion" class="status-badge bg-primary-50 text-primary-700">{{ t('price_list.conversion_badge') }}</span>
                </div>
              </td>
              <td class="px-4 py-3">{{ item.vat_rate_percent }} %</td>
              <td class="px-4 py-3 text-center font-mono">{{ usageCount(item) }}</td>
              <td class="px-4 py-3 text-center">
                <span :class="item.archived ? 'status-badge bg-neutral-100 text-neutral-600' : 'status-badge bg-success-50 text-success-700'">
                  {{ item.archived ? t('price_list.inactive') : t('price_list.active') }}
                </span>
              </td>
              <td class="px-4 py-3 text-right whitespace-nowrap">
                <button type="button" class="cursor-pointer text-primary-700 hover:text-primary-800" @click="editItem(item)">{{ t('common.edit') }}</button>
                <button type="button" class="cursor-pointer ml-3 text-danger-600 hover:text-danger-700" @click="removeItem(item)">{{ t('common.remove') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="divide-y divide-neutral-100 md:hidden">
        <article v-for="item in items" :key="`mobile-${item.id}`" class="space-y-3 p-4">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <span class="font-mono text-xs text-neutral-500">{{ item.code }}</span>
                <span :class="item.archived ? 'status-badge bg-neutral-100 text-neutral-600' : 'status-badge bg-success-50 text-success-700'">
                  {{ item.archived ? t('price_list.inactive') : t('price_list.active') }}
                </span>
              </div>
              <h2 class="mt-1 font-medium text-neutral-900">{{ item.name }}</h2>
              <p class="mt-0.5 text-xs text-neutral-500">{{ item.description }}</p>
            </div>
            <span class="shrink-0 text-sm text-neutral-600">{{ item.vat_rate_percent }} %</span>
          </div>
          <div class="flex flex-wrap gap-1">
            <span v-for="price in item.prices.filter(row => !row.archived)" :key="price.currency_code" class="status-badge bg-neutral-100 text-neutral-700 font-mono">
              {{ price.currency_code }} {{ Number(price.unit_price).toFixed(2) }}
            </span>
            <span v-if="item.allow_exchange_rate_conversion" class="status-badge bg-primary-50 text-primary-700">{{ t('price_list.conversion_badge') }}</span>
          </div>
          <div class="flex items-center justify-between border-t border-neutral-100 pt-3 text-sm">
            <span class="text-neutral-500">{{ t('price_list.usage') }}: <span class="font-mono text-neutral-700">{{ usageCount(item) }}</span></span>
            <div class="flex items-center gap-3">
              <button type="button" class="cursor-pointer text-primary-700 hover:text-primary-800" @click="editItem(item)">{{ t('common.edit') }}</button>
              <button type="button" class="cursor-pointer text-danger-600 hover:text-danger-700" @click="removeItem(item)">{{ t('common.remove') }}</button>
            </div>
          </div>
        </article>
      </div>
    </section>
  </div>
</template>
