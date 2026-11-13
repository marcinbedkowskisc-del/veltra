# Stage 6A.4 — naprawa logowania: PASS z ograniczeniem

## Przyczyna i naprawa

Po restarcie środowiska Docker lokalny kontener PostgreSQL nie wystartował:
runtime zgłosił błąd montowania pliku inicjalizacyjnego. Plik na hoście
istniał. W efekcie backend nie mógł rozwiązać nazwy `postgres`, a
`POST /api/v1/auth/login` zwracał HTTP 500 dla dowolnego konta.
Log Laravel wskazywał błąd operacji bazy tożsamości i SQLSTATE 08006.

Uruchomiono ponownie istniejący kontener PostgreSQL z jego dotychczasowym
wolumenem, bez odtwarzania kontenera lub resetu bazy. Po ponownej weryfikacji
sieci, portów loopback, wyłącznego wolumenu i tożsamości bazy skorygowano
wyłącznie adresy PostgreSQL i Mailpit w lokalnym poświadczeniu RAW. Jego
pierwotny termin ważności pozostał bez zmian: 13.11.2026 16:47:01 UTC.
Nie zmieniono kodu aplikacji, kont, haseł, grantów ani schematu.

## Weryfikacja

- Właściciel zalogował się na `raw.steward@veltra.test`; `/api/v1/auth/me`
  zwraca 200 po odświeżeniu strony i właściwe konto.
- Przed naprawą kontrolna próba dla nieistniejącego konta zwracała 500,
  po naprawie 401; frontend i `/api/health` zwracają 200.
- Normalny katalog i workbench zwracają 200: `RAW-000002` jest zatwierdzony,
  `RAW-000001` pozostaje szkicem. SQL potwierdził odpowiednio 3 i 1 rewizję,
  zdarzenie zatwierdzenia oraz audyt CREATED, REVISION_CREATED, SUBMITTED,
  APPROVED i SKU_RESERVED.
- Kategorie testowe dostępne przez API; lokalny opt-in poprawny, globalny
  `RAW_MATERIALS = NOT_READY`. `git diff --check` przeszedł.
- Nie uruchamiano testów jednostkowych ani pełnej regresji: nie zmieniono kodu.

## Ograniczenia i stan końcowy

Przywrócono bieżące działanie, ale nie potwierdzono trwałego usunięcia przyczyny
błędu montowania Docker/WSL po kolejnym restarcie hosta. W razie powtórzenia
należy ponownie sprawdzić stan kontenera i montowania; nie resetować wolumenu.
Nie wykonywano restartu hosta jako testu.

Pozostają uruchomione lokalne frontend, backend, PostgreSQL, Redis, queue i
Mailpit. Żadnych nowych usług tymczasowych nie tworzono. Stan Git przed
utworzeniem tego raportu był czysty; niniejszy raport jest nowym plikiem.
Bez commitu, pushu, deploymentu, migracji i operacji Google Drive.
Następny etap: ręczny odbiór właściciela pod http://localhost:5174/.
