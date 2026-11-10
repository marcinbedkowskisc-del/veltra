# VELTRA 2.0 — Stage 4B.2A

## Raport audytu i blokady formularza tworzenia RAW

**Status: BLOCKED — implementacja formularza nie została rozpoczęta.**

## 1. Ustalenie blokujące

Wymaganie Stage 4B.2A nakazuje pokazywać i udostępniać akcję „Dodaj surowiec” wyłącznie osobom z efektywnym grantem `RAW_MATERIALS_CREATE`, bez wnioskowania o nim z samej widoczności katalogu. W obecnym frontendzie i kontraktach odczytu nie ma bezpiecznego, bezskutkowego endpointu zwracającego skuteczny grant RAW dla bieżącego użytkownika i lokalizacji:

- `/api/v1/auth/me` zwraca profil, a nie uprawnienia;
- katalog i `/categories` wymagają odczytu RAW, ale nie zwracają zdolności tworzenia;
- `/api/v1/security/users/{user}/assignments` prezentuje rekordy przypisań/uprawnień, a nie autorytatywne efektywne granty RAW po zastosowaniu wygaśnięcia, delegowania i unieważnień;
- `POST /api/v1/raw-materials/sku-reservations` sprawdza `RAW_MATERIALS_CREATE`, ale rezerwuje SKU, zapisuje zdarzenie audytu, zużywa limit rezerwacji i przydzielony numer nie wraca do puli. Nie wolno wywoływać tego endpointu jako sondy autoryzacji.

Dodanie właściwego odczytu capabilities wymagałoby zmiany backendu/RBAC API, co jest poza zakresem Stage 4B.2A. Pokazanie przycisku wszystkim użytkownikom i oczekiwanie na 403 dopiero przy rezerwacji lub zapisie naruszałoby wymóg widoczności wyłącznie dla uprawnionych. Ukrycie przycisku zawsze uniemożliwiłoby użycie funkcji także osobom uprawnionym. Nie wybrano żadnego z tych niezgodnych obejść.

Niezależnie od tego `RAW_MATERIALS` pozostaje `NOT_READY`; grupa tras RAW przepuszcza żądania przez `RequireRawMaterialsAvailability`, więc rzeczywiste tworzenie nie może być wykonane w bieżącym stanie. Syntetyczny preview nie usuwa braku kontraktu autoryzacji produkcyjnej.

## 2. Zweryfikowany kontrakt tworzenia

- Trasa: `POST /api/v1/raw-materials`, zdefiniowana w `backend/routes/api.php`; kontroler `RawMaterialController::store`.
- Autoryzacja: `RawMaterialService::create` sprawdza `RawMaterialPermission::Create` (`RAW_MATERIALS_CREATE`) przed transakcją i ponownie w transakcji.
- Bramki: trasa należy do grupy wymagającej sesji SPA, Sanctum, zweryfikowanego kontekstu tożsamości i lokalizacji oraz dostępności `RAW_MATERIALS`.
- Odpowiedź: HTTP 201 z `data` zawierającym m.in. serwerowy `id`, `code`/`sku`, `lifecycle_state`, `version` i `revision`. Nowy rekord rozpoczyna jako `DRAFT`.
- Żądanie waliduje `RawMaterialDataRequest`:
  - wymagane: `category_id` (UUID istniejącej kategorii organizacji), `name` (do 200 znaków), `base_unit_code` (`mg`, `g`, `kg`, `ml`, `l`, `pc`);
  - opcjonalne: `description` (nullable, do 10 000 znaków), `nominal_quantity` i `usable_quantity` (tekst dziesiętny o maks. 18 cyfrach całkowitych i 6 miejscach dziesiętnych), `aliases` (maks. 50 wpisów po 200 znaków), `conversions` (maks. 20 wpisów);
  - jeśli podano ilość, reguła domenowa wymaga obu ilości, wartości dodatnich, a dla `pc` wartości całkowitych;
  - współczynniki konwersji są tekstami dziesiętnymi do 12 miejsc. Jednostki metryczne o tym samym wymiarze mają kontrolowany współczynnik systemowy; konwersje między wymiarami wymagają współczynnika i podstawy.
- SKU i identyfikatory są serwerowe; formularz nie może ich sam generować. Istnieje `POST /api/v1/raw-materials/sku-reservations`, wymagający `Idempotency-Key` oraz grantu tworzenia. Rezerwacja trwa 24 godziny, jest audytowana i nie odzyskuje przydzielonego numeru po porzuceniu.
- W kontrakcie tworzenia nie ma bezpośrednich pól dla dostawcy, alergenów ani danych finansowych. Nie wolno ich dodawać do formularza bez właściwego kontraktu.
- Kategorie pobiera `GET /api/v1/raw-materials/categories` z prawem VIEW. Tworzenie/zmiana kategorii jest osobną operacją wymagającą `RAW_MATERIALS_CATEGORY_MANAGE`; nie jest częścią formularza.

