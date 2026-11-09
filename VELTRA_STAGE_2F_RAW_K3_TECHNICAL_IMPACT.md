# VELTRA 2.0 — Stage 2F — RAW

## Analiza wpływu technicznego decyzji K3.1–K3.21

- **Data:** 2026-11-08
- **Podstawa:** przekazany przez właściciela zatwierdzony snapshot K3, wersja 1.0
- **Status biznesowy:** decyzje K3.1–K3.21 zatwierdzone
- **Status implementacji:** nieautoryzowana
**Tryb analizy:** read-only; bez zmian kodu, konfiguracji, danych, schematu, modułów i infrastruktury

## 1. Podsumowanie

Decyzje K3 zamykają wcześniejszą niejednoznaczność biznesową dotyczącą właściciela katalogu i modelu dostępu: katalog ma być zarządzany przez pierwszą, trwale oznaczoną kuchnię centralną, a restauracje i pozostałe kuchnie centralne otrzymują wyłącznie jawne udostępnienia. Nie oznacza to, że obecny backend już realizuje ten model.

Obecna implementacja RAW jest organizacyjna, a nie lokalizacyjna. Zatwierdzone surowce są odczytywane po `organization_id`; `RawMaterialAuthorization` sprawdza członkostwo i grant uprawnienia dla kontekstu, ale nie sprawdza przypisania surowca do tej lokalizacji. Uprawnienia RAW są grantami organizacyjnymi. Operacje tworzenia/edycji nie są ograniczone do głównej kuchni centralnej. W obecnych trasach RAW nie ma API przyznawania ani cofania udostępnień surowców.

W konsekwencji backend **nie jest zgodny z K3.2–K3.12, K3.15–K3.21** bez znaczących zmian kontraktowych i domenowych. K3.1, K3.5, K3.13 i K3.14 mają elementy bazowe, ale nie spełniają pełnej reguły biznesowej. K3 nie tworzy sprzeczności z ADR-001: izolacja klienta na poziomie wdrożenia nie zastępuje wymogu egzekwowania granic organizacji i lokalizacji wewnątrz instancji.

**Rekomendacja:** **GO do projektu technicznego i analizy migracji; NO-GO do implementacji, migracji danych lub aktywacji RAW_MATERIALS bez odrębnej akceptacji właściciela.** Szczególnie istotne jest zatwierdzenie sposobu przeniesienia obecnej, szerokiej widoczności katalogu do modelu jawnych udostępnień. Nie wolno rozstrzygać tego przez automatyczne udostępnienie wszystkich istniejących surowców wszystkim lokalizacjom ani przez ciche odebranie obecnego dostępu.

## 2. Zakres, metoda i granice wnioskowania

- Zatwierdzony snapshot K3 przekazany w rozmowie traktuję jako źródło decyzji biznesowych, a nie zgodę na implementację.
- Stan techniczny ustaliłem przez statyczny odczyt bieżących tras, serwisów, autoryzacji, modeli, migracji i testów. Raporty historyczne Stage 1D/1E służyły jako kontekst i nie zastępowały dowodu z kodu.
- Nie uruchamiałem testów, migracji, zapytań do baz, kontenerów ani usług. Nie zmieniałem istniejącego raportu audytu Stage 2F.
- Brak implementacji receptur/produkcji w przeszukanych katalogach backendu nie dowodzi, że nie istnieje ona poza tym repozytorium. Zależność od takiej integracji oznaczam jako niezweryfikowaną i wymagającą potwierdzenia przed projektem integracyjnym.
- Żadne proponowane niżej rozwiązanie nie jest zatwierdzonym kontraktem API ani schematem. To zakres do projektu, nie instrukcja wdrożenia.

## 3. Stan bieżącej implementacji istotny dla K3

