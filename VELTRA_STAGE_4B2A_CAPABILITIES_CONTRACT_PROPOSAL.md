# VELTRA 2.0 — Stage 4B.2A-P

## Kontrakt efektywnych capabilities RAW_MATERIALS — audyt i propozycja

**Tryb:** analiza architektoniczna tylko do odczytu
**Status:** rekomendacja gotowa do decyzji; implementacja nieautoryzowana
**Wynik:** rekomenduję dedykowany, tylko do odczytu endpoint capabilities pod middleware bezpieczeństwa. Kontrakt zwraca osobno efektywne uprawnienie, gotowość/dostępność modułu i wynikową możliwość wykonania akcji.

## 1. Podsumowanie dla właściciela

Blokada Stage 4B.2A jest potwierdzona. Frontend nie może bezpiecznie wywnioskować `RAW_MATERIALS_CREATE` z profilu, katalogu, ról ani wierszy przypisań. Użycie endpointu rezerwacji SKU jako sondy jest niedopuszczalne: rezerwacja przydziela numer, zapisuje audyt biznesowy i zużywa limit.

Backend ma już właściwy wspólny punkt decyzji: `RawMaterialAuthorization`. `RawMaterialService::create` sprawdza `RawMaterialPermission::Create` przed transakcją i ponownie w jej wnętrzu. Resolver sprawdza użytkownika, organizację, członkostwo i lokalizację, politykę głównego kontekstu oraz granty organizacyjne z uwzględnieniem ważności, delegacji, cofnięcia i uprawnienia delegującego. Trzeba współdzielić ten resolver, a nie odtwarzać go w kontrolerze ani na froncie.

Rekomendowany endpoint ma działać także wtedy, gdy moduł jest `NOT_READY`. Powinien odczytać grant w bieżącym kontekście i zwrócić odrębne pola `readiness`, `available`, `permissions.create` oraz `actions.create`. `actions.create` jest jedynie wskazówką do prezentacji UI; nie stanowi autoryzacji żądania. Każda chroniona operacja nadal samodzielnie sprawdza RBAC i gotowość.

## 2. Potwierdzony stan implementacji

### Efektywne RAW RBAC

`RawMaterialAuthorization::assertAllowed` odświeża aktora i lokalizację z bazy, a następnie sprawdza aktywność konta, weryfikację e-maila, aktywność organizacji i lokalizacji, zgodność organizacji i członkostwo w lokalizacji. Dla uprawnień oznaczonych `requiresPrimaryCatalogContext()` wymaga głównej kuchni centralnej. Następnie osobno wymaga dostępności modułu i grantów.

`effectiveGrantIds` jest istniejącym resolverem grantów RAW. Zapytanie rekurencyjne:

- ogranicza granty do organizacji aktora i aktywnych, zweryfikowanych użytkowników;
- uwzględnia `valid_from` i `valid_until`;
- rozpatruje granty bazowe, delegowane i efektywny grant delegowania;
- wymaga zgodności delegującego, permission i źródłowego grantu;
- odrzuca unieważnione zdarzeniem `REVOKED` oraz granty bez efektywnego uprawnienia bootstrapowego.

Granty RAW są organizacyjne, nie rekordami `permission_grants` przypisanymi do jednej lokalizacji. W kontekście lokalizacji operacja nadal wymaga aktywnego członkostwa, a tworzenie — głównego kontekstu katalogu. Z tego powodu sama liczba lub obecność wiersza grantu nie wystarcza.

### Gotowość modułu

`RequireRawMaterialsAvailability` zwraca HTTP 503 z kodem `RAW_MATERIALS_MODULE_UNAVAILABLE` przed wejściem do kontrolera każdej trasy w grupie `/api/v1/raw-materials`. `ConfiguredModuleAvailability::enabled` jest wartością logiczną obejmującą gotowość definicji, zgodność typu lokalizacji, włączenie stanu lokalizacji oraz zależności.

Definicja produkcyjna `RAW_MATERIALS` ma domyślne `ready: false`; istniejący katalog modułów przedstawia to jako `readiness: NOT_READY`. Moduł nie ma być aktywowany w tym etapie. Pole `available` nie jest synonimem `readiness`: dostępność obejmuje również kontekst lokalizacji i konfigurację.

### Obecne API/frontend

