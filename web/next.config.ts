import type { NextConfig } from 'next'

const apiOrigin = (process.env.REPO_WATCH_API_ORIGIN || 'http://127.0.0.1:8012').replace(/\/$/, '')

const nextConfig: NextConfig = {
  reactStrictMode: true,
  async rewrites() {
    return [
      { source: '/api/:path*', destination: `${apiOrigin}/api/:path*` },
    ]
  },
}

export default nextConfig
