import type { Metadata, Viewport } from 'next'
import { Toaster } from 'sonner'
import { AuthBootstrap } from '@/components/AuthBootstrap'
import { RepoWatchAuthGate } from '@/components/RepoWatchAuthGate'
import './globals.css'

export const metadata: Metadata = {
  title: 'DogeOW Repo Watch',
  description: '监控 GitHub 仓库依赖版本更新',
}

export const viewport: Viewport = {
  width: 'device-width',
  initialScale: 1,
  viewportFit: 'cover',
  themeColor: '#0a0a0a',
}

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="zh-CN">
      <body>
        <AuthBootstrap />
        <RepoWatchAuthGate>{children}</RepoWatchAuthGate>
        <Toaster richColors position="top-center" />
      </body>
    </html>
  )
}
