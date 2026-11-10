import { useEffect, useRef } from 'react'
import {
  CATALOG_COLUMNS,
  formatDecimal,
  formatMoney,
  type CatalogRow,
  type SortDirection,
  type SortKey,
} from './catalog'

type Props = {
  rows: CatalogRow[]
  selectedIds: Set<string>
  selectionDisabled: boolean
  sort: SortKey
  direction: SortDirection
  canReadReferencePrice: boolean | null
  canReadUsableCost: boolean | null
  emptyMessage: string | null
  loading: boolean
  onSort: (key: SortKey) => void
  onToggle: (id: string) => void
  onTogglePage: () => void
}

type SortableColumn = {
  label: string
  key: SortKey
}

const SORTABLE_COLUMNS: SortableColumn[] = [
  { label: CATALOG_COLUMNS[1], key: 'sku' },
  { label: CATALOG_COLUMNS[2], key: 'name' },
  { label: CATALOG_COLUMNS[3], key: 'unit' },
  { label: CATALOG_COLUMNS[4], key: 'nominal_quantity' },
  { label: CATALOG_COLUMNS[5], key: 'usable_quantity' },
  { label: CATALOG_COLUMNS[6], key: 'reference_net_price' },
  { label: CATALOG_COLUMNS[7], key: 'usable_unit_cost' },
]

export default function CatalogTable({
  rows,
  selectedIds,
  selectionDisabled,
  sort,
  direction,
  canReadReferencePrice,
  canReadUsableCost,
  emptyMessage,
  loading,
  onSort,
  onToggle,
  onTogglePage,
}: Props) {
  const selectPageRef = useRef<HTMLInputElement>(null)
  const selectedOnPage = rows.filter((row) => selectedIds.has(row.id)).length
  const allSelected = rows.length > 0 && selectedOnPage === rows.length
  const partiallySelected = selectedOnPage > 0 && !allSelected

  useEffect(() => {
    if (selectPageRef.current) selectPageRef.current.indeterminate = partiallySelected
  }, [partiallySelected])

  function headerCell(label: string, key: SortKey, permitted = true) {
    const active = sort === key
    const ariaSort = active ? (direction === 'asc' ? 'ascending' : 'descending') : 'none'
    return (
      <th key={key} scope="col" aria-sort={ariaSort}>
        {permitted ? (
          <button
            type="button"
            className="sort-button"
            onClick={() => onSort(key)}
            aria-label={`Sortuj według: ${label}`}
          >
            {label}
            {active && <span aria-hidden="true">{direction === 'asc' ? ' ↑' : ' ↓'}</span>}
          </button>
        ) : <span>{label}</span>}
      </th>
    )
  }

  function financialValue(
    permitted: boolean | null,
    value: string | null | undefined,
    basis?: string,
  ) {
    if (permitted === false) return <span className="restricted-value">Brak uprawnień</span>
    if (permitted === null) return <span className="muted-value">—</span>
    if (value === undefined) return <span className="restricted-value">Brak danych</span>
    if (value === null) return <span className="muted-value">Brak danych</span>

    return (
      <span className="financial-value">
        <span>{formatMoney(value)}</span>
        <small>{basis ?? 'PLN'}</small>
      </span>
    )
  }

  return (
    <div className="table-scroll" aria-busy={loading}>
      <table className="catalog-table" aria-label="Katalog surowców">
        <thead>
          <tr>
            <th scope="col" aria-sort="none">
              <div className="ordinal-heading">
                <input
                  ref={selectPageRef}
                  type="checkbox"
                  checked={allSelected}
                  onChange={onTogglePage}
                  disabled={selectionDisabled || rows.length === 0}
                  aria-label="Zaznacz wszystkie widoczne na tej stronie"
                />
                <span>{CATALOG_COLUMNS[0]}</span>
              </div>
            </th>
            {SORTABLE_COLUMNS.map((column) => {
              const permitted = column.key === 'reference_net_price'
                ? canReadReferencePrice === true
                : column.key === 'usable_unit_cost'
                  ? canReadUsableCost === true
                  : true
              return headerCell(column.label, column.key, permitted)
            })}
          </tr>
        </thead>
        <tbody>
          {loading && rows.length === 0 && (
            <tr><td className="table-message" colSpan={CATALOG_COLUMNS.length}>Ładowanie katalogu…</td></tr>
          )}
          {!loading && rows.length === 0 && (
            <tr><td className="table-message" colSpan={CATALOG_COLUMNS.length}>{emptyMessage}</td></tr>
          )}
          {rows.map((row) => (
            <tr key={row.id} data-material-id={row.id} aria-selected={selectedIds.has(row.id)}>
              <td>
                <div className="ordinal-cell">
                  <input
                    type="checkbox"
                    checked={selectedIds.has(row.id)}
                    onChange={() => onToggle(row.id)}
                    disabled={selectionDisabled}
                    aria-label={`Zaznacz ${row.name}`}
                  />
                  <span>{row.ordinal}</span>
                </div>
              </td>
              <td className="sku-cell">{row.sku}</td>
              <td className="name-cell">{row.name}</td>
              <td>{row.unit}</td>
              <td>{formatDecimal(row.nominalQuantity)}</td>
              <td>{formatDecimal(row.usableQuantity)}</td>
              <td>{financialValue(canReadReferencePrice, row.referencePrice, 'PLN/opak.')}</td>
              <td>{financialValue(canReadUsableCost, row.usableUnitCost, row.usableCostBasis)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
