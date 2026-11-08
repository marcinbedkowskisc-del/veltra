# VELTRA 2.0 — Stage 2F — przegląd gotowości K2 + K3

## Rekomendacja

**CONDITIONAL GO wyłącznie do przeglądu projektu i domknięcia kontraktów; NO-GO dla implementacji, migracji danych i aktywacji RAW_MATERIALS.**

Zatwierdzone wymagania K2 i K3 dają podstawę do projektu, lecz bieżący backend ich nie realizuje, a oryginalne zatwierdzone specyfikacje Stage 1D/1E nie były w tej sesji bezpośrednio dostępne do pełnego odczytu. Przed implementacją trzeba uzyskać te źródła i rozstrzygnąć pozostałe kwestie wskazane niżej. W istniejącej instalacji designation primary wolno ustawić tylko wtedy, gdy rzeczywistą kolejność utworzenia potwierdza wiarygodny dowód; w przeciwnym razie migracja pozostaje wstrzymana zgodnie z K2.8.

## Potwierdzone fakty techniczne

1. Kod ma dokładnie sześć istniejących ról RBAC. Początkowa tożsamość jest osobnym użytkownikiem z `is_initial_identity=true`; provisioning serializuje utworzenie i odrzuca drugiego użytkownika. DB unikalnie ogranicza i chroni ID/flagę tej początkowej tożsamości.
2. Początkowa tożsamość sama nie otrzymuje roli ani członkostwa na etapie provisioningu. Interaktywna komenda później nadaje jej ADMINISTRATOR i członkostwo.
3. `BootstrapAuthorization` wybiera istniejącą centralę `orderBy('code')`; nie ma trwałego znacznika pierwszej/głównej centrali. To jest niezgodne z K3.16 i nie zapewnia kontekstu K2.1.
4. Nie istnieje status SUPER ADMIN ani ochrona pierwotnego konta przed dezaktywacją, odebraniem roli albo utratą członkostwa. Obecna reguła chroni co najmniej jednego używalnego administratora, nie tę konkretną tożsamość.
5. Obecne usługi administracyjne nie mają rozróżnienia dla zarządzania kontami administratorów wymaganego przez K2.5/K2.7. Nowa decyzja K2.7 celowo ogranicza zarządzanie innymi kontami ADMINISTRATOR do SUPER ADMIN, pozostawiając zwykłe prawa wobec kont nieadministracyjnych.
6. Reset hasła jest przypisany do istniejącego konta i normalnego tokenu; zmiana hasła unieważnia poprzednie sesje. To może pozostać zgodne z K2.2/K2.6, o ile odzyskanie nigdy nie transferuje identyfikatora/statusu ani nie omija logowania.
7. Prawa finansowe są odrębne. K2.3 i K3.2 wymagają utrzymania tej separacji.
8. K3 nie jest jeszcze zaimplementowane w obecnym modelu: katalog RAW ma zakres organizacyjny, brak jawnych share do lokalizacji i trwałego primary. Moduł RAW pozostaje `NOT_READY`; K2 nie daje podstaw do jego aktywacji.
9. Audyt bezpieczeństwa jest append-only i może być rozszerzony przez istniejący wzorzec `SecurityMutation`; nie należy tworzyć konkurencyjnego frameworka autoryzacji.

Źródła lokalne: [projekt zintegrowany K2+K3](./VELTRA_STAGE_2F_K2_K3_INTEGRATED_TECHNICAL_DESIGN.md), [raport Stage 1D](./VELTRA_STAGE_1D_IMPLEMENTATION_REPORT.md), [raport Stage 1E](./VELTRA_STAGE_1E_IMPLEMENTATION_REPORT.md), [ADR-001](./VELTRA_ADR_001_MODEL_WDROZENIA_KLIENTA.md), [audyt RAW 1–100](./VELTRA_STAGE_2F_RAW_BACKEND_COMPATIBILITY_AUDIT.md) i [analiza wpływu K3](./VELTRA_STAGE_2F_RAW_K3_TECHNICAL_IMPACT.md). Raporty historyczne nie zastępują bezpośredniej weryfikacji aktualnego kodu; kluczowe obserwacje kodowe podano w projekcie technicznym.

## Brakujące kontrakty i warunki przed implementacją