- `/api/v1/auth/me` zwraca wyłącznie profil. `AuthContext` frontendu zawiera użytkownika i wybraną lokalizację, bez RAW capabilities.
- `/api/v1/security/access` rozstrzyga `BusinessAuthorization` i ogólne uprawnienia `Permission`; nie jest resolverem RAW.
- `/api/v1/security/users/{user}/assignments` jest administracyjnym widokiem ról i wierszy `PermissionGrant`. Nie uruchamia rekurencyjnego resolvera grantów RAW; nie należy traktować go jako źródła efektywnego grantu RAW.
- Frontendowy `request()` już używa `cache: 'no-store'`; AuthPanel emituje zmianę kontekstu po logowaniu i zmianie lokalizacji, a `App` montuje stronę RAW z kluczem użytkownik/lokalizacja.
- Tworzenie i rezerwacja SKU ponownie sprawdzają `assertAllowed`; utworzenie wykonuje też kontrolę w transakcji po blokadzie organizacji.

## 3. Porównanie wariantów

| Kryterium | A — capabilities w istniejącym kontekście auth | B — dedykowany endpoint RAW | C — capabilities w odpowiedzi katalogu RAW |
|---|---|---|---|
| Bezpieczeństwo | Profil/auth endpoint musiałby dostać semantykę modułu i lokalizacji; ryzyko niejasnego zakresu profilu. | Odczyt dotyczy tylko zalogowanego użytkownika i jego sesyjnego kontekstu lokalizacji; minimalny zakres danych. | Wymaga prawa do odczytu katalogu, nie tylko create, i umieszcza uprawnienia w odpowiedzi danych katalogowych. |
| Zgodność z NOT_READY | Możliwa tylko po powiązaniu auth-context z bieżącą lokalizacją. | Tak, jeśli trasa jest w grupie security, poza middleware gotowości RAW. | Nie: katalog jest za `RequireRawMaterialsAvailability` i zwraca 503 przed kontrolerem. |
| Zgodność z obecnym frontendem | AuthPanel pobiera profil przed zakończeniem wyboru kontekstu; wymaga sprzężenia profilu z lokalizacją. | Osobny GET po ustaleniu `AuthContext`; pasuje do istniejącego API i sesyjnego kontekstu. | Frontend musi już móc pobrać katalog; nie rozwiązuje dostępności UI dla modułu niedostępnego. |
| Lokalizacja | Trzeba rozszerzyć znaczenie endpointu profilu albo dodać parametr. | Middleware wymaga wybranej, aktywnej lokalizacji; endpoint nie przyjmuje dowolnego `location_id`. | Związana z lokalizacją trasy katalogowej, lecz niedostępna przy NOT_READY. |
| Cache/unieważnianie | Middleware sesji już ustawia `no-store`, ale profil jest współdzielonym kontraktem i zyskałby dane zależne od lokalizacji. | Odpowiedź jest mała i jawnie związana z kontekstem lokalizacji; łatwe odświeżanie. | Middleware też ustawia `no-store`, ale capability byłoby sprzężone z odpowiedzią danych katalogowych. |
| Nadmiarowa ekspozycja | Może rozszerzyć odpowiedź używaną przez wiele ekranów. | Można zwrócić tylko zatwierdzone capabilities potrzebne UI. | Rozszerza każdą odpowiedź katalogu i ujawnia uprawnienia wszystkim odbiorcom katalogu. |
| Rozszerzalność | Dobra dla ogólnych capabilities sesji, ale RAW ma własny resolver i gotowość. | Naturalny dla przyszłych akcji RAW; listę dozwolonych capabilities można rozszerzać tylko wraz z UI. | Łączy osobne obszary odpowiedzialności i zależy od gotowości modułu. |
| Werdykt | Odrzucam: zbyt szeroki kontekst profilu i niepotrzebne sprzężenie. | **Rekomenduję.** | Odrzucam: nie rozwiązuje NOT_READY i błędnie wiąże grant z widokiem katalogu. |

## 4. Rekomendowany kontrakt

### Żądanie

```http
GET /api/v1/security/raw-materials/capabilities
Accept: application/json
Cookie: <sesja SPA; przeglądarka zarządza cookie>
```

Żądanie nie przyjmuje `user_id`, `organization_id` ani `location_id` w URL, query string ani body. Kontekst pochodzi wyłącznie ze zweryfikowanej sesji i aktywnego członkostwa operacyjnego.

