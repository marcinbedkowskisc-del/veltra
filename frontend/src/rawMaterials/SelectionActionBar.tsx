type Props = {
  count: number
  exporting: boolean
  disabled: boolean
  onExport: () => void
  onClear: () => void
}

export default function SelectionActionBar({ count, exporting, disabled, onExport, onClear }: Props) {
  if (count === 0) return null

  return (
    <aside className="selection-bar" aria-label="Akcje zaznaczonych surowców" aria-live="polite">
      <strong>Zaznaczono: {count}</strong>
      <div className="selection-actions">
        <button type="button" className="button button-primary" onClick={onExport} disabled={disabled || exporting}>
          {exporting ? 'Przygotowywanie CSV…' : 'Eksportuj zaznaczone'}
        </button>
        <button
          type="button"
          className="button button-secondary"
          disabled
        >
          Archiwizuj zaznaczone
        </button>
        <button type="button" className="button button-quiet" onClick={onClear}>
          Wyczyść zaznaczenie
        </button>
      </div>
      <small className="deferred-note">Archiwizacja zbiorcza będzie dostępna w etapie 4C.</small>
    </aside>
  )
}
