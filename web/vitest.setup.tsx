import '@testing-library/jest-dom'
import { vi } from 'vitest'
import { renderHook, act } from '@testing-library/react'
import * as DomLibrary from '@testing-library/dom'
import Module from 'node:module'
import path from 'node:path'
import fs from 'node:fs'
import React from 'react'

// Make screen, fireEvent, waitFor available globally
const { screen, fireEvent, waitFor, within } = DomLibrary
Object.defineProperty(globalThis, 'screen', { value: screen })
Object.defineProperty(globalThis, 'fireEvent', { value: fireEvent })
Object.defineProperty(globalThis, 'waitFor', { value: waitFor })
Object.defineProperty(globalThis, 'within', { value: within })
Object.defineProperty(globalThis, 'renderHook', { value: renderHook })
Object.defineProperty(globalThis, 'act', { value: act })

// Jest compatibility shim
Object.defineProperty(globalThis, 'jest', {
  value: vi,
  configurable: true,
})

// Support legacy CommonJS require with "@/..." alias
const ModuleAny = Module as unknown as {
  _resolveFilename: (request: string, parent: unknown, isMain: boolean, options: unknown) => string
}
const originalResolveFilename = ModuleAny._resolveFilename
ModuleAny._resolveFilename = function (request, parent, isMain, options) {
  if (typeof request === 'string' && request.startsWith('@/')) {
    const mappedPath = path.resolve(process.cwd(), request.slice(2))
    const candidates = [
      mappedPath,
      `${mappedPath}.ts`,
      `${mappedPath}.tsx`,
      `${mappedPath}.js`,
      `${mappedPath}.jsx`,
      path.join(mappedPath, 'index.ts'),
      path.join(mappedPath, 'index.tsx'),
      path.join(mappedPath, 'index.js'),
      path.join(mappedPath, 'index.jsx'),
    ]
    const resolvedPath = candidates.find(candidate => fs.existsSync(candidate)) ?? mappedPath
    return originalResolveFilename.call(this, resolvedPath, parent, isMain, options)
  }
  return originalResolveFilename.call(this, request, parent, isMain, options)
}

// Mock Next.js router
const mockUseRouter = vi.fn(() => ({
  push: vi.fn(),
  replace: vi.fn(),
  prefetch: vi.fn(),
  back: vi.fn(),
  forward: vi.fn(),
  refresh: vi.fn(),
}))
const mockUseSearchParams = vi.fn(() => new URLSearchParams())
const mockUsePathname = vi.fn(() => '/')

vi.mock('next/navigation', () => ({
  useRouter: mockUseRouter,
  useSearchParams: mockUseSearchParams,
  usePathname: mockUsePathname,
}))

const mockNextFont = () => ({
  className: 'mock-font',
  style: { fontFamily: 'mock-font' },
  variable: '--font-mock',
})

vi.mock('next/font/google', () => ({
  Inter: mockNextFont,
  Long_Cang: mockNextFont,
}))

// Mock Pusher/Echo
vi.mock('laravel-echo', () => ({
  default: vi.fn().mockImplementation(() => ({
    channel: vi.fn().mockReturnThis(),
    private: vi.fn().mockReturnThis(),
    presence: vi.fn().mockReturnThis(),
    listen: vi.fn().mockReturnThis(),
    whisper: vi.fn().mockReturnThis(),
    leave: vi.fn().mockReturnThis(),
    disconnect: vi.fn(),
  })),
}))

vi.mock('pusher-js', () => ({
  default: vi.fn().mockImplementation(() => ({
    subscribe: vi.fn(),
    unsubscribe: vi.fn(),
    disconnect: vi.fn(),
  })),
}))

// Mock window.matchMedia
Object.defineProperty(window, 'matchMedia', {
  writable: true,
  value: vi.fn().mockImplementation(query => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: vi.fn(),
    removeListener: vi.fn(),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
  })),
})

// Mock IntersectionObserver
class MockIntersectionObserver {
  observe = vi.fn()
  unobserve = vi.fn()
  disconnect = vi.fn()
}
global.IntersectionObserver = MockIntersectionObserver as unknown as typeof IntersectionObserver

// Mock ResizeObserver
class MockResizeObserver {
  observe = vi.fn()
  unobserve = vi.fn()
  disconnect = vi.fn()
}
global.ResizeObserver = MockResizeObserver as unknown as typeof ResizeObserver

Object.defineProperty(window.HTMLElement.prototype, 'scrollIntoView', {
  value: vi.fn(),
  writable: true,
})

// Mock Notification API
Object.defineProperty(window, 'Notification', {
  writable: true,
  value: vi.fn().mockImplementation(() => ({
    permission: 'granted',
    requestPermission: vi.fn().mockResolvedValue('granted'),
  })),
})

