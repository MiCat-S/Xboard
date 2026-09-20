/**
 * 极简 i18n：够核心页面用，不引额外依赖。
 * 语言取 window.settings.i18n 与浏览器语言的交集，可用 localStorage 覆盖。
 */
import zhCN from './zh-CN'
import enUS from './en-US'

export type MessageKey = keyof typeof zhCN
export type Dict = Record<MessageKey, string>
export type Locale = 'zh-CN' | 'en-US'

const DICTS: Record<Locale, Dict> = { 'zh-CN': zhCN, 'en-US': enUS }
const STORAGE_KEY = 'xboard_locale'

function detect(): Locale {
  try {
    const saved = localStorage.getItem(STORAGE_KEY)
    if (saved === 'zh-CN' || saved === 'en-US') return saved
  } catch {
    // ignore
  }
  return navigator.language?.toLowerCase().startsWith('zh') ? 'zh-CN' : 'en-US'
}

let current: Locale = detect()

export function getLocale(): Locale {
  return current
}

export function setLocale(locale: Locale) {
  current = locale
  try {
    localStorage.setItem(STORAGE_KEY, locale)
  } catch {
    // ignore
  }
}

/** t('key', { count: 3 }) —— 占位符写成 :name */
export function t(key: MessageKey, params?: Record<string, string | number>): string {
  let text: string = DICTS[current][key] ?? DICTS['zh-CN'][key] ?? (key as string)
  if (params) {
    for (const [name, value] of Object.entries(params)) {
      text = text.replace(new RegExp(`:${name}\\b`, 'g'), String(value))
    }
  }
  return text
}
