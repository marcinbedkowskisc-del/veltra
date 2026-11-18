import DataTable, { type DataTableColumn } from '../design-system/DataTable'
import {
  CATALOG_COLUMNS,
  formatDecimal,
  formatMoney,
  lifecycleLabel,
  type CatalogRow,
  type SortDirection,
  type SortKey,
} from './catalog'
import { unitLabel, type RawMaterialUnitOption } from './create'

type Props = {
  working?: boolean
  rows: CatalogRow[]
  unitOptions?: RawMaterialUnitOption[]
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
  onOpenDetail: (id: string, trigger: HTMLButtonElement) => void
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
  working = false,
  rows,
  unitOptions = [],
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
  onOpenDetail,
}: Props) {
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

  const visibleColumns = SORTABLE_COLUMNS.filter((column) =>
    !working || column.key !== 'reference_net_price' && column.key !== 'usable_unit_cost')
  const columns: Array<DataTableColumn<CatalogRow>> = visibleColumns.map((column) => {
    const permitted = column.key === 'reference_net_price'
      ? canReadReferencePrice === true
      : column.key === 'usable_unit_cost'
        ? canReadUsableCost === true
        : true
    const active = sort === column.key
    return {
      id: column.key,
      header: column.label,
      sortKey: column.key,
      sortDirection: active ? direction : null,
      sortLabel: `Sortuj według: ${column.label}`,
      onSort: permitted ? () => onSort(column.key) : undefined,
      className: `${column.key}-column`,
      cell: (row) => {
        switch (column.key) {
          case 'sku':
            return <span className="sku-cell">{row.sku}</span>
          case 'name':
            return (
              <span>
                <button
                  type="button"
                  className="detail-link name-cell"
                  onClick={(event) => onOpenDetail(row.id, event.currentTarget)}
                  disabled={selectionDisabled}
                  aria-label={`Pokaż szczegóły: ${row.name}`}
                >
                  {row.name}
                </button>
                {row.supplierNames.length > 0 && (
                  <small className="raw-supplier-name">Dostawca: {row.supplierNames.join(', ')}</small>
                )}
                {row.workflowState && <small className="detail-muted"> · {lifecycleLabel(row.workflowState)}</small>}
              </span>
            )
          case 'unit':
            return unitLabel(row.unit, unitOptions)
          case 'nominal_quantity':
            return formatDecimal(row.nominalQuantity)
          case 'usable_quantity':
            return formatDecimal(row.usableQuantity)
          case 'reference_net_price':
            return financialValue(canReadReferencePrice, row.referencePrice, 'PLN/opak.')
          case 'usable_unit_cost':
            return financialValue(canReadUsableCost, row.usableUnitCost, row.usableCostBasis)
        }
      },
    }
  })

  if (working) columns.unshift({ id: 'ordinal', header: CATALOG_COLUMNS[0], cell: (row) => row.ordinal })

  return (
    <DataTable
      rows={rows}
      columns={columns}
      getRowId={(row) => row.id}
      ariaLabel="Katalog surowców"
      loading={loading}
      loadingMessage="Ładowanie katalogu…"
      emptyMessage={emptyMessage}
      stickyHeader
      updating={loading}
      wrapperClassName="table-scroll"
      tableClassName="catalog-table"
      selection={working ? undefined : {
        selectedIds,
        disabled: selectionDisabled,
        headerLabel: 'Zaznacz wszystkie widoczne na tej stronie',
        rowLabel: (row) => `Zaznacz ${row.name}`,
        onToggle,
        onTogglePage,
        headerContent: <span>{CATALOG_COLUMNS[0]}</span>,
        cellContent: (row) => <span>{row.ordinal}</span>,
      }}
    />
  )
}
