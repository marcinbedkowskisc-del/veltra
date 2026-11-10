# VELTRA 2.0 — Stage 4B.2B-P

## Audyt kontraktu edycji RAW_MATERIALS

**Tryb:** autoryzowany audyt architektury wyłącznie do odczytu  
**Werdykt:** **READY WITH PREREQUISITE**  
**Gotowość modułu:** `RAW_MATERIALS = NOT_READY`

## 1. Podsumowanie i werdykt

Backend ma już działający, wersjonowany kontrakt edycji istniejącego surowca: odczyt edytora, zapis nowej rewizji, sprawdzanie lifecycle, obowiązkowy `If-Match`, ponowną autoryzację, blokady transakcyjne, zapis audytu i ochronę historii. Odpowiadające mu testy są w repozytorium. Nie ma potrzeby dodawania trasy edycji ani zmiany schematu.

Warunkiem rozpoczęcia formularza jest małe rozszerzenie istniejącego endpointu capabilities o `permissions.edit` i `actions.edit`. Frontend powinien dodatkowo pytać istniejący endpoint `GET /{material}/completion` i pokazywać przycisk tylko wtedy, gdy jego `available_actions` zawiera `edit`. Ta odpowiedź jest już wyliczana per rekord przez backend i uwzględnia lifecycle oraz wymagania ochrony zawartości rewizji; sam globalny grant nie wystarcza do określenia edytowalności konkretnego surowca.

Wymagana jest osobna autoryzacja `RAW_MATERIALS_EDIT`; `VIEW` jest potrzebne do odczytu edytora, ale nie daje prawa zapisu. `CREATE` nie daje prawa edycji. Alergeny i przechowywanie mają własne uprawnienia i trasy. Nie należy dodawać nowego endpointu, permission, migracji ani frontendowego odgadnięcia reguł lifecycle.

Audyt obejrzał bieżące źródła i testy, nie wykonywał żądań do instalacyjnej bazy ani testów mutujących dane. Przed rozpoczęciem working tree był czysty (`## main...origin/main`).

## 2. Zweryfikowane endpointy i metody

Wszystkie endpointy RAW poniżej znajdują się w grupie sesji SPA, uwierzytelnienia, walidacji tożsamości i kontekstu operacyjnego, limitu żądań oraz middleware `RequireRawMaterialsAvailability`.

| Cel | Endpoint | Zweryfikowane zachowanie |
|---|---|---|
| Bieżące dane edytora | `GET /api/v1/raw-materials/{material}/editor` | Zwraca `data` z rewizją roboczą, oczekującą albo zatwierdzoną (w tej kolejności), tokenem `version`, deklaracją alergenów i wariantami przechowywania. Wymaga VIEW, primary context oraz EDIT lub AUDIT_VIEW do wglądu w rewizję roboczą. |
| Edycja danych podstawowych | `PATCH /api/v1/raw-materials/{material}/draft` | Wywołuje `RawMaterialService::revise`; tworzy nową rewizję, nie nadpisuje istniejącej. Wymaga EDIT i `If-Match`. |
| Edycja deklaracji alergenów | `PUT /api/v1/raw-materials/{material}/draft/allergen-declaration` | Osobny zapis rewizji; wymaga `RAW_MATERIALS_ALLERGEN_MANAGE` i `If-Match`. |
| Edycja przechowywania | `PUT /api/v1/raw-materials/{material}/draft/storage-variants` | Osobny zapis rewizji; wymaga `RAW_MATERIALS_STORAGE_MANAGE` i `If-Match`. |
| Ocena kompletności i akcji rekordu | `GET /api/v1/raw-materials/{material}/completion` | Zwraca `lifecycle_state`, `revision_id`, dozwolone `version`, problemy oraz `available_actions`, w tym istniejące `edit`. To właściwa odpowiedź do bramkowania konkretnego rekordu w UI. |
| Historia rewizji | `GET /api/v1/raw-materials/{material}/revisions` | Czyta wersje historyczne; nie umożliwia ich zmiany. |
| Przekazanie do weryfikacji | `POST /api/v1/raw-materials/{material}/submit` | Osobna, uprawniona zmiana lifecycle; również wymaga `If-Match`. |
| Akceptacja | `POST /api/v1/raw-materials/{material}/approve` | Publikuje rewizję oczekującą po walidacji; wymaga APPROVE i `If-Match`. |
| Przywrócenie archiwum | `POST /api/v1/raw-materials/{material}/restore` | Oddzielny workflow; tworzy rewizję do ponownej weryfikacji, nie jest edycją archiwum. |

