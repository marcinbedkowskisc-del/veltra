# VELTRA 2.0 — Propozycja kontraktu importu katalogu CSV

**Status:** PROPOZYCJA DO DECYZJI WŁAŚCICIELA — NIEZATWIERDZONA  
**Data:** 6 listopada 2026 r.  
**Zakres:** projekt kontraktu; nie jest specyfikacją wdrożeniową ani zgodą na implementację importera.

## 1. Cel i rozdzielenie przepływów

Import katalogu tworzy nowe rekordy katalogowe albo rozpoznaje identyczny rekord już istniejący. Jest odrębny od cenowego CSV:

- katalogowy CSV przekazuje tożsamość SKU, nazwę, jednostkę i zatwierdzone atrybuty katalogowe;
- cenowy CSV Stage 2E.1–2E.2 aktualizuje historyczne ceny/metadane już istniejącego składnika i nigdy nie tworzy brakującego składnika;
- eksport Stage 2E.3 pozostaje odrębnym przepływem, a `ingredient_id` odzwierciedla zapisany SKU.

Plik katalogu nie zawiera cen, VAT, ilości zakupowych ani danych dostawców. Import nie jest kopią bazy v1.x ani bezpośrednią migracją rekordów.

## 2. Proponowany format wspólny

Proponowana wersja 1 ma kodowanie UTF-8, separator `;`, standardowe cytowanie CSV, dokładny nagłówek i stałą kolejność:

```csv
sku;category;name;unit;category_code;description
RAW-000123;RAW;Mąka pszenna;kg;FLOUR;Mąka do wypieków
PRD-000007;PRD;Bułka pszenna;szt.;;Bułka wypiekana na miejscu
```

Wartości w przykładzie są wyłącznie ilustracyjne; format, nazwy pól i wymagane wartości wymagają zatwierdzenia.

| Kolumna | Proponowana reguła |
|---|---|
| `sku` | Wymagana, dokładna wartość kanoniczna `RAW-…`, `INT-…`, `PRD-…` lub `MEN-…`; prefiks musi odpowiadać `category`. Bez automatycznego generowania lub poprawiania. |
| `category` | Wymagana, jedna z wartości `RAW`, `INT`, `PRD`, `MEN`. |
| `name` | Wymagana, niepusta nazwa zachowana znak w znak; bez trimowania, normalizacji, dopasowania ani zmiany istniejącej nazwy. |
| `unit` | Wymagana warunkowo według zatwierdzonego schematu kategorii. Dla RAW proponowane są wyłącznie jednostki bazowe z istniejącego rejestru (`mg`, `g`, `kg`, `ml`, `l`, `pc`). Jednostki INT/PRD/MEN wymagają potwierdzenia domenowego. |
| `category_code` | Wymagana dla RAW jako kod istniejącej kategorii organizacji; importer nie tworzy kategorii. Dla pozostałych kategorii puste tylko wtedy, gdy zatwierdzony schemat uzna pole za nieużywane. |
| `description` | Opcjonalna; puste pole dozwolone. Długość, zachowanie whitespace i limit należy ustalić w zatwierdzonej wersji kontraktu. |

Wszystkie kolumny mają być obecne w nagłówku, nawet jeśli nie dotyczą danego wiersza. Puste pole w kolumnie warunkowo wymaganej jest błędem wiersza. Nieznane kolumny, duplikaty nagłówków, dodatkowe pola i niepoprawne cytowanie są odrzucane.

### Dane specyficzne dla kategorii

- **RAW:** mapowanie do istniejącego modelu surowców, istniejącej kategorii organizacji i zatwierdzonych jednostek. Import nie dostarcza ani nie fabrykuje deklaracji alergenów; nowy rekord powinien rozpocząć zwykły proces rewizji/weryfikacji, a nie być automatycznie aktywowany.
- **INT, PRD, MEN:** osobne domeny i schematy nie są jeszcze zaimplementowane. Nie należy akceptować ich w działającym imporcie, dopóki nie zostaną zatwierdzone ich pola, jednostki, cykle rewizji, uprawnienia i zasady aktywacji. Centralna rezerwacja SKU może być współdzielona bez tworzenia tych domen.

## 3. Zachowanie i konflikty SKU

1. SKU jest identyfikatorem biznesowym, nie UUID. Import zachowuje dokładnie przekazaną wartość i wiąże ją z nowym wewnętrznym identyfikatorem.
2. Brak SKU jest błędem. Import nie przydziela numeru automatycznie ani po cichu.
3. Prefiks jest wymagany dla kategorii: `RAW`/`INT`/`PRD`/`MEN`. Nowy surowiec nie może otrzymać `SUR`.
4. Historyczny `SUR` może pozostać przypisany do istniejącego surowca i może zostać zaakceptowany wyłącznie w jasno określonej operacji ponownego rozpoznania tego samego rekordu. Propozycja odrzuca utworzenie nowego rekordu RAW z `SUR`; wymaga potwierdzenia właściciela.
5. Powtórzenie SKU w jednym pliku odrzuca cały plik, niezależnie od zgodności pozostałych kolumn.
6. SKU zajęte w organizacji przez inny rekord albo inną kategorię jest konfliktem; brak nadpisania, remapowania i cichego scalania.
7. Rezerwacja importowanego numeru i utworzenie rekordu odbywają się w jednej transakcji. Licznik dla organizacji i prefiksu nie cofa się; generator nie może ponownie przydzielić wcześniej zarezerwowanego numeru.

