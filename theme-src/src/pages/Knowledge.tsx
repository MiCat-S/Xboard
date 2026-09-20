import { useState } from 'react'
import { Card, Collapse, Empty, Input, List, Modal, Skeleton, Space, Typography, theme } from 'antd'
import DOMPurify from 'dompurify'
import { api, type KnowledgeItem } from '../api'
import { useRequest } from '../hooks/useRequest'
import Loadable from '../components/Loadable'
import { formatDate } from '../utils/format'
import { getLocale, t } from '../i18n'

const { useToken } = theme

/**
 * 文档正文是后台填的富文本，还会被服务端替换 {{subscribeUrl}} 之类的占位符，
 * 必须按 HTML 渲染；和套餐介绍一样先过 DOMPurify。
 */
function ArticleBody({ html }: { html: string }) {
  return (
    <div
      className="knowledge-body"
      dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(html, { USE_PROFILES: { html: true } }) }}
    />
  )
}

export default function Knowledge() {
  const { token } = useToken()
  const [keyword, setKeyword] = useState('')
  const [search, setSearch] = useState('')
  const [openId, setOpenId] = useState<number | null>(null)

  // language 必须传，后端是严格相等匹配；这里跟随界面语言
  const list = useRequest(() => api.knowledge(getLocale(), search || undefined), [search])
  const article = useRequest(
    () => (openId === null ? Promise.resolve(null) : api.knowledgeArticle(openId)),
    [openId],
  )

  return (
    <>
      <Card
        title={t('navKnowledge')}
        extra={
          <Input.Search
            allowClear
            placeholder={t('knowledgeSearch')}
            value={keyword}
            onChange={(event) => setKeyword(event.target.value)}
            onSearch={setSearch}
            style={{ maxWidth: 240 }}
          />
        }
      >
        <Loadable {...list}>
          {(groups) => {
            const categories = Object.keys(groups)

            if (categories.length === 0) {
              return (
                <Empty
                  description={
                    <Space direction="vertical" size={token.marginXXS}>
                      <span>{t('knowledgeEmpty')}</span>
                      <Typography.Text type="secondary" style={{ fontSize: token.fontSizeSM }}>
                        {t('knowledgeEmptyHint')}
                      </Typography.Text>
                    </Space>
                  }
                />
              )
            }

            return (
              <Collapse
                defaultActiveKey={categories.slice(0, 1)}
                items={categories.map((category) => ({
                  key: category,
                  label: `${category} (${groups[category].length})`,
                  children: (
                    <List<KnowledgeItem>
                      dataSource={groups[category]}
                      renderItem={(item) => (
                        <List.Item
                          style={{ cursor: 'pointer' }}
                          onClick={() => setOpenId(item.id)}
                        >
                          <List.Item.Meta
                            title={<Typography.Link>{item.title}</Typography.Link>}
                            description={
                              <Typography.Text type="secondary" style={{ fontSize: token.fontSizeSM }}>
                                {t('knowledgeUpdatedAt')} {formatDate(item.updated_at)}
                              </Typography.Text>
                            }
                          />
                        </List.Item>
                      )}
                    />
                  ),
                }))}
              />
            )
          }}
        </Loadable>
      </Card>

      <Modal
        open={openId !== null}
        title={article.data?.title}
        onCancel={() => setOpenId(null)}
        footer={null}
        width={720}
        destroyOnHidden
      >
        {article.loading && !article.data ? (
          <Skeleton active paragraph={{ rows: 6 }} />
        ) : article.data?.body ? (
          <ArticleBody html={article.data.body} />
        ) : null}
      </Modal>
    </>
  )
}