Trasy i mapowanie kontrolera znajdują się w `backend/routes/api.php` oraz `backend/app/Http/Controllers/Api/V1/RawMaterialController.php`. W kodzie nie znaleziono endpointu HTTP `PUT`/`PATCH` dla całego mastera poza wskazanym `PATCH .../draft`.

## 3. Pola edytowalne i niezmienne

### Dane podstawowe: `PATCH .../draft`

| Pole | Zachowanie |
|---|---|
| `category_id`, `name`, `base_unit_code` | Wymagane w żądaniu. |
| `description` | Opcjonalne; pominięcie jest serializowane jako `null`, więc formularz powinien wysyłać aktualną wartość albo jawne wyczyszczenie. |
| `nominal_quantity`, `usable_quantity` | Opcjonalne, ale gdy przesyłane, walidowane jako para; zmiana jednostki bazowej przy istniejących ilościach wymaga jawnego przesłania ilości. Ilości są wartościami dziesiętnymi, do 6 miejsc po separatorze. |
| `aliases`, `conversions` | Opcjonalne w walidatorze, lecz brak oznacza pusty zestaw przy budowie nowej rewizji. Aby zachować bieżące wartości, formularz musi je wczytać i przesłać; wyczyszczenie powinno być jawne. Maksymalnie 50 aliasów i 20 przeliczników. |
| `change_reason` | Opcjonalny tekst, maksymalnie 500 znaków. |
| `document_upload_ids`, `detach_document_version_ids` | Opcjonalne UUID-y do dołączenia/odłączenia dokumentów w nowej rewizji. `RawMaterialDocumentSetService` domyślnie kopiuje zestaw bazowy i zmienia go wyłącznie zgodnie z przesłanymi operacjami. Nie ma ich w obecnym formularzu tworzenia. |

`PATCH` nie oznacza tu dowolnego, bezpiecznego sparse merge. Pola podstawowe kategorii, nazwy i jednostki są wymagane, a pominięcie pól opcjonalnych może wyczyścić opis, aliasy lub przeliczniki. Formularz ma pobrać całą bieżącą rewizję z `/editor` i wysyłać pełny zestaw danych podstawowych.

Jednostki i konwersje sprawdza backendowy `RawMaterialUnitRegistry` oraz walidacja domenowa. Frontend może ponownie użyć walidatorów tworzenia, ale odpowiedź serwera pozostaje rozstrzygająca.

### Oddzielne, uprawnione sekcje

`allergen_declaration` i `storage_variants` należą do treści rewizji, lecz nie są polami zwykłego `PATCH .../draft`. Mają osobne `PUT` i uprawnienia `RAW_MATERIALS_ALLERGEN_MANAGE` oraz `RAW_MATERIALS_STORAGE_MANAGE`. Przy utworzeniu zwykłej rewizji backend kopiuje te dane z rewizji bazowej. Akcja `available_actions.edit` może być niedostępna, jeżeli rekord zawiera chronione dane, których zachowanie wymaga dodatkowego uprawnienia. UI nie może zastępować tych reguł samym grantem EDIT.

### Dane niezmienne

- Master zachowuje `id` i kod/SKU; `sku_reservation_id` jest zabronione przy PATCH. Edycja nie rezerwuje ani nie generuje nowego SKU.
- Każda rewizja ma własne niezmienne `revision.id` i rosnący `revision_no`. Poprzednie rewizje, zatwierdzenia, dokumenty i audyt pozostają historią.
- `lifecycle_state`, wskaźnik rewizji zatwierdzonej i wskaźniki working/pending nie są polami edytora. Zmieniają je odrębne endpointy workflow.

## 4. Lifecycle, rewizje i ich identyfikatory

