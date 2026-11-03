# VELTRA 2.0 — Niezależny audyt bezpieczeństwa Stage 2B

**Data:** 2026-11-02  
**Zakres:** niezależny, tylko do odczytu przegląd rdzenia Raw Materials  
**Werdykt:** **REJECT / NO-GO** dla akceptacji bezpieczeństwa, wdrożenia i aktywacji. `RAW_MATERIALS` musi pozostać `NOT_READY`.  
**Zmiany w aplikacji:** brak. Utworzenie tego raportu jest jedyną zmianą dokumentacyjną w ramach audytu.

## 1. Podsumowanie

Niezależny przegląd implementacji, migracji, API, uprawnień i testów wykazał dwa potwierdzone problemy bezpieczeństwa oraz jeden blokujący defekt workflow szkiców:

1. **HIGH — delegowane uprawnienia mogą przetrwać dezaktywację grantora.** Użytkownik, który utracił aktywny dostęp lub został dezaktywowany, może pozostawić aktywną delegację potomną; brak jest również dostępnej ścieżki administracyjnego awaryjnego unieważnienia takiego grantu.
2. **MEDIUM — użytkownik z samym `RAW_MATERIALS_VIEW` otrzymuje niezatwierdzone dane szkicu.** Odpowiedź listy/szczegółów może zwrócić rewizję roboczą zamiast zatwierdzonej migawki.
3. **MEDIUM — nowo utworzonych szkiców nie można poprawiać przez endpoint rewizji.** Utworzenie ustawia `working_revision_id`, a `revise()` odrzuca rekord, gdy to pole nie jest puste.

Skupione i pełne testy automatyczne przechodzą, lecz nie obejmują powyższych niebezpiecznych stanów. Przejście istniejącego zestawu testów nie zmienia oceny ryzyka ani nie stanowi podstawy do wdrożenia.

## 2. Ustalenia

### A-01 — Delegacja nie wygasa wraz z utratą aktywności grantora

**Severity:** HIGH  
**Pewność:** 9/10  
**Klasyfikacja:** potwierdzony błąd kontroli dostępu

**Dowody w kodzie**

- [`RawMaterialAuthorization.php`](backend/app/Domain/RawMaterials/RawMaterialAuthorization.php) — linie 52–75: weryfikacja łańcucha grantów uwzględnia wygaśnięcie i odwołanie, ale nie aktywność ani aktualne uprawnienia grantorów nadrzędnych.
- [`OrganizationRawMaterialPermissionAdministration.php`](backend/app/Domain/RawMaterials/OrganizationRawMaterialPermissionAdministration.php) — linie 121–131: odwołanie grantu ograniczono do pierwotnego grantora posiadającego nadal uprawnienie do delegowania; brak alternatywnej ścieżki administracyjnej.

**Odtworzenie**

W izolowanym PostgreSQL utworzono delegację bez daty wygaśnięcia, następnie dezaktywowano grantora. Wynik: `grantor_active=0`, `child_effective_grant_count=1`, a żądanie odbiorcy nadal zwróciło `ALLOWED`.

**Wpływ**

Użytkownik, którego dostęp organizacyjny został odebrany, może pozostawić potomny grant skuteczny bezterminowo. Administrator nie może go unieważnić, jeśli nie jest pierwotnym grantorem, a grantor utracił już wymagane uprawnienie. To narusza oczekiwane ograniczenie delegowania i utrudnia bezpieczne offboarding/reakcję na incydent.

**Zalecane działanie**

Przed wdrożeniem zdefiniować i zaimplementować audytowane unieważnianie potomnych delegacji po utracie właściwej authority grantora oraz autoryzowaną awaryjną ścieżkę unieważniania. Zachować istniejące zabezpieczenia przed samodzielnym nadaniem uprawnień.

### A-02 — Odczyt szkiców przez ogólne uprawnienie `VIEW`

**Severity:** MEDIUM  
**Pewność:** 9/10  
**Klasyfikacja:** potwierdzone naruszenie granicy poufności i workflow

**Dowody w kodzie**

- [`RawMaterialService.php`](backend/app/Domain/RawMaterials/RawMaterialService.php) — linie 83–98 i 149–156: ścieżki listy/szczegółów wybierają zatwierdzoną rewizję, a gdy jej brak — rewizję roboczą.
- Ten sam plik, linie 731–754: prezentacja zwraca pola danych szkicu, w tym opis, jednostki i aliasy.
- `show()` sprawdza uprawnienie `View`, ale nie wymaga odrębnego uprawnienia do podglądu szkiców.

**Odtworzenie**

Kod ścieżki szczegółów autoryzuje zwykły odczyt, a następnie prezentuje `working_revision_id`, gdy nie istnieje `approved_revision_id`. Nowo utworzony szkic ma rewizję roboczą; jego niezatwierdzone dane trafiają zatem do odpowiedzi dla czytelnika katalogu.

