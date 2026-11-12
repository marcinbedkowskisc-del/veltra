import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import { useState } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import DataTable, { type DataTableColumn } from './DataTable'
import Drawer from './Drawer'
import Pagination from './Pagination'
import { getPageItems } from './pagination'
import SearchField from './SearchField'
import Tabs from './Tabs'
import { formatNumber } from './formatting'

afterEach(cleanup)

describe('wspólne komponenty VELTRA UI', () => {
  it('czyści tylko kontrolowane wyszukiwanie i zachowuje fokus', () => {
    function SearchHarness() {
      const [value, setValue] = useState('mąka')
      return <SearchField value={value} onChange={setValue} label="Szukaj surowca" loading />
    }

    render(<SearchHarness />)
    const input = screen.getByRole('searchbox', { name: 'Szukaj surowca' })
    const clearButton = screen.getByRole('button', { name: 'Wyczyść: Szukaj surowca' })

    fireEvent.click(clearButton)

    expect((input as HTMLInputElement).value).toBe('')
    expect(document.activeElement).toBe(input)
    expect(screen.queryByRole('button', { name: 'Wyczyść: Szukaj surowca' })).toBeNull()
    expect(screen.getByRole('status').textContent).toBe('Aktualizowanie wyników…')
  })

  it('renderuje semantyczną tabelę, sortowanie, zaznaczenie i jawne stany', () => {
    type Row = { id: string; name: string }
    const rows = [{ id: 'one', name: 'Mąka' }]
    const onSort = vi.fn()
    const onToggle = vi.fn()
    const columns: Array<DataTableColumn<Row>> = [{
      id: 'name',
      header: 'Nazwa',
      cell: (row) => row.name,
      sortKey: 'name',
      sortDirection: 'asc',
      sortLabel: 'Sortuj po nazwie',
      onSort,
    }]
    render(
      <DataTable
        rows={rows}
        columns={columns}
        getRowId={(row) => row.id}
        ariaLabel="Surowce"
        selection={{
          selectedIds: new Set(),
          disabled: false,
          headerLabel: 'Zaznacz stronę',
          rowLabel: (row) => `Zaznacz ${row.name}`,
          onToggle,
          onTogglePage: vi.fn(),
          cellContent: () => '1',
        }}
      />,
    )

    expect(screen.getByRole('table', { name: 'Surowce' })).toBeTruthy()
    expect(screen.getByRole('row', { name: /Mąka/ }).getAttribute('data-row-id')).toBe('one')
    fireEvent.click(screen.getByRole('button', { name: 'Sortuj po nazwie' }))
    fireEvent.click(screen.getByRole('checkbox', { name: 'Zaznacz Mąka' }))
    expect(onSort).toHaveBeenCalledOnce()
    expect(onToggle).toHaveBeenCalledWith('one')
  })

  it('pokazuje loading, empty i error bez pobierania danych wewnątrz tabeli', () => {
    const props = {
      rows: [],
      columns: [{ id: 'name', header: 'Nazwa', cell: () => 'Mąka' }],
      getRowId: () => 'one',
      ariaLabel: 'Surowce',
    }
    const { rerender } = render(<DataTable {...props} loading />)
    expect(screen.getByText('Ładowanie…')).toBeTruthy()

    rerender(<DataTable {...props} emptyMessage="Brak surowców." />)
    expect(screen.getByText('Brak surowców.')).toBeTruthy()

    rerender(<DataTable {...props} error="Nie udało się wczytać." />)
    expect(screen.getByText('Nie udało się wczytać.')).toBeTruthy()
  })

  it('obsługuje numery stron, wielokropki i aktywny przycisk klawiaturą', () => {
    expect(getPageItems(6, 12)).toEqual([1, 'start-ellipsis', 5, 6, 7, 'end-ellipsis', 12])
    const onPageChange = vi.fn()
    render(<Pagination currentPage={6} totalPages={12} onPageChange={onPageChange} />)

    const activePage = screen.getByRole('button', { name: 'Przejdź do strony 6' })
    expect(activePage.getAttribute('aria-current')).toBe('page')
    expect((activePage as HTMLButtonElement).disabled).toBe(false)
    fireEvent.click(screen.getByRole('button', { name: 'Przejdź do strony 12' }))
    expect(onPageChange).toHaveBeenCalledWith(12)
  })

  it('obsługuje zakładki klawiaturą i generuje niekolidujące identyfikatory', () => {
    function TabsHarness() {
      const [first, setFirst] = useState('overview')
      const [second, setSecond] = useState('overview')
      const tabs = (prefix: string) => [
        { id: 'overview', label: 'Przegląd', content: `${prefix}: treść przeglądu` },
        { id: 'history', label: 'Historia', content: `${prefix}: treść historii` },
      ]
      return (
        <>
          <Tabs tabs={tabs('Pierwszy')} value={first} onChange={setFirst} label="Pierwsze zakładki" />
          <Tabs tabs={tabs('Drugi')} value={second} onChange={setSecond} label="Drugie zakładki" />
        </>
      )
    }

    render(<TabsHarness />)
    const firstTabList = screen.getByRole('tablist', { name: 'Pierwsze zakładki' })
    const firstOverview = within(firstTabList).getByRole('tab', { name: 'Przegląd' })
    const firstHistory = within(firstTabList).getByRole('tab', { name: 'Historia' })
    expect(firstOverview.id).not.toBe(within(screen.getByRole('tablist', { name: 'Drugie zakładki' }))
      .getByRole('tab', { name: 'Przegląd' }).id)

    firstOverview.focus()
    fireEvent.keyDown(firstTabList, { key: 'ArrowRight' })

    expect(firstHistory.getAttribute('aria-selected')).toBe('true')
    expect(document.activeElement).toBe(firstHistory)
    expect(screen.getByText('Pierwszy: treść historii')).toBeTruthy()
  })

  it('animuje zamknięcie drawera, trzyma fokus i przewijanie do końca', async () => {
    function DrawerHarness() {
      const [visible, setVisible] = useState(false)
      const [trigger, setTrigger] = useState<HTMLButtonElement | null>(null)
      return (
        <>
          <button ref={setTrigger} type="button" onClick={() => setVisible(true)}>Otwórz panel</button>
          {visible && (
            <Drawer
              labelledBy="drawer-title"
              returnFocus={trigger}
              onClose={() => setVisible(false)}
            >
              <>
                <h2 id="drawer-title">Szczegóły</h2>
                <button type="button">Pierwsze działanie</button>
                <button type="button">Ostatnie działanie</button>
              </>
            </Drawer>
          )}
        </>
      )
    }

    render(<DrawerHarness />)
    const trigger = screen.getByRole('button', { name: 'Otwórz panel' })
    fireEvent.click(trigger)
    const dialog = await screen.findByRole('dialog', { name: 'Szczegóły' })
    expect(document.body.style.overflow).toBe('hidden')

    const focusable = within(dialog).getAllByRole('button')
    focusable[0].focus()
    fireEvent.keyDown(dialog, { key: 'Tab', shiftKey: true })
    expect(document.activeElement).toBe(focusable[focusable.length - 1])

    fireEvent.keyDown(dialog, { key: 'Escape' })
    expect(screen.getByRole('dialog', { name: 'Szczegóły' })).toBeTruthy()
    expect(dialog.closest('.ui-drawer-backdrop')?.classList.contains('is-open')).toBe(false)
    await act(async () => {
      await new Promise((resolve) => window.setTimeout(resolve, 260))
    })
    expect(screen.queryByRole('dialog')).toBeNull()
    expect(document.activeElement).toBe(trigger)
    expect(document.body.style.overflow).toBe('')
  })

  it('respektuje prefers-reduced-motion przy otwieraniu i zamykaniu', async () => {
    const previousMatchMedia = Object.getOwnPropertyDescriptor(window, 'matchMedia')
    Object.defineProperty(window, 'matchMedia', {
      configurable: true,
      value: () => ({ matches: true }),
    })
    const onClose = vi.fn()
    const { unmount } = render(
      <Drawer labelledBy="reduced-title" onClose={onClose}>
        <h2 id="reduced-title">Panel bez animacji</h2>
      </Drawer>,
    )
    try {
      const dialog = screen.getByRole('dialog', { name: 'Panel bez animacji' })
      expect(dialog.closest('.ui-drawer-backdrop')?.classList.contains('is-open')).toBe(true)
      fireEvent.keyDown(dialog, { key: 'Escape' })
      await act(async () => { await new Promise((resolve) => window.setTimeout(resolve, 0)) })
      expect(onClose).toHaveBeenCalledOnce()
    } finally {
      unmount()
      if (previousMatchMedia) Object.defineProperty(window, 'matchMedia', previousMatchMedia)
      else Reflect.deleteProperty(window, 'matchMedia')
    }
  })

  it('formatuje liczby po polsku, ucina zera końcowe i zachowuje wymaganą precyzję', () => {
    expect(formatNumber('1.000000')).toBe('1')
    expect(formatNumber('2.500000')).toBe('2,5')
    expect(formatNumber('0.750000')).toBe('0,75')
    expect(formatNumber('250.000000')).toBe('250')
    expect(formatNumber('0.000000125', { maximumFractionDigits: 9 })).toBe('0,000000125')
  })
})
