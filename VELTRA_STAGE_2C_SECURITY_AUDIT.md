# VELTRA 2.0 — niezależny audyt bezpieczeństwa Stage 2C

**Data audytu:** 3 listopada 2026  
**Zakres:** wdrożone Stage 2C — dostawcy, produkty i opakowania, oferty zakupowe, ceny referencyjne, FX oraz integracja z rdzeniem Stage 2B.  
**Rekomendacja:** **REJECT** do czasu usunięcia obu potwierdzonych problemów kontroli dostępu i ponownej niezależnej weryfikacji.

Audyt obejmował specyfikację Stage 2C, jej przegląd architektoniczny, raport implementacji, końcowy audyt Stage 2B oraz rzeczywiste kontrolery, serwisy, routing, migracje, triggery i testy. Raport implementacji nie był traktowany jako dowód. Nie zmieniano kodu aplikacji ani migracji.

## Podsumowanie ustaleń

| # | Ważność | Plik | Linie | Ustalenie | Pewność |
|---|---|---|---|---|---:|
| 1 | 🟡 MEDIUM | [RawMaterialCommercialService.php](/home/veltra/projekty/veltra/backend/app/Domain/RawMaterials/RawMaterialCommercialService.php:670) | 670, 1177–1207 | Odczyt ceny referencyjnej ujawnia kwotę, walutę i identyfikator źródłowej oferty bez `PURCHASING_COSTS_VIEW`. | 10/10 |
| 2 | 🟡 MEDIUM | [RawMaterialCommercialService.php](/home/veltra/projekty/veltra/backend/app/Domain/RawMaterials/RawMaterialCommercialService.php:323) | 323–438, 990–1007 | Zapis nowej oferty/ceny i wyprowadzenie ceny referencyjnej ze źródła nie egzekwują konsekwentnie stanu cyklu życia surowca, produktu i dostawcy. | 10/10 |
| 3 | ⚪ LOW | [RawMaterialCommercialService.php](/home/veltra/projekty/veltra/backend/app/Domain/RawMaterials/RawMaterialCommercialService.php:533) | 533–605 | Udane ponowione żądanie synchronizacji NBP może zwrócić 502 podczas niedostępności NBP, zanim zostanie odtworzony wynik z idempotencji. | 9/10 |
| 4 | ⚪ LOW | [2026_11_03_000013_create_raw_materials_commercial_domain.php](/home/veltra/projekty/veltra/backend/database/migrations/2026_11_03_000013_create_raw_materials_commercial_domain.php:27) | 27–53 | Funkcja HALF-EVEN nie zachowała kontrolowanego wejścia z 14 miejscami po przecinku w sprawdzonym przypadku dużej części całkowitej. | 9/10 |
| 5 | ⚪ LOW | [2026_11_02_000001_create_installation_structure.php](/home/veltra/projekty/veltra/backend/database/migrations/2026_11_02_000001_create_installation_structure.php:70) | 70, 172 | `migrate:fresh` po pozostawieniu funkcji PostgreSQL nie przechodzi; dotyczy czyszczenia środowiska testowego, nie normalnej migracji naprzód. | 10/10 |

Ustalenia 3–5 nie zmieniają podstawy werdyktu REJECT, lecz wymagają uwzględnienia przed uznaniem kompletności operacyjnej, finansowej i migracyjnej.

## Potwierdzone problemy bezpieczeństwa

### 1. Cena referencyjna ujawnia koszty zakupu — MEDIUM

**Obszar:** rozdział RBAC pomiędzy `REFERENCE_PRICE_VIEW` i `PURCHASING_COSTS_VIEW`.

Metoda `referencePrices()` autoryzuje wyłącznie odczyt cen referencyjnych. Prezentacja wpisu przez `presentReferencePrice()` dołącza jednak szczegóły źródła: kwotę zakupu, walutę oraz identyfikatory źródłowej oferty i kursu. Nie jest wymagana kontrola prawa odczytu kosztów przed serializacją tych pól.

**Odtworzenie wykonane podczas audytu:**

1. Utworzono cenę oferty 42,50 PLN za opakowanie zawierające 10 jednostek.
2. Na jej podstawie zapisano cenę referencyjną 4,25 PLN za jednostkę bazową.
3. Użytkownikowi przyznano wyłącznie `REFERENCE_PRICE_VIEW`, bez `PURCHASING_COSTS_VIEW`.
4. `GET /api/v1/raw-materials/{id}/reference-prices` zwrócił HTTP 200 wraz z fragmentem:

   ```json
   {
     "source": {
       "amount": "42.5",
       "currency": "PLN"
     }
   }
   ```

5. Ten sam użytkownik otrzymał HTTP 403 z endpointu `GET /api/v1/raw-materials/supplier-offers/{offer}/price-events`.

