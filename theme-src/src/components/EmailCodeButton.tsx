import { Button, App as AntdApp } from 'antd'
import { useEffect, useRef, useState } from 'react'
import { api } from '../api'
import { t } from '../i18n'

/** 发送邮箱验证码，带 60 秒倒计时（后端本来也有 60 秒的发送冷却） */
export default function EmailCodeButton({ getEmail }: { getEmail: () => string | undefined }) {
  const { message } = AntdApp.useApp()
  const [seconds, setSeconds] = useState(0)
  const [loading, setLoading] = useState(false)
  const timer = useRef<number | undefined>(undefined)

  useEffect(() => () => window.clearInterval(timer.current), [])

  const startCountdown = () => {
    setSeconds(60)
    timer.current = window.setInterval(() => {
      setSeconds((value) => {
        if (value <= 1) {
          window.clearInterval(timer.current)
          return 0
        }
        return value - 1
      })
    }, 1000)
  }

  const send = async () => {
    const email = getEmail()
    if (!email) {
      message.error(t('email'))
      return
    }

    setLoading(true)
    try {
      await api.sendEmailVerify(email)
      message.success(t('codeSent'))
      startCountdown()
    } catch (error) {
      message.error(error instanceof Error ? error.message : String(error))
    } finally {
      setLoading(false)
    }
  }

  return (
    <Button size="large" onClick={send} loading={loading} disabled={seconds > 0}>
      {seconds > 0 ? t('resendIn', { n: seconds }) : t('sendCode')}
    </Button>
  )
}
