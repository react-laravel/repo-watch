'use client'

import { useEffect } from 'react'
import { usePathname } from 'next/navigation'
import useAuthStore from '@/stores/authStore'

export function AuthBootstrap() {
  const restoreSession = useAuthStore(state => state.restoreSession)
  const pathname = usePathname()

  useEffect(() => {
    // The callback exchanges a one-time ticket and must be the only request
    // mutating the fresh game session during this route.
    if (pathname.startsWith('/auth/callback')) return
    void restoreSession()
  }, [pathname, restoreSession])

  return null
}
