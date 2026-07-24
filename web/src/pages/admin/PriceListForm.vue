<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { priceListApi, type PriceListItem, type PriceListPayload, type ResolvedPriceListItem } from '@/api/priceList'
import { codebooksApi, type Currency, type Unit, type VatRate } from '@/api/codebooks'
import { clientsApi, type Client } from '@/api/clients'
import { settingsApi } from '@/api/settings'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()

const itemId = computed(() => Number(route.params.id ?? 0))
const isEdit = computed(() => itemId.value > 0)
const item = ref<PriceListItem | null>(null)
const currencies = ref<Currency[]>([])
const units = ref<Unit[]>([])
const vatRates = ref<VatRate[]>([])
const supplierDefaultCurrencyCode = ref('')
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const form = ref<PriceListPayload>(blankForm())
const newPriceCurrency = ref('')

const overrideClientId = ref<number | null>(null)
const overrideCurrency = ref('')
const overridePrice = ref<number | null>(null)
const clientOptions = ref<{ value: number; label: string; secondary?: string }[]>([])
const clientsLoading = ref(false)
const selectedClientOption = ref<{ value: number; label: string; secondary?: string } | null>(null)

const previewCurrency = ref('')
const preview = ref<ResolvedPriceListItem | null>(null)
const previewLoading = ref(false)

const currencyOptions = computed(() => {
  const byCode = new Map<string, Currency>()
  for (const currency of currencies.value) {
    if (currency.is_active && !byCode.has(currency.code)) byCode.set(currency.code, currency)
  }
  return Array.from(byCode.values()).sort((a, b) => a.code.localeCompare(b.code))
})

const availablePriceCurrencies = computed(() => currencyOptions.value.filter(
  currency => !form.value.prices.some(price => price.currency_code === currency.code),
))

const currentPolicyUsage = computed(() => item.value?.usage
  .filter(row => row.catalog_policy === 'current')
  .reduce((sum, row) => sum + row.count, 0) ?? 0)

function blankForm(): PriceListPayload {
  return {
    code: '',
    name: '',
    description: '',
    unit: '',
    vat_rate_id: 0,
    prices_include_vat: false,
    base_currency_code: '',
    allow_exchange_rate_conversion: false,
    archived: false,
    prices: [],
  }
}

function ensureBasePrice() {
  const code = form.value.base_currency_code
  if (!code) return
  const existing = form.value.prices.find(price => price.currency_code === code)
  if (existing) {
    existing.archived = false
    return
  }
  form.value.prices.unshift({ currency_code: code, unit_price: 0, archived: false })
}

watch(() => form.value.base_currency_code, ensureBasePrice)

function initializeNew() {
  const defaultCurrency = currencyOptions.value.find(
    currency => currency.code === supplierDefaultCurrencyCode.value,
  ) ?? currencyOptions.value[0]
  const defaultUnit = units.value.find(unit => unit.is_default) ?? units.value[0]
  const defaultVat = vatRates.value.find(rate => rate.is_default) ?? vatRates.value[0]
  form.value = {
    ...blankForm(),
    unit: defaultUnit?.code ?? 'ks',
    vat_rate_id: defaultVat?.id ?? 0,
    base_currency_code: defaultCurrency?.code ?? '',
  }
  ensureBasePrice()
  resetOverrideForm()
}

function fillForm(detail: PriceListItem) {
  item.value = detail
  form.value = {
    code: detail.code,
    name: detail.name,
    description: detail.description,
    unit: detail.unit,
    vat_rate_id: detail.vat_rate_id,
    prices_include_vat: detail.prices_include_vat,
    base_currency_code: detail.base_currency_code,
    allow_exchange_rate_conversion: detail.allow_exchange_rate_conversion,
    archived: detail.archived,
    prices: detail.prices.map(price => ({
      currency_code: price.currency_code,
      unit_price: Number(price.unit_price),
      archived: price.archived,
    })),
  }
  previewCurrency.value = detail.base_currency_code
  resetOverrideForm()
}

function addPrice() {
  if (!newPriceCurrency.value) return
  form.value.prices.push({ currency_code: newPriceCurrency.value, unit_price: 0, archived: false })
  newPriceCurrency.value = ''
}

function removePrice(index: number) {
  if (form.value.prices[index].currency_code === form.value.base_currency_code) return
  form.value.prices.splice(index, 1)
}

