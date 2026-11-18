const DISPLAY_NAME_OVERRIDES = new Map([
  ['ALG_KHORASAN', 'PSZENICA KHORASAN (KAMUT)'],
  ['ALG_TREE_NUTS', 'ORZECHY DRZEWNE'],
  ['ALG_WALNUT', 'ORZECHY WŁOSKIE'],
  ['ALG_CLAMS', 'MAŁŻE'],
  ['ALG_ABALONE', 'UCHO MORSKIE'],
  ['ALG_YAM', 'POCHRZYN'],
])

export function allergenDisplayName(allergenId: string, canonicalName: string): string {
  return DISPLAY_NAME_OVERRIDES.get(allergenId) ?? canonicalName.toLocaleUpperCase('pl-PL')
}
