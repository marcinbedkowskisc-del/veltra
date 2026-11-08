# VELTRA 2.0 — Stage 2F — zintegrowany projekt techniczny K2 + K3

## Status dokumentu

**Propozycja do przeglądu właściciela; implementacja niezatwierdzona.** To analiza statyczna kodu i dokumentacji dostępnej lokalnie. Nie zmieniano aplikacji, konfiguracji, danych, schematu ani modułów.

### Ograniczenia źródeł

- Lokalny [raport Stage 1D](./VELTRA_STAGE_1D_IMPLEMENTATION_REPORT.md) streszcza reguły i wyniki implementacji RBAC. Wskazuje zatwierdzoną specyfikację źródłową pod adresem [Google Docs Stage 1D](https://docs.google.com/document/d/1Hbt31CoeEeJV9nmwOtkEXVmAFRsKK3VQabCtCuzvN6E/edit), ale w tej sesji narzędzie przeglądarki nie nawiązało połączenia, więc nie można potwierdzić bezpośredniego odczytu pełnej specyfikacji.
- W repozytorium znaleziono raporty Stage 1E, w tym [raport implementacji](./VELTRA_STAGE_1E_IMPLEMENTATION_REPORT.md) i [weryfikację końcową](./VELTRA_STAGE_1E_FINAL_VERIFICATION.md), ale nie znaleziono zatwierdzonej specyfikacji źródłowej Stage 1E. Raporty są dowodem historycznego wyniku prac, nie substytutem specyfikacji.
- Zlokalizowano [audyt zgodności RAW 1–100](./VELTRA_STAGE_2F_RAW_BACKEND_COMPATIBILITY_AUDIT.md) oraz najnowszą lokalną [analizę wpływu K3](./VELTRA_STAGE_2F_RAW_K3_TECHNICAL_IMPACT.md). K3.1–K3.21 pochodzą z zatwierdzonego snapshotu przekazanego w rozmowie.
- [ADR-001](./VELTRA_ADR_001_MODEL_WDROZENIA_KLIENTA.md) jest dostępny lokalnie i określa odrębną instancję, bazę, Redis, pliki, konfigurację i kopie zapasowe dla każdego klienta, bez zniesienia granic organizacji i lokalizacji.

Wnioski dotyczące Stage 1D należy przed implementacją skonfrontować z oryginalnym zatwierdzonym dokumentem, a właściciel powinien wskazać lub udostępnić źródłową specyfikację Stage 1E. W miejscach zależnych od tych źródeł raport rozróżnia fakty z kodu od kontekstu historycznego.

## 1. Potwierdzone fakty techniczne

### Tożsamość i bootstrap

- [IdentityService::provisionInitial](./backend/app/Domain/Identity/IdentityService.php#L19) normalizuje adres, wymaga silnego hasła, wykonuje transakcję, blokuje instalację i odrzuca utworzenie kolejnej tożsamości, jeśli użytkownik już istnieje. Tworzy użytkownika z `is_initial_identity=true`; sama ta operacja nie nadaje roli ani członkostwa. Blokada advisory i blokada organizacji serializują konkurencyjne wywołania tego serwisu.
- [Migracja tożsamości](./backend/database/migrations/2026_11_02_000002_create_user_identity_and_memberships.php#L19) ustanawia częściowy UNIQUE dla pojedynczej początkowej tożsamości, a trigger uniemożliwia zmianę jej ID, organizacji lub flagi i usunięcie użytkownika. Nie chroni jednak przed dezaktywacją ani odebraniem roli.
- [BootstrapAuthorization::handle](./backend/app/Console/Commands/BootstrapAuthorization.php#L39) wymaga interaktywnego TTY i potwierdzenia hasła początkowej tożsamości. Wybiera istniejącą aktywną kuchnię centralną przez `orderBy('code')`; nie ustala trwale kuchni utworzonej jako pierwsza. Jeśli kuchni nie ma, tworzy ją. Następnie tworzy członkostwo i rolę administratora.
- [RoleAdministration::bootstrapInitial](./backend/app/Domain/Authorization/RoleAdministration.php#L119) blokuje organizację i użytkownika, wymaga początkowej tożsamości oraz braku wcześniejszego administratora, po czym nadaje `ADMINISTRATOR`. Żaden status SUPER ADMIN nie jest obecnie przechowywany ani wyliczany.
- [Test początkowej tożsamości](./backend/tests/Feature/InitialIdentityProvisioningTest.php#L13) potwierdza interaktywny bootstrap, brak ról/członkostw na etapie samego provisioningu i odrzucenie drugiej tożsamości. Nie jest to dowód testu wyścigu dwóch równoległych provisioningów. Istniejący [test współbieżności administratora](./backend/tests/Feature/AdministratorConcurrencyTest.php#L16) dotyczy ochrony ostatniego administratora, nie wyboru głównej kuchni.

### Role, uprawnienia i ochrona kont

- Model ma sześć ról RBAC. `ADMINISTRATOR` ma zakres organizacyjny. [BusinessAuthorization](./backend/app/Domain/Authorization/BusinessAuthorization.php#L14) wymaga aktywnej, zweryfikowanej tożsamości, aktywnego kontekstu i członkostwa; rola nie zastępuje wymogów uwierzytelnienia.
- [RoleAdministration](./backend/app/Domain/Authorization/RoleAdministration.php#L82) i [PermissionAdministration](./backend/app/Domain/Authorization/PermissionAdministration.php#L63) egzekwują istniejące uprawnienia administracyjne. Kod nie rozpoznaje wyjątku SUPER ADMIN; raport Stage 1D opisuje administratora jako podmiot zarządzający kontami, rolami i uprawnieniami.
- Odebranie roli administratora lub dezaktywacja konta sprawdza, czy pozostanie inny używalny administrator. To ochrona ciągłości administracji, nie ochrona konkretnej tożsamości bootstrapowej.
- [PersonnelAdministration::removeMembership](./backend/app/Domain/Authorization/PersonnelAdministration.php#L109) może odebrać członkostwo po kontroli uprawnienia i warunku pozostawienia używalnego administratora. Nie ma wyjątku chroniącego członkostwo pierwotnej tożsamości.
- [AccountLinks::consume](./backend/app/Domain/Identity/AccountLinks.php#L110) realizuje aktywację i reset przez jednorazowy token i nowe hasło dla istniejącego użytkownika; aktualizuje weryfikację adresu, usuwa token i zapisuje zdarzenie. Trigger w migracji kont podnosi `auth_version` po zmianie hasła/adresu/dezaktywacji. Tożsamość użytkownika nie jest zmieniana. Kontroler kończy sesję po zużyciu linku.
- [Migracja audytu i ochrony administratora](./backend/database/migrations/2026_11_02_000004_protect_administrators_and_security_history.php#L8) tworzy append-only `security_events`, jednokierunkowy latch inicjalizacji oraz odroczoną kontrolę istnienia używalnego administratora. Ochrona jest ogólna; nie zabezpiecza SUPER ADMIN jako konkretnej osoby.

### RAW, finanse i gotowość modułów

- [K3 analysis](./VELTRA_STAGE_2F_RAW_K3_TECHNICAL_IMPACT.md) stwierdza na podstawie bieżącego kodu, że RAW jest obecnie widoczny organizacyjnie, brakuje modelu jawnych udostępnień lokalizacyjnych i trwałego znacznika głównej kuchni, a granty RAW nie zastępują udostępnień.
- K3.15 wymaga roli `ADMINISTRATOR` i właściwej autoryzacji do zarządzania udostępnieniami. SUPER ADMIN spełnia ten wymóg przez dziedziczenie roli, a nie przez nową rolę ani pominięcie kontroli.
- Uprawnienia `FOODCOST_VIEW`, `PURCHASE_PRICES_VIEW` i RAW-owe prawa do cen są odrębne. K2.3 nie dopuszcza ich automatycznego dziedziczenia przez SUPER ADMIN.
- [Katalog modułów](./backend/app/Domain/Modules/DefinedModuleCatalog.php#L38) zawiera RAW, ale `ModuleDefinition` domyślnie ustawia gotowość na false; raport Stage 1E potwierdza fail-closed i status produkcyjnych modułów `NOT_READY`. K2 nie zmienia tej bramki.

## 2. Macierz konfliktów K2, Stage 1D i K3

„Stage 1D” w kolumnie poniżej oznacza zachowanie opisane w lokalnym raporcie implementacji; pełna zatwierdzona specyfikacja źródłowa nie była dostępna do bezpośredniej weryfikacji w tej sesji.

| Zatwierdzone K2 | Reguła Stage 1D | Powiązanie K3 | Zachowanie kodu | Zgodność / konflikt | Wymagana zmiana lub decyzja | Konsekwencja bezpieczeństwa | Zalecane testy |
|---|---|---|---|---|---|---|---|
| **K2.1** Status, nie siódma rola; pierwotne konto bootstrapowe; kontekst głównej centrali | Sześć istniejących ról; początkowa tożsamość jest odrębnym etapem przed nadaniem roli | K3.3/K3.16: pierwsza centrala ma być trwale główna | `is_initial_identity` jest trwałe, ale statusu SUPER ADMIN brak; bootstrap wybiera aktywną centralę sortując po kodzie | **Konflikt techniczny:** brak statusu i brak trwałego wyznaczenia centrali | Wyprowadzać status z istniejącego, niezmiennego kotwiczenia początkowej tożsamości oraz jej roli `ADMINISTRATOR`; oznaczać główną centralę jawnie i niezmiennie. Nie dodawać roli RBAC | Dynamiczny wybór konta/lokalizacji może skierować uprawnienia do niewłaściwej osoby lub kontekstu | Provisioning równoległy, bootstrap/idempotencja, jednoznaczność primary, brak statusu bez wymaganych warunków |
| **K2.2** Status na stałe związany z pierwotną tożsamością; bez transferu | ID i flaga `is_initial_identity` są niezmienne; ogólna ochrona konta nie dotyczy szczególnie tej osoby | K3.16 zabrania przenoszenia statusu głównej centrali | Kotwica tożsamości istnieje; nie ma ochrony przed dezaktywacją lub odebraniem ADMINISTRATOR, jeśli pozostaje inny administrator | **Częściowa zgodność** tylko dla niezmienności ID/flag | Zachować kotwicę `is_initial_identity`; uniemożliwić przeniesienie statusu i ochronić powiązane role/kontekst | Możliwe przejęcie statusu przez zmianę roli lub skuteczne odcięcie pierwotnej tożsamości | próby zmiany flagi/ID, odebrania roli, transferu i odtworzenia po resecie |
| **K2.3** Dziedziczenie standardowej roli administratora, bez wyłączności finansowej | Finanse są osobnymi grantami; administrator ma zwykły zakres organizacyjny | K3.2/K3.15: share nie przyznaje praw do cen | Oddzielne prawa finansowe istnieją; SUPER ADMIN nie istnieje | **Zgodne jako wymóg; brak implementacji statusu** | Zachować istniejące kontrole finansowe; status nie może być warunkiem ani źródłem `FOODCOST_VIEW`, `PURCHASE_PRICES_VIEW` ani RAW cen | Łączenie statusu z finansami naruszyłoby minimalne uprawnienia i izolację finansową | Testy odczytu/eksportu dla SUPER ADMIN i innych ról bez jawnego grantu; próby przez API |
| **K2.4** Cztery domeny dodatkowych uprawnień; bez automatycznej akceptacji ich operacji | Istniejący model uprawnień jest jawny i ograniczony; nie ma statusu SUPER ADMIN | K3 określa konkretne potrzeby RAW niezależnie od domen K2.4 | Brak szczegółowego katalogu operacji dla tych czterech domen | **Brak konfliktu; niekompletna specyfikacja operacyjna** | Nie tworzyć operacji/endpoints. Każda konkretna pozycja wymaga akceptacji; żadna z nich nie jest warunkiem realizacji K2.5/K3 | Nieograniczone „super” API tworzyłoby niezatwierdzone, trudne do audytu uprawnienia | Test allowlisty; domyślna odmowa niezatwierdzonych operacji |
| **K2.5 + K2.7** Wyłącznie SUPER ADMIN zarządza innymi kontami ADMINISTRATOR, obejmując role, prawa, status i członkostwa lokalizacyjne; zwykli administratorzy zachowują prawa wobec kont nieadministracyjnych | Jawnie zmieniają poprzedni Stage 1D dla kont administratorów; pozostały model nieadministracyjny pozostaje | K3.15 nadal pozwala ADMINISTRATOROM zarządzać udostępnieniami RAW; to nie jest zarządzanie kontami administratorów | Obecne usługi nie rozróżniają SUPER ADMIN i nie egzekwują tej granicy dla kont | **Jawny, zatwierdzony konflikt z poprzednim Stage 1D** | We wszystkich ścieżkach kont/rol/grantów/statusu/członkostwa celu ADMINISTRATOR wymagać SUPER ADMIN. Zachować zwykłe reguły dla celów nieadministracyjnych. Zmiany własnego konta SUPER ADMIN regulować K2.9/K2.6 | Administrator może zmieniać konto administratora lub pośrednio eskalować przywileje bez wymaganej autoryzacji SUPER ADMIN | Testy pozytywne/negatywne dla każdego typu mutacji; zwykłe konta pozostają zarządzalne zgodnie z dotychczasowym RBAC |
| **K2.6 + K2.9** Chronione działania konta pozostają zabronione nawet dla samego SUPER ADMIN; edycja zwykłego profilu i e-maila jest wyłącznie samoobsługowa | Dotychczasowy Stage 1D dopuszczał administracyjne zmiany kont; K2.9 określa szczególną regułę dla SUPER ADMIN | K3 nie zmienia zasad resetu/uwierzytelnienia | Brak specjalnego rozdziału edycji własnego konta SUPER ADMIN od jego chronionego lifecycle | **Zatwierdzona reguła; obecnie niezaimplementowana** | Zezwalać SUPER ADMIN wyłącznie na własne edytowalne dane, w tym e-mail, z obowiązkową ponowną weryfikacją. Odrzucać modyfikację przez innych administratorów oraz samodzielne usunięcie/dezaktywację/blokadę/odebranie roli/statusu | Niewłaściwe rozdzielenie edycji profilu i lifecycle może dać innym administratorom kontrolę nad statusem lub obejść ochronę | Testy samoedycji e-maila i profilu, weryfikacji, unieważnienia sesji, odmowy dla innych administratorów i blokady chronionych mutacji |
| **K2.8** Pierwsza utworzona kuchnia centralna jest trwale primary; brak zgadywania dla istniejących instalacji o nieustalonej kolejności | Brak wnioskowania o kolejności z ID/kodu; oznaczenie ma odzwierciedlać rzeczywiste tworzenie | Potwierdza K3.16 bez zmiany | Obecny bootstrap wybiera aktywną centralę po `code`; primary nie jest trwale oznaczona | **Zatwierdzony wymóg; obecne zachowanie niezgodne** | Atomowo i niezmiennie wyznaczyć primary przy utworzeniu pierwszej centrali, z gwarancją rzeczywistej kolejności. Dla danych legacy wymagane autorytatywne dowody; bez nich nie ustawiać primary | Błędny wybór może trwale przypisać katalog i status do niewłaściwej lokalizacji | Wyścigi tworzenia pierwszych central, różne kody/UUID, niezmienność primary; legacy bez dowodu pozostaje nierozstrzygnięte i bez cichego backfillu |

## 3. Proponowany projekt techniczny

### 3.1 Minimalne rozszerzenie RBAC

1. Zachować istniejące sześć ról. Nie dodawać `SUPER_ADMIN` jako roli.
2. Użyć `users.is_initial_identity` jako trwałego identyfikatora oryginalnej tożsamości bootstrapowej — pole jest już unikalne i niemodyfikowalne. Status SUPER ADMIN wyliczać wyłącznie wtedy, gdy identyczny użytkownik ma rolę `ADMINISTRATOR` i spełnia zwykłe warunki uwierzytelnienia/autoryzacji w kontekście utrwalonej głównej kuchni centralnej.
3. Nie dodawać odrębnego, swobodnie nadawalnego pola statusu, które mogłoby rozjechać się z `is_initial_identity`. Jeśli po analizie kodu potrzebny okaże się jawny materializowany marker, jego constraint musi wiązać go z dokładnie tą samą początkową tożsamością i uniemożliwiać zmianę.
4. Sprawdzenie statusu umieścić w istniejących serwisach administracyjnych po wcześniejszym sprawdzeniu aktywnej sesji, hasła tam, gdzie jest wymagane, weryfikacji e-maila, członkostwa, organizacji, lokalizacji i RBAC. Nie dodawać globalnego obejścia w `BusinessAuthorization`.
5. Zmiany w usługach zarządzania personelem, rolami, uprawnieniami i kontami stosować do pełnego zakresu K2.7: aktor musi być SUPER ADMIN, gdy zmieniane jest inne konto ADMINISTRATOR — jego role, uprawnienia, status lub członkostwa — albo gdy mutacja nadaje/odbiera rolę ADMINISTRATOR. Zwykli administratorzy zachowują dotychczasowe prawa wobec kont nieadministracyjnych.
6. Wspólny resolver statusu i kontekstu należy współdzielić przez istniejący serwis autoryzacji/administracji; nie tworzyć równoległego frameworka uprawnień.

7. K2.9 dopuszcza edycję własnego profilu SUPER ADMIN, w tym e-maila; zmiana e-maila musi przejść istniejący proces ponownej weryfikacji i unieważnienia sesji. Inny administrator nie może edytować konta SUPER ADMIN. K2.6 nadal odrzuca samoobsługowe lub cudze mutacje chronionego lifecycle.

### 3.2 Trwałe oznaczenie głównej kuchni i wyścigi

- Dodać forward-only, jawne oznaczenie głównej kuchni centralnej per organizacja: co najwyżej jedna wartość `true`, niezmienna po ustawieniu i możliwa tylko dla lokalizacji typu `CENTRAL_KITCHEN`. Możliwa realizacja: kolumna z częściowym unikalnym indeksem albo dedykowany rekord singletona; wybór powinien wynikać z istniejących wzorców migracji.
- Ustawienie następuje w transakcji `InstallationService::createLocation` pod blokadą organizacji i odzwierciedla rzeczywistą kolejność utworzenia. Przy pierwszej kuchni centralnej atomowo oznaczyć ją jako główną; nie ustalać pierwszeństwa przez sortowanie po `id`, `code`, nazwie ani nieudokumentowanym `created_at`. Zapewnić serializację tworzenia konkurencyjnych central i transakcyjne przypisanie designation.
- Bootstrap ma używać utrwalonego klucza głównej centrali, a nie `orderBy('code')`; przypisanie członkostwa i roli ADMINISTRATOR musi nastąpić w tej samej transakcji lub według idempotentnego protokołu.
- Zgodnie z K2.8 designation musi wynikać z rzeczywistej kolejności utworzenia, a nie z ID, kodu lub nazwy; żaden administrator nie może wybierać, zmieniać ani przenosić designation. Dla instalacji legacy bez wiarygodnego dowodu kolejności nie wykonywać backfillu i nie wybierać zastępczej centrali.
- Testy powinny wymusić równoległe tworzenie pierwszych centrali i bootstrapu, sprawdzić jeden trwały rekord główny oraz brak możliwości zmiany/wyłączenia primary poza zatwierdzonym K3 lifecycle.
- Nie da się bezpiecznie wywnioskować, która z istniejących central była „pierwsza” z bieżącego sortowania po kodzie ani z UUID. Migracja istniejących danych wymaga jawnego, zatwierdzonego mapowania per instalacja; nie wykonywać automatycznego backfillu na podstawie ID/kodu.

### 3.3 Ochrona konta, sesji i odzyskiwanie

- Zachować `is_initial_identity` i niezmienność UUID; nie reassignować statusu przy usunięciu, blokadzie, zmianie e-maila ani resetowaniu hasła.
- W warstwie usług odrzucać wszystkie działania K2.6 wobec tej tożsamości, także self-service: dezaktywację, zawieszenie/blokadę, odebranie `ADMINISTRATOR` lub statusu, a także usunięcie/odebranie członkostwa w primary, jeśli uczyniłoby tożsamość niezdolną do działania w wymaganym kontekście.
- Odpowiednie wąskie zabezpieczenia PostgreSQL powinny odrzucać bezpośrednie runtime SQL zmieniające stan konta, rolę lub członkostwo primary. Korzystać z istniejących triggerów i `AuthorizationMutationContext`/`SecurityMutation`, rozszerzając tylko chronione niezmienniki. Nie przenosić całej logiki RBAC do PostgreSQL i nie tworzyć nowego generycznego podpisywania mutacji.
- Reset hasła ma nadal wymagać normalnego linku/tokena, hasła spełniającego politykę i unieważniać stare sesje. Pozytywny test odzyskiwania ma wykazać niezmieniony UUID/`is_initial_identity`, ten sam status (o ile role i primary pozostają), oraz brak automatycznego logowania. Żadna procedura resetu nie może przypisać statusu nowemu użytkownikowi.
- Zgodnie z K2.9 SUPER ADMIN może samodzielnie zmienić własny adres e-mail i inne edytowalne dane profilu. Zmiana e-maila nadal wymaga ponownej weryfikacji zgodnie z obecnym modelem i nie może zmienić trwałej tożsamości. Inny administrator nie może edytować konta SUPER ADMIN.

Ochrona konta nie jest uwierzytelnieniem. SUPER ADMIN nadal musi przejść normalną weryfikację sesji, hasła, weryfikacji e-mail, aktywnego kontekstu, RBAC i gotowości modułu. Uprawnienie do zachowania konta nie daje sesji ani dostępu do API.

### 3.4 Autoryzacja kont administratorów i audyt

- Każdy punkt mutacji ról, permission grants, zmian stanu konta i członkostw musi zostać sklasyfikowany. Dla zakresu K2.7 usługi sprawdzają świeże dane celu pod blokadą i ustalają jego bieżące/zmieniane role administratora.
- Nadanie/odebranie roli ADMINISTRATOR wymaga SUPER ADMIN niezależnie od aktualnej roli celu. Zachować dotychczasowe świeże potwierdzenie hasła dla nadania/odebrania tej roli, limity, zakres organizacji i zabezpieczenie przed brakiem używalnego administratora.
- Wyłącznie SUPER ADMIN może zmienić rolę, uprawnienia, status lub członkostwa innego konta ADMINISTRATOR, a także nadać/odebrać rolę ADMINISTRATOR. Zachować istniejące wymagania świeżego potwierdzenia hasła, limity, zakres organizacji i ochronę minimum używalnego administratora.
- Zwykli administratorzy zachowują dotychczasowe uprawnienia wobec kont nieadministracyjnych. Nie ograniczać ich przez globalny warunek „tylko super” dodany w middleware.
- Zmiana profilu/e-maila SUPER ADMIN jest dozwolona tylko temu użytkownikowi. Weryfikacja nowego adresu i unieważnienie sesji pozostają obowiązkowe. Żaden aktor nie może zmienić jego chronionego lifecycle zdefiniowanego w K2.6.
- Udane mutacje zapisują `security_events` w tej samej transakcji co zmiana: actor, target, kontekst primary/organizacja, typ operacji, rezultat i subject. Odrzucone próby muszą być jawnie audytowane zgodnie z istniejącym wzorcem. Nie zapisywać hasła, tokenu resetu ani sekretu.
- Zachować append-only historii; testy muszą porównywać stan danych z wpisami audytu po sukcesie, odrzuceniu i rollbacku.

### 3.5 Integracja K3

- Katalog RAW i jego własność pozostają oddzielne od statusu SUPER ADMIN: katalog jest jeden dla organizacji i zarządzany z kontekstu utrwalonej głównej kuchni centralnej (K3.1, K3.3, K3.16). SUPER ADMIN nie staje się właścicielem katalogu tylko z racji statusu; prawa katalogowe wynikają z zatwierdzonych uprawnień i kontekstu.
- Zarządzanie udostępnieniami wymaga roli `ADMINISTRATOR` oraz pełnej bieżącej autoryzacji zgodnie z K3.15. SUPER ADMIN dziedziczy tę zwykłą możliwość; K2.5 nie przenosi zarządzania share do domeny wyłącznej SUPER ADMIN.
- Udostępnienie nie daje `FOODCOST_VIEW`, `PURCHASE_PRICES_VIEW` ani praw RAW do kosztów/cen. Sprawdzenie dostępu do materiału musi niezależnie egzekwować share, stan materiału, zatwierdzoną rewizję, aktywny moduł, kontekst i RBAC.
- Rewizje robocze pozostają niewidoczne odbiorcom; revocation blokuje nowe operacje, zachowuje historię; archive i wyłączenie modułu zachowują relacje, lecz zawieszają ich skuteczność. K3.21 odróżnia zwykłe cofnięcie od zagrożenia bezpieczeństwa żywności.
- Formalne wycofanie RAW wymaga dwóch odrębnych, nadal uprawnionych tożsamości ADMINISTRATOR. Inicjator nie może zatwierdzić własnego wniosku; SUPER ADMIN nie może reprezentować obu osób. Jeśli istnieje tylko jeden administrator, drugiego ustanawia się przed procedurą według K2.5, czyli przez SUPER ADMIN. Na zatwierdzeniu należy ponownie sprawdzić role, aktywność, różność tożsamości, zależności i historię.
- Nie tworzyć w K2 żadnych dodatkowych operacji administracyjnych RAW. Share i wycofanie pozostają w kontraktach K3; szczegół API i workflow wymagają osobnego projektu K3.

### 3.6 API, serwisy i migracje

- Utrzymać dotychczasowe API i przenieść politykę do istniejących usług domenowych, z kontrolerami jako cienką warstwą walidacji/transportu. Zabezpieczyć wszystkie istniejące trasy ról, grantów, kont i członkostw, a nie tylko nowy ekran lub endpoint.
- Bootstrap pozostaje zaufaną ścieżką instalacyjną wymagającą interaktywnego potwierdzenia hasła. Nie dodawać publicznego endpointu nadawania statusu.
- Migracje wyłącznie forward-only: trwałe oznaczenie primary, constrainty jedno-primary i niezmienności, ochrona początkowej tożsamości, ewentualne wąskie triggery oraz indeksy. Backfill primary wymaga jawnego mapowania zatwierdzonego dla danej instalacji.
- Nie zmieniać już zastosowanych migracji. Rollback nowej migracji nie może usuwać zatwierdzonych danych ani statusu; w praktyce odwrócenie trwałych oznaczeń może być zabronione. Procedura wdrożenia musi przewidzieć kopię zapasową, preflight istniejących danych, idempotencję i kontrolę współbieżności.
- Zmiany schematu nie otwierają RAW. `NOT_READY` pozostaje bezwzględną bramką niezależną od SUPER ADMIN.

## 4. Macierz testów bezpieczeństwa i regresji

| Obszar | Wymagane scenariusze |
|---|---|
| Bootstrap i tożsamość | Dwie równoległe próby tworzenia pierwszego użytkownika; tylko jedna początkowa tożsamość; początkowe konto samo nie dostaje sesji/roli przed właściwą aktywacją; bootstrap przypisuje ADMINISTRATOR do utrwalonej primary; ponowienie jest idempotentne |
| K2.8 / Primary central | Dwie równoległe centrale zachowują faktyczną kolejność; kod/nazwa/UUID nie wybierają primary; designation jest niezmienne i nie może być wybierane, zmieniane ani transferowane; legacy bez wiarygodnego dowodu kolejności nie otrzymuje zgadywanego backfillu |
| K2.5/K2.7 | Zwykły ADMINISTRATOR nie może zmieniać roli, praw, statusu ani członkostw innego administratora; SUPER ADMIN może zarządzać innymi kontami ADMINISTRATOR; dotychczasowe prawa wobec kont nieadministracyjnych pozostają |
| K2.6 | SUPER ADMIN nie może sam ani przez innego aktora zostać dezaktywowany, zablokowany, pozbawiony roli/statusu lub członkostwa primary; bezpośredni runtime SQL odrzucony; próby są audytowane |
| K2.9 | SUPER ADMIN może edytować własny profil/e-mail; inni administratorzy nie mogą modyfikować tego konta; ponowna weryfikacja e-mail i unieważnienie sesji działają; chronione operacje K2.6 pozostają niemożliwe także dla self |
| Auth/recovery | Brak sesji/nieprawidłowe hasło/niezweryfikowany e-mail nadal odrzucane; reset tokenem przywraca wyłącznie to samo UUID, unieważnia sesje, nie nadaje statusu innemu kontu |
| Audyt/transakcje | Akceptacja i zdarzenie audytu commitują razem; wyjątek/rollback nie pozostawia sukcesu; odrzucenie jest jawnie zapisane; historia niezmienna |
| Finanse | Brak praw finansowych bez odpowiedniego grantu, także u SUPER ADMIN; pola/eksporty cen nadal redagowane dla pozostałych kont |
| K3 share | Tylko ADMINISTRATOR z prawidłowym kontekstem i aktywnym RAW może zarządzać share; SUPER ADMIN działa przez standardową rolę; share nie rozszerza finansów; deny-by-default; rewizje, archive, revoke i module-off zgodne z K3 |
| K3 retirement | Dwie różne aktywne tożsamości ADMINISTRATOR; brak self-approval; ponowna walidacja zależności przy zatwierdzeniu; SUPER ADMIN może być tylko jedną osobą; brak drugiego admina blokuje workflow do czasu prawidłowego nadania roli przez SUPER ADMIN |
| Regresja Stage 1E | `NOT_READY` nadal blokuje aktywację i użycie modułu niezależnie od roli/statusu; prawidłowe logowanie i audyt konfiguracji pozostają wymagane |

## 5. Zależności i kolejność proponowanej realizacji

1. **Uzupełnić źródła i decyzje:** odczytać zatwierdzoną specyfikację Stage 1D/1E; rozstrzygnąć wyłącznie pozostające kwestie operacji K2.4 i zakresu audytu.
2. **Projekt schematu i migracji:** przed kodem zatwierdzić marker primary, invariants, preflight i plan odwracalności. Dla każdej instalacji sprawdzić wiarygodność dowodu faktycznej kolejności; jeśli brak dowodu, nie wyznaczać primary ani nie backfillować.
3. **Tożsamość i bootstrap:** utrwalić primary atomowo, połączyć ją z pierwotnym bootstrap adminem, zachować kolejność i idempotencję; nie dawać statusu drugiemu użytkownikowi.
4. **Ochrona SUPER ADMIN:** dodać wąskie kontrole usług i DB, testy self/other/direct-SQL oraz zachować zwykłe uwierzytelnienie i recovery.
5. **K2.5/K2.7:** ograniczyć do SUPER ADMIN zarządzanie innymi kontami ADMINISTRATOR w pełnym zatwierdzonym zakresie (role, uprawnienia, status i członkostwa); zachować dotychczasowe prawa zwykłych administratorów wobec kont nieadministracyjnych i dodać spójny audyt.
6. **K3 integration:** w osobnym zatwierdzonym zakresie zaprojektować lokalizacyjne udostępnienia, lifecycle i dual-admin retirement; integrować przez tę samą rolę, primary i bazy audytu, bez uruchamiania RAW przed pełną gotowością.
7. **Weryfikacja:** izolowane, syntetyczne PostgreSQL; testy wyścigów, bezpośredniego SQL i regresji; świeża instalacja i forward upgrade reprezentatywnych instalacji; raport readiness przed zgodą na wdrożenie.

## 6. Nierozstrzygnięte kwestie wymagające właściciela

1. Która kuchnia centralna jest primary w każdej istniejącej instalacji, jeśli jej pierwszeństwo nie jest zapisane historycznie.
2. Konkretne operacje i audyt dla domen K2.4 — nie są zatwierdzone samą nazwą domen.
3. Potwierdzenie, że proponowany warunek statusu w kontekście primary realizuje intencję K2.1 bez zmiany organizacyjnego zakresu standardowego RBAC.
4. Źródłowa, zatwierdzona specyfikacja Stage 1E oraz potwierdzenie zgodności szczegółów Stage 1D z oryginalnym dokumentem.

## Wniosek

K2 i K3 mogą współistnieć bez nowej roli RBAC i bez naruszenia izolacji finansowej: SUPER ADMIN jest stałą tożsamością początkowego administratora, K2.7 ogranicza zarządzanie innymi kontami ADMINISTRATOR w pełnym zatwierdzonym zakresie, a K3 share pozostaje zwykłym, autoryzowanym prawem administratora. Obecny backend nie realizuje jeszcze tych gwarancji; najważniejsze luki to brak statusu/tożsamości chronionej, brak trwałego primary, obecny wybór centrali po kodzie oraz brak właściwej granicy zarządzania kontami administratorów. Projekt wymaga uzupełnienia źródeł i decyzji migracyjnych przed implementacją.

## Uzupełnienie zatwierdzonymi decyzjami K2.7–K2.9

Poniższe reguły są zatwierdzonymi wymaganiami biznesowymi i zastępują wcześniejsze fragmenty projektu, które pozostawały z nimi sprzeczne. Proponowane rozwiązania techniczne nadal wymagają osobnej akceptacji przed implementacją.

- **K2.7 — zarządzanie innymi administratorami:** wyłącznie SUPER ADMIN może zarządzać innymi kontami ADMINISTRATOR, obejmując role, uprawnienia, status konta i członkostwa lokalizacyjne. Zwykli administratorzy zachowują dotychczasowe uprawnienia wobec kont nieadministracyjnych. To jawnie zmienia Stage 1D w tym zakresie; nie ogranicza zwykłych uprawnień ADMINISTRATOR do zarządzania udostępnieniami RAW zgodnie z K3.15.
- **K2.8 — primary kitchen:** pierwsza faktycznie utworzona kuchnia centralna staje się automatycznie i niezmiennie główna; żaden administrator nie może jej wybrać, zmienić, ponownie przypisać ani przekazać. Reguła potwierdza K3.16. Kolejności nie wolno wyprowadzać z ID, kodu ani nazwy. W istniejącej instalacji, w której rzeczywista kolejność nie jest wiarygodnie ustalona, nie wolno zgadywać ani po cichu wyznaczyć innej kuchni.
- **K2.9 — własne konto SUPER ADMIN:** wyłącznie SUPER ADMIN może edytować własny adres e-mail i inne edytowalne dane profilu, zachowując tę samą tożsamość. Inny administrator nie może modyfikować tego konta. Zmiana adresu wymaga dotychczasowej ponownej weryfikacji e-mail i zachowania normalnych mechanizmów sesji/auth. K2.6 nadal bez wyjątku zakazuje usunięcia, dezaktywacji, zawieszenia, administracyjnej blokady, odebrania roli ADMINISTRATOR lub statusu SUPER ADMIN, także na własne żądanie.

### Konsekwencje techniczne i testy do projektu

1. Wszystkie istniejące ścieżki mutacji kont, ról, uprawnień, statusu i członkostw muszą rozpoznawać, czy celem jest konto ADMINISTRATOR; nie wystarczy ograniczyć samego endpointu nadawania roli. Autoryzację egzekwować w serwisach, z odpowiednim audytem i bez zmiany praw zwykłych administratorów wobec celów nieadministracyjnych.
2. Tworzenie kuchni centralnych musi serializować zapis trwałego oznaczenia primary tak, by odzwierciedlał rzeczywistą kolejność tworzenia także przy równoczesnych żądaniach. Bootstrap korzysta z utrwalonej primary, nie sortowania po kodzie. Dla legacy bez wiarygodnego dowodu kolejności migracja designation pozostaje zablokowana.
3. Samoedycja SUPER ADMIN musi oddzielać dozwolone pola profilu od zakazanych zmian lifecycle. Testy obejmują zmianę e-maila z ponowną weryfikacją i zachowaniem identyfikatora, odmowę dla edycji przez inne konto oraz zakazy K2.6 dla działań własnych i cudzych.
4. Testy K2.7 obejmują każde pole zarządzania kontem administratora (role, granty, status, członkostwa) oraz regresję praw wobec kont nieadministracyjnych. Testy K2.8 obejmują rzeczywistą kolejność niezależnie od kodu/nazwy/UUID, współbieżne utworzenie i odmowę zgadywanego backfillu legacy.

Wcześniejsze wzmianki w tym dokumencie o nierozstrzygniętym zakresie K2.5, nieokreślonej samoedycji SUPER ADMIN lub swobodnym mapowaniu primary należy interpretować zgodnie z powyższymi zatwierdzonymi decyzjami. Jeśli dla legacy nie ma wiarygodnego dowodu kolejności, designation i migracja pozostają zablokowane — nie jest to zgoda na wybór innej centrali. Właściciel musi jeszcze określić szczegóły niezatwierdzonych operacji domen K2.4 i zatwierdzić techniczne rozwiązanie/migrację.