| Stan mastera | Edytowalność według kodu |
|---|---|
| `DRAFT` | Tak, jeśli istnieje `working_revision_id` i brak rewizji oczekującej. PATCH tworzy kolejną rewizję roboczą, stan pozostaje DRAFT. `submit` przeprowadza szkic do VERIFICATION. |
| `ACTIVE` | Tak, gdy nie ma rewizji pending. PATCH tworzy rewizję working z `critical_change=true`; dotychczasowa zatwierdzona rewizja pozostaje obowiązująca. `submit` przenosi working do pending, zachowując stan ACTIVE; dopiero APPROVE przesuwa wskaźnik zatwierdzonej rewizji. |
| `VERIFICATION` | Nie. Rewizja pending jest rozpatrywana przez REVIEW/APPROVE/RETURN, a nie edytowana. |
| `BLOCKED` | Nie. Blokada ma osobny workflow; odblokowanie wymaga oddzielnych uprawnień i wersji. |
| `ARCHIVED` | Nie. Restore tworzy nową rewizję i przeprowadza rekord do VERIFICATION, a następnie wymaga ponownego approval. |
| Tombstone szkicu | Nie. Tombstone to `deleted_at` na zachowanym masterze, a nie osobna wartość `lifecycle_state`. Odczyty `/editor`, `/completion` i pozostałe zwykłe odczyty zwracają 404. Usunięcie DRAFT jest odrębnym, wersjonowanym workflow `DELETE .../{material}/draft` z niedelegowalnym `RAW_MATERIALS_DELETE_DRAFT`; historia i SKU pozostają. |

Aktualna reguła `RawMaterialService::isEditable` dopuszcza wyłącznie DRAFT i ACTIVE bez pending; DRAFT wymaga rewizji working. Rewizja aktywnego materiału jest oznaczana jako krytyczna przez backend. Formularz nie może sam publikować jej ani sugerować, że stan aktywnej, zatwierdzonej rewizji się zmienił.

**Rozróżnienie pojęć:**

- `material.id` — trwała tożsamość mastera; nie zmienia się przy edycji.
- `version` / `edit_version` — nieprzezroczysty UUID optymistycznej współbieżności dla całego mastera; nie jest numerem rewizji. Migracja 043 generuje nowy UUID po każdym UPDATE mastera, również po przejściu lifecycle.
- `revision.id` — trwała tożsamość pojedynczej, append-only wersji danych.
- `revision.revision_no` — kolejny numer rewizji tego mastera.
- `lifecycle_state` — niezależny stan workflow mastera.
- rewizja historyczna — niezmienny zapis danych i jej zdarzeń approval; nie jest bieżącym tokenem zapisu.

**Przykład dozwolonej ścieżki:** ACTIVE z zatwierdzoną v4, bez pending → odczyt `/editor` zwraca wersję mastera i aktualną rewizję → PATCH tworzy v5 jako working, master nadal ACTIVE i nadal wskazuje zatwierdzoną v4 → SUBMIT tworzy pending dla v5 → APPROVE ustawia v5 jako zatwierdzoną. Historia v4 nie jest nadpisywana. Dla DRAFT edycja tworzy kolejną working rewizję bez zmiany stanu, a SUBMIT rozpoczyna weryfikację.

## 5. Współbieżność i wynik niejednoznaczny

- Token jest zwracany jako `data.version` przez `/editor`; API nie używa ETag. Każda aktualizacja i przejście lifecycle musi przesłać `If-Match: <version>`; obsługiwane są UUID bez cudzysłowów i z cudzysłowami.
- Kontrola następuje w transakcji po ponownej autoryzacji, blokadzie organizacji i blokadzie materiału. Zapis rewizji i audytu jest atomowy.
- Brak tokena: **428 `RAW_MATERIAL_VERSION_REQUIRED`**. Nieprawidłowy format: **422 `RAW_MATERIAL_VERSION_INVALID`**. Nieaktualny token: **409 `RAW_MATERIAL_VERSION_CONFLICT`**.
- Przy 409 backend nie zwraca aktualnej rewizji ani tokena. Komunikat brzmi: „Surowiec został zmieniony. Wczytaj aktualną wersję; lokalne zmiany zostaną utracone.” Backend nie scala pól i nie nadpisuje po konflikcie.
- PATCH nie ma klucza idempotencji. Ponowienie z tym samym starym `If-Match` po zaakceptowanym zapisie nie wykona drugiej rewizji — zakończy się konfliktem. Nie daje to jednak sposobu rozstrzygnięcia, czy pierwsze żądanie dotarło, ani czy w międzyczasie zapisał ktoś inny. Po timeout/zerwaniu połączenia UI ma pokazać wynik niepewny, pobrać aktualny `/editor`/`completion` i wymagać decyzji użytkownika; nie może automatycznie ponowić zapisu z nowym tokenem.
- Zmiana lokalizacji unieważnia stary kontekst i token w UI. Przed zapisem ponownie sprawdza się capabilities/rekord, a backend niezależnie weryfikuje membership, organizację, primary context i grant. Odpowiedzi asynchroniczne z poprzedniej lokalizacji muszą zostać przerwane lub zignorowane.

