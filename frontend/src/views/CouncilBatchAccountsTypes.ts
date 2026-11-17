export type Account = { id: number; username: string; is_active: boolean; last_login_at: string | null }
export type GroupChip = { id: number; track: string; student_count: number }
export type BatchRow = {
  id: number
  name: string
  student_count: number
  program_id: number
  program: string | null
  department: string | null
  department_id: number
  groups: GroupChip[]
  account: Account | null
}
export type Master = {
  batches: BatchRow[]
  departments: { id: number; name: string }[]
  programs: { id: number; name: string }[]
}
export type CreateFormState = {
  name: string
  student_count: string
  دبلوم: boolean
  بكلاريوس: boolean
  program_id: string
  department_id: string
}
