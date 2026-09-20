// i18n 在模块加载时一次性读取语言（模块级 let current = detect()），
// 所以必须在任何业务模块被 import 之前固定住，否则测试会跟着
// jsdom 的 navigator.language 跑成英文。
localStorage.setItem('xboard_locale', 'zh-CN')

import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach, vi } from 'vitest'

// jsdom 没有这两个，antd 的响应式 hook 和 Table/Collapse 都会用到
if (!window.matchMedia) {
  window.matchMedia = vi.fn().mockImplementation((query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: vi.fn(),
    removeListener: vi.fn(),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
  }))
}

if (!window.ResizeObserver) {
  window.ResizeObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
  } as unknown as typeof ResizeObserver
}

// antd v5 在 React 18 下对 CSS-in-JS 有一堆无关紧要的告警，别淹没真正的报错
const originalError = console.error
console.error = (...args: unknown[]) => {
  const first = String(args[0] ?? '')
  if (first.includes('not wrapped in act') || first.includes('antd: compatible')) return
  originalError(...args)
}

afterEach(() => {
  cleanup()
  localStorage.clear()
  // 语言在 import 时已经定好，这里补回去只是为了让读 localStorage 的代码保持一致
  localStorage.setItem('xboard_locale', 'zh-CN')
})
