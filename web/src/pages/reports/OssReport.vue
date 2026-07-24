<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { reportsApi, type OssPreview } from '@/api/reports'
import { apiErrorMessage } from '@/api/errors'
import { useYearOptions } from '@/composables/useYearOptions'

const { t, locale } = useI18n()

const now = new Date()
const year = ref(now.getFullYear())
const quarter = ref(Math.ceil((now.getMonth() + 1) / 3))
const preview = ref<OssPreview | null>(null)
const loading = ref(false)
const error = ref('')

const yearOptions = useYearOptions('invoices', year)
const quarterOptions = [1, 2, 3, 4]

async function loadPreview() {
  loading.value = true
  error.value = ''
  try {
    preview.value = await reportsApi.ossPreview(year.value, quarter.value)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

function downloadXml() {
  if (!preview.value) return
  window.open(reportsApi.ossDownloadUrl(year.value, quarter.value), '_blank')
}

function fmtMoney(v: number, currency?: string): string {
  return new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'cs-CZ', {
    style: 'currency',
    currency: currency || preview.value?.summary.return_currency || 'EUR',
  }).format(Number(v) || 0)
}

function fmtDate(iso: string | null | undefined): string {
  if (!iso) return ''
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  return d.toLocaleDateString(locale.value === 'en' ? 'en-US' : 'cs-CZ')
}

const hasRows = computed(() => (preview.value?.summary.row_count ?? 0) > 0)

watch([year, quarter], loadPreview)
onMounted(loadPreview)
</script>

<template>
  <div class="max-w-full">
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('reports.oss.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('reports.oss.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2">
        <select v-model.number="quarter" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-for="q in quarterOptions" :key="q" :value="q">Q{{ q }}</option>
        </select>
        <select v-model.number="year" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
        </select>
        <button type="button" @click="downloadXml" :disabled="loading || !preview"
          class="cursor-pointer h-9 px-4 bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white text-sm font-medium rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
          {{ t('reports.oss.download_xml') }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-400">
      {{ t('common.loading') }}...
    </div>
    <div v-else-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm">
      {{ error }}
    </div>

    <div v-else-if="preview" class="space-y-4">
      <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium">{{ t('reports.oss.period') }}</div>
          <div class="text-lg font-semibold font-mono mt-1">{{ preview.period.label }}</div>
          <div class="text-xs text-neutral-500 mt-1">{{ fmtDate(preview.period.start) }} - {{ fmtDate(preview.period.end) }}</div>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium">{{ t('reports.oss.total_base') }}</div>
          <div class="text-lg font-semibold font-mono mt-1">{{ fmtMoney(preview.summary.total_base) }}</div>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium">{{ t('reports.oss.current_vat') }}</div>
          <div class="text-lg font-semibold font-mono mt-1">{{ fmtMoney(preview.summary.total_vat) }}</div>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium">{{ t('reports.oss.total_corrections') }}</div>
          <div class="text-lg font-semibold font-mono mt-1">{{ fmtMoney(preview.summary.total_corrections) }}</div>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium">{{ t('reports.oss.total_payable') }}</div>
          <div class="text-lg font-semibold font-mono mt-1">{{ fmtMoney(preview.summary.total_payable) }}</div>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium">{{ t('reports.oss.deadline') }}</div>
          <div class="text-lg font-semibold font-mono mt-1">{{ fmtDate(preview.period.submission_deadline) }}</div>
        </div>
      </div>

      <div v-if="preview.warnings.length" class="bg-warning-50 border border-warning-500/40 text-warning-700 rounded-md p-3 text-sm">
        <div class="font-semibold mb-1">{{ t('reports.oss.warnings') }}</div>
        <ul class="list-disc pl-5 space-y-1">
          <li v-for="w in preview.warnings" :key="w">{{ w }}</li>
        </ul>
      </div>

      <div v-if="!hasRows" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-500">
        {{ t('reports.oss.no_data') }}
      </div>

      <div v-for="country in preview.countries" :key="country.country" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 bg-neutral-50 flex items-center justify-between gap-3">
          <h3 class="text-sm font-semibold text-neutral-800">{{ country.country }}</h3>
          <div class="text-sm font-mono">
            {{ fmtMoney(country.base) }} / {{ fmtMoney(country.vat) }}
          </div>
        </header>
        <div class="overflow-x-auto">
          <table class="w-full text-xs">
            <thead class="bg-neutral-50 text-neutral-500">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('reports.oss.rate') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('reports.oss.rate_type') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('reports.oss.total_base') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('reports.oss.total_vat') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('reports.oss.rows') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="r in country.rates" :key="`${country.country}-${r.rate}-${r.rate_type}`">
                <td class="px-3 py-2 font-mono">{{ r.rate.toFixed(2) }} %</td>
                <td class="px-3 py-2">{{ r.rate_type || '-' }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ fmtMoney(r.base) }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ fmtMoney(r.vat) }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ r.count }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <details class="border-t border-neutral-200">
          <summary class="cursor-pointer px-5 py-3 text-sm text-primary-600">{{ t('reports.oss.detail_rows') }}</summary>
          <div class="overflow-x-auto">
            <table class="w-full text-xs">
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="row in country.rows" :key="row.item_id">
                  <td class="px-3 py-2 font-mono whitespace-nowrap">{{ fmtDate(row.tax_date) }}</td>
                  <td class="px-3 py-2 font-mono whitespace-nowrap">#{{ row.doc_number || row.invoice_id }}</td>
                  <td class="px-3 py-2">{{ row.client_name }}</td>
                  <td class="px-3 py-2">{{ row.description }}</td>
                  <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(row.base_return) }}</td>
                  <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(row.vat_return) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </details>
      </div>

      <section v-if="preview.corrections.length" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200 bg-neutral-50">
          <h2 class="text-sm font-semibold text-neutral-800">{{ t('reports.oss.corrections') }}</h2>
        </header>
        <div v-for="correction in preview.corrections" :key="`${correction.period}-${correction.state_consumption}`"
          class="border-b border-neutral-200 last:border-b-0">
          <div class="px-5 py-3 grid grid-cols-3 gap-3 text-sm">
            <div class="font-mono">Q{{ correction.quarter }} {{ correction.year }}</div>
            <div>{{ correction.state_consumption }}</div>
            <div class="font-mono text-right">{{ fmtMoney(correction.correction) }}</div>
          </div>
          <details class="border-t border-neutral-100">
            <summary class="cursor-pointer px-5 py-2 text-sm text-primary-600">
              {{ t('reports.oss.detail_rows') }} ({{ correction.count }})
            </summary>
            <div class="overflow-x-auto">
              <table class="w-full text-xs">
                <tbody class="divide-y divide-neutral-100">
                  <tr v-for="row in correction.rows" :key="row.item_id">
                    <td class="px-3 py-2 font-mono whitespace-nowrap">{{ fmtDate(row.tax_date) }}</td>
                    <td class="px-3 py-2 font-mono whitespace-nowrap">#{{ row.doc_number || row.invoice_id }}</td>
                    <td class="px-3 py-2">{{ row.client_name }}</td>
                    <td class="px-3 py-2">{{ row.description }}</td>
                    <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(row.vat_return) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </details>
        </div>
      </section>
    </div>
  </div>
</template>
