'use client'

import { useEffect, useRef, useState } from 'react'
import useAuthStore from '@/stores/authStore'

export function SsoCallback({ ticket, returnTo }: { ticket: string; returnTo: string }) {
  const exchangeTicket = useAuthStore(state => state.exchangeTicket)
  const started = useRef(false)
  const [error, setError] = useState<string | null>(ticket ? null : '缺少登录票据')

  useEffect(() => {
    if (!ticket || started.current) return
    started.current = true
    void exchangeTicket(ticket)
      .then(() => {
        const target = new URL(returnTo, window.location.origin)
        window.location.replace(target.origin === window.location.origin ? target.href : '/')
      })
      .catch(reason => {
        setError(reason instanceof Error ? reason.message : '登录票据无效或已过期')
      })
  }, [exchangeTicket, returnTo, ticket])

  return (
    <main className="flex min-h-dvh items-center justify-center p-6">
      <div className="border-border bg-card w-full max-w-md rounded-xl border p-6 text-center shadow-lg">
        {error ? (
          <>
            <h1 className="text-xl font-semibold">无法完成登录</h1>
            <p className="text-muted-foreground mt-2 text-sm">{error}</p>
            <button
              type="button"
              className="bg-primary text-primary-foreground mt-5 rounded-md px-4 py-2"
              onClick={() => useAuthStore.getState().beginLogin()}
            >
              重新登录
            </button>
          </>
        ) : (
          <>
            <div className="border-primary mx-auto h-10 w-10 animate-spin rounded-full border-4 border-t-transparent" />
            <p className="mt-4">正在完成统一登录…</p>
          </>
        )}
      </div>
    </main>
  )
}
