import { useQueryClient } from '@tanstack/react-query'
import { Download, Upload } from 'lucide-react'
import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Button } from '@/components/ui/Button'
import { Spinner } from '@/components/ui/Spinner'
import { useToast } from '@/components/ui/Toast'
import { actionErrorMessage } from '@/lib/forms'
import { importTemplateUrl, previewImport, runImport, type ImportRow } from './profileApi'

/** Two steps: check the file (nothing is written), then import every row or none. */
export function EmployeeImportPage() {
  const { t } = useTranslation()
  const toast = useToast()
  const queryClient = useQueryClient()
  const fileRef = useRef<HTMLInputElement>(null)
  const [file, setFile] = useState<File | null>(null)
  const [rows, setRows] = useState<ImportRow[] | null>(null)
  const [busy, setBusy] = useState<'preview' | 'import' | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [done, setDone] = useState<number | null>(null)
  const bad = rows?.filter((r) => Object.keys(r.errors).length > 0).length ?? 0

  const preview = async (f: File) => {
    setBusy('preview')
    setError(null)
    setDone(null)
    try {
      setRows((await previewImport(f)).rows)
    } catch (e) {
      setRows(null)
      setError(actionErrorMessage(e, t('common.actionFailed')))
    } finally {
      setBusy(null)
    }
  }

  const run = async () => {
    if (!file) return
    setBusy('import')
    setError(null)
    try {
      const r = await runImport(file)
      setDone(r.imported)
      setRows(null)
      setFile(null)
      if (fileRef.current) fileRef.current.value = ''
      void queryClient.invalidateQueries({ queryKey: ['employees'] })
      toast.success(t('import.done', { count: r.imported }))
    } catch (e) {
      setError(actionErrorMessage(e, t('common.actionFailed')))
    } finally {
      setBusy(null)
    }
  }

  return (
    <div className="mx-auto max-w-5xl space-y-5">
      <h1 className="text-2xl font-semibold">{t('import.employeesTitle')}</h1>
      <ol className="list-decimal space-y-1 pl-5 text-sm text-slate-600 dark:text-slate-400">
        <li>
          {t('import.step1')}{' '}
          <a href={importTemplateUrl} className="inline-flex items-center gap-1 font-medium text-brand-700 hover:underline">
            <Download aria-hidden className="size-4" />
            {t('import.template')}
          </a>
        </li>
        <li>{t('import.step2')}</li>
        <li>{t('import.step3')}</li>
      </ol>
      <div className="flex flex-wrap items-center gap-3 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <label htmlFor="imp-file" className="text-sm font-medium">
          {t('import.chooseFile')}
        </label>
        <input
          id="imp-file"
          ref={fileRef}
          type="file"
          accept=".csv,text/csv"
          className="text-sm"
          onChange={(e) => {
            const f = e.target.files?.[0] ?? null
            setFile(f)
            if (f) void preview(f)
          }}
        />
        {busy === 'preview' ? <Spinner label={t('import.checking')} /> : null}
      </div>
      {error ? (
        <p role="alert" className="text-sm text-red-600">
          {error}
        </p>
      ) : null}
      {done !== null ? (
        <p role="status" className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
          {t('import.done', { count: done })} <Link to="/employees" className="font-medium underline">{t('nav.employees')}</Link>
        </p>
      ) : null}
      {rows ? (
        <section className="space-y-3">
          <p className="text-sm" role="status">
            {bad ? t('import.summaryBad', { total: rows.length, bad }) : t('import.summaryOk', { total: rows.length })}
          </p>
          <div className="max-h-[28rem] overflow-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <table className="w-full text-left text-sm">
              <thead className="sticky top-0 bg-slate-50 text-xs text-slate-500 dark:bg-slate-800">
                <tr>
                  <th className="px-3 py-2">{t('import.line')}</th>
                  <th className="px-3 py-2">{t('people.employeeCode')}</th>
                  <th className="px-3 py-2">{t('org.name')}</th>
                  <th className="px-3 py-2">{t('import.problems')}</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.line} className="border-t border-slate-100 align-top dark:border-slate-800">
                    <td className="px-3 py-2 tabular-nums">{r.line}</td>
                    <td className="px-3 py-2 font-mono text-xs">{r.data.employee_code}</td>
                    <td className="px-3 py-2">{r.data.name}</td>
                    <td className="px-3 py-2">
                      {Object.keys(r.errors).length === 0 ? (
                        <span className="text-emerald-700">{t('import.ok')}</span>
                      ) : (
                        <ul className="space-y-0.5 text-red-700">
                          {Object.entries(r.errors).map(([k, m]) => (
                            <li key={k}>
                              <span className="font-mono text-xs">{k}</span>: {m}
                            </li>
                          ))}
                        </ul>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Button disabled={bad > 0 || busy !== null} onClick={() => void run()}>
            <Upload aria-hidden className="size-4" />
            {busy === 'import' ? <Spinner label={t('common.saving')} /> : t('import.run', { count: rows.length })}
          </Button>
        </section>
      ) : null}
    </div>
  )
}