## 6. Statusy i odpowiedzi

| Sytuacja | Oczekiwana odpowiedź | Zachowanie UI |
|---|---|---|
| Zapis nowej rewizji | HTTP 200, `data` zawiera m.in. `id`, `code`/`sku`, `lifecycle_state`, nowy `version` oraz `revision` z `id`, `revision_no` i polami rewizji | Potwierdź wyłącznie zapis zwrócony przez serwer; nie komunikuj approval/publikacji. |
| Błąd pól/reguł domeny | HTTP 422, stabilny kod i/lub błędy walidacji | Zachowaj formularz i mapuj błędy do pól. |
| Brak/stara wersja | HTTP 428/409, kody opisane wyżej | Blokuj zapis; bez automatycznego merge/ponowienia. |
| Brak grantów/kontekstu | HTTP 403 | Usuń akcję i stan edycji; poinformuj po polsku o braku dostępu. |
| Inny/ukryty master lub tombstone | HTTP 404 | Neutralny komunikat, bez ujawniania danych. |
| RAW niedostępny lub NOT_READY | HTTP 503 `RAW_MATERIALS_MODULE_UNAVAILABLE` | Zamknij/wyłącz formularz i wyczyść dane bieżącego kontekstu. |
| Wygasła sesja | HTTP 401/419 | Wstrzymaj zapis, obsłuż ponowne logowanie; nie wysyłaj kolejnego żądania. |
| Błąd sieci/timeout | Brak wiarygodnego statusu | Stan „nie można potwierdzić wyniku”; odczytaj serwer przed dalszą decyzją. |

Testy backendu potwierdzają 428/422/409, brak danych aktualnej rewizji w konflikcie, brak dopisania rewizji/audytu przy konflikcie oraz jeden zwycięski zapis przy równoległym wyścigu.

## 7. RBAC i rozszerzenie capabilities

`RawMaterialPermission` już zawiera `RAW_MATERIALS_EDIT`; `RawMaterialAuthorization` rozwiązuje efektywne granty organizacyjne wraz z aktywnością organizacji/użytkownika, ważnością i cofnięciem delegacji. Permission EDIT wymaga primary catalog context. Nie dodawać nowego permission ani nie wyliczać EDIT z CREATE, VIEW, roli lokalizacyjnej lub obecności przycisku.

Wymagania operacji podstawowej:

1. VIEW do obu odczytów. `/editor` dodatkowo wymaga primary context i EDIT lub AUDIT_VIEW; `/completion` poza primary może zwrócić zakres tylko do odczytu, bez tokena i akcji edycji.
2. EDIT do podstawowego PATCH; `AUDIT_VIEW` pozwala wglądać w working revision, ale nie upoważnia do zapisu.
3. EDIT nie zastępuje AllergenManage/StorageManage, gdy rekord zawiera chronione dane wymagające zachowania.
4. Każda mutacja ponownie sprawdza backendowy grant i readiness; capabilities jest tylko wskazówką interfejsu.

Aktualny `GET /api/v1/security/raw-materials/capabilities` zwraca wyłącznie `permissions.create` i `actions.create`. Minimalna zmiana przed frontendem:

- dodać `permissions.edit` jako efektywny grant `RAW_MATERIALS_EDIT`;
- dodać `actions.edit`, wyliczane z tego grantu, primary-context i dostępności modułu zgodnie z istniejącym wzorcem CREATE;
- zachować osobne obiekty `permissions` i `actions`, `readiness`, `available`, `location_id`, `private, no-store` oraz fail-closed dla NOT_READY/niedostępnej lokalizacji;
- wyliczać w `RawMaterialAuthorization`, nie w kontrolerze przez ręczne zapytania/role i nie w UI.

Globalne `actions.edit` nie wystarczy dla konkretnej pozycji. Do tego celu istnieje już `GET /{material}/completion` z `available_actions`. Rekomendacja to **wariant C**: rozszerzenie globalnego capabilities o EDIT oraz ponowne użycie istniejących, rekordowych akcji completion. Nie dodawać flagi `editable` do detail response ani nowego endpointu. UI ma wymagać równocześnie globalnej capability i `available_actions.includes('edit')`; serwer pozostaje źródłem ostatecznej autoryzacji.

## 8. Frontend: istniejące powierzchnie i bezpieczne ponowne użycie

