import { SsoCallback } from './sso-callback'

export default async function AuthCallbackPage({
  searchParams,
}: {
  searchParams: Promise<{ ticket?: string; return_to?: string }>
}) {
  const { ticket, return_to: returnTo } = await searchParams
  return <SsoCallback ticket={ticket ?? ''} returnTo={returnTo ?? '/'} />
}
