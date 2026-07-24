import { api } from './client'

export interface DphPriznaniLine {
  base: number
  vat: number
  count: number
  label: string
}

export interface DphPriznaniPreview {
  summary: {
    period: string
    period_type: 'monthly' | 'quarterly'
    quarter: number | null
    lines: Record<string, DphPriznaniLine>
    total_vat_output: number
    total_vat_input: number
    tax_due: number
    is_excess_deduction: boolean
    submission_deadline: string
    supplier_vat_period: string
  }
  warnings: string[]
}

export interface DphSettings {
  vat_period: 'monthly' | 'quarterly' | null
  is_vat_payer: boolean
  /** Identifikovaná osoba (§ 6g–6l, issue #94) — přiznání typu I, vždy měsíčně. */
  is_identified?: boolean
  taxpayer_type: 'fo' | 'po' | null
  has_financial_office: boolean
}

export interface KhPreview {
  summary: {
    period: string
    a1_count: number
    a2_count: number
    a4_count: number
    a5_count_aggregated: number
    b1_count: number
    b2_count: number
    b3_count_aggregated: number
    submission_deadline: string
  }
  warnings: string[]
}

export interface DphTrendRow {
  period: string
  vat_output: number
  vat_input: number
  vat_due: number
}

export interface DphDraftsPrediction {
  year: number
  month: number
  period: 'monthly' | 'quarterly'
  vat_output: number
  vat_input: number
  tax_due: number
  sale_count: number
  sale_draft_count: number
  purchase_count: number
  purchase_draft_count: number
}

export interface DphBookRow {
  invoice_id: number
  direction: 'issued' | 'received'
  doc_number: string | null
  original_doc_number: string | null
  tax_date: string | null
  accounting_date: string | null
  description: string
  counterparty_name: string
  counterparty_dic: string
  vat_classification_code: string | null
  vat_rate: number
  currency: string
  exchange_rate: number
  base: number
  vat: number
  total: number
  status: string
  is_draft: boolean
  kh_section: string | null
}

export interface DphBookSection {
  key: string
  direction: 'USKUTEČNĚNÁ' | 'PŘIJATÁ'
  label: string
  dphdp3_line: string
  vat_rate: number
  is_secondary: boolean
  rows: DphBookRow[]
  subtotal_base: number
  subtotal_vat: number
  subtotal_total: number
}

export interface DphBookPreview {
  period: {
    year: number
    month: number
    period_type: 'monthly' | 'quarterly'
    quarter: number | null
    start: string
    end: string
    label: string
  }
  supplier: Record<string, unknown>
  sections: DphBookSection[]
  totals: {
    issued: { base: number; vat: number; total: number }
    received: { base: number; vat: number; total: number }
    vat_balance: number
  }
}

export interface OssPreview {
  period: {
    year: number
    quarter: number
    start: string
    end: string
    label: string
    submission_deadline: string
  }
  settings: {
    oss_enabled: boolean
    oss_valid_from: string | null
    oss_valid_to: string | null
    oss_identification_country: string | null
    oss_return_currency: string
  }
  summary: {
    return_currency: string
    total_base: number
    total_vat: number
    total_corrections: number
    total_payable: number
    invoice_count: number
    row_count: number
    correction_row_count: number
    invalid_correction_count: number
    conversion_missing_count: number
  }
  countries: Array<{
    country: string
    base: number
    vat: number
    rates: Array<{
      rate: number
      rate_type: string | null
      base: number
      vat: number
      count: number
    }>
    rows: Array<{
      invoice_id: number
      item_id: number
      doc_number: string | null
      invoice_type: string
      tax_date: string | null
      client_name: string
      description: string
      currency: string
      base: number
      vat: number
      base_return: number
      vat_return: number
      vat_rate: number
      rate_type: string | null
      supply_type: string | null
    }>
  }>
  corrections: Array<{
    period: string
    year: number
    quarter: number
    state_consumption: string
    correction: number
    count: number
    rows: Array<{
      invoice_id: number
      item_id: number
      doc_number: string | null
      invoice_type: string
      tax_date: string | null
      client_name: string
      description: string
      currency: string
      base_return: number
      vat_return: number
      original_period: string
    }>
  }>
  warnings: string[]
}

export type MonthlyExportPart =
  | 'sales_pdf' | 'sales_isdoc' | 'purchase_pdf' | 'purchase_isdoc'
  | 'bank_pdf' | 'bank_gpc' | 'dph_book'

/** Období hromadného exportu — měsíc nebo celé čtvrtletí. */
export type ExportPeriodArg =
  | { type: 'monthly'; year: number; month: number }
  | { type: 'quarterly'; year: number; quarter: number }

export interface MonthlyExportPreview {
  period: string
  period_type: 'monthly' | 'quarterly'
  counts: Record<MonthlyExportPart, number>
}

export interface MonthlyExportJob {
  id: number
  status: 'queued' | 'running' | 'completed' | 'failed' | 'cancelled'
  total_items: number | null
  processed: number
  created_count: number
  failed_count: number
  current_step: string | null
  log_text?: string | null
  last_error: string | null
  cancel_requested: boolean
  result_name: string | null
  result_size: number | null
  params: Record<string, unknown> | null
  created_at: string
  finished_at: string | null
}

/** Query/body parametry období pro hromadný export (period + year + month|quarter). */
function monthlyExportPeriodParams(period: ExportPeriodArg): Record<string, string | number> {
  return period.type === 'quarterly'
    ? { period: 'quarterly', year: period.year, quarter: period.quarter }
    : { period: 'monthly', year: period.year, month: period.month }
}

