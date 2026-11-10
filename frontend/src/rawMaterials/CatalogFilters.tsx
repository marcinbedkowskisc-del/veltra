import {
  LIFECYCLE_STATES,
  RAW_UNITS,
  lifecycleLabel,
  supplierLabel,
  type CatalogFilters as FilterValues,
  type CategoryOption,
  type SupplierOption,
} from './catalog'

export type SupplierFilterState = 'checking' | 'available' | 'denied' | 'unavailable'

type Props = {
  values: FilterValues
  searchInput: string
  categories: CategoryOption[]
  categoriesAvailable: boolean
  supplierState: SupplierFilterState
  supplierInput: string
  supplierSuggestions: SupplierOption[]
  supplierSearching: boolean
  disabled: boolean
  onChange: (field: keyof FilterValues, value: string) => void
  onSupplierInput: (value: string) => void
  onSupplierSelect: (supplier: SupplierOption) => void
  onSupplierClear: () => void
  onExport: () => void
  exportDisabled: boolean
}

export default function CatalogFilters({
  values,
  searchInput,
  categories,
  categoriesAvailable,
  supplierState,
  supplierInput,
  supplierSuggestions,
  onChange,
  onSupplierInput,
  onSupplierSelect,
  onSupplierClear,
  onExport,
  exportDisabled,
  supplierSearching,
  disabled,
}: Props) {
  return (
    <div className="catalog-toolbar">
      <label className="filter-field search-field">
        <span>Szukaj</span>
        <input
          type="search"
          value={searchInput}
          onChange={(event) => onChange('search', event.target.value)}
          placeholder="Nazwa surowca lub SKU"
          aria-label="Szukaj surowca po nazwie lub SKU"
          disabled={disabled}
        />
      </label>

      <label className="filter-field">
        <span>Kategoria</span>
        <select
          value={values.categoryId}
          onChange={(event) => onChange('categoryId', event.target.value)}
          disabled={disabled || !categoriesAvailable}
          aria-label="Filtruj według kategorii"
        >
          <option value="">Wszystkie kategorie</option>
          {categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
        </select>
        {!categoriesAvailable && <small className="filter-hint" role="status">Lista kategorii chwilowo niedostępna.</small>}
      </label>

      {supplierState === 'available' && (
        <div className="filter-field supplier-filter">
          <label htmlFor="supplier-search">Dostawca</label>
          <input
            id="supplier-search"
            type="search"
            autoComplete="off"
            value={supplierInput}
            onChange={(event) => onSupplierInput(event.target.value)}
            placeholder="Wyszukaj po nazwie"
            disabled={disabled}
          />
          {supplierInput.trim().length > 0 && !values.supplierId && (
            <ul id="supplier-suggestions" className="supplier-suggestions" aria-label="Wyniki dostawców">
              {supplierSearching
                ? <li className="suggestion-empty" role="status">Wyszukiwanie dostawców…</li>
                : supplierSuggestions.length > 0
                ? supplierSuggestions.map((supplier) => (
                  <li key={supplier.id}>
                    <button type="button" onClick={() => onSupplierSelect(supplier)} disabled={disabled}>
                      {supplierLabel(supplier)}
                    </button>
                  </li>
                ))
                : <li className="suggestion-empty">Brak pasujących dostawców.</li>}
            </ul>
          )}
          {values.supplierId && (
            <button type="button" className="filter-clear" onClick={onSupplierClear} disabled={disabled}>
              Wyczyść wybranego dostawcę
            </button>
          )}
        </div>
      )}
      {supplierState === 'checking' && (
        <p className="filter-status" role="status">Sprawdzanie dostępu do filtra dostawcy…</p>
      )}
      {supplierState === 'unavailable' && (
        <p className="filter-status" role="status">Filtr dostawcy jest chwilowo niedostępny.</p>
      )}

      <label className="filter-field">
        <span>Status</span>
        <select
          value={values.lifecycleState}
          onChange={(event) => onChange('lifecycleState', event.target.value)}
          aria-label="Filtruj według statusu"
          disabled={disabled}
        >
          <option value="">Wszystkie statusy</option>
          {LIFECYCLE_STATES.map((state) => <option key={state} value={state}>{lifecycleLabel(state)}</option>)}
        </select>
      </label>

      <label className="filter-field">
        <span>Jednostka</span>
        <select
          value={values.unit}
          onChange={(event) => onChange('unit', event.target.value)}
          aria-label="Filtruj według jednostki"
          disabled={disabled}
        >
          <option value="">Wszystkie jednostki</option>
          {RAW_UNITS.map((unit) => <option key={unit} value={unit}>{unit}</option>)}
        </select>
      </label>

      <button type="button" className="button button-secondary export-button" onClick={onExport} disabled={disabled || exportDisabled}>
        Eksport CSV
      </button>
    </div>
  )
}
