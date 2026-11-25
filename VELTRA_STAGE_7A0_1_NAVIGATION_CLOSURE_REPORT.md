# VELTRA 2.0 — Stage 7A.0.1 — Raport zamknięcia nawigacji RAW

Data: 2026-11-25
Środowisko: lokalne `veltra-stage6a1`
Werdykt: **ZAKOŃCZONO — poprawka wdrożona i zweryfikowana**

## Ręczna akceptacja właściciela

Przed wdrożeniem tej poprawki właściciel potwierdził dla konta BOSS:

- dostęp do katalogu RAW i szczegółów surowców;
- widoczność cen zakupu oraz wyliczonych kosztów jednostkowych;
- brak możliwości edycji materiałów;
- brak dostępu do Panelu Administracyjnego.

To potwierdzenie jest odnotowane oddzielnie od testów automatycznych. Nie wykonywano ponownej autoryzacji ani nie zmieniano kont, ról, uprawnień czy danych.

## Zmiana

- Usunięto zakładkę „Zatwierdzony BOSS” i zapytanie frontendowe właściwe tylko dla tej zakładki. Standardowy katalog RAW pozostaje wspólnym interfejsem.
- Ścieżka `/boss/raw-materials` jest obsługiwana jako legacy: aplikacja zastępuje ją `/raw-materials`.
- Zachowano standardowe widoki katalogu, role-zależną widoczność finansów i operacji oraz istniejące backendowe endpointy, autoryzację i konfigurację capability.
- Nie wprowadzono drugiego katalogu ani zmian backendu.

Zmodyfikowane pliki interfejsu:

- `frontend/src/App.tsx`
- `frontend/src/App.test.tsx`
- `frontend/src/rawMaterials/RawMaterialsCatalogPage.tsx`
- `frontend/src/rawMaterials/RawMaterialsCatalogPage.test.tsx`

## Weryfikacja automatyczna

- Testy `App.test.tsx` i `RawMaterialsCatalogPage.test.tsx`: **61 testów zaliczonych**. Pokrywają wspólną nawigację RAW, obsługę legacy route, interfejs tylko do odczytu i widoczność danych finansowych zależną od uprawnień; testy katalogu zachowują również istniejącą funkcjonalność dla administratora.
- `npm run build`: **zaliczony**, łącznie z TypeScript `tsc -b`. Vite zgłosił istniejące ostrzeżenie o dużym bundlu (>500 kB).
- Skoncentrowany lint dla zmienionych plików: **zaliczony**.
- Po wdrożeniu: `/` zwróciło HTTP 200, `/boss/raw-materials` zwróciło HTTP 200, a `/api/health` przez proxy HTTP 200.
- W nowej, niezalogowanej karcie przeglądarki otwarcie `/boss/raw-materials` zmieniło adres na `/raw-materials`; widoczna była jedna pozycja nawigacji „RAW” i nagłówek „Katalog surowców”. Karta nie była sesją właściciela. Odpowiedzi 401 dla chronionych zapytań API w tej niezalogowanej karcie są oczekiwane i nie stanowią testu konta BOSS.

Nie powtarzano testów backendowych ani pełnej regresji: zmiana nie dotyczyła backendu. Zatwierdzona wcześniej pełna regresja backendu zakończyła się wynikiem **601 testów / 5975 asercji — zaliczona**. W jej zakresie są m.in. `BossRawMaterialCatalogAuthorizationTest` (w tym ograniczenia finansowe w kontekście restauracji) oraz `BossLocationRestrictionsTest` (odczyt BOSS dla kuchni centralnej i restauracji, z uwzględnieniem ograniczeń kategorii). Kod i obraz backendu nie zostały zmienione w ramach tej poprawki.

## Wdrożenie lokalne

Przed wdrożeniem potwierdzono efektywną konfigurację Compose:

- projekt: `veltra-stage6a1`;
- baza i wolumen: `veltra_stage6a1` / `veltra-stage6a1_postgres-data`;
- frontend: `127.0.0.1:5174`;
- backend: `127.0.0.1:8001`;
- PostgreSQL: `veltra-postgres:17-alpine`.

Zbudowano i wdrożono wyłącznie frontend:

- nowy tag: `veltra-stage6a1-frontend:stage7a0.1-raw-nav-20261125-1416-e228f5e`;
- image ID: `sha256:0c05ffa117fb59a2a2b41f38ad1cc5cd19799b48f06d6d8bbd873fd6be0ee5bc`;
- poprzedni obraz zachowany: `veltra-stage6a1-frontend:stage7a0.1-20261125-e228f5e-wt1` (`sha256:bcb784985d2e3c43c2567917ec5e179ab36cc583fb88c2bcbeed3b8743b2a612`);
- wersjonowana konfiguracja wydania: `compose.stage6a1.release-7a0.1-raw-navigation-20261125-e228f5e.yaml`.

Frontend po wdrożeniu jest `healthy`. Kontenery backendu, kolejki, PostgreSQL, Redis i Mailpit zachowały poprzednie identyfikatory i stan zdrowia; nie zostały odtworzone. Compose zgłosił istniejący osierocony kontener migracyjny `veltra-stage6a1-migrate-795356faae84-20261123-deployment`; pozostawiono go bez zmian i nie użyto `--remove-orphans`.

Nie uruchamiano migracji ani nie zmieniano danych, dostępności `RAW_MATERIALS`, stanu capability, kont, grantów czy sekretów. Backend i konfiguracja infrastruktury pozostały na dotychczasowych obrazach.

Rollback frontendu: użyć dotychczasowego pliku `compose.stage6a1.release-7a0.1-20261125-e228f5e-wt1.yaml` zamiast nowej nakładki i wykonać dla projektu `veltra-stage6a1` `docker compose up -d --no-deps --force-recreate frontend`.

## Stan Git i zakres

- Branch: `feature/stage7a-rbac-alignment`
- HEAD: `e228f5ebc1fe6f1d8f3f8dd81b8518230a4a4315`
- Zmiany i pliki nieśledzone istniejące przed tym zadaniem zachowano.
- Nie wykonano commit, push, merge, migracji ani wdrożenia backendu.
- Nie rozpoczęto Stage 7A.1.
