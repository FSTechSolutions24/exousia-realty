import axios from 'axios'

export const api = axios.create({
  baseURL: '/api/v1',
  withCredentials: true,
  headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
})

api.interceptors.request.use((config) => {
  const companyId = localStorage.getItem('exousia_company_id')
  if (companyId) config.headers['X-Company-ID'] = companyId
  const branchId = localStorage.getItem('exousia_branch_id')
  if (branchId) config.headers['X-Branch-ID'] = branchId
  return config
})

api.interceptors.response.use((response) => response, (error) => {
  if (error.response?.status === 409 && error.response?.data?.code === 'invalid_branch' && error.config && !error.config.__branchRetried) {
    localStorage.removeItem('exousia_branch_id')
    error.config.__branchRetried = true
    delete error.config.headers?.['X-Branch-ID']
    return api.request(error.config)
  }
  if (error.response?.status === 402 && window.location.pathname !== '/billing') window.location.assign('/billing')
  if (error.response?.status === 403 && error.response?.data?.code === 'company_suspended' && window.location.pathname !== '/billing') window.location.assign('/billing')
  return Promise.reject(error)
})

export async function prepareCsrf() {
  await axios.get('/sanctum/csrf-cookie', { withCredentials: true })
}

export async function downloadExcel(path: string, params: Record<string, unknown> = {}, fallbackName = 'export.xlsx') {
  const response = await api.get(path, { params, responseType: 'blob', timeout: 120000 })
  const disposition = String(response.headers['content-disposition'] || '')
  const encoded = disposition.match(/filename\*=UTF-8''([^;]+)/i)?.[1]
  const quoted = disposition.match(/filename="([^"]+)"/i)?.[1]
  const filename = encoded ? decodeURIComponent(encoded) : quoted || fallbackName
  const url = URL.createObjectURL(response.data)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = filename
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
  window.setTimeout(() => URL.revokeObjectURL(url), 1000)
}