export const reportsApi = {
  dphSettings: () =>
    api.get<DphSettings>('/reports/dphdp3/settings').then(r => r.data),

  dphPreview: (year: number, month: number, period?: 'monthly' | 'quarterly') =>
    api.get<DphPriznaniPreview>('/reports/dphdp3/preview', {
      params: { year, month, ...(period ? { period } : {}) },
    }).then(r => r.data),

  dphTrend: (months = 12) =>
    api.get<DphTrendRow[]>('/reports/dphdp3/trend', { params: { months } }).then(r => r.data),

  dphDraftsPrediction: (year: number, month: number, period?: 'monthly' | 'quarterly') =>
    api.get<DphDraftsPrediction>('/reports/dphdp3/drafts-prediction', {
      params: { year, month, ...(period ? { period } : {}) },
    }).then(r => r.data),

  khPreview: (year: number, month: number, period?: 'monthly' | 'quarterly') =>
    api.get<KhPreview>('/reports/dphkh1/preview', {
      params: { year, month, ...(period ? { period } : {}) },
    }).then(r => r.data),

  // Souhrnné hlášení (EU dodání) — plátci i identifikované osoby; lze kvartálně pro služby
  shvPreview: (year: number, month: number, period?: 'monthly' | 'quarterly') =>
    api.get<{
      summary: {
        period: string
        rows_count: number
        total_amount: number
        rows: Array<{
          country_iso2: string
          vat_id: string
          sh_type: '0' | '1' | '2' | '3'
          amount: number
          count: number
          counterparty_name: string
        }>
        submission_deadline: string
      }
      warnings: string[]
    }>('/reports/dphshv/preview', { params: { year, month, ...(period ? { period } : {}) } }).then(r => r.data),

  shvDownloadUrl: (year: number, month: number, period?: 'monthly' | 'quarterly') => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams({ year: String(year), month: String(month) })
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    if (period) params.set('period', period)
    return `/api/reports/dphshv?${params.toString()}`
  },

  incomeTaxPreview: (year: number, type: 'fo' | 'po') =>
    api.get<{
      summary: {
        year: number
        taxpayer_type: 'fo' | 'po'
        revenue_orientacni: number
        exempt_revenue_orientacni?: number
        costs_orientacni: number
        profit_orientacni: number
        submission_deadline: string
        currency: string
        is_vat_payer: boolean
      }
      warnings: string[]
    }>('/reports/income-tax/preview', { params: { year, type } }).then(r => r.data),

  incomeTaxDownloadUrl: (year: number, type: 'fo' | 'po') => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams({ year: String(year), type })
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    return `/api/reports/income-tax?${params.toString()}`
  },

  khDownloadUrl: (year: number, month: number, period?: 'monthly' | 'quarterly') => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams({ year: String(year), month: String(month) })
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    if (period) params.set('period', period)
    return `/api/reports/dphkh1?${params.toString()}`
  },

  // Kniha DPH (interní VAT žurnál — NE EPO podání)
  dphBookPreview: (year: number, month: number, period?: 'monthly' | 'quarterly') =>
    api.get<DphBookPreview>('/reports/dph-book/preview', {
      params: period ? { year, month, period } : { year, month },
    }).then(r => r.data),

  ossPreview: (year: number, quarter: number) =>
    api.get<OssPreview>('/reports/oss/preview', { params: { year, quarter } }).then(r => r.data),

  ossDownloadUrl: (year: number, quarter: number) => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams({ year: String(year), quarter: String(quarter) })
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    return `/api/reports/oss?${params.toString()}`
  },

  dphBookPdfUrl: (year: number, month: number, period?: 'monthly' | 'quarterly') => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams({ year: String(year), month: String(month) })
    if (period) params.set('period', period)
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    return `/api/reports/dph-book?${params.toString()}`
  },

  // Hromadný export — background job (počty per část pro UI checkboxy)
  monthlyExportPreview: (period: ExportPeriodArg) =>
    api.get<MonthlyExportPreview>('/reports/monthly-export/preview', { params: monthlyExportPeriodParams(period) })
      .then(r => r.data),

  /** Spustí export job na pozadí → vrátí job_id. */
  monthlyExportStart: (period: ExportPeriodArg, parts: string[]) =>
    api.post<{ job_id: number; status: string; params: Record<string, unknown> }>(
      '/reports/monthly-export/start', { ...monthlyExportPeriodParams(period), parts },
    ).then(r => r.data),

  /** Stav jobu (polling). */
  monthlyExportJob: (id: number) =>
    api.get<MonthlyExportJob>(`/reports/monthly-export/jobs/${id}`).then(r => r.data),

  /** Poslední exporty (historie — zůstávají ke stažení dokud nejsou smazané / uklizené). */
  monthlyExportJobs: () =>
    api.get<MonthlyExportJob[]>('/reports/monthly-export/jobs').then(r => r.data),

  monthlyExportCancel: (id: number) =>
    api.post<{ ok: boolean; cancel_requested: boolean }>(`/reports/monthly-export/jobs/${id}/cancel`).then(r => r.data),

  monthlyExportDeleteJob: (id: number) =>
    api.delete<{ ok: boolean; deleted: boolean }>(`/reports/monthly-export/jobs/${id}`).then(r => r.data),

  /** URL ke stažení hotového ZIPu — otevírá se přímou navigací (cookie auth + supplier_id query). */
  monthlyExportDownloadUrl: (id: number) => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams()
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    const qs = params.toString()
    return `/api/reports/monthly-export/jobs/${id}/download${qs ? `?${qs}` : ''}`
  },

  /** URL na download endpoint — frontend ho otevírá v novém okně */
  dphDownloadUrl: (year: number, month: number, period?: 'monthly' | 'quarterly') => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams({ year: String(year), month: String(month) })
    if (period) params.set('period', period)
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    return `/api/reports/dphdp3?${params.toString()}`
  },
}