- `RawMaterialsCatalogPage.tsx` pobiera capabilities dla lokalizacji, odrzuca spóźnione odpowiedzi, odświeża capabilities przed CREATE i obsługuje NOT_READY. Te same wzorce generacji/AbortController/location generation powinny bramkować EDIT.
- `RawMaterialDetailDrawer.tsx` pokazuje SKU, lifecycle, rewizję i historię, ale jest obecnie tylko do odczytu; nie pobiera `completion` i nie ma akcji edycji.
- `api.ts` ma parser capabilities tylko dla CREATE oraz adaptery detail/revisions; brakuje adapterów editor, completion i PATCH.
- `create.ts` ma jednostki, walidację polskich komunikatów, precyzję dziesiętną, aliasy, konwersje i serializer payloadu. Warto współdzielić walidację i pola danych podstawowych.
- `RawMaterialCreateForm.tsx` zawiera jednak rezerwację SKU, Idempotency-Key i specyficzny stan tworzenia. Nie należy używać tego przepływu do edycji. Formularz edycji powinien dostać własny zapis z `If-Match`, obsługą konfliktu i odpowiedzi niejednoznacznej.
- `detail.ts` parsuje zatwierdzony snapshot. W ACTIVE detail może celowo pokazywać zatwierdzoną rewizję zamiast working. Po edycji UI musi wyraźnie odróżnić nową rewizję roboczą od zatwierdzonej; nie należy po sukcesie przedstawiać starego zatwierdzonego snapshotu jako właśnie zmienionych danych.
- `App.css` ma istniejące style drawer/formularza tworzenia i breakpointy, które można zachować.

## 9. Propozycja interakcji

1. Umieścić **„Edytuj surowiec”** w istniejącym panelu szczegółów. Akcja jest ukryta bez świeżego `actions.edit`; po otwarciu szczegółów pokazać ją dopiero po odczycie `completion` i gdy `available_actions` zawiera `edit`.
2. Dla rekordu niedostępnego backendowo (np. ARCHIVED, BLOCKED, VERIFICATION/pending) nie udostępniać edycji. Nie wyliczać tego warunku lokalnie; użyć `available_actions`. Przy błędzie preflightu fail-closed.
3. Otworzyć osobny dialog/formularz dla istniejącego mastera; wczytać `GET /{id}/editor`, a nie wstępniać wartości z tabeli ani zatwierdzonego detail.
4. Na górze pokazać niezmienne SKU i ID oraz stan, numer/ID rewizji i informację „robocza” albo „zatwierdzona”. Prefill: kategoria, nazwa, opis, jednostka, obie ilości, aliasy i przeliczniki. Dokumentów, alergenów i przechowywania nie zmieniać z formularza podstawowego; backend zachowuje bazowy zestaw, a odrębne sekcje wymagają własnych grantów.
5. Zmiany niezapisane: przy zamknięciu/Escape/kliknięciu tła poprosić po polsku o potwierdzenie odrzucenia; blokować podwójny submit. Przy zmianie lokalizacji przerwać odczyty i zapis, wyczyścić wersję oraz stan edycji.
6. Przy 409 zachować lokalny formularz do chwili decyzji, wyjaśnić: „Surowiec zmienił się od czasu wczytania. Nie nadpisaliśmy nowszych danych. Wczytanie aktualnej wersji odrzuci Twoje niezapisane zmiany.” Udostępnić jawne wczytanie aktualnej rewizji i powrót; nie scalać pól.
7. Po 200 potwierdzić utworzenie nowej rewizji, pokazać numer i stan zwrócone przez serwer oraz wyjaśnić, że zatwierdzenie nie nastąpiło automatycznie. Odświeżyć katalog i szczegóły. W ACTIVE pokazać working jako „niezatwierdzoną rewizję roboczą”, obok/odrębnie od starej obowiązującej rewizji.
8. Po powrocie zachować kontekst tego samego materiału; nie przenosić użytkownika do innego rekordu ani nie zmieniać filtrów/strony katalogu.

## 10. Macierz testów Stage 4B.2B-I