**Wpływ:** użytkownik uprawniony do foodcostu/referencji może odtworzyć kwoty i powiązania komercyjne, które są objęte osobnym permissionem. Przeczy to D-09 i deklarowanej separacji cen referencyjnych i zakupowych.

**Dotknięte powierzchnie:** `referencePrices()` i `presentReferencePrice()` w serwisie, endpointy `GET /{material}/reference-prices` oraz `GET /{material}/reference-prices/{event}/restatements` (jeśli serializer lub relacja prezentuje źródło).

### 2. Zapis finansowy nie respektuje cyklu życia encji — MEDIUM

**Obszar:** blokowanie/archiwizacja oraz kwalifikacja źródeł finansowych.

Kod ścieżek tworzenia ofert i zdarzeń cenowych sprawdza wybrane stany oferty, produktu i dostawcy, ale nie wymusza aktywnego, bieżąco zatwierdzonego surowca w każdym punkcie zapisu. `referencePriceSource()` waliduje dopasowanie źródłowej rewizji do surowca i daty oferty, lecz pomija wymagane aktualne stany cyklu życia produktu/dostawcy. Trigger `raw_materials_commercial_event_validate()` w bazie nie zamyka tej luki.

**Odtworzenie wykonane podczas audytu:**

1. Utworzono zatwierdzony surowiec, produkt dostawcy i ofertę.
2. Zablokowano surowiec.
3. Zapisano nowe zdarzenie `PRICE_SET`: HTTP 201; odpowiedź miała `normalization_status=NORMALIZED`, mimo że surowiec pozostawał `BLOCKED`.
4. Utworzono kolejną ofertę dla produktu: HTTP 201.
5. Odblokowano surowiec i zarchiwizowano dostawcę.
6. Utworzono cenę referencyjną z historycznego zdarzenia oferty zarchiwizowanego dostawcy: HTTP 201.

**Wpływ:** użytkownicy z odpowiednimi permissionami mogą dodawać nowe komercyjne dane lub ustalać nową cenę referencyjną na podstawie źródła, które nie powinno być kwalifikowane do nowego użycia według zatwierdzonych zasad blokowania/archiwizacji. Nie wykazano, że umożliwia to operacyjne zakupy albo użycie w produkcji; te workflowy są poza zakresem Stage 2C.

**Dotknięte powierzchnie:** `createOffer()`, `recordOfferPriceEvent()`, `referencePriceSource()` oraz wyzwalacze walidujące `supplier_offer_events`. Historyczne odczyty i poprawne zdarzenia wycofania powinny pozostać dostępne; naprawa powinna ponownie sprawdzać stan w transakcji i mieć zgodną ochronę bazodanową.

## Pozostałe ustalenia techniczne

### Ponowienie synchronizacji NBP zależy od dostępności upstream — LOW

`syncNbpRate()` pobiera dane NBP przed wejściem do `mutate()`, gdzie sprawdzany jest klucz idempotencji. Po udanej synchronizacji ponowienie identycznego żądania podczas niedostępności NBP zakończyło się HTTP 502, mimo że wynik operacji został już zapisany. Jest to problem dostępności/idempotencji, a nie modyfikacja istniejącego kursu lub ominięcie autoryzacji.

### Niedokładność precyzji HALF-EVEN — LOW

Kontrolowana próba wywołania funkcji dla `50000000.12345678901234` zwróciła `50000000.12345678901200`. Nie uzyskano deklarowanego dokładnego zachowania wejścia o skali 14 w tym przypadku. Może to zmieniać małe części kwoty dla dużych wartości. Nie stwierdzono zewnętrznego wykorzystania, lecz deklarację pełnej precyzji należy uzgodnić z wynikiem funkcji i ograniczeniami kolumn.

### Ograniczenie testów wielotenantowych

Utworzenie drugiej organizacji instalacyjnej zostało odrzucone kodem SQLSTATE `23505` przez unikalne ograniczenie jednej instalacji (`organizations_single_installation_unique`) w migracji instalacyjnej. Ograniczenie odpowiada aktualnemu modelowi pojedynczej instalacji, więc nie należy go usuwać tylko na potrzeby audytu. W konsekwencji nie potwierdzono dynamicznie izolacji między dwiema rzeczywistymi organizacjami. Sprawdzono scope zapytań oraz zachowanie dla nieznanych UUID, ale nie zastępuje to testu dwóch tenantów w zatwierdzonej architekturze, która taki scenariusz obsługuje.

### Ścieżka przyznania nowych permissionów przy aktualizacji Stage 2B

