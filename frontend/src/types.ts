export type Role = 'admin' | 'council' | 'guard' | 'batch'

export type ViewName =
  | 'login'
  | 'dashboard'
  | 'timetables'
  | 'create-timetable'
  | 'propose'
  | 'exams'
  | 'create-exam'
  | 'bookings'
  | 'create-booking'
  | 'halls-available'
  | 'council-lecturers'
  | 'admin-approvals'
  | 'reports'
  | 'admin-users'
  | 'admin-faculties'
  | 'admin-departments'
  | 'admin-halls'
  | 'admin-semesters'
  | 'admin-archive'
  | 'admin-plans'

export interface AuthUser {
  id: number
  name: string
  username: string
  role: Role
  faculty_id: number | null
  batch_id: number | null
}

export interface LoginResponse {
  token: string
  user: AuthUser
}