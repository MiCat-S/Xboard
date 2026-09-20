import { theme as antdTheme, type ThemeConfig } from 'antd'
import { siteSettings } from './settings'

/**
 * 主题完全通过 ConfigProvider + Design Token 定制，组件里不写死颜色和尺寸。
 *
 * 色板对应 theme/Nova/config.json 里「主题色」下拉的取值：管理员在后台选什么，
 * 这里就拿它当 seed token，由 antd 推导出整套色阶。
 */
const PRIMARY_BY_NAME: Record<string, string> = {
  default: '#2f6fed',
  green: '#12b76a',
  purple: '#7a5af8',
  black: '#1f2937',
}

export function resolvePrimaryColor(name: string | undefined): string {
  return PRIMARY_BY_NAME[name ?? 'default'] ?? PRIMARY_BY_NAME.default
}

export function buildThemeConfig(dark: boolean): ThemeConfig {
  const primary = resolvePrimaryColor(siteSettings.theme.color)

  return {
    algorithm: dark ? antdTheme.darkAlgorithm : antdTheme.defaultAlgorithm,
    token: {
      colorPrimary: primary,
      // colorLink 在 antd 里不跟随 colorPrimary，不显式指定的话
      // 换了主题色链接还是蓝的
      colorLink: primary,
      borderRadius: 8,
      // IP、UUID、订阅链接这类内容统一走等宽字体的 token
      fontFamilyCode: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
    },
    components: {
      // 侧栏和顶栏的尺寸/底色交给组件 token，布局代码里就不用再写 style
      Layout: {
        headerHeight: HEADER_HEIGHT,
        headerPadding: '0 16px',
        headerBg: 'transparent',
        siderBg: dark ? '#141414' : '#ffffff',
        bodyBg: dark ? '#000000' : '#f5f7fa',
      },
      Menu: {
        itemMarginInline: 8,
      },
    },
  }
}

export const HEADER_HEIGHT = 56

/** 布局尺寸里 antd 没有对应 token 的部分，集中在此，避免散落成魔法数字 */
export const layout = {
  siderWidth: 216,
  contentMaxWidth: 1080,
  headerHeight: HEADER_HEIGHT,
} as const