| Scenariusz | Oczekiwany wynik |
|---|---|
| EDIT + VIEW w organizacji i primary; DRAFT | Preflight `edit`, PATCH 200, nowy working revision/version, stan DRAFT. |
| EDIT + VIEW; ACTIVE bez pending | PATCH 200, nowa krytyczna working revision; zatwierdzona rewizja i używany snapshot pozostają bez zmian. |
| Brak EDIT | `actions.edit`/rekordowe `edit` nieobecne; bezpośredni PATCH 403. |
| VIEW-only | Brak formularza; brak prawa do pobrania edytora roboczego i brak prawa do PATCH. |
| Inna organizacja | 404 dla obcego mastera, bez wycieku ID, SKU, wersji lub rewizji. |
| Inna/secondary lokalizacja | Brak `actions.edit`; mutation 403 z powodu primary-context/membership. |
| Non-primary operational context | Nie używać udostępnionego snapshotu do edycji katalogu centralnego; brak edycji niezależnie od widoczności. |
| ACTIVE z pending lub VERIFICATION | `available_actions` nie zawiera `edit`; edycja PATCH odrzucona. |
| BLOCKED | `available_actions` nie zawiera `edit`; osobny workflow blokady pozostaje bez zmian. |
| ARCHIVED | Brak `edit`; PATCH odrzucony; osobno możliwy wyłącznie autoryzowany restore. |
| Tombstone szkicu | `/editor` i `/completion` zwracają 404; SKU/historia nie są modyfikowane. |
| Niezmienne ID/SKU | Żądanie nie może zmienić ID/kodu; `sku_reservation_id` w PATCH daje 422; po zmianie wartości serwerowe pozostają niezmienne. |
| Brak kategorii/nazwy/jednostki | 422, brak nowej rewizji i brak zdarzenia udanego zapisu. |
| Ilości dziesiętne | Przecinek normalizowany przez UI; backend odrzuca format/precyzję niezgodne z kontraktem; dla `pc` wartości ułamkowe odrzucone. |
| Konwersje i aliasy | Błędne/powtórzone jednostki, współczynniki, wymiar lub aliasy dają walidację; poprawne dane zachowują mapowanie. |
| Brak `If-Match` | 428 `RAW_MATERIAL_VERSION_REQUIRED`; bez zapisu. |
| Nieprawidłowy/stary `If-Match` | 422 `RAW_MATERIAL_VERSION_INVALID` / 409 `RAW_MATERIAL_VERSION_CONFLICT`; brak nowej rewizji/audytu. |
| Dwóch edytorów | Zapis pierwszego wygrywa; drugi dostaje 409 i nie nadpisuje ani nie dopisuje audytu. |
| Przerwanie sieci / niejednoznaczny wynik | UI nie oznacza sukcesu i nie ponawia z nową wersją; pobiera bieżący editor/completion i prosi o decyzję. |
| Podwójny submit | Jedno żądanie w toku; brak drugiego zapisu/revizji. |
| Wygasła sesja 401/419 | Zapis wstrzymany, stan formularza i sesji obsłużony bez ponowienia. |
| `RAW_MATERIALS = NOT_READY` / 503 | Brak akcji/formularza; istniejąca obsługa katalogu niedostępnego pozostaje fail-closed. |
| Cofnięcie/wygaśnięcie capabilities | Świeży preflight odmawia; bezpośredni zapis również 403. |
| Zmiana lokalizacji podczas edycji | Aborted/ignorowane odpowiedzi; formularz i stary token są unieważnione; brak zapisu do poprzedniego kontekstu. |
| Opóźniona odpowiedź editor/completion | Odpowiedź po zamknięciu, przełączeniu rekordu lub lokalizacji nie aktualizuje UI. |
| Historia rewizji | Poprzednie rewizje i zatwierdzenia zachowane; jedna udana edycja tworzy jedną rewizję oraz zdarzenie audytu. |
| Zawartość chroniona | Bez uprawnień do zachowania istniejących alergenów/przechowywania brak akcji `edit`; bez ich przypadkowego wyczyszczenia. |
| Regresja formularza tworzenia | Rezerwacja SKU, Idempotency-Key, walidacja i istniejące scenariusze tworzenia nadal przechodzą. |
| Regresja panelu szczegółów | Odczyt zatwierdzonego snapshotu, historii, 403/404/NOT_READY, focus/close bez edycji nadal działają. |
| Regresja katalogu | Filtry, sortowanie, paginacja, wybór i eksport nie zmieniają semantyki po odświeżeniu po edycji. |

Testy UI mają używać syntetycznych odpowiedzi i stubów API. Nie tworzyć ani nie edytować prawdziwych materiałów.

## 11. Mapa plików implementacji

### A. Wymagane zmiany backendu