Trasa powinna należeć do istniejącej grupy `/api/v1/security`, która nakłada `RequireSpaSession`, `auth:sanctum`, `ValidateIdentityContext`, `AuditSecurityRejection`, `RequireOperatingContext` i `throttle:security`. Nie należy dołączać `RequireRawMaterialsAvailability`.

### Przykładowa odpowiedź

```json
{
  "data": {
    "module": "RAW_MATERIALS",
    "location_id": "00000000-0000-4000-8000-000000000001",
    "readiness": "NOT_READY",
    "available": false,
    "permissions": {
      "create": true
    },
    "actions": {
      "create": false
    }
  }
}
```

### Semantyka

- `permissions.create` — efektywny grant `RAW_MATERIALS_CREATE` dla uwierzytelnionego aktora według `RawMaterialAuthorization`, niezależnie od gotowości modułu. Wynik nie może być wyliczany z assignment rows ani z ról ogólnych.
- `readiness` — `READY` albo `NOT_READY` z definicji modułu `ModuleCatalog`, a nie z grantów użytkownika.
- `available` — wynik `ModuleAvailability::enabled()` dla bieżącej lokalizacji; uwzględnia readiness, typ lokalizacji, lokalne włączenie i zależności.
- `actions.create` — prawda tylko wtedy, gdy grant jest efektywny, kontekst spełnia ograniczenie primary dla tworzenia, a moduł jest dostępny. Jest informacją do prezentacji, a nie tokenem/uprawnieniem do późniejszego zapisu.
- `location_id` pozwala frontendowi odrzucić spóźnioną odpowiedź należącą do poprzedniego kontekstu. Nie jest akceptowany jako selector lokalizacji przez serwer.

Brak grantu zwraca HTTP 200 z `permissions.create: false` i `actions.create: false`; nie jest błędem serwera ani próbą autoryzacji cudzego użytkownika. Przy `NOT_READY` endpoint nadal zwraca HTTP 200, zachowuje wynik grantu, ale `available` i `actions.create` są `false`. Chronione trasy RAW nadal odpowiadają 503 `RAW_MATERIALS_MODULE_UNAVAILABLE`.

Na pierwszym wdrożeniu należy zwrócić wyłącznie `create`. Nie dodawać całego katalogu grantów ani dowolnego parametru `permission`. Kolejne capabilities (np. `edit`, `approve`, `archive`) dodawać jawnie wraz z wdrożeniem odpowiedniego UI i testów.

### Błędy i cache

- `401` — brak/zakończenie sesji albo nieaktualny `identity_version`; frontend czyści auth context i pokazuje stan ponownego logowania.
- `403` — brak, cofnięcie lub utrata wybranej lokalizacji operacyjnej; frontend czyści lokalizacyjny kontekst i nie wyświetla akcji.
- `419` — jeśli sesyjna/CSRF infrastruktura emituje taki status, frontend traktuje go jak wygasłą sesję/formularz; dla zwykłego GET oczekiwanym statusem wygaśniętej sesji jest zwykle 401, a endpoint nie powinien syntetyzować 419.
- `429` i błędy serwera — istniejące formaty API; frontend pozostaje fail-closed, nie pokazuje akcji na podstawie starego wyniku.
- `503` — nie jest odpowiedzią capabilities dla `NOT_READY`; gotowość jest danymi odpowiedzi 200. Istniejące operacje RAW zachowują 503.

Odpowiedź powinna mieć `Cache-Control: private, no-store` (i bez ETag/conditional caching). Istniejący `RequireSpaSession` już dodaje `Cache-Control: no-store`; endpoint powinien zachować tę ochronę i może doprecyzować `private`. Frontendowe `request()` również ustawia `cache: 'no-store'`. Nie używać cache współdzielonego ani localStorage/sessionStorage dla capabilities.

## 5. Zabezpieczenia i odświeżanie

