# VELTRA 2.0 — formalna decyzja o zamknięciu Stage 2C

**Data decyzji:** 6 listopada 2026  
**Zakres oceny:** kod i migracje do 027, istniejące raporty Stage 2C, snapshot strategii proporcjonalnego bezpieczeństwa z 4 listopada oraz rejestr odłożonych problemów z 5 listopada.  
**Tryb:** ocena dokumentacyjna; bez uruchamiania testów, operacji bazodanowych, zmian kodu ani konfiguracji.

## Decyzja

**NO-GO — obecnie nie rekomenduję formalnego ACCEPT ani CONDITIONAL ACCEPT Stage 2C.**

Jest to decyzja o **braku wystarczających podstaw do zamknięcia etapu**, a nie stwierdzenie, że funkcjonalna regresja nie przeszła. Nowszy raport po migracji 027 dokumentuje 170/170 dozwolonych testów PASS i 1394 asercje. Jednak 17 testów bezpieczeństwa nie zostało uruchomionych, a niezależny statyczny przegląd bieżącego kodu wskazał nierozstrzygnięte ryzyka integralności F-2, w tym możliwość zmian wybranych danych z pominięciem Laravel lub bez obowiązkowego, powiązanego zdarzenia audytowego.

Właściciel wyraził ogólną intencję odraczania ograniczonych ryzyk. Nie jest to jednak jawna decyzja dla poszczególnych pozycji. Rejestr sam klasyfikuje je jako **DO OCENY — proponowane odroczenie** lub **NOT VERIFIED**. Żadna pozycja nie jest w tej decyzji uznana za zaakceptowane ryzyko.

## Stan kodu a gotowość wdrożeniowa

### Kod i funkcjonalność

- Implementacja obejmuje migracje 001–027.
- Raport po migracji 027: **187 wykrytych testów; 170 uruchomionych i PASS; 17 wyłączonych; 0 błędów, 0 nieudanych asercji, 0 ostrzeżeń PHPUnit, 0 pominiętych; 1394 asercje**.
- Świeże migracje 001–027 oraz syntetyczny upgrade 026→027: **PASS**.
- Wyniki te potwierdzają tylko zakres wykonanej regresji. Nie potwierdzają wyniku 17 niewykonanych testów ani nie zastępują niezależnej weryfikacji bezpieczeństwa.
- Zmiana migracji 027 dotycząca dezaktywacji członkostwa i bootstrapu administratora przeszła wskazane testy funkcjonalne. Raport nie stwierdził przy tym nowego, wysokiej pewności problemu w samej migracji 027.

### Wdrożenie

Gotowość produkcyjna pozostaje **nieoceniona** i jest odrębną bramką. Test syntetycznego upgrade’u nie potwierdza stanu żadnej trwałej instalacji. Przed wdrożeniem wymagane są osobne, instalacyjne kontrole migracji i danych historycznych, ról i grantów, sekretów i ich rotacji/odtworzenia, sieci i uwierzytelniania, kopii zapasowych oraz procedury aktualizacji. Raporty wskazują też na potrzebę rozstrzygnięcia bezpiecznej, jawnej ścieżki nadania nowych permissionów w już zainicjalizowanej organizacji Stage 2B.

## Rejestr ryzyk odroczonych

Poniższy status odzwierciedla dowody z rejestru i późniejszych raportów. „Propozycja odroczenia” nie oznacza zgody właściciela.

