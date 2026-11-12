import { useRef, type ChangeEvent, type RefObject } from 'react'

type Props = {
  value: string
  onChange: (value: string) => void
  label: string
  id?: string
  placeholder?: string
  loading?: boolean
  disabled?: boolean
  className?: string
  inputRef?: RefObject<HTMLInputElement | null>
  loadingLabel?: string
}

export default function SearchField({
  value,
  onChange,
  label,
  id,
  placeholder,
  loading = false,
  disabled = false,
  className = '',
  inputRef,
  loadingLabel = 'Aktualizowanie wyników…',
}: Props) {
  const internalRef = useRef<HTMLInputElement>(null)
  const resolvedRef = inputRef ?? internalRef

  function handleChange(event: ChangeEvent<HTMLInputElement>) {
    onChange(event.currentTarget.value)
  }

  function clearSearch() {
    onChange('')
    resolvedRef.current?.focus()
  }

  return (
    <span className={`ui-search-field${className ? ` ${className}` : ''}`}>
      <svg className="ui-search-icon" viewBox="0 0 20 20" aria-hidden="true" focusable="false">
        <circle cx="8.5" cy="8.5" r="5.25" />
        <path d="m12.4 12.4 4.1 4.1" />
      </svg>
      <input
        ref={resolvedRef}
        id={id}
        type="search"
        value={value}
        onChange={handleChange}
        placeholder={placeholder}
        aria-label={label}
        disabled={disabled}
      />
      <span className="ui-search-actions">
        {loading && <span className="ui-search-spinner search-loading-spinner" aria-hidden="true" />}
        {value.length > 0 && (
          <button
            type="button"
            className="ui-search-clear"
            aria-label={`Wyczyść: ${label}`}
            onClick={clearSearch}
            disabled={disabled}
          >
            <svg viewBox="0 0 20 20" aria-hidden="true" focusable="false">
              <path d="m5 5 10 10M15 5 5 15" />
            </svg>
          </button>
        )}
      </span>
      <span className="visually-hidden" role="status" aria-live="polite" aria-atomic="true">
        {loading ? loadingLabel : ''}
      </span>
    </span>
  )
}
