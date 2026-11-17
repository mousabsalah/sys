export type Degree = 'معيد' | 'محاضر' | 'أستاذ مساعد' | 'أستاذ مشارك' | 'أستاذ'

export type Lecturer = {
  id: number
  full_name: string
  degree: Degree | null
  department_id: number | null
  department: string | null
  email: string
  phone: string | null
  username: string | null
  account_active: boolean | null
  faculties: { id: number; name: string; code: string }[]
  courses: { id: number; code: string; name: string }[]
  courses_count: number
}

export type Master = {
  degrees: Degree[]
  faculties: { id: number; name: string; code: string }[]
  departments: { id: number; name: string; faculty_id: number }[]
  lecturers: Lecturer[]
  total: number
}

export const DEGREES: Degree[] = ['معيد', 'محاضر', 'أستاذ مساعد', 'أستاذ مشارك', 'أستاذ']

export const emptyForm = { full_name: '', degree: 'معيد' as Degree, email: '', phone: '', department_id: '', faculty_ids: [] as number[], username: '', password: '' }
export type LecturerFormState = typeof emptyForm
