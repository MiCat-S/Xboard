import { HashRouter, Navigate, Route, Routes } from 'react-router-dom'
import { useAuth } from './AuthContext'
import AppLayout from './layouts/AppLayout'
import SignIn from './pages/SignIn'
import SignUp from './pages/SignUp'
import Forget from './pages/Forget'
import Dashboard from './pages/Dashboard'
import Plans from './pages/Plans'
import Orders from './pages/Orders'
import OrderDetail from './pages/OrderDetail'
import Nodes from './pages/Nodes'
import Devices from './pages/Devices'
import Traffic from './pages/Traffic'
import Account from './pages/Account'

/**
 * 面板只在 / 这一条服务端路由上渲染，深链接不会被 Laravel 接管，
 * 所以走 hash 路由（和旧主题一致，/#/dashboard 这类旧链接仍然可用）。
 */
export default function App() {
  const { authenticated } = useAuth()

  return (
    <HashRouter>
      <Routes>
        {authenticated ? (
          <Route element={<AppLayout />}>
            <Route path="/dashboard" element={<Dashboard />} />
            <Route path="/plans" element={<Plans />} />
            <Route path="/orders" element={<Orders />} />
            <Route path="/order/:tradeNo" element={<OrderDetail />} />
            <Route path="/nodes" element={<Nodes />} />
            <Route path="/devices" element={<Devices />} />
            <Route path="/traffic" element={<Traffic />} />
            <Route path="/account" element={<Account />} />
            <Route path="*" element={<Navigate to="/dashboard" replace />} />
          </Route>
        ) : (
          <>
            <Route path="/login" element={<SignIn />} />
            <Route path="/register" element={<SignUp />} />
            <Route path="/forgetpassword" element={<Forget />} />
            <Route path="*" element={<Navigate to="/login" replace />} />
          </>
        )}
      </Routes>
    </HashRouter>
  )
}