1. **Lokalizacje:** tabela `locations` ma `organization_id`, nazwę, typ i stan aktywności, ale nie ma wskaźnika głównej kuchni RAW. Typ lokalizacji i organizacja są niezmienne. Tworzenie lokalizacji serializuje się na organizacji, co jest użyteczną bazą do późniejszego, kontrolowanego wyznaczenia pierwszej kuchni — nie dowodem, że to wyznaczenie obecnie zachodzi. Zobacz [migrację instalacyjnej struktury](./backend/database/migrations/2026_11_02_000001_create_installation_structure.php#L24) i [InstallationService::createLocation](./backend/app/Domain/Installation/InstallationService.php#L33).
2. **Zakres danych RAW:** surowiec, rewizje, kategorie i dane handlowe zawierają `organization_id`; tabela `raw_materials` nie ma `owner_location_id`, a tabela powiązań udostępnień lokalizacyjnych nie występuje w przeszukanych migracjach. Zobacz [migrację modelu RAW](./backend/database/migrations/2026_11_02_000011_create_raw_materials_core.php#L31).
3. **Odczyt i autoryzacja RAW:** [RawMaterialService::list](./backend/app/Domain/RawMaterials/RawMaterialService.php#L92), [show](./backend/app/Domain/RawMaterials/RawMaterialService.php#L159), [revisions](./backend/app/Domain/RawMaterials/RawMaterialService.php#L177) oraz pomocnicze `material()` ograniczają dane do organizacji. [RawMaterialAuthorization::assertAllowed](./backend/app/Domain/RawMaterials/RawMaterialAuthorization.php#L14) weryfikuje aktywność, członkostwo w bieżącym kontekście, dostępność modułu i skuteczne uprawnienie, lecz nie wymaga udostępnienia konkretnego surowca tej lokalizacji.
4. **Zarządzanie katalogiem:** tworzenie i operacje lifecycle wymagają grantów RAW. Wyszukane ścieżki nie wiążą `Create`, `Edit`, `Review` ani `Approve` z lokalizacją będącą główną kuchnią centralną. Testy RAW tworzą materiały w kontekście restauracji przy użyciu uprawnionego użytkownika, co potwierdza istniejący kontrakt organizacyjny, a nie tylko brak nazwy pola w migracji: [RawMaterialsTest](./backend/tests/Feature/RawMaterialsTest.php#L48).
5. **Cykl rewizji:** istnieją wersjonowane rewizje, submit/approve, niezmienny hash, historia zatwierdzeń, blokowanie, archiwizacja i przywracanie. Lista katalogu filtruje rekordy bez `approved_revision_id`; szczegóły mogą pokazać szkic użytkownikowi z odpowiednim grantem edycji/audytu. Nie ma pojęcia lokalizacji-odbiorcy, której szkice miałyby być niewidoczne.
6. **Granty i role:** `BusinessRole::Administrator` jest rolą organizacyjną, a autoryzacja RAW opiera się na odrębnych grantach uprawnień RAW. `OrganizationRawMaterialPermissionAdministration` weryfikuje uprawnienie delegowania i grant źródłowy, nie wymusza roli `ADMINISTRATOR`. Zarządzanie grantami użytkowników nie jest tym samym co udostępnianie surowca lokalizacji.
7. **API:** istnieją endpointy katalogu, lifecycle, rewizji, alergii, przechowywania, handlu, cen i CSV. Nie znaleziono endpointów udostępniania RAW — ani z poziomu materiału, ani z poziomu lokalizacji — ani raportowania wyniku operacji zbiorczej. Reprezentatywne ścieżki są w [routes/api.php](./backend/routes/api.php#L64).
8. **Moduły:** `location_module_states` przechowuje konfigurację per lokalizacja; brak stanu oznacza wyłączenie. Każde żądanie RAW jest blokowane przez middleware dostępności. Produkcyjny katalog nie ustawia `ready: true`, ponieważ konstruktor `ModuleDefinition` domyślnie ustawia `ready = false`, a definicja `RAW_MATERIALS` nie nadpisuje tej wartości. Konfiguracja modułu zachowuje stan, jest tylko dla administratora, audytowana i odrzuca NOT_READY: [ModuleDefinition](./backend/app/Domain/Modules/ModuleDefinition.php#L10), [ConfiguredModuleAvailability::enabled](./backend/app/Domain/Authorization/ConfiguredModuleAvailability.php#L13), [ModuleConfigurationService::configure](./backend/app/Domain/Modules/ModuleConfigurationService.php#L69) oraz [test odrzucenia modułu NOT_READY](./backend/tests/Feature/ModuleConfigurationTest.php#L70).
9. **Wzorzec udostępnień:** `report_shares` zapisuje jawne udostępnienie i cofnięcie, lecz dotyczy raportu i odbiorcy-użytkownika. [ReportAuthorization](./backend/app/Domain/Authorization/ReportAuthorization.php#L16) zawiera wzorzec audytowanej mutacji, ale modelu raportów nie można utożsamiać z wymogiem K3: udostępnieniem surowca całej lokalizacji.
10. **Ochrona głównej kuchni:** aktualny `InstallationService::updateLocation` blokuje wyłączenie kuchni zarządzającej aktywnymi restauracjami, ale nie sprawdza używania RAW, udostępnień ani formalnego wycofania modułu. Nie ma procesu wycofania RAW ani modelu partii produkcyjnych w przeszukanych domenach backendu.

## 4. Macierz zgodności decyzji K3.1–K3.21

„Częściowo” oznacza, że istnieje przydatny element bazowy, ale nie jest spełniony pełny warunek decyzji. „Wymagana zmiana backendu” nie jest zgodą na jej wykonanie.

| Decyzja | Ocena bieżącego backendu | Dowód i wpływ |
|---|---|---|
| **K3.1** Jeden katalog surowców, zarządzany przez kuchnię centralną | **CZĘŚCIOWO WSPIERANE** | Istnieje jeden katalog per organizacja, ale jego rekordy nie są przypisane do głównej kuchni. Granty RAW i operacje katalogowe nie są ograniczone do takiej lokalizacji. Konieczne rozdzielenie katalogu organizacji od autoryzacji operacji zarządczych głównej centrali. |
| **K3.2** Wybrane restauracje widzą wyłącznie indywidualnie udostępnione surowce | **WYMAGANA ZMIANA BACKENDU** | Dostępność modułu, członkostwo i grant są sprawdzane, ale wynik zapytania nie jest filtrowany po udostępnieniu do bieżącej restauracji. Obecny dostęp jest szerszy niż decyzja. |
| **K3.3** Tylko pierwsza kuchnia centralna zarządza katalogiem; inne są odbiorcami | **WYMAGANA ZMIANA BACKENDU** | Nie ma trwałego znacznika pierwszej centrali ani warunku typu/roli tej lokalizacji w autoryzacji CRUD katalogu. System wspiera wiele kuchni centralnych. |
| **K3.4** Pozostałe centrale widzą tylko udostępnione surowce | **WYMAGANA ZMIANA BACKENDU** | Identyczny brak granicy share jak w K3.2; bieżący filtr organizacyjny obejmuje także inne centrale. |
| **K3.5** Propagacja dopiero po zatwierdzeniu; brak ponownej akceptacji odbiorców; szkice niewidoczne | **CZĘŚCIOWO WSPIERANE** | Rewizje mają cykl zatwierdzania i katalog nie listuje rekordów bez zatwierdzonej rewizji. Nie ma odbiorców, wersji udostępnionych ani kontraktu propagacji do lokalizacji. Widoczność szczegółu szkicu jest grantowa i organizacyjna, nie odbiorcza. |
| **K3.6** Cofnięcie blokuje nowe użycie, nie usuwa historii | **WYMAGANA ZMIANA BACKENDU** | Brak relacji udostępnień i operacji ich cofnięcia. Trzeba odróżnić blokadę nowych użyć od prawa do odczytu historii w kontekście dozwolonym przez RBAC. |
| **K3.7** Receptury zatwierdzone pozostają, nowe operacje blokowane i wskazują przyczynę | **NIEZWERYFIKOWANE / WYMAGANA INTEGRACJA** | W przeszukanych domenach backendu nie znaleziono modelu receptury zależnej od RAW ani API walidacji jej użycia. Bez kontraktu receptur nie da się zweryfikować wymaganego komunikatu i blokady. |
| **K3.8** Ponowne udostępnienie odblokowuje wyłącznie blokadę wynikającą z braku share | **WYMAGANA ZMIANA BACKENDU** | Nie istnieje share lifecycle ani kod agregujący jego skuteczność z innymi blokadami. Nie wolno czyścić blokady bezpieczeństwa/jakości przy zmianie udostępnienia. |
| **K3.9** Zarządzanie z poziomu surowca i lokalizacji, także zbiorczo | **WYMAGANA ZMIANA BACKENDU** | Brak obu kierunków endpointów i serwisu udostępnień. Wymaga wspólnego modelu operacji, aby dwa widoki miały identyczne reguły. |
| **K3.10** Częściowy sukces, raport per cel; brak uprawnienia odrzuca całość | **WYMAGANA ZMIANA BACKENDU** | Brak kontraktu bulk share. Istniejąca konfiguracja modułów jest odrębną operacją transakcyjną, zasadniczo odrzucającą nieprawidłową konfigurację jako całość; nie spełnia raportowania częściowego wyniku dla udostępnień. |
| **K3.11** Domyślny brak udostępnienia | **NIEZGODNE — ZMIANA BACKENDU** | Nie ma relacji share, więc brak relacji nie blokuje odczytu. Każda lokalizacja z włączonym modułem, grantem i członkostwem może odczytywać organizacyjny katalog. |
| **K3.12** Pierwsze udostępnienie dopiero po pierwszym zatwierdzeniu | **WYMAGANA ZMIANA BACKENDU** | Nie istnieje operacja udostępniania, która mogłaby walidować zatwierdzoną pierwszą rewizję i lifecycle. |
| **K3.13** Archiwizacja zachowuje udostępnienia, ale zawiesza ich skuteczność; przywrócenie może ją wznowić | **CZĘŚCIOWO WSPIERANE** | Archiwizacja/przywrócenie surowca i audyt już istnieją. Nie ma share do zachowania ani sprawdzania stanu ARCHIVED przy autoryzacji udostępnienia; sam status archiwizacji nie jest równoważny wyłączeniu dostępu operacyjnego lokalizacji. |
| **K3.14** Wyłączenie modułu nie usuwa udostępnień; ponowne włączenie respektuje wszystkie bramki | **CZĘŚCIOWO WSPIERANE** | Konfiguracja modułu jest per lokalizacja i stan jest trwały; wyłączenie nie usuwa grantów/historii. Połączenie tego mechanizmu z nieistniejącymi share jest niezaimplementowane. `NOT_READY` obecnie zamyka możliwość aktywacji RAW i musi pozostać bezwzględną bramką. |
| **K3.15** Tylko ADMINISTRATOR zarządza udostępnieniami | **WYMAGANA ZMIANA BACKENDU** | Nie ma API share. Istniejąca administracja grantami RAW używa `RAW_MATERIALS_PERMISSION_DELEGATE`, nie tego samego uprawnienia i nie pełni tej samej funkcji. Nowe operacje muszą sprawdzać rolę ADMINISTRATOR po stronie backendu. |
| **K3.16** Pierwsza centrala jest trwale oznaczona i niezmienna | **WYMAGANA ZMIANA BACKENDU** | Brak trwałego pola/relacji i ograniczenia bazy. Identyfikator lub kolejność odczytu nie stanowią zatwierdzonego znacznika. |
| **K3.17** Zakaz dezaktywacji głównej centrali, gdy RAW działa | **WYMAGANA ZMIANA BACKENDU** | Obecny warunek wyłączenia kuchni sprawdza aktywne, zarządzane restauracje, a nie status RAW i formalny stan wycofania. |
| **K3.18** Kontrolowane, audytowane zakończenie RAW po analizie zależności | **WYMAGANA ZMIANA BACKENDU / NIEZWERYFIKOWANE ZALEŻNOŚCI** | Nie ma procesu organizacyjnego wycofania modułu RAW ani agregacji zależności receptur, produkcji i historii. |
| **K3.19** Inicjator i zatwierdzający to dwaj różni administratorzy | **WYMAGANA ZMIANA BACKENDU** | Brak workflow dwuosobowego. Obecne audytowane mutacje bezpieczeństwa nie tworzą same z siebie wniosku i osobnego zatwierdzenia. |
| **K3.20** Bez drugiego administratora najpierw trzeba go ustanowić; bez samozatwierdzenia | **CZĘŚCIOWO WSPIERANE** | RBAC ma zabezpieczenia ostatniego używalnego administratora i audyt nadawania ról. Brak warunku powiązanego z wnioskiem o wycofanie; brak dwuosobowej walidacji. |
| **K3.21** Bieżące partie można dokończyć według zamrożonej receptury, chyba że działa blokada bezpieczeństwa | **NIEZWERYFIKOWANE / WYMAGANA INTEGRACJA** | Nie znaleziono modelu partii, snapshotu receptury ani procedury hold bezpieczeństwa w przeszukanych domenach. Wymagana definicja „rozpoczętej partii” i źródła nadrzędnej blokady bezpieczeństwa. |

## 5. Wpływ architektoniczny i proponowany kierunek projektu

### 5.1 Model danych i własność

Projekt powinien rozdzielić:

- **tożsamość i zawartość katalogu organizacji** — material/revision pozostają przypisane do organizacji; decyzja nie wymaga utworzenia osobnej kopii surowca dla każdej restauracji;
- **uprawnienie głównej centrali do zarządzania katalogiem** — odrębne od udostępniania i od ADMINISTRATOR;
- **udostępnienie surowca lokalizacji** — jawna relacja do restauracji lub dodatkowej kuchni centralnej, z trwałą historią nadania/cofnięcia;
- **skuteczność bieżącą** — wyliczaną z aktywnego share, zatwierdzonej rewizji, stanu lifecycle, dostępności modułu w lokalizacji, członkostwa/kontekstu i RBAC.

Do rozważenia w projekcie jest trwały, nieprzenoszalny wskaźnik głównej kuchni na poziomie organizacji oraz tabela udostępnień złożonymi kluczami obcymi do organizacji, surowca i lokalizacji. Ograniczenia bazy powinny uniemożliwiać więcej niż jedną główną centralę i zapobiegać zmianie jej tożsamości poza kontrolowanym provisioningiem. Nie należy wyznaczać jej przez `MIN(id)`, aktualną kolejność sortowania ani dynamiczne „pierwsze aktywne”.

Serwis tworzenia lokalizacji już blokuje organizację w transakcji; ten punkt serializacji może być elementem rozwiązania wyścigu przy ustanawianiu głównej kuchni, ale wymaga odrębnego zaprojektowania ochrony DB przed alternatywnymi ścieżkami zapisu. Nie zmieniać zastosowanych migracji historycznych — ewentualny schemat wdrażać wyłącznie migracją forward-only po akceptacji.

### 5.2 Granica autoryzacji i kompletność ścieżek

Samo filtrowanie listy nie wystarczy. Po akceptacji projektu należy przejrzeć każdą ścieżkę odczytu i mutacji, tak aby sprawdzenie share odbywało się przy dostępie do obiektu, a nie tylko w UI:

- katalog, szczegóły, revisions, dane Stage 2D, allergen/storage;
- dostawcy, produkty, oferty i ceny — z zachowaniem niezależnej izolacji finansowej;
- importy, eksporty i pobranie pliku, łącznie z ponowną autoryzacją przy download;
- lifecycle, w tym submit/approve, block/unblock, archive/restore;
- kategorie i słowniki: projekt musi rozstrzygnąć, czy są częścią centralnego katalogu, czy współdzielonym zasobem organizacji.

Dla odbiorców każdy nowy odczyt musi wymagać jednocześnie poprawnego kontekstu, członkostwa, właściwego RBAC, dostępności RAW oraz skutecznego udostępnienia materiału. Udostępnienie **nie może** nadawać grantów cen zakupu, cen referencyjnych, operacji edycji ani zatwierdzania. Role do zarządzania katalogiem, do nadawania share i do dostępu finansowego muszą pozostać rozłączne.

### 5.3 Rewizje, cofnięcie, archiwizacja i historia

Obecne rewizje są dobrym fundamentem dla propagacji: nowa zatwierdzona rewizja może stać się aktualną treścią widoczną odbiorcom bez kopiowania danych i bez ponownego zatwierdzania po stronie odbiorcy. Konieczne jest jednak formalne zdefiniowanie, czy lista odbiorców obowiązuje dla każdej rewizji, czy dla logicznego materiału (K3 sugeruje tę drugą semantykę), oraz co widzą odbiorcy podczas `VERIFICATION`.

Cofnięcie share powinno natychmiast zamykać nowe użycie, lecz nie usuwać relacji ani historycznych odwołań. Odczyt historyczny powinien rozróżniać: historię własną operacji lokalizacji, historyczny snapshot wykorzystany przez recepturę/partię oraz bieżący katalog. Nie wolno stosować bieżącego share jako jedynego warunku do wyświetlenia już utrwalonej historii, ani używać zachowanej historii jako obejścia dostępu do aktualnego surowca.

Archiwizacja powinna zachować relacje, a jedynie unieczynnić ich efekt operacyjny. Ponowne przywrócenie nie powinno samo kasować niezależnych blokad `BLOCKED`, jakościowych ani bezpieczeństwa. Potrzebny jest jeden, testowalny predykat „można rozpocząć nowe użycie”, używany przez wszystkie integracje.

### 5.4 Operacje zbiorcze i audyt

API zarządzania udziałami ma dwa kierunki żądań, ale jedną logikę domenową. Brak uprawnień inicjatora, zły kontekst lub brak roli ADMINISTRATOR powinien odrzucać całe żądanie. Po przejściu autoryzacji zbiorcza mutacja ma zwracać wynik per para surowiec–lokalizacja: dodano, już istniało, odrzucono z kodem przyczyny. Kody i szczegóły nie mogą ujawniać niedostępnych lokalizacji ani danych spoza zakresu aktora.

Operacje nadawania, cofania, archiwizacji i wycofania powinny być przypisane do aktora, lokalizacji/kontekstu, materiału, odbiorcy, czasu, wyniku i uzasadnienia zgodnie z polityką audytu. Audyt operacji nie może być zastąpiony logiem aplikacyjnym ani sukcesem HTTP. Przed implementacją trzeba wybrać semantykę transakcji: pełna atomowość każdego zaakceptowanego elementu czy rejestrowanie także każdego odrzuconego elementu w ramach raportu częściowego. Nie wolno jednak dopuścić sukcesu mutacji bez odpowiadającego mu audytu.

### 5.5 Główna centrala i jej dezaktywacja

Obecna reguła chroniąca kuchnię zarządzającą aktywnymi restauracjami nie wystarcza do K3.17. Reguła K3 wymaga blokady dezaktywacji głównej centrali niezależnie od takiej relacji, gdy RAW jest używany; wyjątek istnieje dopiero po formalnym zakończeniu modułu w całej organizacji.

Wycofanie RAW wymaga osobnego, audytowanego workflow, nie zwykłego `POST /modules/configuration`. Projekt powinien obejmować stany wniosku, inicjatora, odrębnego zatwierdzającego, powody, ponowną kontrolę zależności przy zatwierdzeniu oraz blokadę równoczesnych zmian share, lokalizacji i zależności. Przy jednym administratorze operacja pozostaje niemożliwa do zatwierdzenia przez tę samą osobę; istniejące zabezpieczenie ostatniego administratora stanowi bazę, ale nie realizuje dwuosobowego procesu.

### 5.6 Produkcja i bezpieczeństwo żywności

Nie można uznać K3.7 ani K3.21 za zrealizowane przez samo dodanie share. Przed projektem API trzeba ustalić kontrakt z właścicielem receptur/produkcji: identyfikator oraz wersję receptury, warunek uznania partii za rozpoczętą, immutable snapshot danych surowca/jednostek, zasadę rozpoczęcia kolejnej partii po cofnięciu, źródło blokady bezpieczeństwa i tryb hold już trwającej partii.

Zwykłe cofnięcie share i incydent bezpieczeństwa muszą mieć odrębne przyczyny i priorytety. W przypadku aktywnej blokady bezpieczeństwa automatyczne „odblokowanie” przez ponowne nadanie share jest niedopuszczalne. Jeżeli odpowiedzialna domena znajduje się poza tym repozytorium, jej właściciel i kontrakt integracyjny są warunkiem projektu, nie założeniem.

## 6. Migracja istniejących danych i niezmienniki bezpieczeństwa

Największe ryzyko wdrożeniowe wynika z różnicy pomiędzy obecnym dostępem organizacyjnym a nową listą jawnych odbiorców:

1. Istniejące rekordy nie identyfikują głównej kuchni ani listy dozwolonych odbiorców. Nie da się bez danych biznesowych odtworzyć z nich zamiaru „wybrane restauracje”.
2. Automatyczne utworzenie share dla każdej obecnej lokalizacji utrwaliłoby szeroki dostęp wbrew K3.2/K3.4/K3.11.
3. Utworzenie pustych share dla wszystkich materiałów bez jawnego, zatwierdzonego cutover może odebrać dostęp do operacyjnie potrzebnych danych i zależnych procesów.
4. Importy CSV, lokalne włączenie modułu i historyczne przypisania grantów nie mogą być traktowane jako dowód indywidualnego biznesowego zatwierdzenia share.
5. Cutover wymaga uzgodnionego, audytowalnego źródła mapowania organizacja → główna centrala oraz materiał → lokalizacje odbiorców, właścicieli decyzji i procedury obsługi braków. Jeśli mapowanie nie istnieje, wymagana jest jawna decyzja dla każdego przypadku/zakresu; kod nie powinien zgadywać.

Niezmienne bramki pozostają niezależne: `NOT_READY` blokuje aktywację nawet administratora; grant share nie obchodzi RBAC, członkostwa, modułu ani stanu materiału; ceny zakupu i referencyjne zachowują osobne prawa; rewizje i audyt historyczny pozostają niezmienne; wyłączenie modułu nie kasuje share; brak dostępu bieżącego nie usuwa udokumentowanej historii.

## 7. Zgodność z ADR-001, Stage 1D i Stage 1E

- **ADR-001:** decyzje K3 są zgodne z modelem jednego klienta na wdrożenie. ADR jawnie wymaga dalszego egzekwowania `organization_id`, lokalizacji, członkostwa, jawnych uprawnień, audytu i kontroli po stronie aplikacji/DB. K3 dodaje granicę lokalizacyjną wewnątrz jednej instancji; nie uzasadnia współdzielenia danych między klientami ani usunięcia tenantowych kluczy.
- **Stage 1D/RBAC:** bieżący kod ma rolę ADMINISTRATOR, zakres organizacyjny oraz zabezpieczenia ostatniego używalnego administratora. To wspiera K3.15 i K3.19/20 jako fundamenty, ale nowe operacje muszą jawnie sprawdzać rolę administratora i dwóch różnych aktorów. Granty RAW nie zastępują tej kontroli.
- **Stage 1E/moduły:** istnieją stan per lokalizacja, audyt konfiguracji, administrator-only configure i bramka readiness. To wspiera K3.14. Stan modułu nie reprezentuje jednak udostępnienia konkretnego surowca. Wyłączenie i ponowne włączenie modułu musi nadal respektować `NOT_READY`.
- Nie znalazłem sprzeczności K3 z powyższymi granicami architektonicznymi. Jest natomiast brak implementacji oraz ryzyko, że uproszczenie modelu do samego `organization_id` albo przeniesienie share do grantów użytkownika ominęłoby zatwierdzone granice.

## 8. Proponowana kolejność projektu i bramki akceptacyjne

1. **Zatwierdzić właściciela i cutover:** wskazanie głównej centrali dla każdej istniejącej organizacji oraz jawne mapowanie obecnych materiałów do odbiorców. Rozstrzygnąć brak mapowania bez automatycznych grantów.
2. **Zatwierdzić macierz uprawnień:** oddzielić zarządzanie katalogiem w głównej centrali, zarządzanie share przez ADMINISTRATOR, konsumowanie surowca przez odbiorcę, role zatwierdzające i prawa finansowe.
3. **Zatwierdzić model historii:** zakres odczytu historii po cofnięciu, archiwizacji i wyłączeniu RAW; źródło snapshotu i retencję zależnych receptur/dokumentów.
4. **Uzyskać kontrakty od właścicieli domen zależnych:** receptury, produkcja, bezpieczeństwo żywności; formalna definicja rozpoczętej partii i procedury hold.
5. **Zaprojektować spójne API i audyt:** oba kierunki share, bulk partial result, powody, idempotencja, współbieżność i autoryzacja download/import/eksport.
6. **Zaprojektować migrację forward-only i etapowanie:** najpierw baza i kontrola dostępu z zachowaniem `NOT_READY`, następnie audytowalny cutover danych, potem osobna decyzja o gotowości modułu. Nie aktywować RAW w ramach samego projektu.
7. **Przed implementacją uzyskać odrębną zgodę właściciela.** Po jej otrzymaniu dodać testy kontraktowe i DB, uruchamiane wyłącznie na izolowanym, syntetycznym PostgreSQL 17.

## 9. Wymagany plan testów po odrębnej akceptacji implementacji

- Widoczność: udostępniona/nieudostępniona lokalizacja, sąsiednia restauracja, dodatkowa centrala, główna centrala, inna organizacja; testy dla listy, szczegółu, rewizji, cen i CSV.
- Obejście API: bezpośredni UUID, historyczna rewizja, Stage 2D, download eksportu i commit importu po cofnięciu share.
- Brak prawa: osoba z uprawnieniem edycji katalogu, ale bez ADMINISTRATOR, nie może nadawać/cofać share; Administrator bez prawa katalogowego nie uzyskuje prawa do edycji/zatwierdzania.
- Lifecycle: DRAFT/VERIFICATION bez udostępnień; pierwsze share po ACTIVE; nowa rewizja propaguje się dopiero po approve; archive suspends/restore preserves; zwykłe revoke/restore nie usuwa blokady bezpieczeństwa.
- Operacje zbiorcze: brak roli odrzuca całość; dozwolone elementy wykonują się, istniejące są pominięte, niedozwolone otrzymują bezpieczny powód; raport i audyt zgodne po błędzie/rollbacku.
- Lokalizacje i centrala: równoczesne tworzenie pierwszej centrali, próba drugiej, modyfikacja znacznika, wyłączenie głównej podczas RAW oraz po poprawnym wycofaniu.
- Moduły: share przy `NOT_READY`, wyłączonym module i po ponownym włączeniu; brak możliwości aktywacji RAW przy `NOT_READY`; zachowanie share po zmianie stanu modułu.
- Wycofanie: zależności zmieniają się między wnioskiem a zatwierdzeniem; samozatwierdzenie, nieaktywny administrator i jeden administrator; testy współbieżności dla mutation vs. approval.
- Produkcja: bieżąca partia zwykła po revoke, kolejna partia po revoke, aktywny food-safety hold oraz wznowienie wyłącznie zgodnie z procedurą bezpieczeństwa.
- Migracja: brakujące/niejednoznaczne mapowanie, replay, audyt mapowania i kontrola, że żadna lokalizacja nie uzyskuje share domyślnie.

## 10. Nierozstrzygnięte kwestie techniczne

Decyzje K3 zamykają reguły biznesowe, ale następujące szczegóły nadal wymagają zatwierdzenia w projekcie:

- Jak wskazać główną kuchnię dla istniejących organizacji oraz co zrobić, gdy nie ma aktywnej kuchni centralnej albo wiele lokalizacji może pełnić tę rolę historycznie.
- Czy role do zarządzania katalogiem mają być osobnymi rolami, grantami scoped do głównej kuchni, czy kombinacją; jak zabezpieczyć wszystkie operacje CSV i słowniki.
- Czy share obejmuje wyłącznie surowiec, czy również jego katalogowe dane handlowe, ceny referencyjne, aliasy, dokumenty i wyniki zgodności; finanse i tak pozostają chronione odrębnym RBAC.
- Czy bulk partial success utrwala każdy prawidłowy element osobną transakcją, czy jednym transakcyjnym batch-em z jednoznacznym audytem wyników.
- Jak autoryzować odczyt historyczny w nieaktywnym kontekście lokalizacji oraz które role mogą widzieć dane źródłowe i snapshoty.
- Kto jest źródłem prawdy o recepturach, partiach i incydentach bezpieczeństwa, jeśli te domeny nie należą do obecnego backendu.
- Jakie dowody, kontrola i zatwierdzenie są wymagane dla jednorazowego mapowania istniejącego dostępu.

To są ograniczenia projektu, nie powody do samodzielnego uzupełniania wymagań ani do rozpoczęcia implementacji.

## 12. Późniejsze, jawne zmiany decyzji (2026-11-09)

Właściciel zatwierdził późniejsze zmiany dla K3.9–K3.10 i K3.18–K3.20. Szczegóły zapisano w [rejestrze zmian decyzji RAW_MATERIALS](./VELTRA_STAGE_2F_RAW_DECISION_AMENDMENTS_2026-11-09.md). Ten dodatek zastępuje wyłącznie sprzeczne wcześniejsze wiersze i rekomendacje; historycznej treści dokumentu nie usunięto.

- **K3.9–K3.10:** bulk sharing jest atomowe; częściowy sukces jest zabroniony. Każdy nieprawidłowy cel oznacza zero zmian, z raportem dozwolonych powodów i audytem odrzucenia zachowanym poza rollbackiem.
- **K3.18–K3.20:** wycofanie dostępne wyłącznie pierwotnej tożsamości SUPER ADMIN; zwykły ADMINISTRATOR nie inicjuje, nie zatwierdza ani nie wykonuje wycofania. Wymóg drugiego administratora wycofano. Wymagane są świeża ponowna autentykacja hasłem, pełny audyt i zachowanie danych historycznych.
- **Granica implementacji:** nadal brakuje zatwierdzonych stanów, warunków zakończenia, katalogu zależności, skutków i semantyki recovery formalnego wycofania. Nie implementować endpointu retirement ani nie utożsamiać go z wyłączeniem modułu. Szczegóły wskazano w rejestrze.

## 11. Wynik i zalecenie

- **K3 — decyzje biznesowe CLOSED.**
- **Backend — zgodność z docelowym modelem udostępnień NOT ACHIEVED.**
- **Projekt techniczny — GO po przyjęciu powyższych bramek i właścicieli zależnych domen.**
- **Implementacja, migracja danych i aktywacja RAW_MATERIALS — NO-GO bez osobnej, jawnej akceptacji.**

W ramach tej analizy utworzono wyłącznie niniejszy raport. Nie zmieniano istniejącego audytu Stage 2F, kodu, testów, migracji, danych, konfiguracji ani modułów. Testów nie uruchamiano, ponieważ zakres był read-only.
