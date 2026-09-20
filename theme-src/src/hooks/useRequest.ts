import { useCallback, useEffect, useRef, useState } from 'react'

interface State<T> {
  data: T | undefined
  loading: boolean
  error: string | null
}

/**
 * 一个够用的请求 hook：首次自动拉取，暴露 reload 供手动刷新，
 * 组件卸载后不再 setState。
 */
export function useRequest<T>(fetcher: () => Promise<T>, deps: unknown[] = []) {
  const [state, setState] = useState<State<T>>({ data: undefined, loading: true, error: null })
  const alive = useRef(true)
  const fetcherRef = useRef(fetcher)
  fetcherRef.current = fetcher

  useEffect(() => {
    alive.current = true
    return () => {
      alive.current = false
    }
  }, [])

  const run = useCallback(async () => {
    setState((prev) => ({ ...prev, loading: true, error: null }))
    try {
      const data = await fetcherRef.current()
      if (alive.current) setState({ data, loading: false, error: null })
    } catch (error) {
      if (alive.current) {
        setState((prev) => ({
          ...prev,
          loading: false,
          error: error instanceof Error ? error.message : String(error),
        }))
      }
    }
  }, [])

  useEffect(() => {
    void run()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps)

  return { ...state, reload: run }
}