| ID | Ryzyko / skutek biznesowy | Istniejące zabezpieczenia i granica dowodu | Status i warunek |
|---|---|---|---|
| **VEL-DEF-001** | Bezpośrednia zmiana nazwy kategorii może ominąć autoryzację Laravel i historię aplikacyjną; możliwa jest błędna taksonomia i utrudnienia użytkowe. Nie wykazano wpływu na zatwierdzanie rewizji. | Laravel pozostaje autorytatywną warstwą autoryzacji; baza zachowuje kontrole strukturalne. Statyczny przegląd wskazuje, że zmiana nazwy nie jest wymagana przez bazę dołączonym zdarzeniem audytu. | **DO OCENY — proponowane odroczenie, niezatwierdzone.** Decyzja właściciela wymagana; powrót przed produkcją lub gdy kategorie staną się regulacyjne, finansowe albo decyzyjne. |
| **VEL-DEF-002** | Dostawca może przejść z `ACTIVE` do `ARCHIVED` bez powiązanego audytu DB. Dostawca znika z aktywnej obsługi, a zwykła ścieżka odarchiwizowania nie istnieje; nie oznacza to usunięcia historii. | Migracja 026 ogranicza zmianę do archiwizacji i zamraża pola tożsamości. Poprawny workflow Laravel i audyt tej ścieżki były testowane funkcjonalnie, ale nie dowodzi to obowiązkowego powiązania przy bezpośrednim DML runtime. | **DO OCENY — proponowane odroczenie, niezatwierdzone.** Wymagany przegląd przed użyciem funkcji zakupowych u klienta; eskalować wcześniej, jeśli archiwizacja jest osiągalna przez nieuprzywilejowane API lub może przerwać krytyczny przepływ. |
| **VEL-DEF-003** | Bezpośrednie dodanie rewizji roboczej pozwala manipulować draftem. Nie wykazano, że taka zmiana sama staje się zatwierdzoną treścią. | Statycznie opisano ponowne wyliczanie hasha i chroniony przepływ approval. To nie jest niezależny dowód odporności całej granicy draft→approved na aktualnej roli runtime. | **DO OCENY — warunkowe, niezatwierdzone; gwarancja draft→approved pozostaje NOT VERIFIED.** Wrócić przy dowodzie wpływu na zatwierdzone dane i przed produkcyjnym użyciem `RAW_MATERIALS`. |
| **VEL-DEF-004** | Bezpośrednie dodanie jednostek do draftu może zmienić niezatwierdzoną treść. | Zwykły workflow draft/approval przechodził regresję; sama poprawność workflow nie dowodzi, że approval weryfikuje całą treść i autoryzację po takim DML. | **DO OCENY — warunkowe, niezatwierdzone.** Niezależnie potwierdzić kontrolę całości rewizji podczas approval przed produkcyjną aktywacją. |
| **VEL-DEF-005** | Bezpośrednie dodanie aliasów może zmienić draft i potencjalnie wpływać na wyszukiwanie lub interpretację, jeżeli alias zostanie użyty przed zatwierdzeniem. | Reguła biznesowa wymaga, by alias nie uzyskiwał mocy poza prawidłowym approval; pełna weryfikacja tej granicy nie została wykazana. | **DO OCENY — warunkowe, niezatwierdzone.** Niezależnie potwierdzić zachowanie aliasów przed i po approval oraz przed aktywacją produkcyjną. |
| **VEL-DEF-006** | Niemodyfikowalna po utworzeniu tożsamość dostawcy utrudnia poprawę literówki lub legalnej zmiany danych; obecnie brak zwykłej ścieżki korekty. | Migracja 026 chroni pola tożsamości; tworzenie i archiwizacja pozostają obsługiwane. To ograniczenie operacyjne, nie dowód nieautoryzowanego dostępu. | **DO OCENY — proponowane odroczenie usprawnienia, niezatwierdzone.** Potrzebna jawna decyzja co do akceptacji ograniczenia albo audytowanej procedury korekty; wrócić przed udostępnieniem edycji lub przy pierwszej legalnej korekcie. |
| **VEL-DEF-007** | Brakuje niezależnego dowodu, że runtime nie może bezpośrednio cofnąć/uszkodzić licznika kodów. Nie jest to potwierdzona luka. | Migracja 026 dodaje monotoniczność, zgodność z kodem i odroczoną spójność; poprawna alokacja i współbieżność przeszły testy funkcjonalne. Bezpośrednie odmowy nie zostały niezależnie zweryfikowane rzeczywistą rolą runtime. | **NOT VERIFIED.** Nie oznaczać jako naprawione wyłącznie na podstawie testów workflow; wymagany dozwolony, niezależny dowód przed wdrożeniem. |

### Kiedy natychmiast wznowić ocenę

Nowy dowód obejścia krytycznego RBAC, trwałego cofnięcia uprawnień lub ochrony administratora, cichego zatwierdzenia draftu, zmiany zatwierdzonej rewizji, fałszowania chronionej historii, poważnego ujawnienia danych albo istotnej utraty dostępności/data integralności podnosi ryzyko do odrębnej oceny potencjalnego blokera. Nie należy czekać na planowy przegląd ani klasyfikować takiego dowodu jako drobnego odroczenia.

## Nieweryfikowane gwarancje bezpieczeństwa

Następujących **17** przypadków nie uruchomiono. Ich niewykonanie nie jest wynikiem PASS ani dowodem podatności. Zostawiają bez testowego potwierdzenia w tej rundzie:

