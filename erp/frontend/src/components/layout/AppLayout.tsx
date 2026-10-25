import * as Dialog from '@radix-ui/react-dialog'
import * as Dropdown from '@radix-ui/react-dropdown-menu'
import { KeyRound, LogOut, Menu, UserCircle, UserRound, X } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Outlet, useNavigate } from 'react-router-dom'
import { Button } from '@/components/ui/Button'
import { useAuth } from '@/features/auth/AuthProvider'
import { NotificationBell } from '@/features/notifications/NotificationBell'
import { LanguageSwitcher } from './LanguageSwitcher'
import { OrganizationSwitcher } from './OrganizationSwitcher'
import { SidebarNav } from './Sidebar'

function Brand() {
  const { t } = useTranslation()
  return (
    <div className="px-6 py-5">
      <p className="text-lg font-bold text-brand-700">{t('app.name')}</p>
      <p className="text-xs text-slate-500">{t('app.tagline')}</p>
    </div>
  )
}

export function AppLayout() {
  const { t } = useTranslation()
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const [drawerOpen, setDrawerOpen] = useState(false)

  const onLogout = async () => {
    try {
      await logout()
    } finally {
      navigate('/login', { replace: true })
    }
  }

  return (
    <div className="flex h-full">
      {/* Desktop sidebar */}
      <aside className="hidden w-64 shrink-0 border-r border-slate-200 bg-white lg:block dark:border-slate-800 dark:bg-slate-900">
        <Brand />
        <SidebarNav />
      </aside>

      {/* Mobile / tablet drawer */}
      <Dialog.Root open={drawerOpen} onOpenChange={setDrawerOpen}>
        <Dialog.Portal>
          <Dialog.Overlay className="fixed inset-0 z-40 bg-slate-900/40 lg:hidden" />
          <Dialog.Content className="fixed inset-y-0 left-0 z-50 w-72 max-w-[85vw] overflow-y-auto bg-white shadow-xl lg:hidden dark:bg-slate-900">
            <Dialog.Title className="sr-only">{t('nav.main')}</Dialog.Title>
            <Dialog.Description className="sr-only">{t('app.name')}</Dialog.Description>
            <div className="flex items-start justify-between">
              <Brand />
              <Dialog.Close asChild>
                <Button variant="ghost" className="m-3 px-2" aria-label={t('common.closeMenu')}>
                  <X aria-hidden className="size-5" />
                </Button>
              </Dialog.Close>
            </div>
            <SidebarNav onNavigate={() => setDrawerOpen(false)} />
          </Dialog.Content>
        </Dialog.Portal>
      </Dialog.Root>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex h-14 items-center gap-2 border-b border-slate-200 bg-white px-4 dark:border-slate-800 dark:bg-slate-900">
          <Button variant="ghost" className="px-2 lg:hidden" aria-label={t('common.openMenu')} onClick={() => setDrawerOpen(true)}>
            <Menu aria-hidden className="size-5" />
          </Button>
          <div className="min-w-0 flex-1">
            <OrganizationSwitcher />
          </div>
          <LanguageSwitcher />
          <NotificationBell />
          <Dropdown.Root>
            <Dropdown.Trigger asChild>
              <Button variant="ghost" className="max-w-[12rem] px-2">
                <UserCircle aria-hidden className="size-5 shrink-0" />
                <span className="truncate">{user?.name}</span>
              </Button>
            </Dropdown.Trigger>
            <Dropdown.Portal>
              <Dropdown.Content
                align="end"
                sideOffset={6}
                className="z-50 min-w-48 rounded-lg border border-slate-200 bg-white p-1 shadow-lg dark:border-slate-700 dark:bg-slate-900"
              >
                <div className="px-3 py-2 text-xs text-slate-500">{user?.email}</div>
                <Dropdown.Separator className="my-1 h-px bg-slate-200 dark:bg-slate-700" />
                <Dropdown.Item
                  onSelect={() => navigate('/my/profile')}
                  className="flex cursor-pointer items-center gap-2 rounded-md px-3 py-2 text-sm outline-none data-[highlighted]:bg-slate-100 dark:data-[highlighted]:bg-slate-800"
                >
                  <UserRound aria-hidden className="size-4" />
                  {t('nav.myProfile')}
                </Dropdown.Item>
                <Dropdown.Item
                  onSelect={() => navigate('/account/password')}
                  className="flex cursor-pointer items-center gap-2 rounded-md px-3 py-2 text-sm outline-none data-[highlighted]:bg-slate-100 dark:data-[highlighted]:bg-slate-800"
                >
                  <KeyRound aria-hidden className="size-4" />
                  {t('account.changePassword')}
                </Dropdown.Item>
                <Dropdown.Item
                  onSelect={() => void onLogout()}
                  className="flex cursor-pointer items-center gap-2 rounded-md px-3 py-2 text-sm outline-none data-[highlighted]:bg-slate-100 dark:data-[highlighted]:bg-slate-800"
                >
                  <LogOut aria-hidden className="size-4" />
                  {t('auth.logout')}
                </Dropdown.Item>
              </Dropdown.Content>
            </Dropdown.Portal>
          </Dropdown.Root>
        </header>

        <main className="min-w-0 flex-1 overflow-y-auto p-4 sm:p-6">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
