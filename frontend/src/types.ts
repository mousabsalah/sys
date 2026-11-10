export type Role = 'admin' | 'dept' | 'guard' | 'batch'

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
  | 'reports'
  | 'admin'
  | 'admin-users'

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