// Mock Audio API
Object.defineProperty(window, 'Audio', {
  writable: true,
  value: vi.fn().mockImplementation(function Audio() {
    return {
      play: vi.fn().mockResolvedValue(undefined),
      pause: vi.fn(),
      currentTime: 0,
      duration: 100,
      volume: 1,
      muted: false,
    }
  }),
})

const createStorageMock = () => {
  let store: Record<string, string> = {}
  return {
    getItem: vi.fn((key: string) => (key in store ? store[key] : null)),
    setItem: vi.fn((key: string, value: string) => {
      store[key] = String(value)
    }),
    removeItem: vi.fn((key: string) => {
      delete store[key]
    }),
    clear: vi.fn(() => {
      store = {}
    }),
    key: vi.fn((index: number) => Object.keys(store)[index] ?? null),
    get length() {
      return Object.keys(store).length
    },
  }
}

const localStorageMock = createStorageMock()
const sessionStorageMock = createStorageMock()

Object.defineProperty(window, 'localStorage', {
  value: localStorageMock,
  configurable: true,
})
Object.defineProperty(globalThis, 'localStorage', {
  value: localStorageMock,
  configurable: true,
})
Object.defineProperty(window, 'sessionStorage', {
  value: sessionStorageMock,
  configurable: true,
})
Object.defineProperty(globalThis, 'sessionStorage', {
  value: sessionStorageMock,
  configurable: true,
})

// Helpers for lightweight Radix-style mocks used by jsdom tests.
const renderAsChild = (
  children: React.ReactNode,
  props: React.HTMLAttributes<HTMLElement> & { asChild?: boolean },
  fallback: keyof React.JSX.IntrinsicElements = 'button'
) => {
  const { asChild, ...rest } = props

  if (asChild && React.isValidElement(children)) {
    const child = children as React.ReactElement<Record<string, unknown>>
    return React.cloneElement(child, {
      ...rest,
      ...child.props,
      className: [rest.className, child.props.className].filter(Boolean).join(' ') || undefined,
    })
  }

  return React.createElement(fallback, rest, children)
}

const SheetMockContext = React.createContext<{
  open: boolean
  setOpen: (open: boolean) => void
}>({ open: false, setOpen: () => {} })

// Mock shadcn/ui primitives that use Radix browser behavior not needed in jsdom.

vi.mock('@/components/ui/badge', () => ({
  Badge: ({ children, ...props }: React.HTMLAttributes<HTMLSpanElement>) => (
    <span {...props}>{children}</span>
  ),
}))

vi.mock('@/components/ui/card', () => ({
  Card: ({ children, ...props }: React.HTMLAttributes<HTMLDivElement>) => (
    <div {...props}>{children}</div>
  ),
  CardContent: ({ children, ...props }: React.HTMLAttributes<HTMLDivElement>) => (
    <div {...props}>{children}</div>
  ),
  CardDescription: ({ children, ...props }: React.HTMLAttributes<HTMLParagraphElement>) => (
    <p {...props}>{children}</p>
  ),
  CardHeader: ({ children, ...props }: React.HTMLAttributes<HTMLDivElement>) => (
    <div {...props}>{children}</div>
  ),
  CardTitle: ({ children, ...props }: React.HTMLAttributes<HTMLHeadingElement>) => (
    <h3 {...props}>{children}</h3>
  ),
}))

vi.mock('@/components/ui/textarea', () => ({
  Textarea: (props: React.TextareaHTMLAttributes<HTMLTextAreaElement>) => <textarea {...props} />,
}))

vi.mock('@/components/ui/label', () => ({
  Label: ({ children, ...props }: React.LabelHTMLAttributes<HTMLLabelElement>) => (
    <label {...props}>{children}</label>
  ),
}))

vi.mock('@/components/ui/switch', () => ({
  Switch: ({
    checked,
    onCheckedChange,
    disabled,
    ...props
  }: React.InputHTMLAttributes<HTMLInputElement> & {
    onCheckedChange?: (checked: boolean) => void
  }) => (
    <input
      type="checkbox"
      checked={checked}
      onChange={e => onCheckedChange?.(e.target.checked)}
      disabled={disabled}
      {...props}
    />
  ),
}))

const SelectMockContext = React.createContext<{
  value?: string
  onValueChange?: (value: string) => void
} | null>(null)

vi.mock('@/components/ui/select', () => ({
  Select: ({
    children,
    value,
    onValueChange,
  }: {
    children: React.ReactNode
    value?: string
    onValueChange?: (value: string) => void
  }) => (
    <SelectMockContext.Provider value={{ value, onValueChange }}>
      <div>{children}</div>
    </SelectMockContext.Provider>
  ),
  SelectContent: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
  SelectGroup: ({ children }: { children: React.ReactNode }) => <div role="group">{children}</div>,
  SelectLabel: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
  SelectItem: ({ children, value }: { children: React.ReactNode; value: string }) => {
    const context = React.useContext(SelectMockContext)
    return (
      <div
        role="option"
        aria-selected={context?.value === value}
        data-value={value}
        onClick={() => context?.onValueChange?.(value)}
      >
        {children}
      </div>
    )
  },
  SelectTrigger: ({ children, ...props }: React.ButtonHTMLAttributes<HTMLButtonElement>) => (
    <button role="combobox" aria-controls="mock-select-options" aria-expanded={false} {...props}>
      {children}
    </button>
  ),
  SelectValue: ({ children }: { children?: React.ReactNode }) => <span>{children}</span>,
}))

