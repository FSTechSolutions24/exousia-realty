import axios from 'axios'

export const api = axios.create({
  baseURL: '/api/v1',
  withCredentials: true,
  headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
})

api.interceptors.request.use((config) => {
  const companyId = localStorage.getItem('exousia_company_id')
  if (companyId) config.headers['X-Company-ID'] = companyId
  return config
})

export async function prepareCsrf() {
  await axios.get('/sanctum/csrf-cookie', { withCredentials: true })
}