## 3. Audyt przed implementacją i stan repozytorium

Odczytano trasy i kontroler RAW, `RawMaterialDataRequest`, `RawMaterialService::create`, `RawMaterialUnitRegistry`, `RawMaterialCatalogValues`, serwis rezerwacji SKU, trasy i walidator bezpieczeństwa, frontendową abstrakcję API, `AuthPanel`, katalog oraz raporty Stage 4B.1 i zgodności RAW Stage 2F/3E.

Na początku etapu `git status --short` wykazywał istniejące zmiany Stage 4A.2 i 4B.1, w tym modyfikacje plików katalogu, testów, stylów, preview, raporty i artefakty. Zachowano je bez resetu, checkoutu, stashowania ani nadpisania. `git diff --check` na początku etapu zakończył się powodzeniem.

**Jedyny plik dodany w Stage 4B.2A:** `VELTRA_STAGE_4B2A_RAW_CREATE_FORM_REPORT.md`. Nie zmieniono kodu aplikacji ani testów.

## 4. Zachowanie i walidacja

Implementacji formularza, testów Stage 4B.2A, podglądu Stage 4B.2A ani zrzutów nie wykonano, ponieważ nie da się spełnić wymogu dostępu i widoczności bez wiarygodnego odczytu uprawnienia. Nie uruchomiono builda, lint ani testów w tym etapie. Ostatni dostępny wynik Stage 4B.1 odnotowuje 38 testów frontendowych PASS, build PASS, lint PASS z jednym wcześniejszym ostrzeżeniem Fast Refresh oraz `git diff --check` PASS; wyniki te nie są nową weryfikacją Stage 4B.2A.

Brak zrzutów jest celowy: nie utworzono ani nie przedstawiono jako rzeczywistej funkcji widoków formularza, walidacji, zapisu, sukcesu czy odmowy dostępu, których frontend nie implementuje.

## 5. Wymagane rozstrzygnięcie przed wznowieniem

Przed kontynuacją właściciel/koordynator musi wskazać zatwierdzony sposób odczytu efektywnego `RAW_MATERIALS_CREATE` w bieżącym kontekście. Jeśli rozwiązanie wymaga nowej trasy lub zmiany API, należy osobno zatwierdzić i wykonać zakres backendowy, zanim frontend będzie mógł niezawodnie warunkować widoczność akcji. Nie używać rezerwacji SKU, tworzenia szkicu ani odpytywania zakończonego 403 jako testu uprawnień.

Po usunięciu blokady pozostają: implementacja formularza na podstawie powyższego kontraktu; testy regresji oraz obsługi odpowiedzi 401/419/403/409/422/503 i opóźnionych odpowiedzi; syntetyczny preview; siedem rzeczywistych zrzutów w wymaganych wymiarach; pełna walidacja frontendowa; raport końcowy i dostarczenie artefaktów do Drive.

## 6. Google Drive i lokalny raport

Utworzono dedykowany folder [VELTRA_STAGE_4B2A_RAW_CREATE_FORM](https://drive.google.com/drive/folders/1p1_Dqz6suZwIcsalfDdaOGm8Pv2ZIOxt) w autoryzowanym katalogu raportów. Raportu nie udało się przesłać ani zweryfikować w Drive: aktywny selektor plików przeglądarki odrzucił lokalną ścieżkę WSL komunikatem `UNC host 'wsl.localhost' access is not allowed`. Nie zastosowano obejścia ograniczenia przeglądarki i nie załadowano poświadczeń. Folder pozostaje pusty; nie deklaruje się uploadu.

Raport jest dostępny lokalnie:

- Linux: `/home/veltra/projekty/veltra/VELTRA_STAGE_4B2A_RAW_CREATE_FORM_REPORT.md`
- Windows: `\\wsl.localhost\Ubuntu\home\veltra\projekty\veltra\VELTRA_STAGE_4B2A_RAW_CREATE_FORM_REPORT.md`

## 7. Granice wykonania

Nie zmieniano backendu, RBAC, baz danych, migracji ani zasobów instalacji. Nie wykonano żądań tworzenia ani rezerwacji SKU, nie uruchomiono preview ani innych procesów Stage 4B.2A, nie wdrożono, nie commitowano ani nie pushowano. Istniejące prace Stage 4A.2 i 4B.1 pozostają zachowane.

**`RAW_MATERIALS = NOT_READY`.** Etap zatrzymano na audycie; nie rozpoczęto Stage 4B.2B ani nie wykonano aktywacji modułu.