vi.mock('@/components/ui/popover', () => ({
  Popover: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
  PopoverContent: ({ children, ...props }: React.HTMLAttributes<HTMLDivElement>) => (
    <div {...props}>{children}</div>
  ),
  PopoverTrigger: ({
    children,
    ...props
  }: React.HTMLAttributes<HTMLButtonElement> & { asChild?: boolean }) =>
    renderAsChild(children, props, 'button'),
}))

vi.mock('@/components/ui/tooltip', () => ({
  Tooltip: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
  TooltipContent: ({ children, ...props }: React.HTMLAttributes<HTMLDivElement>) => (
    <div {...props}>{children}</div>
  ),
  TooltipProvider: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
  TooltipTrigger: ({
    children,
    ...props
  }: React.HTMLAttributes<HTMLButtonElement> & { asChild?: boolean }) =>
    renderAsChild(children, props, 'button'),
}))

vi.mock('@/components/ui/sheet', () => ({
  Sheet: ({
    children,
    open,
    onOpenChange,
  }: {
    children: React.ReactNode
    open?: boolean
    onOpenChange?: (open: boolean) => void
  }) => {
    const [internalOpen, setInternalOpen] = React.useState(open ?? false)
    const currentOpen = open ?? internalOpen
    const setOpen = React.useCallback(
      (nextOpen: boolean) => {
        setInternalOpen(nextOpen)
        onOpenChange?.(nextOpen)
      },
      [onOpenChange]
    )

    React.useEffect(() => {
      if (!currentOpen) return

      const handleKeyDown = (event: KeyboardEvent) => {
        if (event.key === 'Escape') {
          setOpen(false)
        }
      }

      document.addEventListener('keydown', handleKeyDown)
      return () => document.removeEventListener('keydown', handleKeyDown)
    }, [currentOpen, setOpen])

    return (
      <SheetMockContext.Provider value={{ open: currentOpen, setOpen }}>
        <div data-open={currentOpen}>{children}</div>
      </SheetMockContext.Provider>
    )
  },
  SheetTrigger: ({
    children,
    ...props
  }: React.HTMLAttributes<HTMLButtonElement> & { asChild?: boolean }) => {
    const { setOpen } = React.useContext(SheetMockContext)
    const handleClick: React.MouseEventHandler<HTMLElement> = event => {
      props.onClick?.(event as React.MouseEvent<HTMLButtonElement>)
      setOpen(true)
    }

    return renderAsChild(children, { ...props, onClick: handleClick }, 'button')
  },
  SheetContent: ({
    children,
    side: _side,
    overlayClassName: _overlayClassName,
    ...props
  }: React.HTMLAttributes<HTMLDivElement> & { side?: string; overlayClassName?: string }) => {
    const { open } = React.useContext(SheetMockContext)
    return open ? (
      <div role="dialog" {...props}>
        {children}
      </div>
    ) : null
  },
  SheetHeader: ({ children, ...props }: React.HTMLAttributes<HTMLDivElement>) => (
    <div {...props}>{children}</div>
  ),
  SheetTitle: ({ children, ...props }: React.HTMLAttributes<HTMLHeadingElement>) => (
    <h2 {...props}>{children}</h2>
  ),
  SheetDescription: ({ children, ...props }: React.HTMLAttributes<HTMLParagraphElement>) => (
    <p {...props}>{children}</p>
  ),
}))

vi.mock('@/components/ui/slider', () => ({
  Slider: ({
    value,
    min,
    max,
    step,
    onValueChange,
    ...props
  }: React.InputHTMLAttributes<HTMLInputElement> & {
    value?: number[]
    onValueChange?: (value: number[]) => void
  }) => (
    <input
      {...props}
      type="range"
      role="slider"
      min={min}
      max={max}
      step={step}
      value={value?.[0] ?? 0}
      onChange={event => onValueChange?.([Number(event.currentTarget.value)])}
    />
  ),
}))

vi.mock('@/components/ui/scroll-area', () => ({
  ScrollArea: ({ children, className }: { children: React.ReactNode; className?: string }) => (
    <div data-testid="scroll-area" className={className}>
      {children}
    </div>
  ),
}))

vi.mock('@/components/ui/separator', () => ({
  Separator: (props: React.HTMLAttributes<HTMLHRElement>) => <hr {...props} />,
}))