**Wpływ**

Użytkownik mający jedynie dostęp do katalogu może poznać niezatwierdzone dane organizacyjne. Odpowiedź nie odpowiada kontraktowi Stage 2A, który przewiduje zatwierdzoną migawkę i ogranicza ujawnienie oczekujących zmian.

**Zalecane działanie**

Nie zwracać rewizji roboczej w zwykłych endpointach listy i szczegółów. Podgląd szkicu wymaga odrębnej, jawnej autoryzacji oraz testów odczytu dla różnych ról.

### A-03 — Workflow edycji szkicu jest nieosiągalny

**Severity:** MEDIUM  
**Pewność:** 9/10  
**Klasyfikacja:** potwierdzony defekt funkcjonalny blokujący Stage 2B

**Dowody w kodzie**

- [`RawMaterialService.php`](backend/app/Domain/RawMaterials/RawMaterialService.php) — linie 216–222: tworzenie materiału ustawia `working_revision_id`.
- Ten sam plik, linie 255–260: `revise()` odrzuca materiał, którego `working_revision_id` nie jest `null`.

**Odtworzenie**

Po utworzeniu szkicu warunek w `revise()` jest już spełniony, więc kolejna edycja tego szkicu zostaje odrzucona. To blokuje poprawianie i uzupełnianie nowego rekordu przed weryfikacją.

**Zalecane działanie**

Skorygować regułę cyklu rewizji, zachowując niezmienność zatwierdzonych rewizji, blokadę równoległych zmian oraz audyt wszystkich zmian szkicu.

## 3. Zakres weryfikacji priorytetowej

| Obszar | Wynik audytu |
|---|---|
| Organizacyjne RBAC, delegowanie i odwołanie | Potwierdzono ryzyko trwałej delegacji po dezaktywacji grantora (A-01). |
| Bootstrap i eskalacja uprawnień | Przejrzano bootstrap, potwierdzanie hasłem administratora i ograniczenia samodzielnego nadania. Nie wykazano w ramach tego audytu dodatkowego potwierdzonego obejścia bootstrapu. Granty bootstrapowe mogą być bezterminowe, co zwiększa znaczenie niezawodnego odwołania/offboardingu (A-01). |
| Izolacja organizacji/lokalizacji i sfałszowane identyfikatory | Przegląd objął autoryzację żądań i testy API. Nie wykazano dodatkowego potwierdzonego przejścia między organizacjami/lokalizacjami; wynik nie zastępuje testów po poprawkach A-01 i A-02. |
| `RAW_MATERIALS NOT_READY` | W testach dostępność produkcyjna pozostaje fail-closed; gotowość jest ustawiana wyłącznie w fixture testowym. Nie wykazano produkcyjnego bypassu. |
| Tożsamość, rewizje, kody i audyt | Przejrzano ograniczenia PostgreSQL i testy bezpośrednich mutacji. Testy integralności przechodzą; brak dodatkowego potwierdzonego obejścia w zweryfikowanych scenariuszach. |
| Zatwierdzanie, blokowanie, archiwizacja i przywracanie | Przegląd objął serwis, API i testy cyklu życia. Defekt edycji szkicu jest opisany jako A-03; nie wykazano dodatkowego potwierdzonego obejścia przejść. |
| Transakcje, rollback i spójność audytu | Testy automatyczne i testy PostgreSQL przechodzą w deklarowanym zakresie. Nie wykazano niezależnego incydentu niespójności audytu; negatywne ścieżki A-01/A-02 wymagają regresji po remediacji. |
| Polski API i ujawnianie danych | Odpowiedzi walidacji/obsługi błędów objęto testami; ujawnienie roboczych danych katalogowych jest potwierdzone w A-02. |
| Zgodność Stage 1A–1F | Pełny backendowy zestaw regresyjny przeszedł. Brak wykrytej regresji w tym przebiegu, lecz A-01/A-02 wymagają naprawy przed dalszym użyciem. |

Brak dodatkowego ustalenia w powyższej tabeli oznacza wyłącznie, że podczas tego przeglądu nie potwierdzono kolejnego defektu; nie jest formalnym dowodem braku ryzyka.

## 4. Testy i środowisko

Testy uruchomiono w jednorazowym, odizolowanym środowisku testowym:

- PostgreSQL `veltra_testing` oraz Redis działały w dedykowanej sieci Docker `internal=true`.
- Sieć zawierała wyłącznie testowe kontenery PostgreSQL i Redis; kontenery nie miały trwałych mountów ani opublikowanych portów.
- Kod backendu w runnerze był zamontowany tylko do odczytu.
- Istniejące usługi i wolumeny aplikacji nie były dotykane.
- Redis odpowiedział `PONG`; konfiguracja PHPUnit korzystała z cache/sesji `array` i kolejki `sync`, zatem testy nie weryfikowały ścieżki używającej Redis jako cache/sesji/kolejki.

