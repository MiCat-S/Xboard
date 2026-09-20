// i18n 在模块加载时一次性读取语言（模块级 let current = detect()），
// 所以必须在任何业务模块被 import 之前固定住，否则测试会跟着
// jsdom 的 navigator.language 跑成英文。
localStorage.setItem('xboard_locale', 'zh-CN')

import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach, vi } from 'vitest'

/**
 * jsdom 没有 matchMedia，而 antd 的 Grid.useBreakpoint 完全依赖它。
 * 如果一律返回 matches:false，布局会被判成移动端，侧栏压根不渲染——
 * 默认按桌面宽度回答，需要测移动端的用例自己调 setViewportWidth。
 */
export function setViewportWidth(width: number) {
  window.matchMedia = vi.fn().mockImplementation((query: string) => {
    const min = /min-width:\s*(\d+)px/.exec(query)
    const max = /max-width:\s*(\d+)px/.exec(query)

    let matches = true
    if (min) matches = matches && width >= Number(min[1])
    if (max) matches = matches && width <= Number(max[1])

    return {
      matches,
      media: query,
      onchange: null,
      addListener: vi.fn(),
      removeListener: vi.fn(),
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      dispatchEvent: vi.fn(),
    }
  })
}

export const DESKTOP_WIDTH = 1280
export const MOBILE_WIDTH = 375

setViewportWidth(DESKTOP_WIDTH)

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
  // antd 的 Modal/Drawer/Dropdown 走 portal，容器挂在 body 上，
  // cleanup() 不一定清得干净；残留节点会让 document.querySelector
  // 抓到上一个用例的陈旧元素。
  document.body.innerHTML = ''
  setViewportWidth(DESKTOP_WIDTH)
  // setLocale 改的是模块级变量，切过语言的用例会污染后面所有用例的文案。
  // 必须动态 import：在这个文件顶部静态导入 i18n，会让它早于上面那行
  // localStorage 设置就完成初始化，语言反而会跑成 en-US。
  void import('../i18n').then(({ setLocale }) => setLocale('zh-CN'))
  localStorage.clear()
  // 语言在 import 时已经定好，这里补回去只是为了让读 localStorage 的代码保持一致
  localStorage.setItem('xboard_locale', 'zh-CN')
})
