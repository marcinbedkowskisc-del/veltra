import { getPageItems } from './pagination'

type Props = {
  currentPage: number
  totalPages: number
  onPageChange: (page: number) => void
  disabled?: boolean
  label?: string
  className?: string
}

export default function Pagination({
  currentPage,
  totalPages,
  onPageChange,
  disabled = false,
  label = 'Stronicowanie',
  className = '',
}: Props) {
  const safeTotal = Math.max(1, totalPages)
  const safeCurrent = Math.min(safeTotal, Math.max(1, currentPage))

  return (
    <nav className={`ui-pagination ${className}`.trim()} aria-label={label}>
      <span aria-live="polite">Strona {safeCurrent} z {safeTotal}</span>
      <div className="pagination-controls ui-pagination-controls">
        <button
          type="button"
          className="button button-secondary"
          onClick={() => onPageChange(safeCurrent - 1)}
          disabled={disabled || safeCurrent <= 1}
          aria-label="Poprzednia"
        >
          ‹
        </button>
        {getPageItems(safeCurrent, safeTotal).map((item) => typeof item === 'number' ? (
          <button
            key={item}
            type="button"
            className="button button-secondary pagination-page ui-pagination-page"
            onClick={() => onPageChange(item)}
            disabled={disabled}
            aria-label={`Przejdź do strony ${item}`}
            aria-current={item === safeCurrent ? 'page' : undefined}
          >
            {item}
          </button>
        ) : (
          <span key={item} className="ui-pagination-ellipsis" aria-hidden="true">…</span>
        ))}
        <button
          type="button"
          className="button button-secondary"
          onClick={() => onPageChange(safeCurrent + 1)}
          disabled={disabled || safeCurrent >= safeTotal}
          aria-label="Następna"
        >
          ›
        </button>
      </div>
    </nav>
  )
}