- Bezpośredni odczyt zatwierdzonej specyfikacji Stage 1D: lokalny raport wskazuje [źródłowy dokument](https://docs.google.com/document/d/1Hbt31CoeEeJV9nmwOtkEXVmAFRsKK3VQabCtCuzvN6E/edit), ale połączenie przeglądarki nie powiodło się. Nie potwierdzono pełnej zgodności raportu implementacji z oryginalną specyfikacją.
- Zatwierdzona specyfikacja Stage 1E nie została zlokalizowana w repozytorium; dostępne są wyłącznie raporty implementacji/weryfikacji.
- Dla istniejących instalacji zgodnie z K2.8 należy sprawdzić, czy da się wykazać autorytatywnym dowodem faktyczną kolejność tworzenia central. Gdy nie da się jej ustalić, nie wyznaczać primary ani kuchni zastępczej i wstrzymać designation/migrację.
- Domeny K2.4 nie zawierają listy operacji; właściciel musi je wskazać przed nadaniem dodatkowych praw. Nie wolno ich implementować na podstawie samej nazwy.
- Należy potwierdzić granicę kontekstu SUPER ADMIN w primary kitchen, bez zawężania lub rozszerzania pozostałego organizacyjnego RBAC.
- K3 wymaga osobnych kontraktów udostępnień, migracji obecnego szerokiego dostępu, historii po cofnięciu, zależności produkcji i formalnego wycofania RAW.

## Krytyczne ryzyka

1. **Bootstrap/primary:** obecny wybór centrali po kodzie może przypisać bootstrap admina do innej lokalizacji niż pierwsza utworzona; nowy mechanizm musi utrwalić primary atomowo i niezmiennie.
2. **Przejęcie lub utrata SUPER ADMIN:** zabezpieczenie ostatniego administratora nie wystarcza do ochrony tożsamości. Bez wąskich ochron usługowych i DB kontem można zarządzać, jeśli pozostanie inny administrator.
3. **Eskalacja praw administratora:** K2.7 obejmuje także statusy i członkostwa lokalizacyjne innych administratorów; kontrola wyłącznie na ekranie lub dla samych ról nie spełnia wymogu.
4. **Migracja istniejących danych:** automatyczne wskazanie primary i automatyczne przeniesienie dotychczasowego dostępu RAW mogłyby zmienić zatwierdzone relacje bez zgody; wymaga to jawnej mapy właściciela.
5. **Odzyskiwanie a uwierzytelnienie:** ochrona konta nie może stać się pominięciem hasła, tokenu, sesji, e-mail verification ani blokad modułów.
6. **Dwuosobowe wycofanie:** jeden administrator nie może złożyć i zatwierdzić wniosku. SUPER ADMIN nie może wystąpić jako dwie osoby; drugiego administratora trzeba ustanowić zgodnie z K2.7.
7. **Finanse i moduły:** żadne z K2/K3 nie pozwala rozszerzać praw cenowych ani omijać `NOT_READY`.

## Zalecana kolejność prac

1. Uzupełnić zatwierdzone źródła Stage 1D/1E i uzyskać decyzje właściciela dla brakujących kontraktów, w szczególności nieokreślonych operacji K2.4.
2. Sprawdzić dostępność wiarygodnego dowodu kolejności utworzenia primary w każdej istniejącej instalacji; bez dowodu nie mapować ani nie wyznaczać zastępczej centrali i wstrzymać tę migrację.
3. Zaimplementować trwałe primary i bezpieczny/idempotentny bootstrap, z testami wyścigów.
4. Dodać nieprzenaszalny status oparty na istniejącej początkowej tożsamości, ochronę konta na poziomie usług i wąskie invariants DB, recovery tej samej tożsamości oraz audyt.
5. Zastosować pełny zakres K2.7 do zarządzania innymi kontami ADMINISTRATOR; zachować dotychczasowe prawa zwykłych administratorów wobec kont nieadministracyjnych i niezmienione uprawnienia finansowe.
6. W osobno zatwierdzonym zakresie zintegrować K3 sharing i dual-admin retirement; ponownie walidować role, zależności i stan przy finalizacji.
7. Wykonać testy izolowane PostgreSQL obejmujące API, direct runtime SQL, współbieżność, audyt, regresje Stage 1D/1E, migrację świeżą i forward upgrade. Nie aktywować RAW, dopóki readiness pozostaje `NOT_READY`.

## Zgodność decyzji

- **K2.1–K2.9 zachowane:** projekt nie dodaje siódmej roli, wiąże status z tą samą początkową tożsamością, nie tworzy transferu ani wyjątków, zachowuje zwykłe uwierzytelnienie oraz uwzględnia K2.7 (zarządzanie innymi administratorami), K2.8 (primary według faktycznej kolejności) i K2.9 (samoedycja profilu). Domen K2.4 nie rozwija o niezatwierdzone operacje.
- **K3.1–K3.21 respektowane:** projekt zachowuje katalog organizacyjny z właścicielem primary, lokalizacyjne deny-by-default share, niezależność praw finansowych, widoczność wyłącznie zatwierdzonych rewizji, odwracalne skutki archive/module-off, historyczne rekordy i dwie różne tożsamości przy wycofaniu. Szczegóły brakujących workflow nie są przedstawiane jako istniejąca implementacja.
- **Stage 1D:** K2.7 jawnie i celowo modyfikuje wcześniejsze reguły RBAC dla zarządzania innymi kontami ADMINISTRATOR. Zwykli administratorzy zachowują dotychczasowe uprawnienia wobec kont nieadministracyjnych. Pozostałe reguły Stage 1D nie są zmieniane przez te decyzje.
- **Stage 1E/ADR-001:** zachować per-location gates, fail-closed `NOT_READY`, append-only audyt oraz izolację per klient i nadal egzekwować granice organizacji/lokalizacji wewnątrz instancji.

## Zakres wykonania i lokalizacje raportów

W tej pracy utworzono wyłącznie trzy dokumenty:

1. [VELTRA_STAGE_2F_K2_APPROVED_DECISIONS_SNAPSHOT.md](./VELTRA_STAGE_2F_K2_APPROVED_DECISIONS_SNAPSHOT.md)
2. [VELTRA_STAGE_2F_K2_K3_INTEGRATED_TECHNICAL_DESIGN.md](./VELTRA_STAGE_2F_K2_K3_INTEGRATED_TECHNICAL_DESIGN.md)
3. [VELTRA_STAGE_2F_K2_K3_READINESS_REVIEW.md](./VELTRA_STAGE_2F_K2_K3_READINESS_REVIEW.md)

Połączenie z przeglądarką Google Drive nie było dostępne, a wśród dostępnych narzędzi nie było połączenia Drive do przesyłania plików. Raporty znajdują się lokalnie w katalogu głównym repozytorium; **nie przesłano ich na Dysk i nie można potwierdzić ich obecności w `RAPORTY_COPILOTA`**.

Nie zmieniono kodu aplikacji, migracji, konfiguracji, danych, ról, uprawnień, modułów ani infrastruktury. Nie uruchamiano testów, migracji ani usług.

## Uzupełnienie po zatwierdzeniu K2.7–K2.9

To uzupełnienie ma pierwszeństwo przed wcześniejszymi fragmentami raportu, które wskazywały te kwestie jako nierozstrzygnięte.

- **K2.7 — zatwierdzona granica administracyjna:** wyłącznie SUPER ADMIN może zarządzać innymi kontami ADMINISTRATOR, w tym ich rolami, uprawnieniami, statusem i członkostwami lokalizacyjnymi. Jest to zamierzona zmiana poprzednich reguł Stage 1D. Zwykli administratorzy zachowują dotychczasowe uprawnienia wobec kont nieadministracyjnych. Zakres ten nie usuwa zwykłych uprawnień ADMINISTRATOR do zarządzania udostępnieniami RAW wg K3.15.
- **K2.8 — potwierdzenie K3.16:** pierwsza faktycznie utworzona kuchnia centralna staje się automatycznie i trwale primary; administratorzy nie mogą jej wybrać, zmienić, przypisać ponownie ani przenieść. Nie wolno wnioskować o kolejności na podstawie kodu, nazwy ani UUID. Dla istniejących instalacji bez wiarygodnego dowodu kolejności nie wolno zgadywać ani po cichu wyznaczać zastępczej centrali; taki backfill pozostaje zablokowany.
- **K2.9 — samoedycja SUPER ADMIN:** SUPER ADMIN może edytować własny adres e-mail i inne edytowalne dane profilu, zachowując tę samą tożsamość; inni administratorzy nie mogą modyfikować tego konta. Weryfikacja e-mail i normalne wymogi uwierzytelnienia pozostają obowiązkowe. K2.6 nadal zabrania usunięcia, dezaktywacji, zawieszenia, blokady, odebrania roli lub statusu, także przez samego SUPER ADMIN.

### Zaktualizowana gotowość

Wcześniejsza niepewność, czy K2.5 obejmuje status i członkostwa innych administratorów oraz czy SUPER ADMIN może sam zmienić profil/e-mail, jest rozstrzygnięta przez K2.7 i K2.9. Pozostaje **CONDITIONAL GO wyłącznie do przeglądu projektu; NO-GO dla implementacji, migracji i aktywacji RAW_MATERIALS**.

Przed migracją legacy trzeba ustalić z autorytatywnego dowodu faktyczną kolejność tworzenia centralnej kuchni. Jeżeli nie da się tego wiarygodnie wykazać, nie wyznaczać primary ani kuchni zastępczej i wstrzymać tę migrację; K2.8 nie dopuszcza zgadywania. Nadal niezatwierdzone pozostają konkretne operacje w domenach K2.4 oraz szczegóły technicznej realizacji; nie są one dopowiadane przez to uzupełnienie.

Konflikt Stage 1D jest teraz precyzyjny: jego wcześniejsza reguła administracji kontami zostaje celowo zmodyfikowana wyłącznie dla zarządzania innymi kontami ADMINISTRATOR zgodnie z K2.7; uprawnienia zwykłych administratorów wobec kont nieadministracyjnych pozostają. Pozostałe warunki Stage 1D/1E i K3, w tym auth, separacja finansowa, fail-closed `NOT_READY` i dwuosobowe wycofanie RAW, pozostają w mocy.
