import { api } from './client'

export interface Country {
  id: number
  iso2: string
  iso3: string
  name_cs: string
  name_en: string
  is_eu: boolean
}

export interface Currency {
  id: number
  code: string
  label: string
  symbol: string
  name_cs: string
  name_en: string
  decimals: number
  is_active: boolean
  is_default: boolean
}

export interface VatRate {
  id: number
  code: string
  rate_percent: number
  country: string
  label_cs: string
  label_en: string
  is_default: boolean
  is_reverse_charge: boolean
  valid_from: string
  valid_to?: string | null
  display_order: number
}

export interface Unit {
  id: number
  code: string
  label_cs: string
  label_en: string
  is_default: boolean
  display_order: number
}

export const codebooksApi = {
  countries:  () => api.get<Country[]>('/codebooks/countries').then((r) => r.data),
  currencies: (includeInactive = false) =>
    api.get<Currency[]>('/codebooks/currencies', {
      params: includeInactive ? { include_inactive: 1 } : undefined,
    }).then((r) => r.data),
  vatRates:   (country = 'CZ', activeOn?: string) =>
    api.get<VatRate[]>('/codebooks/vat-rates', {
      params: { country, ...(activeOn ? { active_on: activeOn } : {}) },
    }).then((r) => r.data),
  units:      () => api.get<Unit[]>('/codebooks/units').then((r) => r.data),
  years:      () =>
    api.get<{ invoices: number[]; purchase_invoices: number[]; combined: number[] }>('/codebooks/years')
      .then((r) => r.data),
}
