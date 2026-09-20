import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { getToken, setToken, setUnauthorizedHandler } from './api/client'

interface AuthValue {
  authenticated: boolean
  signIn: (authData: string) => void
  signOut: () => void
}

const AuthContext = createContext<AuthValue>({
  authenticated: false,
  signIn: () => {},
  signOut: () => {},
})

export function AuthProvider({ children }: { children: ReactNode }) {
  const [authenticated, setAuthenticated] = useState(() => Boolean(getToken()))

  const signIn = useCallback((authData: string) => {
    setToken(authData)
    setAuthenticated(true)
  }, [])

  const signOut = useCallback(() => {
    setToken(null)
    setAuthenticated(false)
  }, [])

  // 任何请求拿到 401/403 都会走到这里，统一退回登录页
  useEffect(() => {
    setUnauthorizedHandler(() => setAuthenticated(false))
  }, [])

  const value = useMemo(
    () => ({ authenticated, signIn, signOut }),
    [authenticated, signIn, signOut],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthValue {
  return useContext(AuthContext)
}
