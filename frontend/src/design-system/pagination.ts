export type PageItem = number | 'start-ellipsis' | 'end-ellipsis'

export function getPageItems(currentPage: number, totalPages: number): PageItem[] {
  if (totalPages <= 7) return Array.from({ length: totalPages }, (_, index) => index + 1)

  const start = currentPage <= 4 ? 2 : currentPage >= totalPages - 3 ? totalPages - 4 : currentPage - 1
  const end = currentPage <= 4 ? 5 : currentPage >= totalPages - 3 ? totalPages - 1 : currentPage + 1
  const items: PageItem[] = [1]
  if (start > 2) items.push('start-ellipsis')
  for (let number = start; number <= end; number += 1) items.push(number)
  if (end < totalPages - 1) items.push('end-ellipsis')
  items.push(totalPages)
  return items
}
