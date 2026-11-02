import { useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui/Button'
import { Modal } from '@/components/ui/Modal'
import { api } from '@/lib/api'
import { CURRENT_USER_KEY, useAuth } from './AuthProvider'

const STEPS = ['welcome', 'menu', 'search', 'notifications', 'profile', 'language'] as const

/**
 * The first-sign-in tour (the old app's welcome tour): shown once to people who have not seen it,
 * and again from the account menu (`replay`).
 */
export function OnboardingTour({ replay, onClose }: { replay?: boolean; onClose?: () => void }) {
  const { t } = useTranslation()
  const { user } = useAuth()
  const qc = useQueryClient()
  const [step, setStep] = useState(0)
  const [dismissed, setDismissed] = useState(false)
  const open = !dismissed && (replay || user?.onboarding_seen === false)

  const finish = () => {
    setDismissed(true)
    onClose?.()
    if (user?.onboarding_seen === false) {
      void api.post('/me/onboarding-seen').then((r) => qc.setQueryData(CURRENT_USER_KEY, r.data.data), () => undefined)
    }
  }
  if (!open) return null
  const key = STEPS[step]
  return (
    <Modal
      open
      onOpenChange={(o) => !o && finish()}
      title={t(`tour.${key}.title`, { name: user?.name ?? '' })}
      description={t(`tour.${key}.body`)}
      footer={
        <>
          <Button variant="ghost" onClick={finish}>
            {t('tour.skip')}
          </Button>
          {step > 0 ? (
            <Button variant="secondary" onClick={() => setStep(step - 1)}>
              {t('common.previous')}
            </Button>
          ) : null}
          {step < STEPS.length - 1 ? <Button onClick={() => setStep(step + 1)}>{t('common.next')}</Button> : <Button onClick={finish}>{t('tour.finish')}</Button>}
        </>
      }
    >
      <p className="text-xs text-slate-500" aria-live="polite">
        {t('tour.step', { n: step + 1, total: STEPS.length })}
      </p>
    </Modal>
  )
}
