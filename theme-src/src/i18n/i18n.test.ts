import { describe, expect, it } from 'vitest'
import enUS from './en-US'
import zhCN from './zh-CN'
import { getLocale, setLocale, t } from './index'

describe('dictionaries', () => {
  /** 两本字典必须同步，否则换语言时会漏字符串 */
  it('cover exactly the same keys', () => {
    const zhKeys = Object.keys(zhCN).sort()
    const enKeys = Object.keys(enUS).sort()

    expect(enKeys).toEqual(zhKeys)
  })

  it('has no empty translations', () => {
    for (const [key, value] of Object.entries(zhCN)) {
      expect(value, `zh-CN.${key}`).toBeTruthy()
    }
    for (const [key, value] of Object.entries(enUS)) {
      expect(value, `en-US.${key}`).toBeTruthy()
    }
  })

  /** 带 :name 占位符的条目，两种语言都得留着同样的占位符 */
  it('keeps the same placeholders in both languages', () => {
    const placeholders = (text: string) => (text.match(/:[a-zA-Z]+/g) ?? []).sort()

    for (const key of Object.keys(zhCN) as (keyof typeof zhCN)[]) {
      expect(placeholders(enUS[key]), `placeholders differ for "${key}"`)
        .toEqual(placeholders(zhCN[key]))
    }
  })
})

describe('t()', () => {
  it('returns the string for the current locale', () => {
    setLocale('zh-CN')
    expect(t('signIn')).toBe(zhCN.signIn)

    setLocale('en-US')
    expect(t('signIn')).toBe(enUS.signIn)

    setLocale('zh-CN')
  })

  it('substitutes placeholders', () => {
    setLocale('zh-CN')
    expect(t('daysLeft', { n: 7 })).toBe('剩余 7 天')
    expect(t('inUse', { n: 3 })).toBe('已用 3 台')
  })

  it('leaves no placeholder behind', () => {
    setLocale('zh-CN')
    expect(t('resetIn', { n: 11 })).toBe('11 天后重置')
    expect(t('capacityLeft', { n: 5 })).not.toContain(':n')
  })

  it('leaves the text alone when no params are given', () => {
    setLocale('zh-CN')
    expect(t('daysLeft')).toContain(':n')
  })

  it('remembers the chosen locale', () => {
    setLocale('en-US')
    expect(getLocale()).toBe('en-US')
    expect(localStorage.getItem('xboard_locale')).toBe('en-US')

    setLocale('zh-CN')
  })
})
