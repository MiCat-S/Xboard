import { App as AntdApp, Alert, Button, Card, Input, Skeleton, Space, Steps, Tag, Typography, theme } from 'antd'
import { CopyOutlined, SendOutlined } from '@ant-design/icons'
import { api } from '../api'
import { useRequest } from '../hooks/useRequest'
import Loadable from '../components/Loadable'
import { copyText } from '../utils/format'
import { t } from '../i18n'

const { useToken } = theme

/**
 * 绑定动作本身发生在 Telegram 机器人里（给它发 /bind 订阅链接），
 * 面板这边没有绑定接口，只负责把机器人账号、命令和当前状态摆清楚。
 */
export default function Telegram() {
  const { token } = useToken()
  const { message } = AntdApp.useApp()

  const config = useRequest(() => api.userConfig())
  const info = useRequest(() => api.info())
  const subscribe = useRequest(() => api.subscribe())

  const enabled = config.data?.is_telegram === 1

  // 机器人没配置时 getMe 会抛原始的 cURL/TLS 错误，不能直接甩给用户；
  // 先用 is_telegram 门控，取不到就当成「暂时不可用」。
  const bot = useRequest(
    () => (enabled ? api.botInfo().catch(() => null) : Promise.resolve(null)),
    [enabled],
  )

  const bound = Boolean(info.data?.telegram_id)
  const command = subscribe.data ? `/bind ${subscribe.data.subscribe_url}` : ''

  const copy = async () => {
    const ok = await copyText(command)
    if (ok) message.success(t('copied'))
    else message.error(t('copyFailed'))
  }

  return (
    <Loadable {...config}>
      {() => {
        if (!enabled) {
          return <Alert type="info" showIcon message={t('telegramUnavailable')} />
        }

        if (bot.loading && !bot.data) {
          return <Skeleton active paragraph={{ rows: 4 }} />
        }

        const botInfo = bot.data

        if (!botInfo?.username) {
          return (
            <Alert
              type="warning"
              showIcon
              message={t('telegramBotUnreachable')}
              action={<Button size="small" onClick={bot.reload}>{t('retry')}</Button>}
            />
          )
        }

        const handle = `@${botInfo.username}`
        const botLink = `https://t.me/${botInfo.username}`

        return (
          <Card
            title={
              <Space size={token.marginXS}>
                {t('telegramTitle')}
                {bound ? (
                  <Tag color="success">{t('telegramBound')}</Tag>
                ) : (
                  <Tag>{t('telegramUnbound')}</Tag>
                )}
              </Space>
            }
            extra={
              <Button type="primary" icon={<SendOutlined />} href={botLink} target="_blank">
                {t('telegramOpenBot')}
              </Button>
            }
          >
            <Space direction="vertical" size={token.margin} style={{ width: '100%' }}>
              <Typography.Text type="secondary">{t('telegramHint')}</Typography.Text>

              <Steps
                direction="vertical"
                size="small"
                current={bound ? 3 : -1}
                items={[
                  { title: t('telegramStep1', { bot: handle }) },
                  {
                    title: t('telegramStep2'),
                    description: (
                      <Space.Compact style={{ width: '100%', marginTop: token.marginXXS }}>
                        <Input
                          readOnly
                          value={command}
                          className="break-all"
                          style={{ fontFamily: token.fontFamilyCode }}
                        />
                        <Button icon={<CopyOutlined />} onClick={copy} disabled={!command}>
                          {t('copy')}
                        </Button>
                      </Space.Compact>
                    ),
                  },
                  { title: t('telegramStep3') },
                ]}
              />

              {bound && (
                <Alert type="success" showIcon message={t('telegramUnbindHint')} />
              )}
            </Space>
          </Card>
        )
      }}
    </Loadable>
  )
}