1. Dodać metodę do `RawMaterialAuthorization`, współdzieląc walidację świeżego aktora/lokalizacji, `requiresPrimaryCatalogContext()` i `effectiveGrantIds()`. `assertAllowed()` ma nadal pozostać bramką operacji. Nie implementować drugiego SQL resolvera w kontrolerze.
2. Endpoint rozstrzyga wyłącznie bieżącego `$request->user()` i `operating_location`; nie oferuje odpytywania capabilities innych osób ani dowolnej lokalizacji.
3. GET nie rezerwuje SKU, nie zmienia danych, nie tworzy zdarzenia audytu biznesowego ani nie wykonuje mutacji bezpieczeństwa. Istniejący middleware `AuditSecurityRejection` może nadal odnotować odrzucone żądania zgodnie z obecnym mechanizmem bezpieczeństwa; to nie jest audit biznesowej operacji RAW.
4. `actions.create` jest wskazówką dla UI. `POST /raw-materials`, rezerwacja SKU i każda późniejsza operacja pozostają niezależnie chronione przez serwerowe `assertAllowed()` z odświeżeniem grantu. Odpowiedź capabilities nigdy nie autoryzuje żądania.
5. Po zmianie tożsamości/lokalizacji frontend kasuje stan i anuluje requesty poprzedniego kontekstu. Akceptuje odpowiedź tylko, gdy zgadzają się bieżące `user.id` i `location.id`. Odświeża capability po wejściu na stronę, zmianie kontekstu, powrocie karty do aktywnego stanu i bezpośrednio przed otwarciem formularza.
6. Tworzenie i cofanie grantów RAW blokują ten sam rekord organizacji, a tworzenie ponownie sprawdza autoryzację wewnątrz transakcji po uzyskaniu blokady. Daje to wyraźną kolejność dla tej ścieżki: jeśli cofnięcie grantu zatwierdzi się przed kontrolą tworzenia, tworzenie zobaczy cofnięcie; jeśli tworzenie uzyska blokadę i przejdzie kontrolę wcześniej, jest wcześniejszą operacją. Test współbieżności ma potwierdzić tę serializację, nie obiecywać cofnięcia operacji już autoryzowanej.
7. Nie ma obecnie mechanizmu push do natychmiastowego powiadamiania otwartej karty o cofnięciu grantu. UI może zatem wyświetlać poprzedni wynik do następnego odświeżenia. To nie daje żadnego dostępu: każda operacja ponownie sprawdza serwerowe RBAC; 401/403/503 unieważnia wynik capability i zamyka/ukrywa akcję. Nie należy obiecywać natychmiastowej aktualizacji bez osobnego mechanizmu push.
8. W kontrolerze nie zwracać grant ID, nazwisk/identyfikatorów delegujących, okresów grantów ani danych innych użytkowników. W odpowiedzi nie umieszczać finansowych ani dostawczych danych RAW.

## 6. Minimalny plan implementacji po zatwierdzeniu

### Backend

| Plik | Odpowiedzialność |
|---|---|
| `backend/routes/api.php` | Dodać `GET /v1/security/raw-materials/capabilities` wewnątrz istniejącej grupy security, poza grupą raw-materials z readiness middleware. |
| `backend/app/Domain/RawMaterials/RawMaterialAuthorization.php` | Wyodrębnić wspólne sprawdzenie świeżego aktora/kontekstu i wystawić wąską metodę odczytu efektywnej capability w kontekście. Zachować `assertAllowed()` dla operacji i tę samą ścieżkę resolvera grantów. |
| `backend/app/Http/Controllers/Api/V1/RawMaterialCapabilitiesController.php` *(nowy)* | Odczytać aktora i `operating_location`, wywołać wspólny resolver, odczytać `ModuleCatalog`/`ModuleAvailability`, zbudować wyłącznie powyższy kontrakt i ustawić `private, no-store`. Bez zapisu/audytu biznesowego. |
| `backend/tests/Feature/RawMaterialCapabilitiesApiTest.php` *(nowy)* | Testy kontraktu, RBAC, kontekstu, readiness oraz braku skutków ubocznych. |

Kontroler należy do warstwy HTTP; logika efektywnego dostępu pozostaje w `RawMaterialAuthorization`. Nie dodawać migracji ani tabel. Nie zmieniać polityk grantowania, delegacji, bootstrapu ani readiness. Chronione operacje nadal sprawdzają zgodę niezależnie.

### Frontend

| Plik | Odpowiedzialność |
|---|---|
| `frontend/src/rawMaterials/api.ts` | Typ i walidowany adapter GET capabilities. |
| `frontend/src/rawMaterials/RawMaterialsCatalogPage.tsx` | Stan ładowania/odczytu, fail-closed, obsługa statusów, odświeżanie oraz render akcji tylko dla `actions.create === true`. |
| `frontend/src/rawMaterials/RawMaterialsCatalogPage.test.tsx` | Testy widoczności i cyklu życia odpowiedzi capabilities. |
| `frontend/src/visualPreviewApi.ts` | Tylko jeśli istniejący preview wymaga deterministycznego syntetycznego response adaptera do odtworzenia widoku; nie zmieniać backendu ani używać produkcyjnych danych. |