| Plik | Odpowiedzialność |
|---|---|
| `backend/app/Domain/RawMaterials/RawMaterialAuthorization.php` | Dodać ocenę capabilities EDIT w oparciu o istniejący resolver grantów i primary context. |
| `backend/app/Http/Controllers/Api/V1/RawMaterialCapabilitiesController.php` | Dodać pola `permissions.edit`/`actions.edit`, zachowując kontrakt CREATE, readiness, location i cache headers. |
| `backend/tests/Feature/RawMaterialCapabilitiesApiTest.php` | Pokryć grant EDIT, brak grantu, primary/secondary context, NOT_READY i rozdzielenie permission od action. |

Nie zmieniać `routes/api.php`, `RawMaterialService`, walidatorów ani permission enum dla edycji podstawowej — wymagane endpointy i `RAW_MATERIALS_EDIT` już istnieją. `completion.available_actions` jest wystarczającym źródłem edytowalności konkretnego rekordu. **Nie są potrzebne migracje.**

### B. Wymagane zmiany frontendowe

| Plik | Odpowiedzialność |
|---|---|
| `frontend/src/rawMaterials/api.ts` | Rozszerzyć parser capabilities; dodać typy/adaptery editor, completion i PATCH z `If-Match`, bez cache. |
| `frontend/src/rawMaterials/RawMaterialsCatalogPage.tsx` | Globalne bramkowanie EDIT, sprawdzenie completion przy otwieraniu, ogrodzenie location/generacji, odświeżenie katalogu i ponowne otwarcie tego samego detail po sukcesie. |
| `frontend/src/rawMaterials/RawMaterialDetailDrawer.tsx` | Dodać akcję „Edytuj surowiec”, stany preflightu i poprawne rozróżnienie zatwierdzonej oraz roboczej rewizji. |
| `frontend/src/rawMaterials/detail.ts` | Rozszerzyć parsowanie tylko o potrzebne, zweryfikowane pola; nie zakładać, że zwykły detail zawiera token edycji. |
| `frontend/src/rawMaterials/RawMaterialEditForm.tsx` — nowy | Prefill z editor, dane identyfikacyjne tylko do odczytu, zapis wersjonowany, walidacja, dirty guard, błędy i konflikt. |
| `frontend/src/rawMaterials/RawMaterialFields.tsx` — nowy | Wspólne kontrolki pól podstawowych, jeżeli ekstrakcja pozwoli zachować obecny UX bez duplikacji. |
| `frontend/src/rawMaterials/create.ts` | Wydzielić lub udostępnić wspólne walidatory/mappery danych podstawowych, nie zmieniając obecnego payloadu CREATE. |
| `frontend/src/rawMaterials/RawMaterialCreateForm.tsx` | Użyć wspólnych pól/walidacji; pozostawić dotychczasową rezerwację i idempotentny przepływ tworzenia bez zmian semantycznych. |
| `frontend/src/App.css` | Ograniczone style dialogu/stanów konfliktu; zachować istniejący wygląd i responsywność. |

Nie współdzielić maszyny stanów wysyłania CREATE z EDIT. Jeśli ekstrakcja pól okaże się ryzykowna, pozostawić CREATE bez refaktoru i współdzielić najpierw wyłącznie czyste walidatory/serializer.

### C. Wymagane testy automatyczne

- Backend: `backend/tests/Feature/RawMaterialCapabilitiesApiTest.php`; dopisać testy capabilities EDIT. Rozszerzyć istniejące scenariusze `backend/tests/Concerns/RawEditingStage3eB2Tests.php` lub testy `backend/tests/Feature/RawMaterialsTest.php` tylko o brakujące kontrakty HTTP/payloadu.
- Frontend API: `frontend/src/rawMaterials/api.test.ts`.
- Frontend orchestracja: `frontend/src/rawMaterials/RawMaterialsCatalogPage.test.tsx`.
- Formularz: nowy `frontend/src/rawMaterials/RawMaterialEditForm.test.tsx`.
- Panel szczegółów: nowy `frontend/src/rawMaterials/RawMaterialDetailDrawer.test.tsx` albo testy w istniejącym pliku katalogu.
- Regresje współdzielonych pól: `frontend/src/rawMaterials/create.test.ts` i `frontend/src/rawMaterials/RawMaterialCreateForm.test.tsx`.
- Zachować uruchamiane obecnie testy katalogu, filtrów i paginacji.

### D. Opcjonalny podgląd wizualny

`frontend/src/visualPreview.tsx`, `frontend/src/visualPreviewApi.ts` i istniejący lokalny odbiornik podglądu można rozszerzyć o syntetyczne scenariusze edycji, konfliktu i NOT_READY. To nie jest warunek kontraktu ani funkcji; nie używać żywych danych.