async function save() {
  saving.value = true
  error.value = ''
  try {
    if (isEdit.value) await priceListApi.update(itemId.value, form.value)
    else await priceListApi.create(form.value)
    toast.success(t('price_list.saved'))
    await router.push({ name: 'admin-price-list' })
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}

function cancel() {
  router.push({ name: 'admin-price-list' })
}

async function onClientSearch(value: string) {
  clientsLoading.value = true
  try {
    const result = await clientsApi.list({ q: value || undefined, role: 'customers', per_page: 50 })
    clientOptions.value = result.data.map((client: Client) => ({
      value: client.id,
      label: client.company_name,
      secondary: client.ic ?? undefined,
    }))
  } finally {
    clientsLoading.value = false
  }
}

function resetOverrideForm() {
  overrideClientId.value = null
  overrideCurrency.value = form.value.base_currency_code
  overridePrice.value = null
  selectedClientOption.value = null
  clientOptions.value = []
}

async function saveOverride() {
  if (!isEdit.value || !overrideClientId.value || !overrideCurrency.value || overridePrice.value === null) return
  try {
    const overrides = await priceListApi.upsertCustomerOverride(
      itemId.value,
      overrideClientId.value,
      overrideCurrency.value,
      overridePrice.value,
    )
    if (item.value) item.value.customer_overrides = overrides
    toast.success(t('price_list.override_saved'))
    resetOverrideForm()
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function removeOverride(clientId: number, currencyCode: string) {
  if (!isEdit.value || !window.confirm(t('price_list.override_delete_confirm'))) return
  try {
    await priceListApi.deleteCustomerOverride(itemId.value, clientId, currencyCode)
    if (item.value) {
      item.value.customer_overrides = (item.value.customer_overrides ?? []).filter(
        override => override.client_id !== clientId || override.currency_code !== currencyCode,
      )
    }
    toast.success(t('price_list.override_deleted'))
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function loadPreview() {
  if (!isEdit.value || !previewCurrency.value) return
  const currency = currencies.value.find(row => row.code === previewCurrency.value && row.is_active)
  if (!currency) return
  previewLoading.value = true
  preview.value = null
  try {
    preview.value = await priceListApi.resolve(itemId.value, {
      client_id: overrideClientId.value ?? undefined,
      currency_id: currency.id,
      rate_date: new Date().toISOString().slice(0, 10),
      prices_include_vat: form.value.prices_include_vat,
    })
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    previewLoading.value = false
  }
}

function priceSourceLabel(source: ResolvedPriceListItem['catalog_price_source']) {
  return t(`price_list.price_source.${source}`)
}

onMounted(async () => {
  try {
    const [loadedCurrencies, loadedUnits, loadedVatRates, supplier] = await Promise.all([
      codebooksApi.currencies(true),
      codebooksApi.units(),
      codebooksApi.vatRates('CZ'),
      settingsApi.getSupplier(),
    ])
    currencies.value = loadedCurrencies
    units.value = loadedUnits
    vatRates.value = loadedVatRates
    supplierDefaultCurrencyCode.value = supplier.default_currency

    if (isEdit.value) {
      const detail = await priceListApi.get(itemId.value)
      fillForm(detail)
    } else {
      initializeNew()
    }
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="w-full">
    <header class="mb-4">
      <button type="button" class="cursor-pointer text-sm text-neutral-600 hover:text-neutral-900" @click="cancel">{{ t('price_list.back_to_list') }}</button>
      <h1 class="mt-1 text-2xl font-semibold">{{ isEdit ? t('price_list.edit') : t('price_list.new') }}</h1>
      <p class="mt-1 text-sm text-neutral-500">{{ t('price_list.form_hint') }}</p>
    </header>

    <div v-if="error" class="mb-4 rounded-md border border-danger-300 bg-danger-50 px-4 py-3 text-sm text-danger-700">
      {{ error }}
    </div>
    <div v-if="loading" class="py-12 text-center text-neutral-400">…</div>

    <form v-else class="w-full border border-neutral-200 bg-surface shadow-sm rounded-lg" @submit.prevent="save">
      <div class="space-y-6 p-5">
        <div v-if="currentPolicyUsage > 0" class="rounded-md border border-warning-200 bg-warning-50 px-4 py-3 text-sm text-warning-700">
          {{ t('price_list.current_usage_warning', { count: currentPolicyUsage }) }}
        </div>

        <div class="grid gap-4 lg:grid-cols-4">
          <label class="block text-sm font-medium text-neutral-700">
            {{ t('price_list.code') }}
            <input v-model="form.code" type="text" required class="mt-1 w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface" />
          </label>
          <label class="block text-sm font-medium text-neutral-700 lg:col-span-2">
            {{ t('price_list.name') }}
            <input v-model="form.name" type="text" required class="mt-1 w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface" />
          </label>
          <label class="block text-sm font-medium text-neutral-700">
            {{ t('price_list.unit') }}
            <select v-model="form.unit" required class="mt-1 w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface">
              <option v-for="unit in units" :key="unit.id" :value="unit.code">{{ unit.code }} — {{ locale === 'en' ? unit.label_en : unit.label_cs }}</option>
            </select>
          </label>
          <label class="block text-sm font-medium text-neutral-700 lg:col-span-3">
            {{ t('price_list.description') }}
            <input v-model="form.description" type="text" required class="mt-1 w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface" />
          </label>
          <label class="block text-sm font-medium text-neutral-700">
            {{ t('price_list.vat_rate') }}
            <select v-model.number="form.vat_rate_id" required class="mt-1 w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface">
              <option v-for="rate in vatRates" :key="rate.id" :value="rate.id">{{ rate.rate_percent }} % — {{ locale === 'en' ? rate.label_en : rate.label_cs }}</option>
            </select>
          </label>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
          <label class="block text-sm font-medium text-neutral-700">
            {{ t('price_list.base_currency') }}
            <select v-model="form.base_currency_code" required class="mt-1 w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface">
              <option v-for="currency in currencyOptions" :key="currency.code" :value="currency.code">{{ currency.code }}</option>
            </select>
          </label>
          <label class="flex items-center gap-2 self-end h-10 text-sm text-neutral-700">
            <input v-model="form.prices_include_vat" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('price_list.prices_include_vat') }}
          </label>
          <label class="flex items-center gap-2 self-end h-10 text-sm text-neutral-700">
            <input v-model="form.allow_exchange_rate_conversion" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('price_list.allow_conversion') }}
          </label>
        </div>

        <div>
          <div class="mb-2 flex flex-wrap items-end justify-between gap-3">
            <div>
              <h2 class="text-sm font-semibold">{{ t('price_list.prices') }}</h2>
              <p class="text-xs text-neutral-500">{{ t('price_list.prices_hint') }}</p>
            </div>
            <div class="flex gap-2">
              <select v-model="newPriceCurrency" class="h-9 min-w-32 px-2 border border-neutral-300 rounded-md bg-surface text-sm">
                <option value="">{{ t('price_list.currency') }}</option>
                <option v-for="currency in availablePriceCurrencies" :key="currency.code" :value="currency.code">{{ currency.code }}</option>
              </select>
              <button type="button" class="cursor-pointer inline-flex items-center justify-center h-9 px-3 border border-neutral-300 bg-surface hover:bg-neutral-50 disabled:opacity-50 disabled:cursor-not-allowed text-sm font-medium rounded-md" :disabled="!newPriceCurrency" @click="addPrice">+ {{ t('common.add') }}</button>
            </div>
          </div>
          <div class="overflow-x-auto border-y border-neutral-200">
            <table class="w-full text-sm">
              <thead class="bg-neutral-50 text-xs text-neutral-500">
                <tr>
                  <th class="px-3 py-2 text-left">{{ t('price_list.currency') }}</th>
                  <th class="px-3 py-2 text-left">{{ t('price_list.unit_price') }}</th>
                  <th class="px-3 py-2 text-left">{{ t('price_list.price_kind') }}</th>
                  <th class="px-3 py-2"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-200">
                <tr v-for="(price, index) in form.prices" :key="price.currency_code">
                  <td class="px-3 py-2 font-mono">{{ price.currency_code }}</td>
                  <td class="px-3 py-2">
                    <input v-model.number="price.unit_price" type="number" min="0" step="0.01" required class="h-9 w-40 px-3 border border-neutral-300 rounded-md bg-surface text-right font-mono" />
                  </td>
                  <td class="px-3 py-2">
                    <span v-if="price.currency_code === form.base_currency_code" class="status-badge bg-primary-50 text-primary-700">{{ t('price_list.base_price') }}</span>
                    <span v-else class="status-badge bg-neutral-100 text-neutral-700">{{ t('price_list.explicit_price') }}</span>
                    <label v-if="price.currency_code !== form.base_currency_code" class="ml-3 inline-flex items-center gap-1 text-xs text-neutral-600">
                      <input v-model="price.archived" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
                      {{ t('price_list.price_archived') }}
                    </label>
                  </td>
                  <td class="px-3 py-2 text-right">
                    <button v-if="price.currency_code !== form.base_currency_code" type="button" class="cursor-pointer text-danger-600 hover:text-danger-700" @click="removePrice(index)">{{ t('common.remove') }}</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div v-if="isEdit" class="border-t border-neutral-200 pt-5">
          <h2 class="text-sm font-semibold">{{ t('price_list.preview_title') }}</h2>
          <p class="text-xs text-neutral-500">{{ t('price_list.preview_hint') }}</p>
          <div class="mt-3 flex flex-wrap items-end gap-2">
            <label class="block text-sm font-medium text-neutral-700">
              {{ t('price_list.currency') }}
              <select v-model="previewCurrency" class="mt-1 h-10 min-w-32 px-3 border border-neutral-300 rounded-md bg-surface">
                <option v-for="currency in currencyOptions" :key="currency.code" :value="currency.code">{{ currency.code }}</option>
              </select>
            </label>
            <button type="button" class="cursor-pointer inline-flex items-center justify-center h-10 px-3 border border-neutral-300 bg-surface hover:bg-neutral-50 disabled:opacity-50 disabled:cursor-not-allowed text-sm font-medium rounded-md" :disabled="previewLoading || !previewCurrency" @click="loadPreview">
              {{ previewLoading ? t('common.loading') : t('price_list.preview_action') }}
            </button>
          </div>
          <div v-if="preview" class="mt-3 flex flex-wrap items-center gap-3 text-sm">
            <span class="font-mono font-semibold">{{ preview.unit_price_without_vat.toFixed(2) }} {{ preview.target_currency_code }}</span>
            <span class="status-badge bg-success-50 text-success-700">{{ priceSourceLabel(preview.catalog_price_source) }}</span>
            <span v-if="preview.catalog_exchange_rate" class="text-neutral-500">
              {{ preview.catalog_source_unit_price }} {{ preview.catalog_source_currency_code }} · {{ preview.catalog_exchange_rate.toFixed(6) }} · {{ preview.catalog_exchange_rate_date }}
            </span>
          </div>
        </div>

        <div v-if="isEdit" class="border-t border-neutral-200 pt-5">
          <h2 class="text-sm font-semibold">{{ t('price_list.customer_prices') }}</h2>
          <p class="text-xs text-neutral-500">{{ t('price_list.customer_prices_hint') }}</p>
          <div class="mt-3 grid gap-2 lg:grid-cols-[minmax(260px,1fr)_120px_160px_auto]">
            <SearchableSelect v-model="overrideClientId" :options="clientOptions" :selected-option="selectedClientOption" :placeholder="t('price_list.customer')" :loading="clientsLoading" remote @search="onClientSearch" />
            <select v-model="overrideCurrency" class="h-10 px-3 border border-neutral-300 rounded-md bg-surface">
              <option v-for="currency in currencyOptions" :key="currency.code" :value="currency.code">{{ currency.code }}</option>
            </select>
            <input v-model.number="overridePrice" type="number" min="0" step="0.01" :placeholder="t('price_list.unit_price')" class="h-10 px-3 border border-neutral-300 rounded-md bg-surface text-right font-mono" />
            <button type="button" class="cursor-pointer inline-flex items-center justify-center h-10 px-3 border border-neutral-300 bg-surface hover:bg-neutral-50 disabled:opacity-50 disabled:cursor-not-allowed text-sm font-medium rounded-md" :disabled="!overrideClientId || overridePrice === null" @click="saveOverride">{{ t('common.add') }}</button>
          </div>
          <div v-if="item?.customer_overrides?.length" class="mt-3 overflow-x-auto border-y border-neutral-200">
            <table class="w-full text-sm">
              <tbody class="divide-y divide-neutral-200">
                <tr v-for="override in item.customer_overrides" :key="`${override.client_id}-${override.currency_code}`">
                  <td class="px-3 py-2">{{ override.client_name }}</td>
                  <td class="px-3 py-2 font-mono">{{ override.currency_code }}</td>
                  <td class="px-3 py-2 text-right font-mono">{{ override.unit_price.toFixed(2) }}</td>
                  <td class="px-3 py-2 text-xs text-neutral-500">
                    {{ override.affected_template_count ? t('price_list.affected_templates', { count: override.affected_template_count }) : '' }}
                  </td>
                  <td class="px-3 py-2 text-right">
                    <button type="button" class="cursor-pointer text-danger-600 hover:text-danger-700" @click="removeOverride(override.client_id, override.currency_code)">{{ t('common.remove') }}</button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <label v-if="isEdit" class="flex items-center gap-2 border-t border-neutral-200 pt-5 text-sm text-neutral-700">
          <input v-model="form.archived" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('price_list.archive_item') }}
        </label>

        <div class="flex justify-end gap-2 border-t border-neutral-200 pt-5">
          <button type="button" class="cursor-pointer inline-flex items-center justify-center h-9 px-3 border border-neutral-300 bg-surface hover:bg-neutral-50 text-sm font-medium rounded-md" @click="cancel">{{ t('common.cancel') }}</button>
          <button type="submit" class="cursor-pointer inline-flex items-center justify-center h-9 px-3 bg-primary-600 hover:bg-primary-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium rounded-md" :disabled="saving">{{ saving ? t('common.saving') : t('common.save') }}</button>
        </div>
      </div>
    </form>
  </div>
</template>
