'use client'

import { usePathname } from 'next/navigation'
import useAuthStore from '@/stores/authStore'

export function RepoWatchAuthGate({ children }: { children: React.ReactNode }) {
  const pathname = usePathname()
  const { beginLogin, isAuthenticated, loading } = useAuthStore()

  if (pathname.startsWith('/auth/callback')) {
    return <>{children}</>
  }

  if (loading) {
    return (
      <main className="flex min-h-dvh items-center justify-center p-6">
        <div className="text-center">
          <div className="border-primary mx-auto h-10 w-10 animate-spin rounded-full border-4 border-t-transparent" />
          <p className="text-muted-foreground mt-4 text-sm">正在恢复 Repo Watch 会话…</p>
        </div>
      </main>
    )
  }

  if (!isAuthenticated) {
    return (
      <main className="flex min-h-dvh items-center justify-center p-6">
        <div className="border-border bg-card w-full max-w-md rounded-xl border p-6 text-center shadow-lg">
          <h1 className="text-xl font-semibold">DogeOW Repo Watch</h1>
          <p className="text-muted-foreground mt-2 text-sm">
            使用 DogeOW 账号统一登录；登录完成后会自动返回 Repo Watch。
          </p>
          <button
            type="button"
            className="bg-primary text-primary-foreground mt-5 rounded-md px-4 py-2 font-medium"
            onClick={beginLogin}
          >
            前往 DogeOW 登录
          </button>
        </div>
      </main>
    )
  }

  return <>{children}</>
}