Przy błędzie albo nieznanym/niepoprawnym kształcie odpowiedzi nie wolno ustawić capability na `true`. W stanie ładowania i po błędzie przycisk jest ukryty lub niedostępny z jasnym stanem UI; żadna wartość domyślna nie może oznaczać przyznania prawa. Przy braku `create` przycisk nie jest renderowany. Przy `NOT_READY` `actions.create` pozostaje false, a istniejąca obsługa unavailable UI nadal wyświetla komunikat modułu.

### Decyzja architektoniczna

Przed implementacją wymagana jest akceptacja właściciela dla nowego, publicznego kontraktu REST i rozdzielenia gotowości od uprawnienia. Zalecam zachować tę decyzję w krótkim ADR lub jako zatwierdzony kontrakt Stage 4B.2A; ten raport nie wprowadza samodzielnie ADR ani zmiany API.

## 7. Macierz testów regresji

Proponowana nowa klasa backendowa: `RawMaterialCapabilitiesApiTest` z izolowanymi fixture’ami `AuthorizationTestCase` i `ModuleCatalogFixtures`. Wykorzystać rzeczywisty resolver; nie testować go przez stub/imitację w testach kontraktu.

| Przypadek | Oczekiwany wynik |
|---|---|
| Efektywny `RAW_MATERIALS_CREATE` w głównym kontekście i gotowym module | 200; permission/availability/action = true. |
| VIEW bez CREATE | 200; create permission i action false; brak odmowy całego read endpointu. |
| Brak grantów RAW | 200, wszystkie pola create false; brak ujawnienia grantów/identity innych osób. |
| Grant zaplanowany w przyszłości lub wygasły | permission/action false zgodnie z czasem serwera. |
| Grant cofnięty eventem `REVOKED` | false; grant nie wraca po ponownym odczycie. |
| Delegowany grant aktywny wraz z aktywnym grantem delegowania | true; wynik dokładnie zgodny z resolverem używanym przez write. |
| Grant źródłowy/delegujący cofnięty lub wygasły | false dla delegowanego grantu. |
| Rola Administratora bez jawnego RAW grant | false; rola nie omija modelu RAW. |
| Inny użytkownik w tej samej lokalizacji | wynik zależy tylko od jego własnych grantów; brak odczytu grantów sąsiada. |
| Przełączenie na inną aktywną lokalizację tej samej organizacji | odpowiedź ma nowe `location_id`; kontekst primary decyduje o `actions.create`, nie o module readiness. |
| Lokalizacja z innej organizacji / cudzy `location_id` w query | brak możliwości odpytywania cudzego kontekstu; GET nie przyjmuje selectorów. |
| `RAW_MATERIALS` ma `NOT_READY` | 200 z `readiness: NOT_READY`, `available: false`, `actions.create: false`, a permission odzwierciedla grant. Katalog RAW nadal 503. |
| Moduł READY, ale lokalnie wyłączony/niewspierany typ | permission pozostaje osobnym wynikiem; `available` i action false. |
| Brak sesji / wygasła tożsamość / nieaktualny identity version | 401; frontend czyści capability i auth context. |
| Brak/odebrane członkostwo lub brak kontekstu | 403; bez capabilities dla niedostępnej lokalizacji. |
| 419 z warstwy sesji/CSRF | frontend fail-closed i odnowienie sesji; GET nie zmienia uprawnień. |
| Grant cofnięty po GET, przed otwarciem formularza | rewalidacja zwraca false; formularz/akcja nie są otwierane. |
| Współbieżne cofnięcie grantu i tworzenie | Obie ścieżki serializują się na blokadzie organizacji; rezultat odpowiada kolejności uzyskania blokady. Jeśli cofnięcie jest wcześniejsze, kontrola wewnątrz transakcji tworzenia odmawia. Nie testować oczekiwania, że cofnięcie cofa już autoryzowaną transakcję. |
| Brak skutków ubocznych udanego GET | nie zmieniają się rezerwacje/SKU, `organization_sku_assignments`, rekordy RAW, `raw_material_audit_events`, `security_events` dla poprawnego odczytu. |
| Tylko `GET` w routingu | `POST`, `PUT`, `PATCH`, `DELETE` na ścieżce capabilities nie wykonują operacji; brak SKU/rezerwacji. |
| Frontend: ładowanie, false, malformed/error, NOT_READY | fail-closed; przycisk nie widoczny; nie ma domyślnego przyznania. |
| Frontend: spóźniona odpowiedź z poprzedniego user/location | odpowiedź anulowana/odrzucona; nie może zmienić widoku nowego kontekstu. |
| Frontend: `actions.create: true` | akcja widoczna; rzeczywisty zapis nadal obsługuje 401/403/419/503 i unieważnia stan. |

