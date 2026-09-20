import { Alert, Button, Skeleton, Empty } from 'antd'
import type { ReactNode } from 'react'
import { t } from '../i18n'

interface Props<T> {
  loading: boolean
  error: string | null
  data: T | undefined
  reload: () => void
  empty?: boolean
  emptyText?: string
  children: (data: T) => ReactNode
}

/** 加载中 / 出错可重试 / 空态 / 正常内容，四态统一处理 */
export default function Loadable<T>({
  loading, error, data, reload, empty, emptyText, children,
}: Props<T>) {
  if (loading && data === undefined) return <Skeleton active paragraph={{ rows: 4 }} />

  if (error) {
    return (
      <Alert
        type="error"
        showIcon
        message={t('loadFailed')}
        description={error}
        action={<Button size="small" onClick={reload}>{t('retry')}</Button>}
      />
    )
  }

  if (data === undefined) return null
  if (empty) return <Empty description={emptyText} />

  return <>{children(data)}</>
}