## 12. Ryzyka i otwarte decyzje

1. Zakres proponowany dla 4B.2B-I to pola podstawowe. Zarządzanie dokumentami, alergenami i przechowywaniem powinno pozostać w ich osobnych uprawnionych przepływach; decyzję o dodaniu tych kontrolek trzeba podjąć osobno.
2. PATCH wymaga pełnych pól podstawowych, a pominięte aliasy/przeliczniki mogą zostać wyzerowane. Serializer edycji musi budować kompletny payload z `/editor` i nie może serializować danych z detail snapshotu.
3. Przy aktywnym materiale odpowiedź zwykłego detail nadal może wskazywać zatwierdzoną rewizję. UI po zapisie musi jawnie pokazać working/pending, aby nie sugerować, że aktywna rewizja produkcyjna została już zmieniona.
4. `actions.edit` jest capability globalną; dopiero `completion.available_actions` odpowiada, czy dany rekord może być edytowany przez tego użytkownika.
5. `RAW_MATERIALS` pozostaje NOT_READY. Formularz nie może stać się operacyjny w tej fazie, a syntetyczne testy readiness nie mogą zmienić konfiguracji instalacyjnej.
6. Upload do Google Drive nie został wykonany: w dostępnych narzędziach sesji nie ma zatwierdzonego konektora uploadu. Zgodnie z instrukcją nie ponawiano znanej, zawodnej metody selektora plików WSL.

## 13. Stan zmian i granice

- Audyt nie zmienił kodu aplikacji, backendu, frontendowych testów ani konfiguracji. Jedynym utworzonym plikiem jest niniejszy raport.
- Nie uruchomiono testów, migracji, instalacyjnej ani produkcyjnej bazy, podglądu operacyjnego ani żądań mutujących. Podstawą raportu jest odczyt kodu, testów i istniejących raportów Stage 2A/2B, 3E-B.2/B.4.1 oraz 4B.2A.
- Nie wykonano wdrożenia, aktywacji, commita ani pushu.
- `RAW_MATERIALS = NOT_READY`; definicja `ModuleDefinition` domyślnie ustawia `ready=false`, a test capabilities potwierdza odpowiedź NOT_READY bez fixture READY.
- Raport zapisano lokalnie jako `VELTRA_STAGE_4B2B_P_RAW_EDIT_CONTRACT_AUDIT.md`. Upload do Drive nie został potwierdzony.

## 14. Źródła w repozytorium

- Architektura i zatwierdzony model lifecycle/RBAC: `VELTRA_STAGE_2A_RAW_MATERIALS_ARCHITECTURE.md`.
- Kontrakt edycji, concurrency i dotychczasowe testy: `VELTRA_STAGE_3E_B2_RAW_EDIT_COMPLETION_AUDIT_REPORT.md`, `backend/app/Domain/RawMaterials/RawMaterialVersion.php`, `backend/app/Domain/RawMaterials/RawMaterialService.php`, `backend/tests/Concerns/RawEditingStage3eB2Tests.php`.
- Tombstone i 404: `VELTRA_STAGE_3E_B41_RAW_DRAFT_TOMBSTONE_IMPLEMENTATION_REPORT.md`, `backend/app/Domain/RawMaterials/RawMaterialService.php`, `backend/tests/Concerns/RawDraftTombstoneTests.php`.
- Capabilities/RBAC: `backend/app/Domain/RawMaterials/RawMaterialAuthorization.php`, `backend/app/Domain/RawMaterials/RawMaterialPermission.php`, `backend/app/Http/Controllers/Api/V1/RawMaterialCapabilitiesController.php`, `backend/tests/Feature/RawMaterialCapabilitiesApiTest.php`.
- Frontend: `frontend/src/rawMaterials/RawMaterialsCatalogPage.tsx`, `RawMaterialDetailDrawer.tsx`, `api.ts`, `detail.ts`, `create.ts`, `RawMaterialCreateForm.tsx`, ich testy oraz `frontend/src/App.css`.

**Decyzja końcowa: READY WITH PREREQUISITE — najpierw minimalne rozszerzenie capabilities EDIT opisane w sekcji 7; następnie implementacja Stage 4B.2B-I zgodnie z mapą i testami. Nie rozpoczynać implementacji ani aktywacji bez odrębnej akceptacji właściciela.**
