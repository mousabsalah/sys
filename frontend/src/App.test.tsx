import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import App from './App'

const BASE = 'http://127.0.0.1:8765'
const kpis = {
  halls_registered: 20,
  lectures_this_term: 16,
  bookings_today: 1,
  exams_this_week: 3,
  batches_count: 8,
  courses_count: 30,
}

const adminUser = {
  id: 1,
  name: 'مدير النظام',
  username: 'مدير_النظام',
  role: 'admin',
  faculty_id: null,
  batch_id: null,
}

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

function installFetchMock(options: { loginFails?: boolean } = {}) {
  const mock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const url = String(input)
    const method = (init?.method ?? 'GET').toUpperCase()

    if (url === `${BASE}/api/login` && method === 'POST') {
      const body = JSON.parse(String(init?.body ?? '{}'))
      if (options.loginFails || body.password !== 'Admin@123456') {
        return jsonResponse({ message_ar: 'بيانات الدخول غير صحيحة' }, 401)
      }
      return jsonResponse({ token: 'tok-test-123', user: adminUser })
    }
    if (url.startsWith(`${BASE}/api/dashboard`)) {
      return jsonResponse({ kpis, latest_bookings: [] })
    }
    if (url.startsWith(`${BASE}/api/timetables`)) {
      return jsonResponse([])
    }
    if (url.startsWith(`${BASE}/api/exams`)) {
      return jsonResponse([])
    }
    if (url.startsWith(`${BASE}/api/bookings`)) {
      return jsonResponse([])
    }
    if (url.startsWith(`${BASE}/api/reports/utilization`)) {
      return jsonResponse({ utilization: [] })
    }
    if (url.startsWith(`${BASE}/api/admin/master-data`)) {
      return jsonResponse({
        faculties: [], departments: [], programs: [], periods: [], batches: [],
        groups: [], halls: [], courses: [], faculty_members: [], course_assignments: [],
      })
    }
    return jsonResponse({}, 404)
  })
  vi.stubGlobal('fetch', mock)
  return mock
}

describe('SYST App', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('renders the AUST login screen', () => {
    installFetchMock()
    render(<App />)
    expect(screen.getByRole('heading', { name: /نظام إدارة القاعات/ })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'دخول' })).toBeInTheDocument()
    expect(screen.getByLabelText('اسم المستخدم')).toBeInTheDocument()
    expect(screen.getByLabelText('كلمة المرور')).toBeInTheDocument()
  })

  it('logs in and shows the dashboard with live KPIs', async () => {
    installFetchMock()
    render(<App />)
    fireEvent.change(screen.getByLabelText('اسم المستخدم'), { target: { value: 'مدير_النظام' } })
    fireEvent.change(screen.getByLabelText('كلمة المرور'), { target: { value: 'Admin@123456' } })
    fireEvent.click(screen.getByRole('button', { name: 'دخول' }))

    await waitFor(() => expect(screen.getByRole('heading', { name: 'لوحة التحكم' })).toBeInTheDocument())
    expect(screen.getByText('القاعات المسجلة')).toBeInTheDocument()
    expect(screen.getByText('20')).toBeInTheDocument()
    expect(screen.getByText('16')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'تسجيل الخروج' })).toBeInTheDocument()
  })

  it('shows an error alert when login fails', async () => {
    installFetchMock({ loginFails: true })
    render(<App />)
    fireEvent.change(screen.getByLabelText('كلمة المرور'), { target: { value: 'wrong-password' } })
    fireEvent.click(screen.getByRole('button', { name: 'دخول' }))

    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('بيانات الدخول غير صحيحة'))
  })

  it('logs out back to the login screen', async () => {
    installFetchMock()
    render(<App />)
    fireEvent.click(screen.getByRole('button', { name: 'دخول' }))
    await waitFor(() => expect(screen.getByRole('heading', { name: 'لوحة التحكم' })).toBeInTheDocument())
    fireEvent.click(screen.getByRole('button', { name: 'تسجيل الخروج' }))
    await waitFor(() => expect(screen.getByRole('button', { name: 'دخول' })).toBeInTheDocument())
  })
})