| Polecenie | Wynik |
|---|---:|
| `vendor/bin/phpunit --do-not-cache-result tests/Feature/RawMaterialsTest.php tests/Feature/RawMaterialPermissionBootstrapTest.php tests/Feature/RawMaterialConcurrencyTest.php` | 12 testów, 80 asercji — PASS |
| `vendor/bin/phpunit --do-not-cache-result` | 157 testów, 1195 asercji — PASS |
| `php artisan migrate --force` | PASS |
| `php artisan migrate:rollback --step=11 --force` | PASS |
| `php artisan migrate --force` po rollbacku | PASS |
| `php artisan migrate:fresh --force` | FAIL — pozostała funkcja PostgreSQL z wcześniejszej migracji |

### Adwersarialne scenariusze

- **Delegacja po dezaktywacji grantora:** FAIL — grant potomny pozostał skuteczny; szczegóły A-01.
- **Nieautoryzowany/self-grant, delegowanie poza zakresem, odwołanie cudzego grantu i wygaśnięcie:** sprawdzono istniejące testy kontroli bootstrapu/delegacji oraz warunki łańcucha grantów. Dla tych scenariuszy nie uzyskano dodatkowego potwierdzonego obejścia poza A-01; testy istniejące nie dowodzą unieważniania potomków po dezaktywacji grantora.
- **Sfałszowane identyfikatory organizacji/lokalizacji:** przegląd API i testów nie wykazał dodatkowego potwierdzonego cross-tenant dostępu.
- **Bezpośrednie mutacje chronionych rekordów SQL:** sprawdzono testy ograniczeń i triggerów; próby objęte zestawem integralności są odrzucane.
- **Rollback i audyt:** migracje rollback/reapply oraz testy integralności przeszły. Nie wykazano dodatkowej niespójności audytu w tych przebiegach.
- **Dostęp do niezatwierdzonych danych:** FAIL — zwykły odczyt może zwrócić rewizję roboczą; A-02.
- **Edycja nowo utworzonego szkicu:** FAIL — warunek `working_revision_id` blokuje `revise()`; A-03.

## 5. `migrate:fresh` a funkcje PostgreSQL

`php artisan migrate:fresh --force` usunęło tabele, ale pozostawiło funkcję PostgreSQL `serialize_installation_mutation()`, po czym początkowa migracja `000001` nie mogła ponownie utworzyć tej funkcji. Pełny rollback (`--step=11`) usunął funkcje; ponowne `migrate --force` zadziałało.

Wynik klasyfikujemy jako **ograniczenie/niezgodność strategii czyszczenia disposable środowiska `migrate:fresh` z obiektami PostgreSQL tworzonymi przez migracje**, a nie jako błąd migracji Stage 2B w cyklu up/down: rollback i ponowne zastosowanie wszystkich 11 migracji zakończyły się powodzeniem. Należy jednak pamiętać, że `migrate:fresh` nie jest bezpiecznym substytutem pełnego rollbacku dla tego schematu. Audyt nie wykonywał tej komendy na bazie aplikacyjnej.

## 6. Ryzyka szczątkowe i wymagane bramki

Przed ponowną oceną należy:

1. Naprawić propagację odwołania/dezaktywacji delegacji i dodać audytowaną administracyjną ścieżkę awaryjną.
2. Ograniczyć zwykły odczyt do zatwierdzonych migawek i dodać testy poufności szkiców dla roli `VIEW`.
3. Naprawić edycję szkiców i dodać regresję tworzenie → edycja → weryfikacja → zatwierdzenie.
4. Dodać testy negatywne na potomków delegacji, przejęcie/wygaśnięcie grantów oraz odwołanie podczas równoległego żądania.
5. Powtórzyć testy skupione, pełną regresję oraz bezpośrednie testy PostgreSQL w nowym, zweryfikowanym środowisku disposable.
6. Pozostawić produkcyjny katalog `RAW_MATERIALS` jako `NOT_READY`; nie uruchamiać endpointów przez produkcyjny bypass.
7. Wykonać ponowny niezależny przegląd bezpieczeństwa po remediacji.

## 7. Decyzja

**REJECT / NO-GO** dla bieżącej akceptacji bezpieczeństwa Stage 2B. Wynik testów regresyjnych jest pozytywny, ale nie eliminuje potwierdzonych błędów autoryzacji i ujawniania danych. Nie wdrażać ani nie aktywować `RAW_MATERIALS`; zachować `NOT_READY`. Nie wykonano remediacji, wdrożenia, migracji na bazie aplikacyjnej ani commit/push. Raport przekazuje wyniki do niezależnego przeglądu i oczekuje na odrębną autoryzację ewentualnych zmian.
