import { api } from './client'

export type CatalogPolicy = 'fixed' | 'current' | 'review_required'
export type CatalogDescriptionSource = 'catalog' | 'template'

export interface PriceListPrice {
  id?: number
  price_list_item_id?: number
  currency_code: string
  unit_price: number
  archived: boolean
}

export interface PriceListUsage {
  currency_code: string
  catalog_policy: CatalogPolicy
  count: number
}

export interface PriceListCustomerOverride {
  id: number
  price_list_item_id: number
  client_id: number
  client_name: string
  currency_code: string
  unit_price: number
  affected_template_count: number
  created_at?: string
  updated_at?: string
}

export interface PriceListItem {
  id: number
  supplier_id: number
  code: string
  name: string
  description: string
  unit: string
  vat_rate_id: number
  vat_code?: string
  vat_rate_percent: number
  prices_include_vat: boolean
  base_currency_code: string
  base_unit_price: number
  allow_exchange_rate_conversion: boolean
  archived: boolean
  prices: PriceListPrice[]
  usage: PriceListUsage[]
  resolved_price?: ResolvedPriceListItem
  customer_overrides?: PriceListCustomerOverride[]
  created_at?: string
  updated_at?: string
}

export interface PriceListPayload {
  code: string
  name: string
  description: string
  unit: string
  vat_rate_id: number
  prices_include_vat: boolean
  base_currency_code: string
  allow_exchange_rate_conversion: boolean
  archived?: boolean
  prices: Array<{
    currency_code: string
    unit_price: number
    archived?: boolean
  }>
}

export interface ResolvedPriceListItem {
  price_list_item_id: number
  code: string
  name: string
  description: string
  unit: string
  vat_rate_id: number
  vat_rate_percent: number
  prices_include_vat: boolean
  unit_price_without_vat: number
  target_currency_code: string
  catalog_price_source: 'customer_explicit' | 'catalog_explicit' | 'customer_base_converted' | 'catalog_base_converted'
  catalog_source_currency_code: string
  catalog_source_unit_price: number
  catalog_exchange_rate: number | null
  catalog_exchange_rate_date: string | null
  catalog_rate_fallback_used: boolean
  catalog_rate_source: string | null
}

export interface PriceListResponse {
  data: PriceListItem[]
  meta: { total: number; page: number; per_page: number; pages: number }
}

export const priceListApi = {
  list: (params: { q?: string; currency?: string; client_id?: number; rate_date?: string; prices_include_vat?: boolean; include_archived?: boolean; page?: number; per_page?: number } = {}) =>
    api.get<PriceListResponse>('/price-list-items', { params: {
      ...params,
      prices_include_vat: params.prices_include_vat === undefined ? undefined : (params.prices_include_vat ? 1 : 0),
    } }).then(r => r.data),
  get: (id: number) => api.get<PriceListItem>(`/price-list-items/${id}`).then(r => r.data),
  create: (payload: PriceListPayload) => api.post<PriceListItem>('/price-list-items', payload).then(r => r.data),
  update: (id: number, payload: PriceListPayload) => api.put<PriceListItem>(`/price-list-items/${id}`, payload).then(r => r.data),
  delete: (id: number) => api.delete<{ deleted: boolean; archived: boolean }>(`/price-list-items/${id}`).then(r => r.data),
  resolve: (id: number, params: { client_id?: number; currency_id: number; rate_date: string; prices_include_vat: boolean }) =>
    api.get<ResolvedPriceListItem>(`/price-list-items/${id}/resolve`, { params: {
      ...params,
      prices_include_vat: params.prices_include_vat ? 1 : 0,
    } }).then(r => r.data),
  upsertPrice: (id: number, currencyCode: string, unitPrice: number) =>
    api.put<PriceListItem>(`/price-list-items/${id}/prices/${currencyCode}`, { unit_price: unitPrice }).then(r => r.data),
  deletePrice: (id: number, currencyCode: string) =>
    api.delete<{ deleted: boolean; archived: boolean }>(`/price-list-items/${id}/prices/${currencyCode}`).then(r => r.data),
  customerOverrides: (id: number) =>
    api.get<PriceListCustomerOverride[]>(`/price-list-items/${id}/customer-overrides`).then(r => r.data),
  upsertCustomerOverride: (id: number, clientId: number, currencyCode: string, unitPrice: number) =>
    api.put<PriceListCustomerOverride[]>(`/price-list-items/${id}/customer-overrides/${clientId}/${currencyCode}`, { unit_price: unitPrice }).then(r => r.data),
  deleteCustomerOverride: (id: number, clientId: number, currencyCode: string) =>
    api.delete<{ deleted: boolean }>(`/price-list-items/${id}/customer-overrides/${clientId}/${currencyCode}`).then(r => r.data),
}
