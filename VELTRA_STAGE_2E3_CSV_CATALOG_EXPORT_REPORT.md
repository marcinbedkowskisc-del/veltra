# VELTRA 2.0 — Stage 2E.3 — Raport eksportu katalogu CSV

## Podsumowanie

Zaimplementowano backendowy, prywatny eksport katalogu surowców zgodny z zatwierdzonym kontraktem CSV. Eksport zawiera wyłącznie `ingredient_id;ingredient_name`, korzysta z istniejących kodów SUR i zatwierdzonych rewizji, wymaga uprawnienia `RAW_MATERIALS_VIEW` oraz pozostaje za bramką gotowości `RAW_MATERIALS`.

**Wynik weryfikacji:** testy ukierunkowane i pełna dozwolona regresja zakończyły się powodzeniem. Nie aktywowano `RAW_MATERIALS` ani `VSEF`. Nie zmieniano semantyki importu CSV, schematu bazy ani migracji.

## Zaimplementowany kontrakt

- `POST /api/v1/raw-materials/catalog-exports` tworzy eksport, a `GET /api/v1/raw-materials/catalog-exports/{export}/download` udostępnia jego pobranie.
- Zapytanie jest ograniczone do organizacji aktora, obejmuje tylko składniki `ACTIVE` z rewizją wskazaną przez `approved_revision_id` i sortuje wynik deterministycznie po kodzie składnika.
- CSV ma dokładnie nagłówek `ingredient_id;ingredient_name`, kodowanie UTF-8, separator średnika i standardowe quoting. Nazwy pochodzą bez zmian z zatwierdzonej rewizji; aliasy i pozostałe dane wewnętrzne nie są eksportowane.
- Nazwy zaczynające się po białych znakach od znaków ryzyka formuły lub znaków sterujących powodują jawne odrzucenie eksportu z identyfikatorami składników; nazwy nie są „naprawiane” ani modyfikowane.
- Wymagane są istniejąca autoryzacja `RAW_MATERIALS_VIEW`, członkostwo i kontekst lokalizacji. Uprawnienie i kontekst są ponownie weryfikowane podczas pobrania. Nie zmieniono bramki gotowości; żądania tworzenia i pobrania zwracają `503`, gdy moduł nie jest dostępny.
- Pliki są przechowywane na prywatnym dysku poza publicznym serwowaniem. Manifest wiąże eksport z organizacją, aktorem i lokalizacją, zawiera SHA-256 i wygasa po określonym czasie. Pobranie weryfikuje sumę przed wysłaniem. Odpowiedź ma `Cache-Control: no-store` i `X-Content-Type-Options: nosniff`.
- Tworzenie i udane pobranie są rejestrowane w istniejącym audycie z użyciem istniejącego podpisanego kontekstu mutacji. Błędy limitów i nazw ryzykownych również pozostawiają zdarzenie odrzucenia. Niepoprawny lub przekraczający limit eksport nie otrzymuje manifestu do pobrania.
- Konfigurowalne limity domyślne: 5000 wierszy, 5 MiB i 60 minut ważności. Eksport jest przygotowywany w strumieniu tymczasowym; plik końcowy jest publikowany dopiero po zapisaniu całości.

## Zmiany techniczne

- Dodano `RawMaterialCsvCatalogExportWorkflow` do budowania pliku, kontroli limitów i integralności, autoryzacji pobrania oraz audytu.
- Dodano kontroler API i dwie trasy eksportu.
- Dodano konfigurację limitów eksportu i prywatny dysk `raw_material_csv_exports`.
- Dostosowano `RequireSpaSession`, aby ustawiać nagłówek cache przez obiekt nagłówków Symfony, zgodnie z typem odpowiedzi strumieniowej.
- Rozszerzono testy funkcjonalne eksportu i kontroli dostępu.
- Nie dodano migracji: eksport nie wymaga nowych struktur bazy danych.

## Weryfikacja

W odizolowanym środowisku testowym:

- Testy ukierunkowane eksportu CSV: **13 testów, 171 asercji — PASS**.
- Pełna dozwolona regresja: **214 testów, 1818 asercji — PASS**.
- Zestaw testów zawierał 231 testów; poza uruchomieniem pozostało dokładnie 17 ograniczonych testów Stage 2C.
- PHP lint dla wszystkich 7 zmienionych plików PHP — PASS.
- Pint dla wszystkich 7 zmienionych plików PHP — PASS.
- `git diff --check` — PASS.

Pokryto m.in. strukturę i nazwy kolumn, dokładne kody i nazwy zatwierdzonych rewizji, kolejność, cytowanie CSV, odrzucenie nazw grożących formułą, brak pliku przy błędach limitów, autoryzację, ponowną autoryzację przy pobraniu, audyt oraz odpowiedź `503` przy `RAW_MATERIALS NOT_READY`.

## Ograniczenia i pozostałe uwagi

- Test weryfikuje filtr organizacji przez sprawdzenie parametrów zapytania. Istniejące fixture’y testowe nie pozwalają utworzyć danych drugiej organizacji, dlatego izolacja między dwoma rzeczywistymi tenantami nie została bezpośrednio przetestowana end-to-end.
- Nie wykonano testów produkcyjnych ani wdrożeniowych; moduł pozostaje `NOT_READY`, a eksport jest blokowany w tym stanie.
- W tej fazie nie wykonano katalogowego eksportu HTTP na rzeczywistych danych aplikacji; testy biznesowe korzystały z izolowanych fixture’ów.
- Nie uruchomiono żadnego z 17 ograniczonych testów Stage 2C i nie zmieniano statusu Stage 2C.

## Zakres dalszych prac

Stage 2E.3 obejmował wyłącznie eksport katalogu i pobieranie pliku. Dalszy zakres Stage 2E pozostaje odrębny i wymaga osobnego zatwierdzenia. Nie dodano eksportu cen, interfejsu frontendowego ani funkcji fakturowych, OCR, KSeF, księgowych, dostawczych lub magazynowych.
