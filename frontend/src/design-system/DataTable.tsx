import { useEffect, useRef, type ReactNode } from 'react'

export type DataTableColumn<Row> = {
  id: string
  header: ReactNode
  cell: (row: Row) => ReactNode
  sortKey?: string
  sortDirection?: 'asc' | 'desc' | null
  sortLabel?: string
  onSort?: () => void
  className?: string
}

export type DataTableSelection<Row> = {
  selectedIds: ReadonlySet<string>
  disabled: boolean
  headerLabel: string
  rowLabel: (row: Row) => string
  onToggle: (id: string) => void
  onTogglePage: () => void
  cellContent: (row: Row) => ReactNode
  headerContent?: ReactNode
}

type Props<Row> = {
  rows: Row[]
  columns: Array<DataTableColumn<Row>>
  getRowId: (row: Row) => string
  ariaLabel: string
  loading?: boolean
  loadingMessage?: ReactNode
  emptyMessage?: ReactNode
  error?: ReactNode
  selection?: DataTableSelection<Row>
  stickyHeader?: boolean
  updating?: boolean
  wrapperClassName?: string
  tableClassName?: string
}

export default function DataTable<Row>({
  rows,
  columns,
  getRowId,
  ariaLabel,
  loading = false,
  loadingMessage = 'Ładowanie…',
  emptyMessage = 'Brak wyników.',
  error,
  selection,
  stickyHeader = false,
  updating = false,
  wrapperClassName = '',
  tableClassName = '',
}: Props<Row>) {
  const pageCheckboxRef = useRef<HTMLInputElement>(null)
  const selectedCount = selection
    ? rows.reduce((count, row) => count + Number(selection.selectedIds.has(getRowId(row))), 0)
    : 0
  const allSelected = Boolean(selection && rows.length > 0 && selectedCount === rows.length)
  const partiallySelected = Boolean(selectedCount > 0 && !allSelected)

  useEffect(() => {
    if (pageCheckboxRef.current) pageCheckboxRef.current.indeterminate = partiallySelected
  }, [partiallySelected])

  return (
    <div
      className={`ui-data-table-scroll ${wrapperClassName}${updating && rows.length > 0 ? ' is-updating' : ''}`.trim()}
      aria-busy={loading || updating}
      data-sticky-header={stickyHeader || undefined}
    >
      <table className={`ui-data-table ${tableClassName}`.trim()} aria-label={ariaLabel}>
        <thead>
          <tr>
            {selection && (
              <th scope="col">
                <div className="ui-data-table-selection-heading">
                  <input
                    ref={pageCheckboxRef}
                    type="checkbox"
                    checked={allSelected}
                    onChange={selection.onTogglePage}
                    disabled={selection.disabled || rows.length === 0}
                    aria-label={selection.headerLabel}
                  />
                  {selection.headerContent}
                </div>
              </th>
            )}
            {columns.map((column) => {
              const ariaSort = column.sortDirection === 'asc'
                ? 'ascending'
                : column.sortDirection === 'desc' ? 'descending' : 'none'
              return (
                <th
                  key={column.id}
                  scope="col"
                  aria-sort={column.sortKey ? ariaSort : undefined}
                  className={column.className}
                >
                  {column.onSort ? (
                    <button
                      type="button"
                      className="sort-button"
                      onClick={column.onSort}
                      aria-label={column.sortLabel}
                    >
                      {column.header}
                      {column.sortDirection && (
                        <span aria-hidden="true">{column.sortDirection === 'asc' ? ' ↑' : ' ↓'}</span>
                      )}
                    </button>
                  ) : column.header}
                </th>
              )
            })}
          </tr>
        </thead>
        <tbody>
          {error ? (
            <tr><td className="table-message" colSpan={columns.length + Number(Boolean(selection))}>{error}</td></tr>
          ) : rows.length === 0 ? (
            <tr>
              <td className="table-message" colSpan={columns.length + Number(Boolean(selection))}>
                {loading ? loadingMessage : emptyMessage}
              </td>
            </tr>
          ) : rows.map((row) => {
            const id = getRowId(row)
            const isSelected = Boolean(selection?.selectedIds.has(id))
            return (
              <tr key={id} data-row-id={id} aria-selected={selection ? isSelected : undefined}>
                {selection && (
                  <td>
                    <div className="ordinal-cell">
                      <input
                        type="checkbox"
                        checked={isSelected}
                        onChange={() => selection.onToggle(id)}
                        disabled={selection.disabled}
                        aria-label={selection.rowLabel(row)}
                      />
                      {selection.cellContent(row)}
                    </div>
                  </td>
                )}
                {columns.map((column) => (
                  <td key={column.id} className={column.className}>{column.cell(row)}</td>
                ))}
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}