## 4. Ponowny import i zmiany istniejących rekordów

Proponowane reguły domyślne:

- ten sam SKU i identyczny, kanoniczny zestaw atrybutów daje `BEZ ZMIAN`/wynik idempotentny;
- ten sam SKU z inną nazwą, jednostką lub kategorią jest konfliktem, a nie automatyczną aktualizacją;
- SKU pozostaje niezmienne po przypisaniu; zmiana nazwy lub innych rewizjonowanych danych korzysta z istniejącego, autoryzowanego procesu edycji i zatwierdzania;
- import nie archiwizuje, nie usuwa ani nie deaktywuje rekordów nieobecnych w pliku;
- ewentualny tryb aktualizacji wymaga odrębnego zatwierdzenia: jawnej opcji, uprawnienia, podglądu różnic, audytu i ponownej walidacji przy zapisie.

## 5. Walidacja i podgląd

Podgląd powinien być prywatny, tenant-scoped i bez mutacji danych katalogowych. Waliduje cały plik, a następnie prezentuje wynik każdego wiersza z numerem, SKU i polskim opisem:

- `UTWORZENIE` — SKU i atrybuty są poprawne, a kod jest wolny;
- `BEZ ZMIAN` — istniejący rekord jest identyczny i zgodny z regułą ponownego importu;
- `KONFLIKT SKU` — kod jest już zajęty lub ma inny typ/kategorię;
- `BŁĄD DANYCH` — błędny format, nieznana kategoria/jednostka, brak wymaganej wartości lub niedozwolony prefiks.

Walidacja obejmuje exact header, UTF-8, średnik, quoting, liczbę kolumn, pustki zależne od kategorii, długości, duplikaty SKU, zgodność prefix/category, organizację, istniejące kody i pola kategorii. Nazwy nie służą do identyfikacji i nie są normalizowane.

Podgląd pokazuje operacje tworzenia/bez zmian/konfliktów bez rezerwowania SKU, tworzenia rekordów ani aktywowania ich. Wymagane są konfigurowalne limity rozmiaru i liczby wierszy oraz prywatne przechowywanie źródła z SHA-256, jeśli podgląd będzie zatwierdzalny później.

## 6. Autoryzacja, zatwierdzenie i audyt

- Upload, podgląd, zatwierdzenie i odczyt wyniku wymagają uwierzytelnionego aktora, aktualnego członkostwa/kontekstu organizacji, uprawnień do importu katalogu i gotowości odpowiedniej domeny.
- Dla RAW należy użyć istniejącego RBAC i readiness gate `RAW_MATERIALS`; `NOT_READY` musi blokować rzeczywiste żądania HTTP.
- Dla INT/PRD/MEN nie należy tworzyć pozornych uprawnień ani omijać braku domeny/readiness.
- Commit wymaga jawnego potwierdzenia operatora, klucza idempotencji, weryfikacji SHA-256, ponownej walidacji uprawnień i danych oraz wykrycia zmian od podglądu.
- Zapis wszystkich poprawnych wierszy musi być atomowy: rezerwacje numerów, rekordy, historie rewizji i audyt zatwierdzają się razem albo są wycofywane razem.
- Audit powinien rejestrować aktora, organizację, lokalizację, hash pliku, identyfikator operacji, liczbę wierszy, status oraz liczbę wyników. Nie powinien przechowywać pełnych plików ani sekretów w logach.

## 7. Decyzje wymagające zatwierdzenia

Przed implementacją właściciel powinien zatwierdzić lub zmienić co najmniej:

1. dokładny nagłówek, pola wspólne, limity i reguły pustych pól;
2. jednostki oraz pola kategorii RAW, INT, PRD i MEN;
3. regułę, że `SUR` jest dozwolony wyłącznie przy rozpoznaniu istniejącego rekordu, a nigdy przy tworzeniu nowego;
4. semantykę identycznego reimportu i zakaz aktualizacji istniejącego rekordu przez import v1;
5. status nowych rekordów po imporcie RAW i ich relację do normalnego procesu rewizji/akceptacji;
6. uprawnienia, gotowość domen i wymagania lokalizacyjne dla każdej kategorii;
7. limity pliku/wierszy, okres ważności podglądu i przechowywanie audytowe.

**Niniejsza propozycja nie zatwierdza kontraktu ani nie autoryzuje implementacji pełnego importera katalogu.**