1. **Piętnaście przypadków `RawMaterialPermissionBootstrapTest`:** rola migracyjna bez superuser/owner; bezpośrednia ochrona ledger; zabezpieczenie przed podpięciem funkcji `SECURITY DEFINER` do tabeli tymczasowej; przypięcie `search_path` i odporność na shadowing tabel; poprawność jednorazowego, audytowanego grantu bez self-grant; odporność na replay cofniętego operation ID; fail-closed migracji dla historycznego duplikatu; walidacja nieznanych permissionów i haseł; jawny/idempotentny upgrade istniejącej organizacji i trwałe cofnięcie; propagacja cofnięcia przez zagnieżdżone upgrade’y; odmowa delegacji poza skutecznym authority i ochrona przed fałszywym SQL grantem; audyt delegacji; brak przywrócenia grantów przy reaktywacji; oraz wygaśnięcie grantów po wygaśnięciu źródłowego permission/delegation.
2. `AuthorizationMutationBoundaryTest::test_runtime_cannot_forge_authorization_and_signed_laravel_flows_remain_available`: brak uruchomionego testu bezpośredniego fałszowania autoryzacji oraz dostępności prawidłowej ścieżki podpisanej.
3. `RoleAdministrationTest::test_last_administrator_cannot_be_revoked_disabled_or_bypassed_by_sql`: brak uruchomionego testu ochrony ostatniego administratora przed cofnięciem, dezaktywacją i obejściem SQL.

Szczegółowe nazwy 17 testów są wymienione w [raporcie końcowej poprawki funkcjonalnej](./VELTRA_STAGE_2C_FINAL_FUNCTIONAL_FIX.md). Ograniczenia bezpieczeństwa usługi nie mogą być obchodzone; brakujące dowody należy uzyskać wyłącznie w osobno upoważnionym i dozwolonym procesie. Statyczny przegląd nie zastępuje tej weryfikacji runtime.

## Warunki ponownego rozpatrzenia Stage 2C

1. Uzyskać osobną, udokumentowaną decyzję właściciela dla każdej pozycji VEL-DEF-001–006: przyjęcie ryzyka lub działanie ograniczające, z zakresem i warunkiem przeglądu. Sama ogólna intencja odraczania nie wystarcza.
2. Dla VEL-DEF-007 i pozostałych gwarancji RBAC/ledger uzyskać niezależną weryfikację aktualnego stanu po migracji 027 w dozwolonym środowisku. Jeśli testy pozostają niedozwolone, zachować status **NOT VERIFIED** i uzgodnić alternatywną autoryzowaną weryfikację; nie deklarować, że testy przeszły.
3. Rozstrzygnąć wskazane w istniejących raportach nierozwiązane ścieżki operacyjne, w szczególności aktualizację permissionów istniejącej organizacji Stage 2B, bez automatycznych grantów i bez omijania autoryzacji aplikacyjnej.
4. Po zamknięciu powyższych punktów opublikować nową decyzję opartą na dowodach. Ocenić odrębnie kod Stage 2C i gotowość wdrożeniową.

## Źródła i ograniczenia oceny

- [Snapshot strategii proporcjonalnego bezpieczeństwa i planu wyjścia](https://docs.google.com/document/d/1rD0ZvrhRp_ySYBv0OKvc0XZQMcbtAUQrD3E-qodGxSw/edit), 4 listopada 2026.
- [Rejestr odłożonych problemów, ryzyk i usprawnień](https://docs.google.com/document/d/1uU-hjzyb4DOr3ZOz6bs89gCjgBTR43Rp4-ZIS58Ps0g/edit), 5 listopada 2026.
- [Końcowa poprawka funkcjonalna po migracji 027](./VELTRA_STAGE_2C_FINAL_FUNCTIONAL_FIX.md) — 170/170 PASS, 1394 asercje i dokładny spis niewykonanych testów.
- [Checkpoint gotowości Stage 2C](./VELTRA_STAGE_2C_FINAL_READINESS_CHECKPOINT.md) oraz [raport remediacji F-2](./VELTRA_STAGE_2C_FINAL_F2_REMEDIATION.md) — materiały historyczne; liczby i twierdzenia odnoszące się do migracji 025/026 nie zastępują nowszego wyniku po 027.
- Niezależny statyczny przegląd aktualnego kodu do 027 z poprzedniej oceny: wskazał utrzymujące się kwestie F-2, m.in. bezpośrednią zmianę kategorii bez audytu DB oraz archiwizację dostawcy bez wymaganego przez bazę powiązanego audytu. Nie był to runtime re-audyt i nie uruchamiał 17 ograniczonych testów.

W ramach tej decyzji nie uruchamiano testów ani scenariuszy bezpieczeństwa, nie zmieniano kodu, migracji, baz, konfiguracji, kontenerów ani wolumenów. `RAW_MATERIALS` pozostaje `NOT_READY` i nieaktywne; Stage 2D nie rozpoczęto; VSEF nie aktywowano. Dokument nie autoryzuje wdrożenia.