Brak migracji. Testy integracyjne muszą działać wyłącznie na izolowanym środowisku testowym; nie wykonywać ich na bazie instalacyjnej ani produkcyjnej.

## 8. Ryzyka i decyzje do rozstrzygnięcia

1. **Akceptacja semantyki:** zatwierdzić, że `permissions.create` jest efektywnym grantem niezależnym od gotowości, a `actions.create` jest połączeniem grantu, polityki głównego kontekstu i dostępności modułu.
2. **Zakres pierwszej allowlisty:** Stage 4B.2A potrzebuje `create`. Każde następne uprawnienie ma wymagać jawnego wniosku i testów; nie zwracać całego grafu grantów.
3. **Odświeżanie po revokacji:** nie istnieje push do aktywnej karty. Ustalić, czy odświeżanie przy focus/open wystarczy dla UX. Bez tego nie obiecywać natychmiastowego ukrycia przycisku, ale pamiętać, że write nie polega na UI.
4. **Readiness kontra availability:** `NOT_READY` jest readiness katalogu modułów; dostępność może być również false przez wyłączenie, typ lokalizacji lub zależność. UI nie może przedstawiać każdego `available:false` jako zmiany RBAC.
5. **Statusy sesji:** potwierdzić wspólną obsługę 419 w UI; dla GET typowym skutkiem wygaśnięcia tożsamości jest 401. Nie zmieniać middleware sesji w ramach capabilities.
6. **ADR:** wymagana jest decyzja właściciela o nowym read API. Raport jest projektem kontraktu, nie zatwierdzeniem implementacji.

## 9. Google Drive i ślad pracy

Zgodnie z instrukcją sprawdzono dostępność stagingu Windows: mount `/mnt/c` oraz `/mnt/c/Users/Public` istnieją; sprawdzony katalog profilu `/mnt/c/Users/veltra` nie istnieje. Nie znaleziono zatwierdzonej, prywatnej ścieżki stagingowej należącej do właściciela raportu. Publiczny katalog nie został użyty, aby nie poszerzać dostępu do raportu. Nie ponawiano odrzuconej ścieżki WSL w selektorze plików.

Docelowy folder Drive: [RAPORTY_COPILOTA](https://drive.google.com/drive/folders/1I2r4uTKcpl69E3dXOru18VOLi74N613t). Dedykowany folder `VELTRA_STAGE_4B2A_CAPABILITIES_DESIGN` i upload nie zostały potwierdzone w tej analizie; raport pozostaje dostępny lokalnie:

- Linux: `/home/veltra/projekty/veltra/VELTRA_STAGE_4B2A_CAPABILITIES_CONTRACT_PROPOSAL.md`
- Windows: `\\wsl.localhost\Ubuntu\home\veltra\projekty\veltra\VELTRA_STAGE_4B2A_CAPABILITIES_CONTRACT_PROPOSAL.md`

Nie odczytywano haseł, cookies ani tokenów. Nie twierdzi się, że plik został przesłany.

## 10. Potwierdzenie zakresu

- Nie zmieniono kodu aplikacji, backendu, frontendowej logiki, testów ani schematu bazy.
- Nie uruchamiano testów, migracji, aplikacji, kontenerów ani usług.
- Nie rezerwowano SKU, nie tworzono danych RAW, nie dotykano instalacyjnych baz ani zasobów.
- Nie wykonano wdrożenia, commitu ani pushu.
- Istniejące, niezatwierdzone zmiany Stage 4A.2 i 4B.1 pozostawiono bez zmian.
- `RAW_MATERIALS = NOT_READY`; nie wykonano aktywacji.

Przed implementacją wymagane są osobna, jawna zgoda właściciela i akceptacja powyższego kontraktu. Nie rozpoczynać Stage 4B.2A ani 4B.2B na podstawie samego raportu.
