/**
 * dashboard.blade.php 注入的站点配置。
 * 本地 vite dev 时 window.settings 不存在，用一份默认值兜底。
 */
export interface SiteSettings {
  title: string
  description: string
  logo: string
  version: string
  background_url: string
  theme: { color: string }
}

const injected = (window as unknown as { settings?: Partial<SiteSettings> }).settings ?? {}

export const siteSettings: SiteSettings = {
  title: injected.title || 'Xboard',
  description: injected.description || '',
  logo: injected.logo || '',
  version: injected.version || 'dev',
  background_url: injected.background_url || '',
  theme: { color: injected.theme?.color || 'default' },
}