Nie znaleziono wspieranej ścieżki addytywnego bootstrapu nowych permissionów dla organizacji już zainicjalizowanej. Początkowy bootstrap odmawia ponownej inicjalizacji, a delegowanie wymaga, by delegujący już posiadał dane uprawnienie. Testowe fixture’y nadają nowe enumy permissionów i nie dowodzą obsługiwanej aktualizacji istniejącej organizacji. Wymaga to wyjaśnienia przed wdrożeniem migracji na istniejącym środowisku Stage 2B; nie należy rozwiązywać tego przez automatyczny grant.

## Pozytywne wyniki sprawdzeń

Poniższe wyniki pochodzą z uruchomień wykonanych przez audytora w tej sesji, a nie z raportu implementacji:

- Migracje na świeżej, jednorazowej bazie PostgreSQL: **PASS**.
- Skupione `RawMaterialsTest.php`: **14 testów, 161 asercji — PASS**.
- Pełny backend PHPUnit: **166 testów, 1356 asercji — PASS**.
- Wszystkie **36 zarejestrowanych tras**, w tym 19 tras Stage 2C, odrzuciły żądania przy `RAW_MATERIALS` niedostępnym; weryfikacja używała prawidłowych zasobów i testowego fixture’u dostępności.
- Aktualny kod produkcyjny zachowuje `ready=false`, a nowe trasy korzystają z `RequireRawMaterialsAvailability`.
- Operacje UPDATE/DELETE historii zostały odrzucone SQLSTATE `23514` dla ośmiu tabel rewizji/zdarzeń/audytu/idempotencji.
- Bezpośrednie próby zapisu kwot ujemnych, o nadmiernej skali, poza zakresem, `NaN` i `Infinity` zostały odrzucone; sfałszowana migawka normalizacji nie przeszła walidacji.
- Kontrolowane odpowiedzi NBP: niepoprawny JSON, upstream HTTP 503 i błąd połączenia zwróciły kontrolowane HTTP 502 bez zapisu kursów.
- Zweryfikowano wybór kursu dla weekendu, błąd przy braku wcześniejszego kursu, brak zmiany wcześniejszych migawek po ręcznym nadpisaniu oraz trwałe cofnięcie delegowanego permissionu po reaktywacji grantora.
- Próba kilku procesów z serwisem zweryfikowała sekwencyjne zdarzenia, wpisy audytu i idempotencję, jednak nie udowodniła procesu oczekiwania na blokadę zgodnie z zamierzoną barierą. Nie zastępuje równoległych żądań HTTP przez pełny stos aplikacji.

## Izolacja audytu i ograniczenia

- Wszystkie testy destrukcyjne wykonano wyłącznie w jednorazowych, izolowanych kontenerach PostgreSQL/Redis, bez opublikowanych portów i bez mountów istniejących danych aplikacji.
- Kod zamontowano tylko do odczytu; katalogi runtime były tymczasowe.
- Nie uruchomiono migracji na bazie aplikacyjnej, nie zmieniono konfiguracji ani wolumenów aplikacji.
- Kontenery i sieć audytowa zostały usunięte po testach.
- Nie wykonano napraw ani zmian kodu źródłowego.
- Nie wykonano kontrolowanego testu dwóch aktywnych tenantów z powodu wymuszonej pojedynczej instalacji.
- Nie wykonano równoległych żądań HTTP przez rzeczywisty serwer aplikacyjny.

## Problem `migrate:fresh` i funkcji PostgreSQL

**Klasyfikacja: LOW — ograniczenie odtwarzania środowiska testowego, nie potwierdzona luka bezpieczeństwa Stage 2C.**

Na jednorazowej bazie odtworzono sekwencję:

1. Migracje zakończyły się powodzeniem.
2. `migrate:fresh` usunął tabele, ale nie wywołał `down()` wcześniejszej migracji.
3. Pozostała funkcja `serialize_installation_mutation`.
4. Ponowne `up()` przerwało się błędem PostgreSQL `42723 Duplicate function`.

Funkcja jest tworzona w `2026_11_02_000001_create_installation_structure.php`; odpowiadające `down()` jawnie usuwa funkcję. Zachowanie wynika z różnicy pomiędzy czyszczeniem tabel przez `migrate:fresh` i odwracaniem migracji. Normalna migracja naprzód na czystej bazie zadziałała. Nie stwierdzono wpływu na dane aplikacyjne ani na kontrolę dostępu.

## Werdykt końcowy

**REJECT.** Dwa odtwarzalne naruszenia separacji kosztów i egzekwowania cyklu życia wymagają naprawy oraz ponownego niezależnego audytu przed akceptacją Stage 2C. Wyniki pozytywnych testów nie neutralizują tych błędów, ponieważ wystąpiły na poprawnie uwierzytelnionych użytkownikach z prawidłowymi grantami.

`RAW_MATERIALS` pozostaje `NOT_READY` i nieaktywne. Raport nie jest zgodą na wdrożenie, migrację bazy aplikacyjnej ani rozpoczęcie Stage 2D.
